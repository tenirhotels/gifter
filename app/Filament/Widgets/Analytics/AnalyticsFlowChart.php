<?php

namespace App\Filament\Widgets\Analytics;

use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/** Продажа, оплата и использование (в тенге) по месяцам года или по годам. */
class AnalyticsFlowChart extends ChartWidget
{
    use Concerns;
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected ?string $heading = 'Продажа, оплата и использование';

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '340px';

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $r = $this->report();
        $net = array_map(fn ($paid, $refund) => round($paid - $refund, 2), $r['paid'], $r['refunded']);

        return [
            'labels' => $r['labels'],
            'datasets' => [
                ['label' => __('Продано, ₸'), 'data' => $r['sold'], 'borderColor' => '#5F7872', 'backgroundColor' => '#5F787233', 'tension' => 0.25],
                ['label' => __('Оплачено (за вычетом возвратов), ₸'), 'data' => $net, 'borderColor' => '#EAA55A', 'backgroundColor' => '#EAA55A33', 'tension' => 0.25],
                ['label' => __('Использовано, ₸'), 'data' => $r['used'], 'borderColor' => '#2f6f9f', 'backgroundColor' => '#2f6f9f33', 'tension' => 0.25],
            ],
        ];
    }
}
