<?php

namespace App\Models;

use App\Enums\CertificateKind;
use App\Enums\CertificateStatus;
use App\Enums\ComplimentaryPurpose;
use App\Enums\NameMatch;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Enums\ReservationReview;
use App\Models\Concerns\PreventsDeletion;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
        'valid_until', 'custom_validity', 'total_amount', 'total_cost', 'payable_amount', 'marketing_cost', 'currency', 'issued_at', 'cancelled_at', 'cancellation_reason',
        'notes', 'created_by', 'sold_by', 'updated_by',
        'pms_booking_number', 'pms_verified_at', 'pms_data_age_minutes', 'pms_checked_at', 'pms_check_status', 'pms_check_note', 'booking_guest_name', 'arrival_date', 'departure_date', 'name_match', 'reservation_note',
        'reservation_review_status', 'reservation_reviewed_by', 'reservation_reviewed_at', 'reservation_review_comment',
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
            'payable_amount' => 'decimal:2',
            'marketing_cost' => 'decimal:2',
            'complimentary_approved_at' => 'datetime',
            'issued_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'pms_verified_at' => 'datetime',
            'pms_checked_at' => 'datetime',
            'arrival_date' => 'date',
            'departure_date' => 'date',
            'name_match' => NameMatch::class,
            'reservation_review_status' => ReservationReview::class,
            'reservation_reviewed_at' => 'datetime',
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

    /** Заявки на исполнение услуг (фаза 5). */
    public function fulfillments(): HasMany
    {
        return $this->hasMany(ServiceFulfillment::class);
    }

    /** Продавец, за которым закреплена комиссия (по умолчанию — автор оформления). */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sold_by');
    }

    /**
     * Сколько ночей проживания даёт сертификат: услуги «ночь» в составе — пакета и доп. ночи.
     * null — проживания в составе нет (сверка по ночам не применяется).
     */
    public function nightsIncluded(): ?int
    {
        $nights = $this->items()->get()->filter(fn (CertificateItem $item) => str_starts_with(mb_strtolower(trim((string) $item->unit_snapshot)), 'ноч'))
            ->sum('quantity');

        return $nights > 0 ? (int) $nights : null;
    }

    public function reservationExceptions(): HasMany
    {
        return $this->hasMany(ReservationException::class);
    }

    /** Ждёт ли решения директора заявка на бронь по исключению. */
    public function hasPendingException(): bool
    {
        return $this->reservationExceptions()->where('status', 'pending')->exists();
    }

    public function commission(): HasOne
    {
        return $this->hasOne(CertificateCommission::class);
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

    public function hasReservation(): bool
    {
        return $this->pms_booking_number !== null;
    }

    /** «№ 12345 · 20.10–22.10.2026 · Иванов Иван». */
    public function reservationLabel(): ?string
    {
        if (! $this->hasReservation()) {
            return null;
        }

        return "№ {$this->pms_booking_number} · {$this->arrival_date->format('d.m')}–{$this->departure_date->format('d.m.Y')} · {$this->booking_guest_name}";
    }

    public function reservationReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reservation_reviewed_by');
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

    /** Есть часть, которую оплачивает заказчик: вся продажа или платные доп. услуги бесплатного. */
    public function hasPayablePart(): bool
    {
        return Money::toMinor($this->payable_amount) > 0;
    }

    /** Платная часть оплачена полностью (если её нет — оплачивать нечего). */
    public function isFullyPaid(): bool
    {
        return Money::toMinor($this->paidAmount()) >= Money::toMinor($this->payable_amount);
    }

    /** Сумма оплат, подтверждённых Финансами (сверено по выписке). */
    public function reconciledAmount(): string
    {
        return Money::fromMinor(Money::toMinor($this->payments()->where('status', PaymentStatus::Paid)->whereNotNull('reconciled_at')->sum('amount')));
    }

    /**
     * Оплату подтвердили Финансы: продавец сам отмечает поступление по Kaspi/Halyk, поэтому выпуск — только после сверки
     * (решение по аудиту; отключается GIFTER_REQUIRE_PAYMENT_RECONCILED=false). Нет платной части — подтверждать нечего.
     */
    public function isPaymentConfirmed(): bool
    {
        if (! config('gifter.require_payment_reconciled') || ! $this->hasPayablePart()) {
            return true;
        }

        return Money::toMinor($this->reconciledAmount()) >= Money::toMinor($this->payable_amount);
    }

    /** Черновик можно выпускать: бесплатный согласован, платная часть (если есть) оплачена полностью. */
    public function isReadyToIssue(): bool
    {
        if (! $this->isDraft()) {
            return false;
        }

        return $this->isComplimentary()
            ? $this->isComplimentaryApproved() && $this->isFullyPaid() && $this->isPaymentConfirmed()
            : $this->hasPayablePart() && $this->isFullyPaid() && $this->isPaymentConfirmed();
    }

    /** Первый выставленный и ещё не оплаченный счёт. */
    public function pendingPayment(): ?Payment
    {
        return $this->payments()->where('status', PaymentStatus::Pending)->orderBy('id')->first();
    }
}
