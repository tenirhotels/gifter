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
 * Группа: несколько одинаковых сертификатов одного пакета в одной продаже.
 *
 * Платная: черновики → один счёт на всю сумму (по строке на сертификат, общий номер)
 * → оплата → выпуск. Бесплатная: черновики → согласование директором → выпуск.
 * Черновики группы оформляются только всей группой; после выпуска каждый сертификат
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
     * @param  array{customer_id?:int|null, complimentary_reason?:string|null, complimentary_purpose?:ComplimentaryPurpose|string|null, validity?:array|null, extras?:list<array{service_id:int|string, quantity:int|string, paid?:bool}>, sold_at?:mixed, notes?:string|null}  $data
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
            if (empty($data['customer_id']) && collect($data['extras'] ?? [])->contains(fn (array $extra) => ! empty($extra['paid']))) {
                throw new DomainRuleViolation('В группе есть платные услуги — укажите заказчика, который их оплатит.');
            }
        } elseif (empty($data['customer_id'])) {
            throw new DomainRuleViolation('Для продажи укажите заказчика.');
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
                // Срок группы хранится только если задан вручную; иначе — по пакету.
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
                    'sold_by' => $data['sold_by'] ?? null,
                    'seller_reason' => $data['seller_reason'] ?? null,
                    'sold_at_reason' => $data['sold_at_reason'] ?? null,
                    'validity' => $validity,
                    'sold_at' => $soldAt,
                    'notes' => $data['notes'] ?? null,
                ]);
                foreach ($data['extras'] ?? [] as $extra) {
                    $this->certificates->addItem($certificate, Service::findOrFail($extra['service_id']), (int) $extra['quantity'], viaBatch: true, paid: ! empty($extra['paid']));
                }
            }

            return $batch;
        });
    }

    /**
     * Один счёт на всю группу: по строке на сертификат с общим номером. Бесплатной группе
     * счёт выставляется только на платные доп. услуги — до или после согласования директором.
     */
    public function invoice(CertificateBatch $batch, PaymentMethod $method, ?string $invoiceNumber = null, ?string $comment = null): CertificateBatch
    {
        return $this->transition($batch, [BatchStatus::Draft, BatchStatus::Approved], function (CertificateBatch $batch) use ($method, $invoiceNumber, $comment) {
            if ($batch->isComplimentary() && $batch->certificates()->sum('payable_amount') == 0) {
                throw new DomainRuleViolation('В бесплатной группе нет платных услуг — счёт выставлять не на что.');
            }
            if (! $batch->isComplimentary() && $batch->status !== BatchStatus::Draft) {
                throw new DomainRuleViolation("Группа в статусе «{$batch->statusLabel()}» — действие недоступно.");
            }
            if ($batch->payments()->where('status', '!=', PaymentStatus::Cancelled)->exists()) {
                throw new DomainRuleViolation('Счёт по группе уже выставлен.');
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

    /** Сумма счёта группы (без отменённых строк). */
    public function invoiceTotal(CertificateBatch $batch): string
    {
        return Money::fromMinor(Money::toMinor($batch->payments()->where('status', '!=', PaymentStatus::Cancelled)->sum('amount')));
    }

    public function approve(CertificateBatch $batch, ?string $comment = null): CertificateBatch
    {
        return $this->transition($batch, BatchStatus::Draft, function (CertificateBatch $batch) use ($comment) {
            if (! $batch->isComplimentary()) {
                throw new DomainRuleViolation('Согласование нужно только бесплатной группе.');
            }
            foreach ($batch->certificates as $certificate) {
                $this->certificates->approveComplimentary($certificate, trim("{$batch->shortLabel()}. ".($comment ?? '')), viaBatch: true);
            }
            $batch->update(['status' => BatchStatus::Approved, 'approved_by' => auth()->id(), 'approved_at' => now()]);
        });
    }

    /** Бесплатная — после согласования (и оплаты платных услуг, если они есть); платная — когда оплачен каждый сертификат. */
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

    /** Аннулировать группу до выпуска (без поступивших денег). Выпущенные — поштучно. */
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
            throw new DomainRuleViolation('По группе нет выставленного неоплаченного счёта.');
        }

        return $line;
    }

    /** @param  BatchStatus|list<BatchStatus>  $expected */
    private function transition(CertificateBatch $batch, BatchStatus|array $expected, callable $callback): CertificateBatch
    {
        return DB::transaction(function () use ($batch, $expected, $callback) {
            $batch = CertificateBatch::query()->lockForUpdate()->findOrFail($batch->id);
            if (! in_array($batch->status, is_array($expected) ? $expected : [$expected], true)) {
                throw new DomainRuleViolation("Группа в статусе «{$batch->statusLabel()}» — действие недоступно.");
            }
            $callback($batch);

            return $batch;
        });
    }
}
