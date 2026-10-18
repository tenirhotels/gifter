<?php

namespace App\Services;

use App\Enums\BatchStatus;
use App\Enums\CertificateKind;
use App\Enums\ComplimentaryPurpose;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\CertificateBatch;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Service;
use App\Support\Money;
use App\Support\ValidityChoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Покупка: несколько одинаковых сертификатов одного пакета в одной продаже.
 *
 * Платная: черновики → один счёт на всю сумму (по строке на сертификат, общий номер)
 * → оплата → выпуск. Бесплатная: черновики → согласование директором → выпуск.
 * Черновики покупки оформляются только всей покупкой; после выпуска каждый сертификат
 * погашается, бронируется и возвращается отдельно. Получатели — по одному, в любой момент.
 */
class CertificateBatchManager
{
    public const MAX_QUANTITY = 200;

    public function __construct(
        private readonly CertificateManager $certificates,
        private readonly PaymentManager $payments,
    ) {}

    /**
     * @param  array{customer_id?:int|null, complimentary_reason?:string|null, complimentary_purpose?:ComplimentaryPurpose|string|null, validity?:array|null, extras?:list<array{service_id:int|string, quantity:int|string}>, sold_at?:mixed, notes?:string|null}  $data
     */
    public function create(Package $package, int $quantity, array $data = []): CertificateBatch
    {
        if ($quantity < 2 || $quantity > self::MAX_QUANTITY) {
            throw new DomainRuleViolation('Вместе оформляется от 2 до '.self::MAX_QUANTITY.' сертификатов (один — обычным порядком).');
        }
        $complimentary = $package->is_complimentary;
        $reason = trim((string) ($data['complimentary_reason'] ?? ''));
        $purpose = null;
        $validity = ValidityChoice::fromArray($data['validity'] ?? null);
        if ($complimentary) {
            if ($reason === '') {
                throw new DomainRuleViolation('Укажите основание: кому и зачем выпускаются сертификаты.');
            }
            $purpose = $data['complimentary_purpose'] ?? null;
            $purpose = $purpose instanceof ComplimentaryPurpose ? $purpose : (ComplimentaryPurpose::tryFrom((string) $purpose) ?? $package->complimentary_purpose);
            if (! $purpose) {
                throw new DomainRuleViolation('Укажите цель бесплатных сертификатов.');
            }
        } elseif (empty($data['customer_id'])) {
            throw new DomainRuleViolation('Для продажи укажите покупателя.');
        }
        $soldAt = Carbon::parse($data['sold_at'] ?? today())->startOfDay();
        [$validFrom, $validUntil] = $validity->resolve($package, $soldAt);

        return DB::transaction(function () use ($package, $quantity, $data, $complimentary, $reason, $purpose, $validity, $validFrom, $validUntil, $soldAt) {
            $batch = CertificateBatch::create([
                'package_id' => $package->id,
                'kind' => $complimentary ? CertificateKind::Complimentary : CertificateKind::Sale,
                'customer_id' => $data['customer_id'] ?? null,
                'quantity' => $quantity,
                'purpose' => $purpose,
                'reason' => $complimentary ? $reason : null,
                // Срок покупки хранится только если задан вручную; иначе — по пакету.
                'valid_from' => $validity->isCustom() ? $validFrom : null,
                'valid_until' => $validity->isCustom() ? $validUntil : null,
                'status' => BatchStatus::Draft,
                'created_by' => auth()->id(),
            ]);
            foreach (range(1, $quantity) as $_) {
                $certificate = $this->certificates->createDraft([
                    'package_id' => $package->id,
                    'customer_id' => $data['customer_id'] ?? null,
                    'complimentary_reason' => $complimentary ? $reason : null,
                    'complimentary_purpose' => $purpose,
                    'batch_id' => $batch->id,
                    'validity' => $validity,
                    'sold_at' => $soldAt,
                    'notes' => $data['notes'] ?? null,
                ]);
                foreach ($data['extras'] ?? [] as $extra) {
                    $this->certificates->addItem($certificate, Service::findOrFail($extra['service_id']), (int) $extra['quantity'], viaBatch: true);
                }
            }

            return $batch;
        });
    }

    /** Один счёт на всю покупку: по строке на сертификат с общим номером. */
    public function invoice(CertificateBatch $batch, PaymentMethod $method, ?string $invoiceNumber = null, ?string $comment = null): CertificateBatch
    {
        return $this->transition($batch, BatchStatus::Draft, function (CertificateBatch $batch) use ($method, $invoiceNumber, $comment) {
            if ($batch->isComplimentary()) {
                throw new DomainRuleViolation('Бесплатные сертификаты не оплачиваются.');
            }
            if ($batch->payments()->where('status', '!=', PaymentStatus::Cancelled)->exists()) {
                throw new DomainRuleViolation('Счёт по покупке уже выставлен.');
            }
            foreach ($batch->certificates as $certificate) {
                $this->payments->invoice($certificate, $method, invoiceNumber: $invoiceNumber, comment: $comment, batchId: $batch->id);
            }
        });
    }

    public function markPaid(CertificateBatch $batch, ?Carbon $paidAt = null): CertificateBatch
    {
        $this->payments->markPaid($this->openInvoiceLine($batch), $paidAt);

        return $batch->refresh();
    }

    public function cancelInvoice(CertificateBatch $batch, string $reason): CertificateBatch
    {
        $this->payments->cancel($this->openInvoiceLine($batch), $reason);

        return $batch->refresh();
    }

    /** Сумма счёта покупки (без отменённых строк). */
    public function invoiceTotal(CertificateBatch $batch): string
    {
        return Money::fromMinor(Money::toMinor($batch->payments()->where('status', '!=', PaymentStatus::Cancelled)->sum('amount')));
    }

    public function approve(CertificateBatch $batch, ?string $comment = null): CertificateBatch
    {
        return $this->transition($batch, BatchStatus::Draft, function (CertificateBatch $batch) use ($comment) {
            if (! $batch->isComplimentary()) {
                throw new DomainRuleViolation('Согласование нужно только бесплатной выдаче.');
            }
            foreach ($batch->certificates as $certificate) {
                $this->certificates->approveComplimentary($certificate, trim("{$batch->shortLabel()}. ".($comment ?? '')), viaBatch: true);
            }
            $batch->update(['status' => BatchStatus::Approved, 'approved_by' => auth()->id(), 'approved_at' => now()]);
        });
    }

    /** Бесплатная — после согласования; платная — когда оплачен каждый сертификат. */
    public function issue(CertificateBatch $batch): CertificateBatch
    {
        $expected = $batch->isComplimentary() ? BatchStatus::Approved : BatchStatus::Draft;

        return $this->transition($batch, $expected, function (CertificateBatch $batch) {
            foreach ($batch->certificates as $certificate) {
                $this->certificates->issue($certificate, viaBatch: true);
            }
            $batch->update(['status' => BatchStatus::Issued, 'issued_at' => now()]);
        });
    }

    /** Аннулировать покупку до выпуска (без поступивших денег). Выпущенные — поштучно. */
    public function cancel(CertificateBatch $batch, string $reason): CertificateBatch
    {
        if (trim($reason) === '') {
            throw new DomainRuleViolation('Укажите причину аннулирования.');
        }

        return DB::transaction(function () use ($batch, $reason) {
            $batch = CertificateBatch::query()->lockForUpdate()->findOrFail($batch->id);
            if (! in_array($batch->status, [BatchStatus::Draft, BatchStatus::Approved], true)) {
                throw new DomainRuleViolation("{$batch->shortLabel()} в статусе «{$batch->statusLabel()}» аннулировать нельзя.");
            }
            foreach ($batch->certificates as $certificate) {
                $this->certificates->cancel($certificate, "{$batch->shortLabel()}: {$reason}", viaBatch: true);
            }
            $batch->update(['status' => BatchStatus::Cancelled]);

            return $batch;
        });
    }

    private function openInvoiceLine(CertificateBatch $batch): Payment
    {
        $line = $batch->payments()->where('status', PaymentStatus::Pending)->orderBy('id')->first();
        if (! $line) {
            throw new DomainRuleViolation('По покупке нет выставленного неоплаченного счёта.');
        }

        return $line;
    }

    private function transition(CertificateBatch $batch, BatchStatus $expected, callable $callback): CertificateBatch
    {
        return DB::transaction(function () use ($batch, $expected, $callback) {
            $batch = CertificateBatch::query()->lockForUpdate()->findOrFail($batch->id);
            if ($batch->status !== $expected) {
                throw new DomainRuleViolation("Покупка в статусе «{$batch->statusLabel()}» — действие недоступно.");
            }
            $callback($batch);

            return $batch;
        });
    }
}
