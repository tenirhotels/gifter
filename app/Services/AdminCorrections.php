<?php

namespace App\Services;

use App\Enums\CertificateStatus;
use App\Enums\HistoryEvent;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Certificate;
use App\Models\CertificateHistory;
use App\Models\Payment;
use App\Models\Refund;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Исправление ошибок администратором: откат статуса сертификата, оплаты,
 * сверки, решения по возврату, согласования бесплатного.
 *
 * Обычные правила переходов здесь намеренно не действуют, но:
 * причина обязательна, прежняя история не стирается — исправление
 * добавляется отдельной записью «Исправление администратора» с «было → стало».
 */
class AdminCorrections
{
    public function __construct(
        private readonly CertificateHistoryRecorder $history,
        private readonly FulfillmentManager $fulfillments,
    ) {}

    public function setStatus(Certificate $certificate, CertificateStatus $target, string $reason): Certificate
    {
        return $this->inTransaction($reason, function () use ($certificate, $target, $reason) {
            $certificate = Certificate::query()->lockForUpdate()->findOrFail($certificate->id);
            $from = $certificate->status;
            if ($from === $target) {
                throw new DomainRuleViolation('Сертификат уже в этом статусе.');
            }
            if ($certificate->hasPendingRefund()) {
                throw new DomainRuleViolation('Сначала решите или верните запрос на возврат.');
            }
            $changes = ['status' => $target, 'updated_by' => auth()->id()];
            if ($from === CertificateStatus::Cancelled) {
                $changes += ['cancelled_at' => null, 'cancellation_reason' => null];
            }
            if ($target === CertificateStatus::Draft) {
                $changes['issued_at'] = null;
            } elseif ($from === CertificateStatus::Draft) {
                $changes['issued_at'] = now();
                $changes['verification_token'] = $certificate->verification_token ?? Str::random(32);
            }
            $certificate->update($changes);
            $this->record($certificate, "Статус «{$from->getLabel()}» → «{$target->getLabel()}»", $reason,
                ['status' => $from->value], ['status' => $target->value]);
            $this->fulfillments->syncWithCertificate($certificate, "Исправление администратора: {$reason}");

            return $certificate;
        });
    }

    /** Ошибочно отмеченную оплату или ошибочно отменённый счёт — обратно в «Счёт выставлен». */
    public function reopenPayment(Payment $payment, string $reason): Payment
    {
        return $this->inTransaction($reason, function () use ($payment, $reason) {
            $certificate = Certificate::query()->lockForUpdate()->findOrFail($payment->certificate_id);
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($payment->status === PaymentStatus::Pending) {
                throw new DomainRuleViolation('Счёт и так ожидает оплаты.');
            }
            if ($payment->status === PaymentStatus::Cancelled) {
                $outstanding = Money::toMinor($certificate->payable_amount) - Money::toMinor($certificate->invoicedAmount());
                if (Money::toMinor($payment->amount) > $outstanding) {
                    throw new DomainRuleViolation('Нельзя вернуть счёт: вместе с другими счетами он превысит стоимость сертификата.');
                }
            }
            $before = ['status' => $payment->status->value, 'paid_at' => $payment->paid_at?->toDateTimeString(), 'reconciled' => $payment->isReconciled()];
            $payment->update(['status' => PaymentStatus::Pending, 'paid_at' => null, 'cancelled_at' => null, 'reconciled_at' => null, 'reconciled_by' => null]);
            $this->record($certificate, "Счёт {$payment->method->getLabel()} ".Money::format($payment->amount).' возвращён в «Счёт выставлен»', $reason,
                $before, ['status' => PaymentStatus::Pending->value, 'payment_id' => $payment->id]);

            return $payment;
        });
    }

    public function unreconcile(Payment $payment, string $reason): Payment
    {
        return $this->inTransaction($reason, function () use ($payment, $reason) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if (! $payment->isReconciled()) {
                throw new DomainRuleViolation('Оплата не сверена.');
            }
            $payment->update(['reconciled_at' => null, 'reconciled_by' => null]);
            $this->record($payment->certificate, 'Снята сверка оплаты '.Money::format($payment->amount), $reason,
                ['reconciled' => true, 'payment_id' => $payment->id], ['reconciled' => false]);

            return $payment;
        });
    }

    /**
     * Решение по возврату — обратно «На согласование». Если возврат был согласован,
     * сертификату возвращается статус, который был до аннулирования.
     */
    public function reopenRefund(Refund $refund, string $reason): Refund
    {
        return $this->inTransaction($reason, function () use ($refund, $reason) {
            $certificate = Certificate::query()->lockForUpdate()->findOrFail($refund->certificate_id);
            $refund = Refund::query()->lockForUpdate()->findOrFail($refund->id);
            if ($refund->status === RefundStatus::Requested) {
                throw new DomainRuleViolation('Запрос и так на согласовании.');
            }
            $from = $refund->status;
            if (in_array($from, [RefundStatus::Approved, RefundStatus::Completed], true)) {
                $approval = CertificateHistory::query()->where('certificate_id', $certificate->id)
                    ->where('event', HistoryEvent::RefundApproved)->latest('id')->first();
                $previous = CertificateStatus::tryFrom($approval?->before['status'] ?? '') ?? CertificateStatus::Active;
                $certificate->update(['status' => $previous, 'cancelled_at' => null, 'cancellation_reason' => null, 'updated_by' => auth()->id()]);
                $this->fulfillments->syncWithCertificate($certificate, "Исправление администратора: {$reason}");
            }
            $refund->update([
                'status' => RefundStatus::Requested, 'reviewed_by' => null, 'reviewed_at' => null,
                'decision_comment' => null, 'paid_by' => null, 'paid_at' => null, 'payout_reference' => null,
            ]);
            $this->record($certificate, "Возврат «{$from->getLabel()}» возвращён на согласование", $reason,
                ['refund_status' => $from->value], ['refund_status' => RefundStatus::Requested->value, 'status' => $certificate->status->value]);

            return $refund;
        });
    }

    public function revokeComplimentaryApproval(Certificate $certificate, string $reason): Certificate
    {
        return $this->inTransaction($reason, function () use ($certificate, $reason) {
            $certificate = Certificate::query()->lockForUpdate()->findOrFail($certificate->id);
            if (! $certificate->isDraft() || ! $certificate->isComplimentaryApproved()) {
                throw new DomainRuleViolation('Отозвать можно только согласование ещё не выпущенного бесплатного сертификата.');
            }
            $certificate->update(['complimentary_approved_by' => null, 'complimentary_approved_at' => null, 'updated_by' => auth()->id()]);
            $this->record($certificate, 'Отозвано согласование бесплатного сертификата', $reason);

            return $certificate;
        });
    }

    private function inTransaction(string $reason, callable $callback): mixed
    {
        if (trim($reason) === '') {
            throw new DomainRuleViolation('Укажите причину исправления.');
        }

        return DB::transaction($callback);
    }

    private function record(Certificate $certificate, string $what, string $reason, ?array $before = null, ?array $after = null): void
    {
        $this->history->record($certificate, HistoryEvent::AdminCorrection, "{$what}. Причина: {$reason}", $before, $after);
    }
}
