<?php

namespace App\Services;

use App\Enums\CertificateStatus;
use App\Enums\HistoryEvent;
use App\Enums\PaymentStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Certificate;
use App\Models\CertificateItem;
use App\Models\Package;
use App\Models\Service;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Жизненный цикл сертификата. Все изменения статуса, состава и сроков идут
 * отсюда и записываются в историю.
 */
class CertificateManager
{
    public function __construct(
        private readonly CertificateNumberGenerator $numbers,
        private readonly ServicePricing $pricing,
        private readonly CertificateHistoryRecorder $history,
    ) {}

    /**
     * @param  array{customer_id:int, recipient_id:int, sold_at?:string|Carbon|null, valid_from?:string|Carbon|null, valid_until?:string|Carbon|null, notes?:string|null, package_id?:int|null}  $data
     */
    public function createDraft(array $data): Certificate
    {
        return DB::transaction(function () use ($data) {
            $soldAt = Carbon::parse($data['sold_at'] ?? today())->startOfDay();
            $validFrom = Carbon::parse($data['valid_from'] ?? $soldAt)->startOfDay();
            $validUntil = Carbon::parse($data['valid_until'] ?? $validFrom->copy()->addDays(config('gifter.validity_days')))->startOfDay();
            $this->assertValidity($validFrom, $validUntil);

            $certificate = Certificate::create([
                'number' => $this->numbers->next($soldAt),
                'customer_id' => $data['customer_id'],
                'recipient_id' => $data['recipient_id'],
                'status' => CertificateStatus::Draft,
                'sold_at' => $soldAt,
                'valid_from' => $validFrom,
                'valid_until' => $validUntil,
                'currency' => config('gifter.currency'),
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]);
            $this->history->record($certificate, HistoryEvent::Created, after: $this->snapshot($certificate));

            if (! empty($data['package_id'])) {
                $this->applyPackage($certificate, Package::findOrFail($data['package_id']));
            }

            return $certificate->refresh();
        });
    }

    /**
     * Правка реквизитов черновика. Смена даты продажи пересчитывает цены.
     *
     * @param  array{customer_id?:int, recipient_id?:int, sold_at?:string|Carbon, valid_from?:string|Carbon, valid_until?:string|Carbon, notes?:string|null}  $data
     */
    public function updateDraft(Certificate $certificate, array $data): Certificate
    {
        return $this->locked($certificate, function (Certificate $certificate) use ($data) {
            $this->assertDraft($certificate);
            $data = array_intersect_key($data, array_flip(['customer_id', 'recipient_id', 'sold_at', 'valid_from', 'valid_until', 'notes']));
            $soldAtChanged = isset($data['sold_at']) && ! Carbon::parse($data['sold_at'])->isSameDay($certificate->sold_at);
            if ($soldAtChanged) {
                $this->assertNoActivePayments($certificate);
            }

            $before = $this->snapshot($certificate);
            $certificate->fill($data + ['updated_by' => auth()->id()]);
            $this->assertValidity($certificate->valid_from, $certificate->valid_until);
            if (! $certificate->isDirty()) {
                return $certificate;
            }
            $certificate->save();
            $this->history->record($certificate, HistoryEvent::Updated, null, ...$this->diff($before, $this->snapshot($certificate)));

            if ($soldAtChanged) {
                $this->reprice($certificate);
            }

            return $certificate;
        });
    }

    public function addItem(Certificate $certificate, Service $service, int $quantity): CertificateItem
    {
        return $this->locked($certificate, function (Certificate $certificate) use ($service, $quantity) {
            $this->assertCompositionEditable($certificate);
            $item = $this->createItem($certificate, $service, $quantity);
            $this->recalculateTotal($certificate);
            $this->history->record($certificate, HistoryEvent::ItemAdded,
                "{$item->service_name_snapshot} × {$item->quantity}", after: $this->itemSnapshot($item));

            return $item;
        });
    }

    public function removeItem(CertificateItem $item): void
    {
        $this->locked($item->certificate, function (Certificate $certificate) use ($item) {
            $this->assertCompositionEditable($certificate);
            $snapshot = $this->itemSnapshot($item);
            $item->delete();
            $this->recalculateTotal($certificate);
            $this->history->record($certificate, HistoryEvent::ItemRemoved,
                "{$snapshot['service']} × {$snapshot['quantity']}", before: $snapshot);
        });
    }

    /** Добавляет в черновик услуги пакета; цены — на дату продажи сертификата. */
    public function applyPackage(Certificate $certificate, Package $package): void
    {
        $this->locked($certificate, function (Certificate $certificate) use ($package) {
            $this->assertCompositionEditable($certificate);
            $package->loadMissing('items.service');
            if ($package->items->isEmpty()) {
                throw new DomainRuleViolation("В пакете «{$package->name}» нет услуг.");
            }
            foreach ($package->items as $packageItem) {
                $this->createItem($certificate, $packageItem->service, $packageItem->quantity);
            }
            $certificate->update(['package_id' => $package->id, 'updated_by' => auth()->id()]);
            $this->recalculateTotal($certificate);
            $this->history->record($certificate, HistoryEvent::PackageApplied, $package->name,
                after: ['package_id' => $package->id, 'items' => $certificate->items()->get()->map($this->itemSnapshot(...))->all()]);
        });
    }

    /** Выпуск: черновик с составом, полностью оплаченный, становится активным. */
    public function issue(Certificate $certificate): Certificate
    {
        return $this->locked($certificate, function (Certificate $certificate) {
            $this->assertDraft($certificate);
            if (! $certificate->items()->exists()) {
                throw new DomainRuleViolation('Нельзя выпустить сертификат без услуг.');
            }
            if (! $certificate->isFullyPaid()) {
                throw new DomainRuleViolation('Сертификат оплачен не полностью: получено '
                    .Money::format($certificate->paidAmount()).' из '.Money::format($certificate->total_amount).'.');
            }
            if ($certificate->valid_until->lt(today())) {
                throw new DomainRuleViolation('Срок действия уже истёк — исправьте даты перед выпуском.');
            }

            $certificate->update(['status' => CertificateStatus::Active, 'issued_at' => now(), 'updated_by' => auth()->id()]);
            $this->history->record($certificate, HistoryEvent::Issued, null,
                ['status' => CertificateStatus::Draft->value], ['status' => CertificateStatus::Active->value]);

            return $certificate;
        });
    }

    /**
     * Смена статуса выпущенного сертификата (бронь, заезд, погашение).
     * Погашение (USED) — всегда всего сертификата целиком.
     */
    public function transition(Certificate $certificate, CertificateStatus $target, ?string $reason = null): Certificate
    {
        return $this->locked($certificate, function (Certificate $certificate) use ($target, $reason) {
            $from = $certificate->status;
            if ($from === CertificateStatus::Draft) {
                throw new DomainRuleViolation('Черновик переводится в работу только выпуском.');
            }
            if ($target === CertificateStatus::Cancelled) {
                throw new DomainRuleViolation('Для аннулирования используйте отдельное действие с причиной.');
            }
            if (! $from->canTransitionTo($target)) {
                throw new DomainRuleViolation("Переход «{$from->getLabel()}» → «{$target->getLabel()}» недопустим.");
            }

            $certificate->update(['status' => $target, 'updated_by' => auth()->id()]);
            $this->history->record($certificate, $target === CertificateStatus::Expired ? HistoryEvent::Expired : HistoryEvent::StatusChanged,
                $reason, ['status' => $from->value], ['status' => $target->value]);

            return $certificate;
        });
    }

    public function cancel(Certificate $certificate, string $reason): Certificate
    {
        return $this->locked($certificate, function (Certificate $certificate) use ($reason) {
            if (trim($reason) === '') {
                throw new DomainRuleViolation('Укажите причину аннулирования.');
            }
            $from = $certificate->status;
            if (! $from->canTransitionTo(CertificateStatus::Cancelled)) {
                throw new DomainRuleViolation("Сертификат в статусе «{$from->getLabel()}» аннулировать нельзя.");
            }
            if ($certificate->payments()->where('status', PaymentStatus::Pending)->exists()) {
                throw new DomainRuleViolation('Сначала отмените выставленные неоплаченные счета.');
            }

            $certificate->update([
                'status' => CertificateStatus::Cancelled,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
                'updated_by' => auth()->id(),
            ]);
            $this->history->record($certificate, HistoryEvent::Cancelled, $reason,
                ['status' => $from->value], ['status' => CertificateStatus::Cancelled->value]);

            return $certificate;
        });
    }

    /** Изменение срока действия выпущенного сертификата — только с причиной. */
    public function changeValidity(Certificate $certificate, Carbon $validFrom, Carbon $validUntil, string $reason): Certificate
    {
        return $this->locked($certificate, function (Certificate $certificate) use ($validFrom, $validUntil, $reason) {
            if (trim($reason) === '') {
                throw new DomainRuleViolation('Укажите причину изменения срока.');
            }
            if (! in_array($certificate->status, [CertificateStatus::Draft, CertificateStatus::Active, CertificateStatus::Reserved], true)) {
                throw new DomainRuleViolation("Срок сертификата в статусе «{$certificate->status->getLabel()}» изменить нельзя.");
            }
            $this->assertValidity($validFrom, $validUntil);

            $before = ['valid_from' => $certificate->valid_from->toDateString(), 'valid_until' => $certificate->valid_until->toDateString()];
            $certificate->update(['valid_from' => $validFrom, 'valid_until' => $validUntil, 'updated_by' => auth()->id()]);
            $this->history->record($certificate, HistoryEvent::ValidityChanged, $reason, $before,
                ['valid_from' => $validFrom->toDateString(), 'valid_until' => $validUntil->toDateString()]);

            return $certificate;
        });
    }

    /** Переводит активные сертификаты с прошедшим сроком в EXPIRED. Возвращает количество. */
    public function expireOverdue(?Carbon $today = null): int
    {
        $today = ($today ?? today())->toDateString();
        $count = 0;
        Certificate::query()->where('status', CertificateStatus::Active)->whereDate('valid_until', '<', $today)
            ->each(function (Certificate $certificate) use (&$count) {
                $this->transition($certificate, CertificateStatus::Expired, 'Автоматически: срок действия истёк');
                $count++;
            });

        return $count;
    }

    private function reprice(Certificate $certificate): void
    {
        $before = $certificate->items()->get()->map($this->itemSnapshot(...))->all();
        foreach ($certificate->items()->with('service')->get() as $item) {
            $price = $this->pricing->priceOn($item->service, $certificate->sold_at);
            $item->update([
                'price_version_id' => $price->id,
                'unit_price' => $price->price,
                'line_total' => Money::multiply($price->price, $item->quantity),
            ]);
        }
        $this->recalculateTotal($certificate);
        if ($before !== []) {
            $this->history->record($certificate, HistoryEvent::Repriced, 'Цены на дату продажи '.$certificate->sold_at->format('d.m.Y'),
                ['items' => $before], ['items' => $certificate->items()->get()->map($this->itemSnapshot(...))->all()]);
        }
    }

    private function createItem(Certificate $certificate, Service $service, int $quantity): CertificateItem
    {
        if ($quantity < 1) {
            throw new DomainRuleViolation('Количество должно быть не меньше 1.');
        }
        if (! $service->is_active) {
            throw new DomainRuleViolation("Услуга «{$service->name}» не активна.");
        }
        $price = $this->pricing->priceOn($service, $certificate->sold_at);

        return $certificate->items()->create([
            'service_id' => $service->id,
            'price_version_id' => $price->id,
            'service_name_snapshot' => $service->name,
            'unit_snapshot' => $service->unit,
            'quantity' => $quantity,
            'unit_price' => $price->price,
            'line_total' => Money::multiply($price->price, $quantity),
            'position' => (int) $certificate->items()->max('position') + 1,
        ]);
    }

    private function recalculateTotal(Certificate $certificate): void
    {
        $total = $certificate->items()->get()->sum(fn (CertificateItem $item) => Money::toMinor($item->line_total));
        $certificate->update(['total_amount' => Money::fromMinor($total), 'updated_by' => auth()->id()]);
    }

    /**
     * @template T
     *
     * @param  callable(Certificate): T  $callback
     * @return T
     */
    private function locked(Certificate $certificate, callable $callback): mixed
    {
        return DB::transaction(function () use ($certificate, $callback) {
            $fresh = Certificate::query()->lockForUpdate()->findOrFail($certificate->id);
            $result = $callback($fresh);
            $certificate->setRawAttributes($fresh->getAttributes(), true);

            return $result;
        });
    }

    private function assertDraft(Certificate $certificate): void
    {
        if (! $certificate->isDraft()) {
            throw new DomainRuleViolation('Действие доступно только для черновика.');
        }
    }

    private function assertCompositionEditable(Certificate $certificate): void
    {
        $this->assertDraft($certificate);
        $this->assertNoActivePayments($certificate);
    }

    private function assertNoActivePayments(Certificate $certificate): void
    {
        if ($certificate->payments()->where('status', '!=', PaymentStatus::Cancelled)->exists()) {
            throw new DomainRuleViolation('По сертификату уже выставлен счёт. Чтобы изменить состав или дату продажи, отмените неоплаченный счёт.');
        }
    }

    private function assertValidity(Carbon $from, Carbon $until): void
    {
        if ($until->lt($from)) {
            throw new DomainRuleViolation('Дата окончания действия раньше даты начала.');
        }
    }

    private function snapshot(Certificate $certificate): array
    {
        return [
            'customer_id' => $certificate->customer_id,
            'recipient_id' => $certificate->recipient_id,
            'sold_at' => $certificate->sold_at?->toDateString(),
            'valid_from' => $certificate->valid_from?->toDateString(),
            'valid_until' => $certificate->valid_until?->toDateString(),
            'notes' => $certificate->notes,
        ];
    }

    /** @return array{0: array, 1: array} */
    private function diff(array $before, array $after): array
    {
        $changed = array_keys(array_diff_assoc(array_map('strval', $after), array_map('strval', $before)));

        return [array_intersect_key($before, array_flip($changed)), array_intersect_key($after, array_flip($changed))];
    }

    private function itemSnapshot(CertificateItem $item): array
    {
        return [
            'service' => $item->service_name_snapshot,
            'quantity' => $item->quantity,
            'unit_price' => (string) $item->unit_price,
            'line_total' => (string) $item->line_total,
            'price_version_id' => $item->price_version_id,
        ];
    }
}
