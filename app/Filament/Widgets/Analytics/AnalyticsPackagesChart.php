<?php

namespace App\Filament\Widgets\Analytics;

use Filament\Support\RawJs;
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

    public function getEmptyStateHeading(): string
    {
        return __('Нет продаж за период');
    }

    protected function getData(): array
    {
        $packages = $this->report()['packages'];
        if ($packages === []) {
            return [];
        }

        return [
            'labels' => array_map(fn ($p) => $p['name'], $packages),
            'datasets' => [['label' => __('Продано, шт.'), 'data' => array_map(fn ($p) => $p['count'], $packages), 'backgroundColor' => '#5F7872']],
        ];
    }

    protected function getOptions(): array|RawJs|null
    {
        return RawJs::make(<<<'JS'
        {
            indexAxis: 'y',
            plugins: { legend: { display: false }, tooltip: { callbacks: { label: (ctx) => new Intl.NumberFormat('ru-RU').format(ctx.parsed.x) + ' шт.' } } },
            scales: { x: { beginAtZero: true, ticks: { precision: 0 } } },
        }
        JS);
    }
}
