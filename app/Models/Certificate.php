<?php

namespace App\Models;

use App\Enums\CertificateKind;
use App\Enums\CertificateStatus;
use App\Enums\ComplimentaryPurpose;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\Concerns\PreventsDeletion;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Подарочный сертификат — неделимый пакет погашения: все услуги
 * используются только вместе, по отдельности не гасятся и не обмениваются на деньги.
 * Статус, состав и оплаты меняются только через сервисы (App\Services\Certificates).
 */
class Certificate extends Model
{
    use HasFactory, PreventsDeletion;

    protected $attributes = ['kind' => 'sale', 'custom_validity' => false];

    protected $fillable = [
        'number', 'verification_token', 'customer_id', 'recipient_id', 'package_id', 'status', 'kind', 'complimentary_reason', 'complimentary_purpose', 'batch_id', 'complimentary_approved_by', 'complimentary_approved_at', 'sold_at', 'valid_from',
        'valid_until', 'custom_validity', 'total_amount', 'total_cost', 'currency', 'issued_at', 'cancelled_at', 'cancellation_reason',
        'notes', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => CertificateStatus::class,
            'kind' => CertificateKind::class,
            'complimentary_purpose' => ComplimentaryPurpose::class,
            'sold_at' => 'date',
            'valid_from' => 'date',
            'valid_until' => 'date',
            'custom_validity' => 'boolean',
            'total_amount' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'complimentary_approved_at' => 'datetime',
            'issued_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Recipient::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CertificateItem::class)->orderBy('position')->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(CertificateHistory::class)->orderByDesc('id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CertificateBatch::class, 'batch_id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class)->orderByDesc('id');
    }

    public function complimentaryApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'complimentary_approved_by');
    }

    public function isComplimentaryApproved(): bool
    {
        return $this->complimentary_approved_at !== null;
    }

    /** У части строк себестоимость не указана — итог себестоимости неполный. */
    public function hasUnknownCost(): bool
    {
        return $this->items()->whereNull('unit_cost')->exists();
    }

    public function isComplimentary(): bool
    {
        return $this->kind === CertificateKind::Complimentary;
    }

    /** Есть запрос на возврат, ожидающий решения Финансов: сертификат заморожен. */
    public function hasPendingRefund(): bool
    {
        return $this->refunds()->where('status', RefundStatus::Requested)->exists();
    }

    /** Сертификат выпущен и ещё может быть использован — его документ можно выдать гостю. */
    public function isPrintable(): bool
    {
        return in_array($this->status, [CertificateStatus::Active, CertificateStatus::Reserved, CertificateStatus::InUse], true)
            && $this->verification_token !== null;
    }

    public function isDraft(): bool
    {
        return $this->status === CertificateStatus::Draft;
    }

    /** Сумма поступивших оплат. */
    public function paidAmount(): string
    {
        return Money::fromMinor(Money::toMinor($this->payments()->where('status', PaymentStatus::Paid)->sum('amount')));
    }

    /** Сумма выставленных и оплаченных счетов (без отменённых). */
    public function invoicedAmount(): string
    {
        return Money::fromMinor(Money::toMinor($this->payments()->where('status', '!=', PaymentStatus::Cancelled)->sum('amount')));
    }

    public function isFullyPaid(): bool
    {
        return Money::toMinor($this->paidAmount()) >= Money::toMinor($this->total_amount);
    }
}
