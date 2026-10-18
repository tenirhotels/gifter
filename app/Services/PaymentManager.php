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

    /**
     * Выставить счёт. Без суммы — на весь неоплаченный остаток.
     * Для сертификата из группы счёт выставляет CertificateBatchManager (один на всю группу).
     */
    public function invoice(Certificate $certificate, PaymentMethod $method, ?string $amount = null, ?string $invoiceNumber = null, ?string $comment = null, ?int $batchId = null): Payment
    {
        return DB::transaction(function () use ($certificate, $method, $amount, $invoiceNumber, $comment, $batchId) {
            $certificate = Certificate::query()->lockForUpdate()->findOrFail($certificate->id);
            if ($certificate->batch_id && $batchId !== $certificate->batch_id) {
                throw new DomainRuleViolation('Сертификат входит в группу — счёт выставляется на всю группу на её странице.');
            }
            if ($certificate->status !== CertificateStatus::Draft) {
                throw new DomainRuleViolation('Счёт выставляется только по черновику, до выпуска сертификата.');
            }
            if ($certificate->isComplimentary()) {
                throw new DomainRuleViolation('Бесплатный сертификат не оплачивается.');
            }
            if (Money::toMinor($certificate->total_amount) === 0) {
                throw new DomainRuleViolation('В сертификате нет услуг — счёт выставлять не на что.');
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
                'batch_id' => $batchId,
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

    /**
     * Отметить поступление. Перечисление на р/с отмечают Финансы по выписке —
     * такая отметка сразу считается сверкой.
     */
    public function markPaid(Payment $payment, ?Carbon $paidAt = null): Payment
    {
        // Счёт группы — один для заказчика: отмечаются все его строки.
        if ($payment->batch_id) {
            return $this->forBatchSiblings($payment, PaymentStatus::Pending, fn (Payment $line) => $this->markPaidLine($line, $paidAt));
        }

        return $this->markPaidLine($payment, $paidAt);
    }

    private function markPaidLine(Payment $payment, ?Carbon $paidAt): Payment
    {
        return $this->lockedPending($payment, function (Payment $payment) use ($paidAt) {
            $before = $this->snapshot($payment);
            $reconciled = $payment->method->requiresFinanceConfirmation();
            $payment->update([
                'status' => PaymentStatus::Paid,
                'paid_at' => $paidAt ?? now(),
                'reconciled_at' => $reconciled ? now() : null,
                'reconciled_by' => $reconciled ? auth()->id() : null,
            ]);
            $this->history->record($payment->certificate, HistoryEvent::PaymentPaid,
                $payment->method->getLabel().', '.Money::format($payment->amount), $before, $this->snapshot($payment));

            return $payment;
        });
    }

    /** Отменить неоплаченный счёт. Поступившую оплату отменить нельзя. */
    public function cancel(Payment $payment, string $reason): Payment
    {
        if ($payment->batch_id) {
            return $this->forBatchSiblings($payment, PaymentStatus::Pending, fn (Payment $line) => $this->cancelLine($line, $reason));
        }

        return $this->cancelLine($payment, $reason);
    }

    private function cancelLine(Payment $payment, string $reason): Payment
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

    /** Сверка Финансами: поступление подтверждено выпиской банка. */
    public function reconcile(Payment $payment, ?string $comment = null): Payment
    {
        if ($payment->batch_id) {
            return $this->forBatchSiblings($payment, PaymentStatus::Paid, fn (Payment $line) => $line->isReconciled() ? $line : $this->reconcileLine($line, $comment), onlyUnreconciled: true);
        }

        return $this->reconcileLine($payment, $comment);
    }

    private function reconcileLine(Payment $payment, ?string $comment): Payment
    {
        return DB::transaction(function () use ($payment, $comment) {
            $fresh = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($fresh->status !== PaymentStatus::Paid) {
                throw new DomainRuleViolation('Сверить можно только поступившую оплату.');
            }
            if ($fresh->isReconciled()) {
                throw new DomainRuleViolation('Оплата уже сверена.');
            }
            $fresh->update([
                'reconciled_at' => now(),
                'reconciled_by' => auth()->id(),
                'comment' => $comment ? trim(($fresh->comment ? $fresh->comment."\n" : '').'Сверка: '.$comment) : $fresh->comment,
            ]);
            $this->history->record($fresh->certificate, HistoryEvent::PaymentReconciled,
                $fresh->method->getLabel().', '.Money::format($fresh->amount).($comment ? '. '.$comment : ''));
            $payment->setRawAttributes($fresh->getAttributes(), true);

            return $fresh;
        });
    }

    /**
     * Действие над всеми строками счёта группы в нужном статусе (одна транзакция).
     * Возвращает переданную строку в её новом состоянии.
     */
    private function forBatchSiblings(Payment $payment, PaymentStatus $status, callable $callback, bool $onlyUnreconciled = false): Payment
    {
        return DB::transaction(function () use ($payment, $status, $callback, $onlyUnreconciled) {
            $lines = Payment::query()->where('batch_id', $payment->batch_id)->where('invoice_number', $payment->invoice_number)
                ->where('status', $status)->when($onlyUnreconciled, fn ($q) => $q->whereNull('reconciled_at'))->orderBy('id')->get();
            if ($lines->isEmpty()) {
                throw new DomainRuleViolation("Счёт в статусе «{$payment->status->getLabel()}» изменить нельзя.");
            }
            foreach ($lines as $line) {
                $callback($line);
            }
            $payment->refresh();

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
            'reconciled' => $payment->reconciled_at !== null,
        ];
    }
}
