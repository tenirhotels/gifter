<?php

namespace App\Filament\Widgets\Analytics;

use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/** Платные и бесплатные сертификаты по количеству и расход на бесплатные. */
class AnalyticsFreeChart extends ChartWidget
{
    use Concerns;
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected ?string $heading = 'Платные и бесплатные';

    protected ?string $maxHeight = '300px';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $r = $this->report();

        return [
            'labels' => $r['labels'],
            'datasets' => [
                ['label' => __('Платные, шт.'), 'data' => $r['sold_count'], 'backgroundColor' => '#5F7872', 'yAxisID' => 'y'],
                ['label' => __('Бесплатные, шт.'), 'data' => $r['free_count'], 'backgroundColor' => '#EAA55A', 'yAxisID' => 'y'],
                ['type' => 'line', 'label' => __('Расход на бесплатные, ₸'), 'data' => $r['free_cost'], 'borderColor' => '#b4452f', 'backgroundColor' => '#b4452f33', 'yAxisID' => 'y1'],
            ],
        ];
    }

    protected function getOptions(): array
    {
        return ['scales' => ['y' => ['position' => 'left', 'beginAtZero' => true], 'y1' => ['position' => 'right', 'beginAtZero' => true, 'grid' => ['drawOnChartArea' => false]]]];
    }
}
