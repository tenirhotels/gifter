<?php

namespace App\Support;

use App\Enums\BatchStatus;
use App\Enums\CertificateKind;
use App\Enums\CertificateStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\Certificate;
use App\Models\CertificateBatch;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Service;
use App\Models\User;
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
            ->where(fn (Builder $q) => $q->where('kind', CertificateKind::Complimentary)->whereNotNull('complimentary_approved_at'))
            ->orWhere(fn (Builder $q) => $q->where('kind', CertificateKind::Sale)->where('total_amount', '>', 0)
                ->whereRaw('(select coalesce(sum(amount), 0) from payments where payments.certificate_id = certificates.id and payments.status = ?) >= certificates.total_amount', [PaymentStatus::Paid->value])));
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

    /** Штучные бесплатные на согласовании (блоки считаются отдельно). */
    public function complimentaryAwaitingApproval(): Builder
    {
        return $this->drafts()->where('kind', CertificateKind::Complimentary)->whereNull('complimentary_approved_at')->whereNull('batch_id');
    }

    public function batches(BatchStatus $status): Builder
    {
        return CertificateBatch::query()->where('status', $status);
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
        return Payment::query()->where('status', PaymentStatus::Pending)->where('method', PaymentMethod::BankTransfer);
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
