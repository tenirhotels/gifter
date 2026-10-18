<?php

namespace App\Services;

use App\Enums\CertificateKind;
use App\Enums\CertificateStatus;
use App\Enums\ComplimentaryPurpose;
use App\Enums\HistoryEvent;
use App\Enums\PaymentStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Certificate;
use App\Models\CertificateItem;
use App\Models\Package;
use App\Models\Service;
use App\Support\Money;
use App\Support\ValidityChoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
        private readonly FulfillmentManager $fulfillments,
    ) {}

    /**
     * Черновик по пакету (продукту). Срок действия считается из пакета от даты
     * продажи и вручную при создании не задаётся. Получатель может быть неизвестен.
     *
     * Тип определяет пакет: из бесплатного пакета — бесплатный сертификат.
     * Для бесплатного срок можно задать при выпуске (`validity`, см. ValidityChoice).
     *
     * @param  array{package_id:int, customer_id?:int|null, recipient_id?:int|null, complimentary_reason?:string|null, complimentary_purpose?:ComplimentaryPurpose|string|null, sold_at?:string|Carbon|null, notes?:string|null, batch_id?:int|null, validity?:array|ValidityChoice|null}  $data
     */
    public function createDraft(array $data): Certificate
    {
        return DB::transaction(function () use ($data) {
            if (empty($data['package_id'])) {
                throw new DomainRuleViolation('Выберите пакет: он задаёт состав и срок действия сертификата.');
            }
            $package = Package::findOrFail($data['package_id']);
            if (! $package->is_active) {
                throw new DomainRuleViolation("Пакет «{$package->name}» не активен.");
            }
            $soldAt = Carbon::parse($data['sold_at'] ?? today())->startOfDay();
            $kind = $package->is_complimentary ? CertificateKind::Complimentary : CertificateKind::Sale;
            $this->assertKind($kind, $data['customer_id'] ?? null, $data['complimentary_reason'] ?? null);
            $purpose = $kind === CertificateKind::Complimentary ? $this->purpose($data['complimentary_purpose'] ?? null, $package) : null;
            $validity = ($data['validity'] ?? null) instanceof ValidityChoice ? $data['validity'] : ValidityChoice::fromArray($data['validity'] ?? null);
            [$validFrom, $validUntil] = $validity->resolve($package, $soldAt);

            $certificate = Certificate::create([
                'number' => $this->numbers->next($soldAt),
                'customer_id' => $data['customer_id'] ?? null,
                'recipient_id' => $data['recipient_id'] ?? null,
                'package_id' => $package->id,
                'status' => CertificateStatus::Draft,
                'kind' => $kind,
                'complimentary_reason' => $kind === CertificateKind::Complimentary ? $data['complimentary_reason'] : null,
                'complimentary_purpose' => $purpose,
                'batch_id' => $data['batch_id'] ?? null,
                'sold_at' => $soldAt,
                'valid_from' => $validFrom,
                'valid_until' => $validUntil,
                'custom_validity' => $validity->isCustom(),
                'currency' => config('gifter.currency'),
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]);
            $this->history->record($certificate, HistoryEvent::Created,
                $package->name.', '.($validity->isCustom() ? $validity->describe($validFrom, $validUntil) : $package->validityLabel()),
                after: $this->snapshot($certificate));
            $this->addPackageItems($certificate, $package);

            return $certificate->refresh();
        });
    }

    /**
     * Предварительный расчёт до создания черновика (для мастера продажи):
     * базовый состав пакета + доп. услуги по ценам на дату продажи.
     *
     * @param  list<array{service_id:int|string|null, quantity:int|string|null}>  $extras
     * @return array{lines: list<array{name:string, quantity:int, unit_price:string, line_total:string, from_package:bool}>, total:string, missing: list<string>}
     */
    public function quote(Package $package, array $extras, Carbon $soldAt): array
    {
        $rows = $package->items()->with('service')->get()
            ->map(fn ($item) => [$item->service, (int) $item->quantity, true])->all();
        foreach ($extras as $extra) {
            $service = Service::find($extra['service_id'] ?? null);
            if ($service && (int) ($extra['quantity'] ?? 0) > 0) {
                $rows[] = [$service, (int) $extra['quantity'], false];
            }
        }

        $lines = [];
        $missing = [];
        $total = 0;
        foreach ($rows as [$service, $quantity, $fromPackage]) {
            try {
                $price = $this->pricing->priceOn($service, $soldAt)->price;
            } catch (DomainRuleViolation) {
                $missing[] = $service->name;

                continue;
            }
            $lineTotal = Money::multiply($price, $quantity);
            $total += Money::toMinor($lineTotal);
            $lines[] = ['name' => $service->name, 'quantity' => $quantity, 'unit_price' => (string) $price, 'line_total' => $lineTotal, 'from_package' => $fromPackage];
        }

        return ['lines' => $lines, 'total' => Money::fromMinor($total), 'missing' => $missing];
    }

    /**
     * Правка реквизитов черновика. Смена пакета заменяет базовый состав (доп. услуги
     * остаются); смена пакета или даты продажи пересчитывает срок и цены.
     *
     * @param  array{customer_id?:int|null, recipient_id?:int|null, package_id?:int, kind?:CertificateKind|string, complimentary_reason?:string|null, sold_at?:string|Carbon, notes?:string|null}  $data
     */
    public function updateDraft(Certificate $certificate, array $data): Certificate
    {
        return $this->locked($certificate, function (Certificate $certificate) use ($data) {
            $this->assertDraft($certificate);
            $data = array_intersect_key($data, array_flip(['customer_id', 'recipient_id', 'package_id', 'complimentary_reason', 'complimentary_purpose', 'sold_at', 'notes']));
            $soldAtChanged = isset($data['sold_at']) && ! Carbon::parse($data['sold_at'])->isSameDay($certificate->sold_at);
            $packageChanged = isset($data['package_id']) && (int) $data['package_id'] !== $certificate->package_id;
            $newPackage = $packageChanged ? Package::findOrFail($data['package_id']) : null;
            $kindChanged = $newPackage && $newPackage->is_complimentary !== $certificate->isComplimentary();
            $customerChanged = array_key_exists('customer_id', $data) && (int) $data['customer_id'] !== (int) $certificate->customer_id;
            if ($certificate->batch_id && ($packageChanged || $soldAtChanged || $customerChanged)) {
                throw new DomainRuleViolation('Сертификат входит в '.$certificate->batch->term('acc').': пакет, заказчик и дата продажи у всех её сертификатов общие.');
            }
            if ($soldAtChanged || $kindChanged || $packageChanged) {
                $this->assertNoActivePayments($certificate);
                $this->assertNotApproved($certificate);
            }
            $package = $newPackage ?? $certificate->package;
            if ($packageChanged && ! $package->is_active) {
                throw new DomainRuleViolation("Пакет «{$package->name}» не активен.");
            }

            $before = $this->snapshot($certificate);
            $certificate->fill($data + ['updated_by' => auth()->id()]);
            $certificate->kind = $package?->is_complimentary ? CertificateKind::Complimentary : CertificateKind::Sale;
            if ($certificate->isComplimentary()) {
                $certificate->complimentary_purpose = $this->purpose($certificate->complimentary_purpose, $package);
            } else {
                $certificate->complimentary_reason = null;
                $certificate->complimentary_purpose = null;
            }
            // Срок по пакету пересчитывается; срок, заданный вручную, сохраняется
            // (кроме смены пакета — это другой продукт, срок снова по пакету).
            if ($packageChanged && $package) {
                $certificate->custom_validity = false;
            }
            if (($soldAtChanged || $packageChanged) && $package && ! $certificate->custom_validity) {
                $certificate->valid_from = $certificate->sold_at;
                $certificate->valid_until = $package->validUntil($certificate->sold_at);
            }
            if ($certificate->custom_validity && $certificate->valid_from->lt($certificate->sold_at)) {
                throw new DomainRuleViolation('Дата продажи позже начала срока, заданного вручную: сначала измените срок.');
            }
            $this->assertKind($certificate->kind, $certificate->customer_id, $certificate->complimentary_reason);
            if (! $certificate->isDirty()) {
                return $certificate;
            }
            $certificate->save();
            $this->history->record($certificate, HistoryEvent::Updated, null, ...$this->diff($before, $this->snapshot($certificate)));

            if ($packageChanged) {
                $certificate->items()->where('from_package', true)->get()->each->delete();
                $this->addPackageItems($certificate, $package);
                $this->recalculateTotal($certificate);
            }
            if ($soldAtChanged) {
                $this->reprice($certificate);
            }

            return $certificate;
        });
    }

    public function addItem(Certificate $certificate, Service $service, int $quantity, bool $viaBatch = false): CertificateItem
    {
        return $this->locked($certificate, function (Certificate $certificate) use ($service, $quantity, $viaBatch) {
            $this->assertNotInBatch($certificate, $viaBatch);
            $this->assertCompositionEditable($certificate);
            $item = $this->createItem($certificate, $service, $quantity);
            $this->recalculateTotal($certificate);
            $this->history->record($certificate, HistoryEvent::ItemAdded,
                "{$item->service_name_snapshot} × {$item->quantity}", after: $this->itemSnapshot($item));

            return $item;
        });
    }

    /** Убрать доп. услугу. Услуги пакета не убираются — смысл пакета в его составе. */
    public function removeItem(CertificateItem $item): void
    {
        $this->locked($item->certificate, function (Certificate $certificate) use ($item) {
            if ($item->from_package) {
                throw new DomainRuleViolation('Услуги пакета не убираются: пакет продаётся целиком. Можно сменить пакет или убрать доп. услугу.');
            }
            $this->assertNotInBatch($certificate, false);
            $this->assertCompositionEditable($certificate);
            $snapshot = $this->itemSnapshot($item);
            $item->delete();
            $this->recalculateTotal($certificate);
            $this->history->record($certificate, HistoryEvent::ItemRemoved,
                "{$snapshot['service']} × {$snapshot['quantity']}", before: $snapshot);
        });
    }

    /** Базовый состав пакета в черновик; цены — на дату продажи сертификата. */
    private function addPackageItems(Certificate $certificate, Package $package): void
    {
        $package->loadMissing('items.service');
        if ($package->items->isEmpty()) {
            return; // пакет задаёт только срок, состав — доп. услугами
        }
        foreach ($package->items as $packageItem) {
            $this->createItem($certificate, $packageItem->service, $packageItem->quantity, fromPackage: true);
        }
        $this->recalculateTotal($certificate);
        $this->history->record($certificate, HistoryEvent::PackageApplied, $package->name,
            after: ['package_id' => $package->id, 'items' => $certificate->items()->get()->map($this->itemSnapshot(...))->all()]);
    }

    /** Получатель указывается или меняется в любой момент до погашения. */
    public function setRecipient(Certificate $certificate, ?int $recipientId): Certificate
    {
        return $this->locked($certificate, function (Certificate $certificate) use ($recipientId) {
            if ($certificate->status->isFinal() || $certificate->status === CertificateStatus::InUse) {
                throw new DomainRuleViolation("Получателя сертификата в статусе «{$certificate->status->getLabel()}» изменить нельзя.");
            }
            if ($certificate->recipient_id === $recipientId) {
                return $certificate;
            }
            $before = ['recipient_id' => $certificate->recipient_id];
            $certificate->update(['recipient_id' => $recipientId, 'updated_by' => auth()->id()]);
            $this->history->record($certificate, HistoryEvent::RecipientChanged, $certificate->recipient?->full_name, $before, ['recipient_id' => $recipientId]);

            return $certificate;
        });
    }

    /**
     * Согласование бесплатного сертификата директором по продажам.
     * После согласования состав и дата продажи фиксируются.
     */
    public function approveComplimentary(Certificate $certificate, ?string $comment = null, bool $viaBatch = false): Certificate
    {
        return $this->locked($certificate, function (Certificate $certificate) use ($comment, $viaBatch) {
            $this->assertDraft($certificate);
            $this->assertNotInBatch($certificate, $viaBatch);
            if (! $certificate->isComplimentary()) {
                throw new DomainRuleViolation('Согласование нужно только для бесплатного сертификата.');
            }
            if ($certificate->isComplimentaryApproved()) {
                throw new DomainRuleViolation('Сертификат уже согласован.');
            }
            if (! $certificate->items()->exists()) {
                throw new DomainRuleViolation('Нечего согласовывать: в сертификате нет услуг.');
            }
            $certificate->update(['complimentary_approved_by' => auth()->id(), 'complimentary_approved_at' => now(), 'updated_by' => auth()->id()]);
            $this->history->record($certificate, HistoryEvent::ComplimentaryApproved,
                trim('Расход по себестоимости '.Money::format($certificate->total_cost).'. '.($comment ?? '')));

            return $certificate;
        });
    }

    /** Выпуск: черновик с составом, полностью оплаченный, становится активным. */
    public function issue(Certificate $certificate, bool $viaBatch = false): Certificate
    {
        return $this->locked($certificate, function (Certificate $certificate) use ($viaBatch) {
            $this->assertDraft($certificate);
            $this->assertNotInBatch($certificate, $viaBatch);
            if (! $certificate->items()->exists()) {
                throw new DomainRuleViolation('Нельзя выпустить сертификат без услуг.');
            }
            if ($certificate->isComplimentary() && ! $certificate->isComplimentaryApproved()) {
                throw new DomainRuleViolation('Бесплатный сертификат выпускается после согласования директором по продажам.');
            }
            if (! $certificate->isComplimentary() && ! $certificate->isFullyPaid()) {
                throw new DomainRuleViolation('Сертификат оплачен не полностью: получено '
                    .Money::format($certificate->paidAmount()).' из '.Money::format($certificate->total_amount).'.');
            }
            if ($certificate->valid_until->lt(today())) {
                throw new DomainRuleViolation('Срок действия уже истёк — исправьте даты перед выпуском.');
            }

            $certificate->update([
                'status' => CertificateStatus::Active,
                'issued_at' => now(),
                'verification_token' => $certificate->verification_token ?? Str::random(32),
                'updated_by' => auth()->id(),
            ]);
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
            $this->assertNoPendingRefund($certificate);
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
            if ($target === CertificateStatus::Reserved && ! $certificate->recipient_id) {
                throw new DomainRuleViolation('Для брони укажите получателя (гостя).');
            }

            $certificate->update(['status' => $target, 'updated_by' => auth()->id()]);
            $this->history->record($certificate, $target === CertificateStatus::Expired ? HistoryEvent::Expired : HistoryEvent::StatusChanged,
                $reason, ['status' => $from->value], ['status' => $target->value]);
            // Бронь открывает заявки по услугам, требующим подготовки; снятие брони и погашение закрывают открытые.
            $this->fulfillments->syncWithCertificate($certificate);

            return $certificate;
        });
    }

    /** Аннулирование без денег. Оплаченный сертификат аннулируется через RefundManager. */
    public function cancel(Certificate $certificate, string $reason, bool $viaBatch = false): Certificate
    {
        return $this->locked($certificate, function (Certificate $certificate) use ($reason, $viaBatch) {
            if ($certificate->isDraft()) {
                $this->assertNotInBatch($certificate, $viaBatch);
            }
            $this->assertNoPendingRefund($certificate);
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
            if (Money::toMinor($certificate->paidAmount()) > 0) {
                throw new DomainRuleViolation('По сертификату есть оплата — аннулирование только через запрос на возврат с согласованием Финансов.');
            }

            $certificate->update([
                'status' => CertificateStatus::Cancelled,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
                'updated_by' => auth()->id(),
            ]);
            $this->history->record($certificate, HistoryEvent::Cancelled, $reason,
                ['status' => $from->value], ['status' => CertificateStatus::Cancelled->value]);
            $this->fulfillments->syncWithCertificate($certificate);

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
            $this->assertNoPendingRefund($certificate);
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
                'unit_cost' => $price->cost,
                'line_cost' => $price->cost === null ? null : Money::multiply($price->cost, $item->quantity),
            ]);
        }
        $this->recalculateTotal($certificate);
        if ($before !== []) {
            $this->history->record($certificate, HistoryEvent::Repriced, 'Цены на дату продажи '.$certificate->sold_at->format('d.m.Y'),
                ['items' => $before], ['items' => $certificate->items()->get()->map($this->itemSnapshot(...))->all()]);
        }
    }

    private function createItem(Certificate $certificate, Service $service, int $quantity, bool $fromPackage = false): CertificateItem
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
            'provider_id' => $service->provider_id,
            'price_version_id' => $price->id,
            'service_name_snapshot' => $service->name,
            'unit_snapshot' => $service->unit,
            'quantity' => $quantity,
            'unit_price' => $price->price,
            'line_total' => Money::multiply($price->price, $quantity),
            'unit_cost' => $price->cost,
            'line_cost' => $price->cost === null ? null : Money::multiply($price->cost, $quantity),
            'requires_fulfillment' => $service->requires_fulfillment,
            'requires_scheduling' => $service->requires_scheduling,
            'requires_confirmation' => $service->requires_confirmation,
            'from_package' => $fromPackage,
            'position' => (int) $certificate->items()->max('position') + 1,
        ]);
    }

    private function recalculateTotal(Certificate $certificate): void
    {
        $items = $certificate->items()->get();
        $certificate->update([
            'total_amount' => Money::fromMinor($items->sum(fn (CertificateItem $item) => Money::toMinor($item->line_total))),
            'total_cost' => Money::fromMinor($items->sum(fn (CertificateItem $item) => Money::toMinor($item->line_cost))),
            'updated_by' => auth()->id(),
        ]);
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

    private function assertNoPendingRefund(Certificate $certificate): void
    {
        if ($certificate->hasPendingRefund()) {
            throw new DomainRuleViolation('По сертификату ждёт решения запрос на возврат — действия заблокированы до решения Финансов.');
        }
    }

    /** Черновики блока согласуются, выпускаются и аннулируются только всем блоком. */
    private function assertNotInBatch(Certificate $certificate, bool $viaBatch): void
    {
        if ($certificate->batch_id && ! $viaBatch) {
            throw new DomainRuleViolation('Сертификат входит в '.$certificate->batch->term('acc').' — это действие выполняется на странице '.$certificate->batch->term('gen').' для всех её сертификатов.');
        }
    }

    private function purpose(ComplimentaryPurpose|string|null $purpose, ?Package $package): ComplimentaryPurpose
    {
        $purpose = $purpose instanceof ComplimentaryPurpose ? $purpose : ComplimentaryPurpose::tryFrom((string) $purpose);
        $purpose ??= $package?->complimentary_purpose;
        if (! $purpose) {
            throw new DomainRuleViolation('Укажите цель бесплатного сертификата.');
        }

        return $purpose;
    }

    private function assertKind(CertificateKind $kind, ?int $customerId, ?string $reason): void
    {
        if ($kind === CertificateKind::Complimentary && trim((string) $reason) === '') {
            throw new DomainRuleViolation('Для бесплатного сертификата укажите основание (кому и зачем).');
        }
        if ($kind === CertificateKind::Sale && ! $customerId) {
            throw new DomainRuleViolation('Для продажи укажите заказчика.');
        }
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
        $this->assertNotApproved($certificate);
    }

    private function assertNotApproved(Certificate $certificate): void
    {
        if ($certificate->isComplimentaryApproved()) {
            throw new DomainRuleViolation('Бесплатный сертификат уже согласован директором — состав и дата продажи зафиксированы.');
        }
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
            'package_id' => $certificate->package_id,
            'kind' => $certificate->kind?->value,
            'complimentary_reason' => $certificate->complimentary_reason,
            'complimentary_purpose' => $certificate->complimentary_purpose?->value,
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
            'from_package' => $item->from_package,
            'quantity' => $item->quantity,
            'unit_price' => (string) $item->unit_price,
            'line_total' => (string) $item->line_total,
            'line_cost' => $item->line_cost === null ? null : (string) $item->line_cost,
            'price_version_id' => $item->price_version_id,
        ];
    }
}
