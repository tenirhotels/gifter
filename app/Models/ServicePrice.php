<?php

namespace App\Models;

use App\Exceptions\DomainRuleViolation;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\PreventsDeletion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/** Версия цены услуги на период [valid_from; valid_until]. */
class ServicePrice extends Model
{
    use Auditable, HasFactory, PreventsDeletion;

    protected $fillable = ['service_id', 'price', 'currency', 'valid_from', 'valid_until', 'created_by'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'valid_from' => 'date', 'valid_until' => 'date'];
    }

    protected static function booted(): void
    {
        // Цена, уже попавшая в сертификаты, не переписывается: нужна новая версия.
        static::updating(function (self $price) {
            if ($price->isDirty(['price', 'valid_from', 'service_id']) && $price->certificateItems()->exists()) {
                throw new DomainRuleViolation('Цена уже использована в сертификатах — добавьте новую версию цены.');
            }
        });
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function certificateItems(): HasMany
    {
        return $this->hasMany(CertificateItem::class, 'price_version_id');
    }

    public function scopeEffectiveOn(Builder $query, Carbon $date): void
    {
        $day = $date->toDateString();
        $query->whereDate('valid_from', '<=', $day)
            ->where(fn (Builder $q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $day));
    }
}
