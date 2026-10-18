<?php

namespace App\Services;

use App\Enums\CertificateStatus;
use App\Enums\HistoryEvent;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Certificate;
use App\Models\Payment;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Счета на оплату сертификата. Интеграции с банками нет: менеджер выставляет
 * счёт в Kaspi или Halyk и вручную отмечает поступление денег.
 */
class PaymentManager
{
    public function __construct(private readonly CertificateHistoryRecorder $history) {}

    /** Выставить счёт. Без суммы — на весь неоплаченный остаток. */
    public function invoice(Certificate $certificate, PaymentMethod $method, ?string $amount = null, ?string $invoiceNumber = null, ?string $comment = null): Payment
    {
        return DB::transaction(function () use ($certificate, $method, $amount, $invoiceNumber, $comment) {
            $certificate = Certificate::query()->lockForUpdate()->findOrFail($certificate->id);
            if ($certificate->status !== CertificateStatus::Draft) {
                throw new DomainRuleViolation('Счёт выставляется только по черновику, до выпуска сертификата.');
            }
            $outstanding = Money::toMinor($certificate->total_amount) - Money::toMinor($certificate->invoicedAmount());
            if ($outstanding <= 0) {
                throw new DomainRuleViolation('На всю сумму сертификата счета уже выставлены.');
            }
            $amountMinor = $amount === null ? $outstanding : Money::toMinor($amount);
            if ($amountMinor <= 0) {
                throw new DomainRuleViolation('Сумма счёта должна быть больше нуля.');
            }
            if ($amountMinor > $outstanding) {
                throw new DomainRuleViolation('Сумма счёта больше неоплаченного остатка ('.Money::format(Money::fromMinor($outstanding)).').');
            }

            $payment = $certificate->payments()->create([
                'method' => $method,
                'status' => PaymentStatus::Pending,
                'amount' => Money::fromMinor($amountMinor),
                'currency' => config('gifter.currency'),
                'invoice_number' => $invoiceNumber,
                'invoiced_at' => now(),
                'comment' => $comment,
                'recorded_by' => auth()->id(),
            ]);
            $this->history->record($certificate, HistoryEvent::PaymentRegistered,
                $method->getLabel().', '.Money::format($payment->amount), after: $this->snapshot($payment));

            return $payment;
        });
    }

    public function markPaid(Payment $payment, ?Carbon $paidAt = null): Payment
    {
        return $this->lockedPending($payment, function (Payment $payment) use ($paidAt) {
            $before = $this->snapshot($payment);
            $payment->update(['status' => PaymentStatus::Paid, 'paid_at' => $paidAt ?? now()]);
            $this->history->record($payment->certificate, HistoryEvent::PaymentPaid,
                $payment->method->getLabel().', '.Money::format($payment->amount), $before, $this->snapshot($payment));

            return $payment;
        });
    }

    /** Отменить неоплаченный счёт. Поступившую оплату отменить нельзя. */
    public function cancel(Payment $payment, string $reason): Payment
    {
        return $this->lockedPending($payment, function (Payment $payment) use ($reason) {
            if (trim($reason) === '') {
                throw new DomainRuleViolation('Укажите причину отмены счёта.');
            }
            $before = $this->snapshot($payment);
            $payment->update([
                'status' => PaymentStatus::Cancelled,
                'cancelled_at' => now(),
                'comment' => trim(($payment->comment ? $payment->comment."\n" : '').'Отмена: '.$reason),
            ]);
            $this->history->record($payment->certificate, HistoryEvent::PaymentCancelled, $reason, $before, $this->snapshot($payment));

            return $payment;
        });
    }

    private function lockedPending(Payment $payment, callable $callback): Payment
    {
        return DB::transaction(function () use ($payment, $callback) {
            Certificate::query()->lockForUpdate()->findOrFail($payment->certificate_id);
            $fresh = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($fresh->status !== PaymentStatus::Pending) {
                throw new DomainRuleViolation("Счёт в статусе «{$fresh->status->getLabel()}» изменить нельзя.");
            }
            $result = $callback($fresh);
            $payment->setRawAttributes($fresh->getAttributes(), true);

            return $result;
        });
    }

    private function snapshot(Payment $payment): array
    {
        return [
            'payment_id' => $payment->id,
            'method' => $payment->method->value,
            'status' => $payment->status->value,
            'amount' => (string) $payment->amount,
            'invoice_number' => $payment->invoice_number,
            'paid_at' => $payment->paid_at?->toDateTimeString(),
        ];
    }
}
