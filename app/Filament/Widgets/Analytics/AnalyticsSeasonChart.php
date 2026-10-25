<?php

namespace App\Filament\Widgets\Analytics;

use App\Services\AnalyticsReport;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

/** Сезонность: сколько платных сертификатов в среднем продаётся в каждом месяце года (за все годы). */
class AnalyticsSeasonChart extends ChartWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $heading = 'Сезонность продаж';

    protected ?string $maxHeight = '300px';

    protected function getType(): string
    {
        return 'bar';
    }

    public function getDescription(): ?string
    {
        return __('Среднее число платных продаж по месяцам за все годы.');
    }

    public function getEmptyStateHeading(): string
    {
        return __('Нет продаж');
    }

    protected function getData(): array
    {
        $values = app(AnalyticsReport::class)->seasonality();
        if (array_sum($values) == 0) {
            return [];
        }

        return [
            'labels' => array_map(fn ($m) => ucfirst(Carbon::create(2000, $m, 1)->translatedFormat('M')), range(1, 12)),
            'datasets' => [['label' => __('Продаж в среднем'), 'data' => $values, 'backgroundColor' => '#5F7872']],
        ];
    }

    protected function getOptions(): array|RawJs|null
    {
        return RawJs::make('{ plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }');
    }
}
