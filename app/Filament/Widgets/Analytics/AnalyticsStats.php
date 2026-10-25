<?php

namespace App\Filament\Widgets\Analytics;

use App\Services\AnalyticsReport;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Ключевые числа периода с динамикой к прошлому периоду и мини-графиками. */
class AnalyticsStats extends StatsOverviewWidget
{
    use Concerns;
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $r = $this->report();
        $prev = $this->previousReport();
        $t = $r['totals'];
        $p = $prev['totals'] ?? null;
        $service = app(AnalyticsReport::class);
        $average = $t['sold_count'] > 0 ? $t['sold'] / $t['sold_count'] : 0.0;
        $usage = $t['sold_count'] > 0 ? round($t['used_count'] / $t['sold_count'] * 100) : 0;
        $net = $t['paid'] - $t['refunded'];
        $outstanding = $service->outstanding();
        $days = $service->averageDaysToUse();

        return [
            $this->stat(__('Продано'), $this->money($t['sold']), __(':p1 серт., средний чек :p2', ['p1' => $t['sold_count'], 'p2' => $this->money($average)]), $this->delta($t['sold'], $p['sold'] ?? null), $r['sold'], 'primary'),
            $this->stat(__('Оплачено'), $this->money($net), __('оплат :p1, возвраты :p2', ['p1' => $this->money($t['paid']), 'p2' => $this->money($t['refunded'])]), $this->delta($net, $p ? $p['paid'] - $p['refunded'] : null),
                array_map(fn ($a, $b) => $a - $b, $r['paid'], $r['refunded']), 'success'),
            $this->stat(__('Использовано'), (string) $t['used_count'], __('на :p1 · :p2% от продаж', ['p1' => $this->money($t['used']), 'p2' => $usage]).($days !== null ? ' · '.__('в среднем через :p1 дн.', ['p1' => number_format($days, 0, ',', ' ')]) : ''),
                $this->delta($t['used_count'], $p['used_count'] ?? null), $r['used_count'], 'info'),
            $this->stat(__('Бесплатных'), (string) $t['free_count'], __('расход :p1', ['p1' => $this->money($t['free_cost'])]), $this->delta($t['free_cost'], $p['free_cost'] ?? null, higherIsBetter: false), $r['free_count'], 'warning'),
            Stat::make(__('Обязательства'), $this->money($outstanding['sum']))->description(__(':p1 действующих непогашенных сертификатов на сегодня', ['p1' => $outstanding['count']]))->color('gray'),
            Stat::make(__('Аннулировано'), (string) $t['cancelled'])->description(__('из выпущенных: :p1', ['p1' => $t['issued']]))->color('gray'),
        ];
    }

    /** @param  array{0: string, 1: string, 2: string}|null  $delta @param  list<float|int>  $chart */
    private function stat(string $label, string $value, string $description, ?array $delta, array $chart, string $color): Stat
    {
        $stat = Stat::make($label, $value)->description($description.($delta ? ' · '.$delta[0] : ''))->color($delta[1] ?? $color);
        if ($delta) {
            $stat->descriptionIcon($delta[2]);
        }
        if (count($chart) > 1 && array_sum($chart) != 0) {
            $stat->chart(array_map(fn ($v) => round((float) $v, 2), $chart))->chartColor($color);
        }

        return $stat;
    }
}
