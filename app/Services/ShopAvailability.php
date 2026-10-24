<?php

namespace App\Services;

use App\Models\Package;
use App\Models\Service;
use Illuminate\Support\Carbon;

/**
 * Почему пакет не показывается на сайте. Пустой список — показывается. Условия те же, что у витрины
 * (`PublicShopController::offers`): активен, платный, «Продаётся онлайн», период не закончился, на все услуги состава есть
 * утверждённая цена на сегодня. Нужен, чтобы сотрудник сам видел причину в карточке пакета.
 */
class ShopAvailability
{
    public function __construct(private readonly CertificateManager $certificates) {}

    /** @return list<string> */
    public function problems(Package $package): array
    {
        $problems = [];
        if (! $package->is_active) {
            $problems[] = __('Пакет не активен');
        }
        if ($package->is_complimentary) {
            $problems[] = __('Бесплатные пакеты на сайте не продаются');
        }
        if (! $package->sold_online) {
            $problems[] = __('Не включено «Продаётся онлайн»');
        }
        if ($package->periodEnded()) {
            $problems[] = __('Период пакета закончился :p1', ['p1' => $package->period_until->format('d.m.Y')]);
        }
        $package->loadMissing('items.service');
        if ($package->items->isEmpty()) {
            $problems[] = __('В пакете нет состава (услуг)');

            return $problems;
        }
        foreach ($package->items as $item) {
            if ($reason = $this->priceProblem($item->service)) {
                $problems[] = $reason;
            }
        }
        if ($problems === []) {
            $quote = $this->certificates->quote($package, [], today());
            if ((float) $quote['total'] <= 0) {
                $problems[] = __('Сумма пакета равна нулю');
            }
        }

        return array_values(array_unique($problems));
    }

    /** Нет цены на сегодня у услуги: не задана, ждёт утверждения или начнёт действовать позже. */
    private function priceProblem(Service $service): ?string
    {
        $today = today();
        if ($service->prices()->getQuery()->reorder()->effectiveOn($today)->exists()) {
            return null;
        }
        $name = $service->localizedName('ru');
        if ($service->prices()->whereNull('approved_at')->exists()) {
            return __('Цена услуги «:p1» ждёт утверждения администратором', ['p1' => $name]);
        }
        $later = $service->prices()->getQuery()->reorder()->whereNotNull('approved_at')->whereDate('valid_from', '>', $today->toDateString())->orderBy('valid_from')->first();
        if ($later) {
            return __('Цена услуги «:p1» начинает действовать только с :p2', ['p1' => $name, 'p2' => Carbon::parse($later->valid_from)->format('d.m.Y')]);
        }

        return __('У услуги «:p1» нет действующей цены на сегодня', ['p1' => $name]);
    }
}
