<?php

namespace App\Services;

use App\Exceptions\DomainRuleViolation;
use App\Models\Service;
use App\Models\ServicePrice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Версии цен услуг: поиск цены на дату и добавление новой версии без пересечений.
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
    public function addPrice(Service $service, string $price, Carbon $validFrom, ?Carbon $validUntil = null): ServicePrice
    {
        if ((float) $price <= 0) {
            throw new DomainRuleViolation('Цена должна быть больше нуля.');
        }
        if ($validUntil && $validUntil->lt($validFrom)) {
            throw new DomainRuleViolation('Дата окончания раньше даты начала.');
        }

        return DB::transaction(function () use ($service, $price, $validFrom, $validUntil) {
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
                'currency' => config('gifter.currency'),
                'valid_from' => $validFrom,
                'valid_until' => $validUntil,
                'created_by' => auth()->id(),
            ]);
        });
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
