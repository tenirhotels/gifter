<?php

namespace App\Filament\Widgets\Analytics;

use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/** Откуда сертификаты: сайт, продавцы, бесплатные. */
class AnalyticsChannelsChart extends ChartWidget
{
    use Concerns;
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected ?string $heading = 'Каналы продаж';

    protected ?string $maxHeight = '320px';

    protected function getType(): string
    {
        return 'doughnut';
    }

    public function getEmptyStateHeading(): string
    {
        return __('Нет данных за период');
    }

    protected function getData(): array
    {
        $channels = $this->report()['channels'];
        if ($channels === []) {
            return [];
        }

        return [
            'labels' => array_keys($channels),
            'datasets' => [['data' => array_values($channels), 'backgroundColor' => ['#5F7872', '#EAA55A', '#2f6f9f', '#b4452f', '#8a9b97']]],
        ];
    }
}
