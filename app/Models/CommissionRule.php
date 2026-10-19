<?php

namespace App\Models;

use App\Enums\CommissionType;
use App\Exceptions\DomainRuleViolation;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\PreventsDeletion;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Правило комиссии по пакету на период. Версия не правится: меняется только дата окончания
 * (когда её закрывает новая версия); ставка и период — только новой версией через CommissionManager.
 */
class CommissionRule extends Model
{
    use Auditable, PreventsDeletion;

    protected $fillable = ['package_id', 'type', 'rate', 'fixed_amount', 'valid_from', 'valid_until', 'created_by'];

    protected function casts(): array
    {
        return [
            'type' => CommissionType::class,
            'rate' => 'decimal:2',
            'fixed_amount' => 'decimal:2',
            'valid_from' => 'date',
            'valid_until' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $rule) {
            if ($rule->isDirty(['package_id', 'type', 'rate', 'fixed_amount', 'valid_from'])) {
                throw new DomainRuleViolation('Правило комиссии не правится — добавьте новую версию.');
            }
        });
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(CertificateCommission::class, 'rule_id');
    }

    public function scopeEffectiveOn(Builder $query, Carbon $date): void
    {
        $day = $date->toDateString();
        $query->whereDate('valid_from', '<=', $day)
            ->where(fn (Builder $q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $day));
    }

    /** «5%» или «15 000 ₸». */
    public function describe(): string
    {
        return $this->type === CommissionType::Percent
            ? rtrim(rtrim((string) $this->rate, '0'), '.').'%'
            : Money::format($this->fixed_amount);
    }

    /** Комиссия с цены продажи в тиынах, округление до тиына. */
    public function calculate(string|float $salePrice): string
    {
        if ($this->type === CommissionType::Fixed) {
            return Money::fromMinor(Money::toMinor($this->fixed_amount));
        }

        return Money::fromMinor((int) round(Money::toMinor($salePrice) * (float) $this->rate / 100, 0, PHP_ROUND_HALF_UP));
    }
}
