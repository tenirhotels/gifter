<?php

namespace App\Filament\Widgets\Analytics;

use App\Support\Money;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AnalyticsStats extends StatsOverviewWidget
{
    use Concerns;
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $t = $this->report()['totals'];
        $money = fn (float $v) => Money::format(number_format($v, 2, '.', ''));
        $average = $t['sold_count'] > 0 ? $t['sold'] / $t['sold_count'] : 0.0;
        $usage = $t['sold_count'] > 0 ? round($t['used_count'] / $t['sold_count'] * 100) : 0;

        return [
            Stat::make(__('Продано'), $money($t['sold']))->description(__(':p1 серт., средний чек :p2', ['p1' => $t['sold_count'], 'p2' => $money($average)]))->color('primary'),
            Stat::make(__('Оплачено'), $money($t['paid'] - $t['refunded']))->description(__('оплат :p1, возвраты :p2', ['p1' => $money($t['paid']), 'p2' => $money($t['refunded'])]))->color('success'),
            Stat::make(__('Использовано'), $t['used_count'])->description(__('на :p1 · :p2% от продаж', ['p1' => $money($t['used']), 'p2' => $usage]).($t['used_unknown_date'] ? ' · '.__('без даты: :p1', ['p1' => $t['used_unknown_date']]) : ''))->color('info'),
            Stat::make(__('Бесплатных'), $t['free_count'])->description(__('расход :p1', ['p1' => $money($t['free_cost'])]))->color('warning'),
            Stat::make(__('Аннулировано'), $t['cancelled'])->description(__('из выпущенных: :p1', ['p1' => $t['issued']]))->color('gray'),
        ];
    }
}
