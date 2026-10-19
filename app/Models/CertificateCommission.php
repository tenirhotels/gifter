<?php

namespace App\Models;

use App\Enums\CommissionStatus;
use App\Enums\CommissionType;
use App\Models\Concerns\PreventsDeletion;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Комиссия продавца по одному проданному сертификату. Снимок (правило, ставка, цена продажи, сумма)
 * сохраняется при выпуске и не пересчитывается по будущим правилам. Изменения пишутся в журнал аудита
 * через CommissionManager.
 */
class CertificateCommission extends Model
{
    use PreventsDeletion;

    protected $fillable = [
        'certificate_id', 'seller_id', 'status', 'rule_id', 'rule_type', 'rule_rate', 'rule_fixed_amount', 'sale_price', 'amount',
        'earned_at', 'approved_by', 'approved_at', 'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => CommissionStatus::class,
            'rule_type' => CommissionType::class,
            'rule_rate' => 'decimal:2',
            'rule_fixed_amount' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'amount' => 'decimal:2',
            'earned_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function certificate(): BelongsTo
    {
        return $this->belongsTo(Certificate::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(CommissionRule::class, 'rule_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(CommissionPayment::class, 'commission_id');
    }

    /** Действующая (не отменённая) выплата. */
    public function activePayment(): ?CommissionPayment
    {
        return $this->payments()->whereNull('reversed_at')->latest('id')->first();
    }

    /** «5% от 160 000 ₸» или «фикс. 15 000 ₸». */
    public function ruleLabel(): string
    {
        return $this->rule_type === CommissionType::Percent
            ? rtrim(rtrim((string) $this->rule_rate, '0'), '.').'%'
            : 'фикс. '.Money::format($this->rule_fixed_amount);
    }
}
