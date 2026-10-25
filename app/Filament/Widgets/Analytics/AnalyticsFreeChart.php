<?php

namespace App\Filament\Widgets\Analytics;

use App\Services\AnalyticsReport;
use Filament\Support\RawJs;
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

    public function getEmptyStateHeading(): string
    {
        return __('Нет данных за период');
    }

    protected function getData(): array
    {
        $r = $this->report();
        if (app(AnalyticsReport::class)->isEmpty($r)) {
            return [];
        }

        return [
            'labels' => $r['labels'],
            'datasets' => [
                ['label' => __('Платные, шт.'), 'data' => $r['sold_count'], 'backgroundColor' => '#5F7872', 'yAxisID' => 'y'],
                ['label' => __('Бесплатные, шт.'), 'data' => $r['free_count'], 'backgroundColor' => '#EAA55A', 'yAxisID' => 'y'],
                ['type' => 'line', 'label' => __('Расход на бесплатные, ₸'), 'data' => $r['free_cost'], 'borderColor' => '#b4452f', 'backgroundColor' => '#b4452f33', 'yAxisID' => 'y1'],
            ],
        ];
    }

    protected function getOptions(): array|RawJs|null
    {
        return RawJs::make(<<<'JS'
        {
            interaction: { mode: 'index', intersect: false },
            plugins: { tooltip: { callbacks: { label: (ctx) => ctx.dataset.label + ': ' + new Intl.NumberFormat('ru-RU').format(ctx.parsed.y) } } },
            scales: {
                y: { position: 'left', beginAtZero: true, ticks: { precision: 0 } },
                y1: { position: 'right', beginAtZero: true, grid: { drawOnChartArea: false }, ticks: { callback: (v) => new Intl.NumberFormat('ru-RU', { notation: 'compact' }).format(v) } },
            },
        }
        JS);
    }
}
