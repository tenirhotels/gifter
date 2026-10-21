<?php

namespace App\Services;

use App\Enums\CertificateKind;
use App\Enums\CertificateStatus;
use App\Enums\ComplimentaryPurpose;
use App\Enums\HistoryEvent;
use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Enums\ReservationReview;
use App\Enums\SaleSource;
use App\Exceptions\DomainRuleViolation;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\CertificateItem;
use App\Models\Package;
use App\Models\Service;
use App\Models\User;
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
        private readonly CommissionManager $commissions,
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
                throw new DomainRuleViolation(__('Выберите пакет: он задаёт состав и срок действия сертификата.'));
            }
            $package = Package::findOrFail($data['package_id']);
            if (! $package->is_active) {
                throw new DomainRuleViolation(__('Пакет «:p1» не активен.', ['p1' => $package->name]));
            }
            $soldAt = Carbon::parse($data['sold_at'] ?? today())->startOfDay();
            $soldAtReason = $soldAt->isSameDay(today()) ? null : $this->overrideReason('Дата продажи отличается от сегодняшней', $data['sold_at_reason'] ?? null);
            $sellerReason = ($data['sold_by'] ?? null) && (int) $data['sold_by'] !== auth()->id()
                ? $this->overrideReason('Продавец назначен не автором оформления', $data['seller_reason'] ?? null) : null;
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
                'source' => $data['source'] ?? SaleSource::Staff,
                'online_order_id' => $data['online_order_id'] ?? null,
                'created_by' => auth()->id(),
                // Продавец — автор оформления; менеджер продаж или администратор может назначить другого.
                'sold_by' => $this->sellerId($data['sold_by'] ?? null),
                'updated_by' => auth()->id(),
            ]);
            $this->history->record($certificate, HistoryEvent::Created,
                $package->name.', '.($validity->isCustom() ? $validity->describe($validFrom, $validUntil) : $package->validityLabel()),
                after: $this->snapshot($certificate));
            $this->addPackageItems($certificate, $package);
            $this->recordOverride($certificate, 'sale_date_overridden', ['sold_at' => $soldAt->toDateString()], $soldAtReason);
            $this->recordOverride($certificate, 'seller_overridden', ['sold_by' => $certificate->sold_by], $sellerReason);

            return $certificate->refresh();
        });
    }

    /**
     * Предварительный расчёт до создания черновика (для мастера продажи):
     * базовый состав пакета + допуслуги по ценам на дату продажи.
     *
     * Оплата: у платного пакета всё оплачивает заказчик; у бесплатного — только допуслуги
     * с признаком `paid` (тумблер «Платная»).
     *
     * @param  list<array{service_id:int|string|null, quantity:int|string|null, paid?:bool|null}>  $extras
     * @return array{lines: list<array{name:string, quantity:int, unit_price:string, line_total:string, from_package:bool, paid:bool}>, total:string, payable:string, free:string, missing: list<string>}
     */
    public function quote(Package $package, array $extras, Carbon $soldAt): array
    {
        $rows = $package->items()->with('service')->get()
            ->map(fn ($item) => [$item->service, (int) $item->quantity, true, ! $package->is_complimentary])->all();
        foreach ($extras as $extra) {
            $service = Service::find($extra['service_id'] ?? null);
            if ($service && (int) ($extra['quantity'] ?? 0) > 0) {
                $rows[] = [$service, (int) $extra['quantity'], false, ! $package->is_complimentary || (bool) ($extra['paid'] ?? false)];
            }
        }

        $lines = [];
        $missing = [];
        $total = 0;
        $payable = 0;
        foreach ($rows as [$service, $quantity, $fromPackage, $paid]) {
            try {
                $price = $this->pricing->priceOn($service, $soldAt)->price;
            } catch (DomainRuleViolation) {
                $missing[] = $service->name;

                continue;
            }
            $lineTotal = Money::multiply($price, $quantity);
            $total += Money::toMinor($lineTotal);
            $payable += $paid ? Money::toMinor($lineTotal) : 0;
            $lines[] = ['name' => $service->name, 'quantity' => $quantity, 'unit_price' => (string) $price, 'line_total' => $lineTotal, 'from_package' => $fromPackage, 'paid' => $paid];
        }

        return ['lines' => $lines, 'total' => Money::fromMinor($total), 'payable' => Money::fromMinor($payable),
            'free' => Money::fromMinor($total - $payable), 'missing' => $missing];
    }

    /**
     * Правка реквизитов черновика. Смена пакета заменяет базовый состав (допуслуги
     * остаются); смена пакета или даты продажи пересчитывает срок и цены.
     *
     * @param  array{customer_id?:int|null, recipient_id?:int|null, package_id?:int, kind?:CertificateKind|string, complimentary_reason?:string|null, sold_at?:string|Carbon, notes?:string|null}  $data
     */
    public function updateDraft(Certificate $certificate, array $data): Certificate
    {
        return $this->locked($certificate, function (Certificate $certificate) use ($data) {
            $this->assertDraft($certificate);
            $soldAtReason = $data['sold_at_reason'] ?? null;
            $data = array_intersect_key($data, array_flip(['customer_id', 'recipient_id', 'package_id', 'complimentary_reason', 'complimentary_purpose', 'sold_at', 'notes']));
            $soldAtChanged = isset($data['sold_at']) && ! Carbon::parse($data['sold_at'])->isSameDay($certificate->sold_at);
            if ($soldAtChanged) {
                $soldAtReason = $this->overrideReason('Изменена дата продажи', $soldAtReason);
            }
            $packageChanged = isset($data['package_id']) && (int) $data['package_id'] !== $certificate->package_id;
            $newPackage = $packageChanged ? Package::findOrFail($data['package_id']) : null;
            $kindChanged = $newPackage && $newPackage->is_complimentary !== $certificate->isComplimentary();
            $customerChanged = array_key_exists('customer_id', $data) && (int) $data['customer_id'] !== (int) $certificate->customer_id;
            if ($certificate->batch_id && ($packageChanged || $soldAtChanged || $customerChanged)) {
                throw new DomainRuleViolation(__('Сертификат входит в группу: пакет, заказчик и дата продажи у всех её сертификатов общие.'));
            }
            if ($soldAtChanged || $kindChanged || $packageChanged) {
                $this->assertNoActivePayments($certificate);
                $this->assertNotApproved($certificate);
            }
            $package = $newPackage ?? $certificate->package;
            if ($packageChanged && ! $package->is_active) {
                throw new DomainRuleViolation(__('Пакет «:p1» не активен.', ['p1' => $package->name]));
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
                throw new DomainRuleViolation(__('Дата продажи позже начала срока, заданного вручную: сначала измените срок.'));
            }
            $this->assertKind($certificate->kind, $certificate->customer_id, $certificate->complimentary_reason);
            $this->assertPayablePartHasCustomer($certificate);
            if (! $certificate->isDirty()) {
                return $certificate;
            }
            $certificate->save();
            $this->history->record($certificate, HistoryEvent::Updated, null, ...$this->diff($before, $this->snapshot($certificate)));

            if ($packageChanged) {
                $certificate->items()->where('from_package', true)->get()->each->delete();
                if ($kindChanged) {
                    // Сменился тип: у платного оплачивается всё, у бесплатного допуслуги снова бесплатные.
                    $certificate->items()->update(['paid_by_customer' => ! $certificate->isComplimentary()]);
                }
                $this->addPackageItems($certificate, $package);
                $this->recalculateTotal($certificate);
                $this->assertPayablePartHasCustomer($certificate);
            }
            if ($soldAtChanged) {
                $this->reprice($certificate);
                $this->recordOverride($certificate, 'sale_date_overridden', ['sold_at' => $certificate->sold_at->toDateString(), 'was' => $before['sold_at'] ?? null], $soldAtReason);
            }

            return $certificate;
        });
    }

    /**
     * Доп. услуга. В бесплатном сертификате — бесплатная (маркетинговый расход) или платная
     * (`$paid`: её оплачивает заказчик, нужен счёт); в платном оплачивается всё.
     */
    public function addItem(Certificate $certificate, Service $service, int $quantity, bool $viaBatch = false, bool $paid = false): CertificateItem
    {
        return $this->locked($certificate, function (Certificate $certificate) use ($service, $quantity, $viaBatch, $paid) {
            $this->assertNotInBatch($certificate, $viaBatch);
            $this->assertCompositionEditable($certificate);
            $item = $this->createItem($certificate, $service, $quantity, paid: $paid);
            $this->recalculateTotal($certificate);
            $this->assertPayablePartHasCustomer($certificate);
            $this->history->record($certificate, HistoryEvent::ItemAdded,
                "{$item->service_name_snapshot} × {$item->quantity}".($certificate->isComplimentary() ? ($item->paid_by_customer ? __(' (платная)') : __(' (бесплатная)')) : ''),
                after: $this->itemSnapshot($item));

            return $item;
        });
    }

    /** Платная или бесплатная допуслуга бесплатного сертификата — до согласования и до выставления счёта. */
    public function setItemPaid(CertificateItem $item, bool $paid, bool $viaBatch = false): CertificateItem
    {
        return $this->locked($item->certificate, function (Certificate $certificate) use ($item, $paid, $viaBatch) {
            $item = CertificateItem::query()->findOrFail($item->id);
            if (! $certificate->isComplimentary()) {
                throw new DomainRuleViolation(__('В платном сертификате оплачивается весь состав.'));
            }
            if ($item->from_package) {
                throw new DomainRuleViolation(__('Услуги бесплатного пакета бесплатны: платной можно сделать только допуслугу.'));
            }
            $this->assertNotInBatch($certificate, $viaBatch);
            $this->assertCompositionEditable($certificate);
            if ($item->paid_by_customer === $paid) {
                return $item;
            }
            $before = $this->itemSnapshot($item);
            $item->update(['paid_by_customer' => $paid]);
            $this->recalculateTotal($certificate);
            $this->assertPayablePartHasCustomer($certificate);
            $this->history->record($certificate, HistoryEvent::ItemChanged,
                "{$item->service_name_snapshot} × {$item->quantity}: ".($paid ? __('платная, оплачивает заказчик') : __('бесплатная')),
                before: $before, after: $this->itemSnapshot($item->refresh()));

            return $item;
        });
    }

    /** Убрать допуслугу. Услуги пакета не убираются — смысл пакета в его составе. */
    public function removeItem(CertificateItem $item): void
    {
        $this->locked($item->certificate, function (Certificate $certificate) use ($item) {
            if ($item->from_package) {
                throw new DomainRuleViolation(__('Услуги пакета не убираются: пакет продаётся целиком. Можно сменить пакет или убрать допуслугу.'));
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
            return; // пакет задаёт только срок, состав — допуслугами
        }
        foreach ($package->items as $packageItem) {
            $this->createItem($certificate, $packageItem->service, $packageItem->quantity, fromPackage: true);
        }
        $this->recalculateTotal($certificate);
        $this->history->record($certificate, HistoryEvent::PackageApplied, $package->name,
            after: ['package_id' => $package->id, 'items' => $certificate->items()->get()->map($this->itemSnapshot(...))->all()]);
    }

    /**
     * Продавец сертификата — для комиссии. Меняется только в черновике (менеджер продаж или администратор),
     * после выпуска закреплён навсегда. В группе продавец у всех сертификатов один.
     */
    public function setSeller(Certificate $certificate, int $sellerId, bool $viaBatch = false, ?string $reason = null): Certificate
    {
        return $this->locked($certificate, function (Certificate $certificate) use ($sellerId, $viaBatch, $reason) {
            $this->assertDraft($certificate);
            $this->assertNotInBatch($certificate, $viaBatch);
            $seller = User::query()->where('is_active', true)->find($sellerId);
            if (! $seller || ! $seller->hasPermission(Permission::CreateCertificates)) {
                throw new DomainRuleViolation(__('Продавцом может быть только действующий сотрудник отдела продаж.'));
            }
            if ((int) $certificate->sold_by === $sellerId) {
                return $certificate;
            }
            $reason = $this->overrideReason('Смена продавца', $reason);
            $before = ['sold_by' => $certificate->sold_by];
            $certificate->update(['sold_by' => $sellerId, 'updated_by' => auth()->id()]);
            $this->history->record($certificate, HistoryEvent::Updated, "Продавец: {$seller->name}. Причина: {$reason}", $before, ['sold_by' => $sellerId]);
            $this->recordOverride($certificate, 'seller_overridden', ['sold_by' => $sellerId, 'was' => $before['sold_by']], $reason);

            return $certificate;
        });
    }

    /** Исключение из обычного порядка (дата продажи, продавец) — только с причиной: видно директору и в журнале. */
    private function overrideReason(string $what, ?string $reason): string
    {
        $reason = trim((string) $reason);
        if (mb_strlen($reason) < 5) {
            throw new DomainRuleViolation(__(':p1: укажите причину (не короче 5 знаков) — её увидит директор по продажам.', ['p1' => $what]));
        }

        return $reason;
    }

    /** @param  array<string, mixed>  $after */
    private function recordOverride(Certificate $certificate, string $event, array $after, ?string $reason): void
    {
        if ($reason === null) {
            return;
        }
        AuditLog::record($event, $certificate, null, $after + ['reason' => $reason]);
    }

    private function sellerId(?int $requested): ?int
    {
        if ($requested && $requested !== auth()->id()) {
            if (! auth()->user()?->can(Permission::AssignSeller)) {
                throw new DomainRuleViolation(__('Назначить другого продавца может менеджер продаж.'));
            }
            $seller = User::query()->where('is_active', true)->find($requested);
            if (! $seller || ! $seller->hasPermission(Permission::CreateCertificates)) {
                throw new DomainRuleViolation(__('Продавцом может быть только действующий сотрудник отдела продаж.'));
            }
        }

        return $requested ?: auth()->id();
    }

    /** Получатель указывается или меняется в любой момент до погашения. */
    public function setRecipient(Certificate $certificate, ?int $recipientId): Certificate
    {
        return $this->locked($certificate, function (Certificate $certificate) use ($recipientId) {
            if ($certificate->status->isFinal() || $certificate->status === CertificateStatus::InUse) {
                throw new DomainRuleViolation(__('Получателя сертификата в статусе «:p1» изменить нельзя.', ['p1' => $certificate->status->getLabel()]));
            }
            // Имя гостя в брони сверено с получателем — подменить получателя после брони нельзя.
            if ($certificate->status === CertificateStatus::Reserved && $certificate->recipient_id !== $recipientId) {
                throw new DomainRuleViolation(__('Сертификат забронирован: получателя можно сменить только после снятия брони.'));
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
                throw new DomainRuleViolation(__('Согласование нужно только для бесплатного сертификата.'));
            }
            if ($certificate->isComplimentaryApproved()) {
                throw new DomainRuleViolation(__('Сертификат уже согласован.'));
            }
            if (! $certificate->items()->exists()) {
                throw new DomainRuleViolation(__('Нечего согласовывать: в сертификате нет услуг.'));
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
                throw new DomainRuleViolation(__('Нельзя выпустить сертификат без услуг.'));
            }
            if ($certificate->isComplimentary() && ! $certificate->isComplimentaryApproved()) {
                throw new DomainRuleViolation(__('Бесплатный сертификат выпускается после согласования директором по продажам.'));
            }
            if (! $certificate->isFullyPaid()) {
                throw new DomainRuleViolation(($certificate->isComplimentary()
                    ? __('Платная часть оплачена не полностью: получено :p1 из :p2.', ['p1' => Money::format($certificate->paidAmount()), 'p2' => Money::format($certificate->payable_amount)])
                    : __('Сертификат оплачен не полностью: получено :p1 из :p2.', ['p1' => Money::format($certificate->paidAmount()), 'p2' => Money::format($certificate->payable_amount)])));
            }
            if (! $certificate->isPaymentConfirmed()) {
                throw new DomainRuleViolation(__('Оплата ждёт подтверждения Финансов: получено :p1, сверено :p2 из :p3. Выпуск — после сверки.', ['p1' => Money::format($certificate->paidAmount()), 'p2' => Money::format($certificate->reconciledAmount()), 'p3' => Money::format($certificate->payable_amount)]));
            }
            if ($certificate->valid_until->lt(today())) {
                throw new DomainRuleViolation(__('Срок действия уже истёк — исправьте даты перед выпуском.'));
            }

            $certificate->update([
                'status' => CertificateStatus::Active,
                'issued_at' => now(),
                'verification_token' => $certificate->verification_token ?? Str::random(32),
                'updated_by' => auth()->id(),
            ]);
            $this->history->record($certificate, HistoryEvent::Issued, null,
                ['status' => CertificateStatus::Draft->value], ['status' => CertificateStatus::Active->value]);
            // Продавец закреплён навсегда; снимок комиссии — по правилу на дату продажи (начисляется при погашении).
            $this->commissions->snapshotOnIssue($certificate);

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
                throw new DomainRuleViolation(__('Черновик переводится в работу только выпуском.'));
            }
            if ($target === CertificateStatus::Cancelled) {
                throw new DomainRuleViolation(__('Для аннулирования используйте отдельное действие с причиной.'));
            }
            if (! $from->canTransitionTo($target)) {
                throw new DomainRuleViolation(__('Переход «:p1» → «:p2» недопустим.', ['p1' => $from->getLabel(), 'p2' => $target->getLabel()]));
            }
            // Бронь — с номером брони PMS, датами и сверкой имени (ReservationManager).
            if ($target === CertificateStatus::Reserved || ($from === CertificateStatus::Reserved && $target === CertificateStatus::Active)) {
                throw new DomainRuleViolation(__('Бронь отмечается и снимается действиями «Отметить бронь» / «Снять бронь».'));
            }
            if ($target === CertificateStatus::InUse) {
                if (! $certificate->hasReservation()) {
                    throw new DomainRuleViolation(__('Заезд — только по брони с номером PMS.'));
                }
                if ($certificate->arrival_date->gt(today()->addDay())) {
                    throw new DomainRuleViolation(__('Заезд по брони — с :p1.', ['p1' => $certificate->arrival_date->format('d.m.Y')]));
                }
            }
            if ($target === CertificateStatus::Used && $certificate->reservation_review_status === ReservationReview::Disputed) {
                throw new DomainRuleViolation(__('Бронь оспорена Финансами — отметить сертификат использованным нельзя, пока спор не снят.'));
            }

            $certificate->update(['status' => $target, 'updated_by' => auth()->id()]);
            $this->history->record($certificate, $target === CertificateStatus::Expired ? HistoryEvent::Expired : HistoryEvent::StatusChanged,
                $reason, ['status' => $from->value], ['status' => $target->value]);
            // Бронь открывает заявки по услугам, требующим подготовки; снятие брони и погашение закрывают открытые.
            $this->fulfillments->syncWithCertificate($certificate);
            // Погашение начисляет комиссию продавца; истечение срока — не начисляет.
            if ($target === CertificateStatus::Used) {
                $this->commissions->earn($certificate);
            } elseif ($target === CertificateStatus::Expired) {
                $this->commissions->void($certificate, $reason ?: __('Срок действия сертификата истёк'));
            }

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
                throw new DomainRuleViolation(__('Укажите причину аннулирования.'));
            }
            $from = $certificate->status;
            if (! $from->canTransitionTo(CertificateStatus::Cancelled)) {
                throw new DomainRuleViolation(__('Сертификат в статусе «:p1» аннулировать нельзя.', ['p1' => $from->getLabel()]));
            }
            if ($certificate->payments()->where('status', PaymentStatus::Pending)->exists()) {
                throw new DomainRuleViolation(__('Сначала отмените выставленные неоплаченные счета.'));
            }
            if (Money::toMinor($certificate->paidAmount()) > 0) {
                throw new DomainRuleViolation(__('По сертификату есть оплата — аннулирование только через запрос на возврат с согласованием Финансов.'));
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
            $this->commissions->void($certificate, "Сертификат аннулирован: {$reason}");

            return $certificate;
        });
    }

    /** Изменение срока действия выпущенного сертификата — только с причиной. */
    public function changeValidity(Certificate $certificate, Carbon $validFrom, Carbon $validUntil, string $reason): Certificate
    {
        return $this->locked($certificate, function (Certificate $certificate) use ($validFrom, $validUntil, $reason) {
            if (trim($reason) === '') {
                throw new DomainRuleViolation(__('Укажите причину изменения срока.'));
            }
            $this->assertNoPendingRefund($certificate);
            if (! in_array($certificate->status, [CertificateStatus::Draft, CertificateStatus::Active, CertificateStatus::Reserved], true)) {
                throw new DomainRuleViolation(__('Срок сертификата в статусе «:p1» изменить нельзя.', ['p1' => $certificate->status->getLabel()]));
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

    private function createItem(Certificate $certificate, Service $service, int $quantity, bool $fromPackage = false, bool $paid = false): CertificateItem
    {
        if ($quantity < 1) {
            throw new DomainRuleViolation(__('Количество должно быть не меньше 1.'));
        }
        if (! $service->is_active) {
            throw new DomainRuleViolation(__('Услуга «:p1» не активна.', ['p1' => $service->name]));
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
            // Платный сертификат оплачивается целиком; в бесплатном платная — только отмеченная допуслуга.
            'paid_by_customer' => ! $certificate->isComplimentary() || (! $fromPackage && $paid),
            'position' => (int) $certificate->items()->max('position') + 1,
        ]);
    }

    private function recalculateTotal(Certificate $certificate): void
    {
        $items = $certificate->items()->get();
        $minor = fn ($rows, string $field) => $rows->sum(fn (CertificateItem $item) => Money::toMinor($item->{$field}));
        $certificate->update([
            'total_amount' => Money::fromMinor($minor($items, 'line_total')),
            'total_cost' => Money::fromMinor($minor($items, 'line_cost')),
            // К оплате заказчиком: у продажи — всё, у бесплатного — платные допуслуги.
            'payable_amount' => Money::fromMinor($minor($items->where('paid_by_customer', true), 'line_total')),
            // Маркетинговый расход: себестоимость того, что заказчик не оплачивает (только у бесплатного).
            'marketing_cost' => $certificate->isComplimentary() ? Money::fromMinor($minor($items->where('paid_by_customer', false), 'line_cost')) : '0.00',
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
            throw new DomainRuleViolation(__('По сертификату ждёт решения запрос на возврат — действия заблокированы до решения Финансов.'));
        }
    }

    /** Черновики блока согласуются, выпускаются и аннулируются только всем блоком. */
    private function assertNotInBatch(Certificate $certificate, bool $viaBatch): void
    {
        if ($certificate->batch_id && ! $viaBatch) {
            throw new DomainRuleViolation(__('Сертификат входит в группу — это действие выполняется на странице группы для всех её сертификатов.'));
        }
    }

    private function purpose(ComplimentaryPurpose|string|null $purpose, ?Package $package): ComplimentaryPurpose
    {
        $purpose = $purpose instanceof ComplimentaryPurpose ? $purpose : ComplimentaryPurpose::tryFrom((string) $purpose);
        $purpose ??= $package?->complimentary_purpose;
        if (! $purpose) {
            throw new DomainRuleViolation(__('Укажите цель бесплатного сертификата.'));
        }

        return $purpose;
    }

    private function assertKind(CertificateKind $kind, ?int $customerId, ?string $reason): void
    {
        if ($kind === CertificateKind::Complimentary && trim((string) $reason) === '') {
            throw new DomainRuleViolation(__('Для бесплатного сертификата укажите основание (кому и зачем).'));
        }
        if ($kind === CertificateKind::Sale && ! $customerId) {
            throw new DomainRuleViolation(__('Для продажи укажите заказчика.'));
        }
    }

    /** Платную часть оплачивает заказчик — он должен быть указан (у бесплатного он по умолчанию необязателен). */
    private function assertPayablePartHasCustomer(Certificate $certificate): void
    {
        if ($certificate->isComplimentary() && $certificate->hasPayablePart() && ! $certificate->customer_id) {
            throw new DomainRuleViolation(__('В сертификате есть платные услуги — укажите заказчика, который их оплатит.'));
        }
    }

    private function assertDraft(Certificate $certificate): void
    {
        if (! $certificate->isDraft()) {
            throw new DomainRuleViolation(__('Действие доступно только для черновика.'));
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
            throw new DomainRuleViolation(__('Бесплатный сертификат уже согласован директором — состав и дата продажи зафиксированы.'));
        }
    }

    private function assertNoActivePayments(Certificate $certificate): void
    {
        if ($certificate->payments()->where('status', '!=', PaymentStatus::Cancelled)->exists()) {
            throw new DomainRuleViolation(__('По сертификату уже выставлен счёт. Чтобы изменить состав или дату продажи, отмените неоплаченный счёт.'));
        }
    }

    private function assertValidity(Carbon $from, Carbon $until): void
    {
        if ($until->lt($from)) {
            throw new DomainRuleViolation(__('Дата окончания действия раньше даты начала.'));
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
            'paid_by_customer' => $item->paid_by_customer,
            'quantity' => $item->quantity,
            'unit_price' => (string) $item->unit_price,
            'line_total' => (string) $item->line_total,
            'line_cost' => $item->line_cost === null ? null : (string) $item->line_cost,
            'price_version_id' => $item->price_version_id,
        ];
    }
}
