<?php

namespace App\Services;

use App\Enums\Permission;
use App\Exceptions\DomainRuleViolation;
use App\Models\Service;
use App\Models\ServicePrice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Версии цен услуг (цена + себестоимость): поиск версии на дату и добавление новой без пересечений.
 */
class ServicePricing
{
    public function priceOn(Service $service, Carbon $date): ServicePrice
    {
        $price = $service->prices()->getQuery()->reorder()->effectiveOn($date)->orderByDesc('valid_from')->first();

        if (! $price) {
            throw new DomainRuleViolation(__('Нет действующей цены на :p1 для услуги «:p2».', ['p1' => $date->format('d.m.Y'), 'p2' => $service->name]));
        }

        return $price;
    }

    /**
     * Добавляет версию цены. Если начало новой попадает в период действующей версии,
     * та закрывается днём раньше начала новой (см. shortenCovering).
     */
    public function addPrice(Service $service, string $price, Carbon $validFrom, ?Carbon $validUntil = null, ?string $cost = null): ServicePrice
    {
        if ((float) $price <= 0) {
            throw new DomainRuleViolation(__('Цена должна быть больше нуля.'));
        }
        if ($cost !== null && $cost !== '' && (float) $cost < 0) {
            throw new DomainRuleViolation(__('Себестоимость не может быть отрицательной.'));
        }
        if ($validUntil && $validUntil->lt($validFrom)) {
            throw new DomainRuleViolation(__('Дата окончания раньше даты начала.'));
        }

        $pending = $this->needsApproval();

        return DB::transaction(function () use ($service, $price, $validFrom, $validUntil, $cost, $pending) {
            $cover = $this->covering($service, $validFrom);
            // Неподтверждённая цена ничего не закрывает: предыдущая действует, пока администратор не подтвердит новую.
            $this->shortenCovering($service, $cover, $validFrom, $validUntil, ! $pending);

            if ($this->overlapsOthers($service, $validFrom, $validUntil, $pending ? $cover?->id : null)) {
                throw new DomainRuleViolation(__('Период пересекается с существующей ценой этой услуги.'));
            }

            return $service->prices()->create([
                'price' => $price,
                'cost' => $cost === '' ? null : $cost,
                'currency' => config('gifter.currency'),
                'valid_from' => $validFrom,
                'valid_until' => $validUntil,
                'created_by' => auth()->id(),
                'approved_at' => $pending ? null : now(),
                'approved_by' => $pending ? null : auth()->id(),
            ]);
        });
    }

    /** Есть подтверждённая цена на сегодня (услугу можно продавать). */
    public function hasApprovedPrice(Service $service): bool
    {
        return $service->prices()->getQuery()->reorder()->effectiveOn(today())->exists();
    }

    /** Цену директора подтверждает администратор (решение владельца 19.10.2026). Выключается GIFTER_REQUIRE_PRICE_APPROVAL=false. */
    public function needsApproval(): bool
    {
        return config('gifter.require_price_approval') && ! auth()->user()?->can(Permission::AdminCorrections);
    }

    /** Подтверждение цены: версия вступает в силу, предыдущая открытая закрывается днём раньше. */
    public function approve(ServicePrice $version): ServicePrice
    {
        if (! auth()->user()?->can(Permission::AdminCorrections)) {
            throw new DomainRuleViolation(__('Цену подтверждает администратор.'));
        }

        return DB::transaction(function () use ($version) {
            $fresh = ServicePrice::query()->lockForUpdate()->findOrFail($version->id);
            if ($fresh->isApproved()) {
                throw new DomainRuleViolation(__('Цена уже подтверждена.'));
            }
            $service = $fresh->service;
            $this->shortenCovering($service, $this->covering($service, $fresh->valid_from, $fresh->id, true), $fresh->valid_from, $fresh->valid_until, true, $fresh->id);
            if ($service->prices()->getQuery()->reorder()->whereKeyNot($fresh->id)->whereNotNull('approved_at')
                ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $fresh->valid_from->toDateString()))
                ->when($fresh->valid_until, fn ($q) => $q->whereDate('valid_from', '<=', $fresh->valid_until->toDateString()))->exists()) {
                throw new DomainRuleViolation(__('Период пересекается с подтверждённой ценой этой услуги — исправьте даты.'));
            }
            $fresh->update(['approved_at' => now(), 'approved_by' => auth()->id()]);
            $version->setRawAttributes($fresh->getAttributes(), true);

            return $fresh;
        });
    }

    /**
     * Исправить версию цены — в день её добавления и пока она не попала
     * в сертификаты (администратор — любую неиспользованную). Иначе — новая версия.
     */
    public function updatePrice(ServicePrice $version, string $price, Carbon $validFrom, ?Carbon $validUntil = null, ?string $cost = null): ServicePrice
    {
        if (! $this->isEditable($version)) {
            throw new DomainRuleViolation($version->certificateItems()->exists()
                ? __('Цена уже использована в сертификатах — добавьте новую версию.')
                : __('Исправить можно только цену, добавленную сегодня. Для изменения добавьте новую версию.'));
        }
        if ((float) $price <= 0) {
            throw new DomainRuleViolation(__('Цена должна быть больше нуля.'));
        }
        if ($cost !== null && $cost !== '' && (float) $cost < 0) {
            throw new DomainRuleViolation(__('Себестоимость не может быть отрицательной.'));
        }
        if ($validUntil && $validUntil->lt($validFrom)) {
            throw new DomainRuleViolation(__('Дата окончания раньше даты начала.'));
        }

        return DB::transaction(function () use ($version, $price, $validFrom, $validUntil, $cost) {
            $service = $version->service;
            // Предыдущая версия, закрытая автоматически при добавлении этой, — сдвигаем её конец.
            $previous = $service->prices()->getQuery()->reorder()->whereKeyNot($version->id)
                ->whereDate('valid_until', $version->valid_from->copy()->subDay()->toDateString())->lockForUpdate()->first();
            if ($previous && $validFrom->gt($previous->valid_from)) {
                $previous->update(['valid_until' => $validFrom->copy()->subDay()]);
            }
            if ($this->overlapsOthers($service, $validFrom, $validUntil, $version->id)) {
                throw new DomainRuleViolation(__('Период пересекается с другой ценой этой услуги.'));
            }
            $version->update([
                'price' => $price,
                'cost' => $cost === '' ? null : $cost,
                'valid_from' => $validFrom,
                'valid_until' => $validUntil,
            ] + ($this->needsApproval() ? ['approved_at' => null, 'approved_by' => null] : []));

            return $version;
        });
    }

    /** Сегодняшнюю версию — директор; любую неиспользованную — администратор (исправление ошибок). */
    public function isEditable(ServicePrice $version): bool
    {
        if ($version->certificateItems()->exists()) {
            return false;
        }

        return $version->created_at?->isToday() || (bool) auth()->user()?->can(Permission::AdminCorrections);
    }

    /** Версия, начавшаяся раньше `$from` и действующая в этот день (бессрочная или с концом не раньше `$from`). */
    private function covering(Service $service, Carbon $from, ?int $exceptId = null, bool $approvedOnly = false): ?ServicePrice
    {
        return $service->prices()->getQuery()->reorder()
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->when($approvedOnly, fn ($q) => $q->whereNotNull('approved_at'))
            ->whereDate('valid_from', '<', $from->toDateString())
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $from->toDateString()))
            ->orderByDesc('valid_from')->lockForUpdate()->first();
    }

    /**
     * Новая цена начинается внутри периода действующей: та заканчивается днём раньше.
     * Бессрочную закрываем как раньше; версию с датой окончания — только с сегодняшнего дня (прошлое не переписываем)
     * и если новая цена не заканчивается раньше неё (иначе «хвост» периода остался бы без цены).
     * `$apply = false` — только проверить; `$selfId` — подтверждаемая версия (себя не считаем) (неподтверждённая цена ничего не закрывает).
     */
    private function shortenCovering(Service $service, ?ServicePrice $cover, Carbon $from, ?Carbon $until, bool $apply = true, ?int $selfId = null): void
    {
        if (! $cover) {
            return;
        }
        if ($cover->valid_until !== null) {
            if ($from->lt(today())) {
                throw new DomainRuleViolation(__('Начало новой цены попадает в период действующей (до :p1) — укажите дату не раньше сегодняшней.', ['p1' => $cover->valid_until->format('d.m.Y')]));
            }
            if ($until && $until->lt($cover->valid_until)) {
                throw new DomainRuleViolation(__('Действующая цена идёт до :p1 — оставьте дату «по» пустой или не раньше этой даты.', ['p1' => $cover->valid_until->format('d.m.Y')]));
            }
        } elseif ($this->overlapsOthers($service, $from, $until, array_filter([$cover->id, $selfId]))) {
            return;
        }
        if ($apply) {
            $cover->update(['valid_until' => $from->copy()->subDay()]);
        }
    }

    /** @param  int|array<int>|null  $exceptId */
    private function overlapsOthers(Service $service, Carbon $from, ?Carbon $until, int|array|null $exceptId = null): bool
    {
        return $service->prices()->getQuery()->reorder()
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $from->toDateString()))
            ->when($until, fn ($q) => $q->whereDate('valid_from', '<=', $until->toDateString()))
            ->exists();
    }
}
