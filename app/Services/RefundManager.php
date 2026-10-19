<?php

namespace App\Services;

use App\Enums\CertificateStatus;
use App\Enums\HistoryEvent;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Certificate;
use App\Models\Refund;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Возврат денег и аннулирование оплаченного сертификата (редкий случай).
 *
 * Менеджер запрашивает → Финансы согласуют (сертификат аннулируется) или отклоняют
 * → Финансы подтверждают выплату. Пока запрос не рассмотрен, сертификат заморожен.
 */
class RefundManager
{
    public function __construct(
        private readonly CertificateHistoryRecorder $history,
        private readonly FulfillmentManager $fulfillments,
        private readonly CommissionManager $commissions,
    ) {}

    public function request(Certificate $certificate, string $amount, string $reason): Refund
    {
        return DB::transaction(function () use ($certificate, $amount, $reason) {
            $certificate = Certificate::query()->lockForUpdate()->findOrFail($certificate->id);
            if (trim($reason) === '') {
                throw new DomainRuleViolation('Укажите причину возврата.');
            }
            if (! $certificate->status->canTransitionTo(CertificateStatus::Cancelled)) {
                throw new DomainRuleViolation("Сертификат в статусе «{$certificate->status->getLabel()}» аннулировать нельзя.");
            }
            if ($certificate->hasPendingRefund()) {
                throw new DomainRuleViolation('Запрос на возврат уже ждёт решения.');
            }
            if ($certificate->payments()->where('status', PaymentStatus::Pending)->exists()) {
                throw new DomainRuleViolation('Сначала отмените выставленные неоплаченные счета.');
            }
            $paid = Money::toMinor($certificate->paidAmount());
            if ($paid === 0) {
                throw new DomainRuleViolation('Оплаты нет — сертификат можно аннулировать без возврата.');
            }
            $amountMinor = Money::toMinor($amount);
            if ($amountMinor < 0 || $amountMinor > $paid) {
                throw new DomainRuleViolation('Сумма возврата должна быть от 0 до '.Money::format(Money::fromMinor($paid)).'.');
            }

            $refund = $certificate->refunds()->create([
                'status' => RefundStatus::Requested,
                'amount' => Money::fromMinor($amountMinor),
                'currency' => config('gifter.currency'),
                'reason' => $reason,
                'requested_by' => auth()->id(),
            ]);
            $this->history->record($certificate, HistoryEvent::RefundRequested,
                'Возврат '.Money::format($refund->amount).'. '.$reason, after: ['refund_id' => $refund->id, 'amount' => (string) $refund->amount]);

            return $refund;
        });
    }

    /** Согласовать: сертификат аннулируется. Нулевой возврат сразу считается завершённым. */
    public function approve(Refund $refund, ?string $comment = null): Refund
    {
        return $this->lockedRequested($refund, function (Refund $refund, Certificate $certificate) use ($comment) {
            $zero = Money::toMinor($refund->amount) === 0;
            $refund->update([
                'status' => $zero ? RefundStatus::Completed : RefundStatus::Approved,
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
                'decision_comment' => $comment,
            ]);
            $from = $certificate->status;
            $certificate->update([
                'status' => CertificateStatus::Cancelled,
                'cancelled_at' => now(),
                'cancellation_reason' => 'Возврат: '.$refund->reason,
                'updated_by' => auth()->id(),
            ]);
            $this->history->record($certificate, HistoryEvent::RefundApproved, $comment,
                ['status' => $from->value], ['status' => CertificateStatus::Cancelled->value]);
            $this->fulfillments->syncWithCertificate($certificate);
            $this->commissions->void($certificate, 'Возврат по сертификату: комиссия не начисляется');

            return $refund;
        });
    }

    public function reject(Refund $refund, string $comment): Refund
    {
        if (trim($comment) === '') {
            throw new DomainRuleViolation('Укажите причину отказа.');
        }

        return $this->lockedRequested($refund, function (Refund $refund, Certificate $certificate) use ($comment) {
            $refund->update([
                'status' => RefundStatus::Rejected,
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
                'decision_comment' => $comment,
            ]);
            $this->history->record($certificate, HistoryEvent::RefundRejected, $comment);

            return $refund;
        });
    }

    public function markPaid(Refund $refund, ?Carbon $paidAt = null, ?string $reference = null): Refund
    {
        return DB::transaction(function () use ($refund, $paidAt, $reference) {
            $fresh = Refund::query()->lockForUpdate()->findOrFail($refund->id);
            if ($fresh->status !== RefundStatus::Approved) {
                throw new DomainRuleViolation('Выплату можно отметить только по согласованному возврату.');
            }
            $fresh->update([
                'status' => RefundStatus::Completed,
                'paid_by' => auth()->id(),
                'paid_at' => $paidAt ?? now(),
                'payout_reference' => $reference,
            ]);
            $this->history->record($fresh->certificate, HistoryEvent::RefundPaid,
                Money::format($fresh->amount).($reference ? ", {$reference}" : ''));
            $refund->setRawAttributes($fresh->getAttributes(), true);

            return $fresh;
        });
    }

    private function lockedRequested(Refund $refund, callable $callback): Refund
    {
        return DB::transaction(function () use ($refund, $callback) {
            $certificate = Certificate::query()->lockForUpdate()->findOrFail($refund->certificate_id);
            $fresh = Refund::query()->lockForUpdate()->findOrFail($refund->id);
            if ($fresh->status !== RefundStatus::Requested) {
                throw new DomainRuleViolation("Запрос в статусе «{$fresh->status->getLabel()}» уже рассмотрен.");
            }
            $result = $callback($fresh, $certificate);
            $refund->setRawAttributes($fresh->getAttributes(), true);

            return $result;
        });
    }
}
