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
            throw new DomainRuleViolation("Нет действующей цены на {$date->format('d.m.Y')} для услуги «{$service->name}».");
        }

        return $price;
    }

    /**
     * Добавляет версию цены. Если у предыдущей открытой версии нет даты окончания,
     * она закрывается днём раньше начала новой.
     */
    public function addPrice(Service $service, string $price, Carbon $validFrom, ?Carbon $validUntil = null, ?string $cost = null): ServicePrice
    {
        if ((float) $price <= 0) {
            throw new DomainRuleViolation('Цена должна быть больше нуля.');
        }
        if ($cost !== null && $cost !== '' && (float) $cost < 0) {
            throw new DomainRuleViolation('Себестоимость не может быть отрицательной.');
        }
        if ($validUntil && $validUntil->lt($validFrom)) {
            throw new DomainRuleViolation('Дата окончания раньше даты начала.');
        }

        $pending = $this->needsApproval();

        return DB::transaction(function () use ($service, $price, $validFrom, $validUntil, $cost, $pending) {
            $open = $service->prices()->getQuery()->reorder()->whereNull('valid_until')
                ->whereDate('valid_from', '<', $validFrom->toDateString())->lockForUpdate()->first();
            // Неподтверждённая цена ничего не закрывает: предыдущая действует, пока администратор не подтвердит новую.
            if ($open && ! $pending && ! $this->overlapsOthers($service, $validFrom, $validUntil, $open->id)) {
                $open->update(['valid_until' => $validFrom->copy()->subDay()]);
            }

            if ($this->overlapsOthers($service, $validFrom, $validUntil, $pending ? $open?->id : null)) {
                throw new DomainRuleViolation('Период пересекается с существующей ценой этой услуги.');
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
            throw new DomainRuleViolation('Цену подтверждает администратор.');
        }

        return DB::transaction(function () use ($version) {
            $fresh = ServicePrice::query()->lockForUpdate()->findOrFail($version->id);
            if ($fresh->isApproved()) {
                throw new DomainRuleViolation('Цена уже подтверждена.');
            }
            $service = $fresh->service;
            $open = $service->prices()->getQuery()->reorder()->whereKeyNot($fresh->id)->whereNotNull('approved_at')->whereNull('valid_until')
                ->whereDate('valid_from', '<', $fresh->valid_from->toDateString())->lockForUpdate()->first();
            if ($open) {
                $open->update(['valid_until' => $fresh->valid_from->copy()->subDay()]);
            }
            if ($service->prices()->getQuery()->reorder()->whereKeyNot($fresh->id)->whereNotNull('approved_at')
                ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $fresh->valid_from->toDateString()))
                ->when($fresh->valid_until, fn ($q) => $q->whereDate('valid_from', '<=', $fresh->valid_until->toDateString()))->exists()) {
                throw new DomainRuleViolation('Период пересекается с подтверждённой ценой этой услуги — исправьте даты.');
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
                ? 'Цена уже использована в сертификатах — добавьте новую версию.'
                : 'Исправить можно только цену, добавленную сегодня. Для изменения добавьте новую версию.');
        }
        if ((float) $price <= 0) {
            throw new DomainRuleViolation('Цена должна быть больше нуля.');
        }
        if ($cost !== null && $cost !== '' && (float) $cost < 0) {
            throw new DomainRuleViolation('Себестоимость не может быть отрицательной.');
        }
        if ($validUntil && $validUntil->lt($validFrom)) {
            throw new DomainRuleViolation('Дата окончания раньше даты начала.');
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
                throw new DomainRuleViolation('Период пересекается с другой ценой этой услуги.');
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

    private function overlapsOthers(Service $service, Carbon $from, ?Carbon $until, ?int $exceptId = null): bool
    {
        return $service->prices()->getQuery()->reorder()
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $from->toDateString()))
            ->when($until, fn ($q) => $q->whereDate('valid_from', '<=', $until->toDateString()))
            ->exists();
    }
}
