<?php

namespace App\Services;

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

        return DB::transaction(function () use ($service, $price, $validFrom, $validUntil, $cost) {
            $open = $service->prices()->getQuery()->reorder()->whereNull('valid_until')
                ->whereDate('valid_from', '<', $validFrom->toDateString())->lockForUpdate()->first();
            if ($open && ! $this->overlapsOthers($service, $validFrom, $validUntil, $open->id)) {
                $open->update(['valid_until' => $validFrom->copy()->subDay()]);
            }

            if ($this->overlapsOthers($service, $validFrom, $validUntil)) {
                throw new DomainRuleViolation('Период пересекается с существующей ценой этой услуги.');
            }

            return $service->prices()->create([
                'price' => $price,
                'cost' => $cost === '' ? null : $cost,
                'currency' => config('gifter.currency'),
                'valid_from' => $validFrom,
                'valid_until' => $validUntil,
                'created_by' => auth()->id(),
            ]);
        });
    }

    /**
     * Исправить версию цены — только в день её добавления и пока она не попала
     * в сертификаты. Более старые версии не меняются: только новая версия.
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
            ]);

            return $version;
        });
    }

    public function isEditable(ServicePrice $version): bool
    {
        return $version->created_at?->isToday() && ! $version->certificateItems()->exists();
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
