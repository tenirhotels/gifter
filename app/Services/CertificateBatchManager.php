<?php

namespace App\Services;

use App\Enums\BatchStatus;
use App\Enums\ComplimentaryPurpose;
use App\Exceptions\DomainRuleViolation;
use App\Models\CertificateBatch;
use App\Models\Package;
use App\Support\ValidityChoice;
use Illuminate\Support\Facades\DB;

/**
 * Выпуск бесплатных сертификатов блоком: менеджер создаёт N черновиков
 * бесплатного пакета → директор согласует блок → менеджер выпускает блок.
 * Каждый сертификат — обычный: свой номер, история, получатель (позже).
 */
class CertificateBatchManager
{
    public const MAX_QUANTITY = 200;

    public function __construct(private readonly CertificateManager $certificates) {}

    /** @param  array|null  $validity  срок для всех сертификатов блока (см. ValidityChoice); null — по пакету */
    public function create(Package $package, int $quantity, string $reason, ComplimentaryPurpose|string|null $purpose = null, ?int $customerId = null, ?array $validity = null): CertificateBatch
    {
        if (! $package->is_complimentary) {
            throw new DomainRuleViolation('Блоком выпускаются только сертификаты бесплатного пакета.');
        }
        if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
            throw new DomainRuleViolation('Количество в блоке — от 1 до '.self::MAX_QUANTITY.'.');
        }
        if (trim($reason) === '') {
            throw new DomainRuleViolation('Укажите основание: кому и зачем выпускается блок.');
        }
        $purpose = $purpose instanceof ComplimentaryPurpose ? $purpose : (ComplimentaryPurpose::tryFrom((string) $purpose) ?? $package->complimentary_purpose);
        if (! $purpose) {
            throw new DomainRuleViolation('Укажите цель блока.');
        }

        $validity = ValidityChoice::fromArray($validity);
        [$validFrom, $validUntil] = $validity->resolve($package, today());

        return DB::transaction(function () use ($package, $quantity, $reason, $purpose, $customerId, $validity, $validFrom, $validUntil) {
            $batch = CertificateBatch::create([
                'package_id' => $package->id,
                'customer_id' => $customerId,
                'quantity' => $quantity,
                'purpose' => $purpose,
                'reason' => $reason,
                // Срок блока хранится только если задан вручную; иначе — по пакету.
                'valid_from' => $validity->isCustom() ? $validFrom : null,
                'valid_until' => $validity->isCustom() ? $validUntil : null,
                'status' => BatchStatus::Draft,
                'created_by' => auth()->id(),
            ]);
            foreach (range(1, $quantity) as $_) {
                $this->certificates->createDraft([
                    'package_id' => $package->id,
                    'customer_id' => $customerId,
                    'complimentary_reason' => $reason,
                    'complimentary_purpose' => $purpose,
                    'batch_id' => $batch->id,
                    'validity' => $validity,
                ]);
            }

            return $batch;
        });
    }

    public function approve(CertificateBatch $batch, ?string $comment = null): CertificateBatch
    {
        return $this->transition($batch, BatchStatus::Draft, function (CertificateBatch $batch) use ($comment) {
            foreach ($batch->certificates as $certificate) {
                $this->certificates->approveComplimentary($certificate, trim("Блок №{$batch->id}. ".($comment ?? '')), viaBatch: true);
            }
            $batch->update(['status' => BatchStatus::Approved, 'approved_by' => auth()->id(), 'approved_at' => now()]);
        });
    }

    public function issue(CertificateBatch $batch): CertificateBatch
    {
        return $this->transition($batch, BatchStatus::Approved, function (CertificateBatch $batch) {
            foreach ($batch->certificates as $certificate) {
                $this->certificates->issue($certificate, viaBatch: true);
            }
            $batch->update(['status' => BatchStatus::Issued, 'issued_at' => now()]);
        });
    }

    /** Аннулировать блок до выпуска. Выпущенные сертификаты аннулируются по одному. */
    public function cancel(CertificateBatch $batch, string $reason): CertificateBatch
    {
        if (trim($reason) === '') {
            throw new DomainRuleViolation('Укажите причину аннулирования блока.');
        }

        return DB::transaction(function () use ($batch, $reason) {
            $batch = CertificateBatch::query()->lockForUpdate()->findOrFail($batch->id);
            if (! in_array($batch->status, [BatchStatus::Draft, BatchStatus::Approved], true)) {
                throw new DomainRuleViolation("Блок в статусе «{$batch->status->getLabel()}» аннулировать нельзя.");
            }
            foreach ($batch->certificates as $certificate) {
                $this->certificates->cancel($certificate, "Блок №{$batch->id}: {$reason}", viaBatch: true);
            }
            $batch->update(['status' => BatchStatus::Cancelled]);

            return $batch;
        });
    }

    private function transition(CertificateBatch $batch, BatchStatus $expected, callable $callback): CertificateBatch
    {
        return DB::transaction(function () use ($batch, $expected, $callback) {
            $batch = CertificateBatch::query()->lockForUpdate()->findOrFail($batch->id);
            if ($batch->status !== $expected) {
                throw new DomainRuleViolation("Блок в статусе «{$batch->status->getLabel()}» — действие недоступно.");
            }
            $callback($batch);

            return $batch;
        });
    }
}
