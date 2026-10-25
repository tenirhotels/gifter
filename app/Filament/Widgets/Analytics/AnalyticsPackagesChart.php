<?php

namespace App\Filament\Widgets\Analytics;

use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/** Какие пакеты покупают чаще всего (платные продажи, топ-10). */
class AnalyticsPackagesChart extends ChartWidget
{
    use Concerns;
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected ?string $heading = 'Популярные пакеты';

    protected ?string $maxHeight = '320px';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $packages = $this->report()['packages'];

        return [
            'labels' => array_map(fn ($p) => $p['name'], $packages),
            'datasets' => [['label' => __('Продано, шт.'), 'data' => array_map(fn ($p) => $p['count'], $packages), 'backgroundColor' => '#5F7872']],
        ];
    }

    protected function getOptions(): array
    {
        return ['indexAxis' => 'y', 'plugins' => ['legend' => ['display' => false]]];
    }
}
