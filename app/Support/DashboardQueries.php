<?php

namespace App\Support;

use App\Enums\ArrivalReadinessLevel;
use App\Enums\CertificateKind;
use App\Enums\CertificateStatus;
use App\Enums\FulfillmentStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Enums\ReservationReview;
use App\Models\Certificate;
use App\Models\CertificateBatch;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Service;
use App\Models\ServiceFulfillment;
use App\Models\User;
use App\Policies\CertificateBatchPolicy;
use App\Policies\CertificatePolicy;
use Illuminate\Database\Eloquent\Builder;

/** Запросы для инфопанели. Видимость сертификатов — по тем же правилам, что и в реестре. */
final class DashboardQueries
{
    public const EXPIRING_DAYS = 14;

    public function __construct(private readonly User $user) {}

    public function certificates(): Builder
    {
        return CertificatePolicy::scopeVisible(Certificate::query(), $this->user);
    }

    public function drafts(): Builder
    {
        return $this->certificates()->where('status', CertificateStatus::Draft);
    }

    /** Черновики со счётом, по которому деньги ещё не пришли. */
    public function awaitingPayment(): Builder
    {
        return $this->drafts()->whereHas('payments', fn (Builder $q) => $q->where('status', PaymentStatus::Pending));
    }

    /** Оплачены полностью (или согласованы как бесплатные), но не выпущены. */
    public function readyToIssue(): Builder
    {
        return $this->drafts()->whereNull('batch_id')->where(fn (Builder $q) => $q
            ->where(fn (Builder $q) => $q->where('kind', CertificateKind::Complimentary)->whereNotNull('complimentary_approved_at')
                ->whereRaw('(select coalesce(sum(amount), 0) from payments where payments.certificate_id = certificates.id and payments.status = ?) >= certificates.payable_amount', [PaymentStatus::Paid->value]))
            ->orWhere(fn (Builder $q) => $q->where('kind', CertificateKind::Sale)->where('payable_amount', '>', 0)
                ->whereRaw('(select coalesce(sum(amount), 0) from payments where payments.certificate_id = certificates.id and payments.status = ?) >= certificates.payable_amount', [PaymentStatus::Paid->value])));
    }

    public function withoutRecipient(): Builder
    {
        return $this->certificates()->whereNull('recipient_id')
            ->whereNotIn('status', [CertificateStatus::Used, CertificateStatus::Expired, CertificateStatus::Cancelled]);
    }

    public function expiringSoon(): Builder
    {
        return $this->certificates()->whereIn('status', [CertificateStatus::Active, CertificateStatus::Reserved])
            ->whereDate('valid_until', '<=', today()->addDays(self::EXPIRING_DAYS)->toDateString());
    }

    /** Бесплатные сертификаты на согласовании — штучные и из бесплатных групп. */
    public function complimentaryAwaitingApproval(): Builder
    {
        return $this->drafts()->where('kind', CertificateKind::Complimentary)->whereNull('complimentary_approved_at');
    }

    /** Группы сертификатов (несколько, оформленных вместе), видимые пользователю. */
    public function batches(): Builder
    {
        return CertificateBatchPolicy::scopeVisible(CertificateBatch::query(), $this->user);
    }

    public function freeIssuesAwaitingApproval(): Builder
    {
        return $this->batches()->awaitingApproval();
    }

    /** Платные группы, оплаченные полностью, — к выпуску. */
    public function purchasesReadyToIssue(): Builder
    {
        return $this->batches()->readyToIssue()->where('kind', CertificateKind::Sale);
    }

    /** Бесплатные группы, согласованные директором, — к выпуску. */
    public function freeIssuesReadyToIssue(): Builder
    {
        return $this->batches()->readyToIssue()->where('kind', CertificateKind::Complimentary);
    }

    /** Забронированные с заездом сегодня. */
    public function arrivalsToday(): Builder
    {
        return $this->certificates()->where('status', CertificateStatus::Reserved)->whereDate('arrival_date', today()->toDateString());
    }

    /** Заезды в ближайшие 7 дней, которые «Требуют действия» или «Исключение». */
    public function arrivalsNeedingAttention(): Builder
    {
        $base = fn () => $this->certificates()->where('status', CertificateStatus::Reserved)
            ->whereBetween('arrival_date', [today()->toDateString(), today()->addDays(6)->toDateString()]);
        $ids = ArrivalReadiness::scope($base(), ArrivalReadinessLevel::Action)->pluck('id')
            ->merge(ArrivalReadiness::scope($base(), ArrivalReadinessLevel::Exception)->pluck('id'));

        return $this->certificates()->whereKey($ids->all());
    }

    /** Брони на проверке Финансов. */
    public function reservationsUnderReview(): Builder
    {
        return $this->certificates()->where('reservation_review_status', ReservationReview::Pending);
    }

    /** Заявки на исполнение по видимым сертификатам. */
    public function fulfillments(): Builder
    {
        return ServiceFulfillment::query()->whereHas('certificate', fn (Builder $q) => CertificatePolicy::scopeVisible($q, $this->user));
    }

    /** Ждут подтверждения поставщика: отправлены или назначены, но ещё не подтверждены. */
    public function fulfillmentsAwaitingConfirmation(): Builder
    {
        return $this->fulfillments()->where('requires_confirmation', true)
            ->whereIn('status', [FulfillmentStatus::Requested, FulfillmentStatus::Scheduled]);
    }

    /** Открытые заявки с датой на сегодня. */
    public function fulfillmentsToday(): Builder
    {
        return $this->fulfillments()->whereIn('status', FulfillmentStatus::open())->whereDate('scheduled_date', today()->toDateString());
    }

    /** Выпущенные в этом месяце (продажи, без бесплатных и аннулированных). */
    public function soldThisMonth(): Builder
    {
        return $this->certificates()->where('kind', CertificateKind::Sale)->whereNotNull('issued_at')
            ->where('status', '!=', CertificateStatus::Cancelled)
            ->whereBetween('issued_at', [now()->startOfMonth(), now()->endOfMonth()]);
    }

    public function complimentaryThisMonth(): Builder
    {
        return $this->certificates()->where('kind', CertificateKind::Complimentary)->whereNotNull('issued_at')
            ->where('status', '!=', CertificateStatus::Cancelled)
            ->whereBetween('issued_at', [now()->startOfMonth(), now()->endOfMonth()]);
    }

    public function status(CertificateStatus $status): Builder
    {
        return $this->certificates()->where('status', $status);
    }

    public function paymentsToReconcile(): Builder
    {
        return Payment::query()->where('status', PaymentStatus::Paid)->whereNull('reconciled_at');
    }

    public function bankTransfersAwaiting(): Builder
    {
        return Payment::query()->where('status', PaymentStatus::Pending)->whereIn('method', PaymentMethod::financeConfirmed());
    }

    public function receivedThisMonth(): Builder
    {
        return Payment::query()->where('status', PaymentStatus::Paid)->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()]);
    }

    public function refunds(RefundStatus $status): Builder
    {
        return Refund::query()->where('status', $status);
    }

    /** Активные услуги без действующей сегодня цены — их нельзя продать. */
    public function servicesWithoutPrice(): Builder
    {
        $day = today()->toDateString();

        return Service::query()->where('is_active', true)->whereDoesntHave('prices', fn (Builder $q) => $q
            ->whereDate('valid_from', '<=', $day)->where(fn (Builder $q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $day)));
    }

    public function servicesWithoutCost(): Builder
    {
        $day = today()->toDateString();

        return Service::query()->where('is_active', true)->whereHas('prices', fn (Builder $q) => $q->whereNull('cost')
            ->whereDate('valid_from', '<=', $day)->where(fn (Builder $q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $day)));
    }
}
