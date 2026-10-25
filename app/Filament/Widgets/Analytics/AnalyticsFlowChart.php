<?php

namespace App\Filament\Widgets\Analytics;

use App\Services\AnalyticsReport;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/** Продажа, оплата и использование (в тенге) по месяцам или годам, плюс продажи нарастающим итогом (по клику в легенде). */
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

    public function getDescription(): ?string
    {
        return __('Продажа — по дате продажи, оплата — по дате оплаты, использование — по заезду или отметке в системе.');
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
        $net = array_map(fn ($paid, $refund) => round($paid - $refund, 2), $r['paid'], $r['refunded']);
        $running = 0.0;
        $cumulative = array_map(function ($v) use (&$running) {
            $running += $v;

            return round($running, 2);
        }, $r['sold']);

        return [
            'labels' => $r['labels'],
            'datasets' => [
                ['label' => __('Продано, ₸'), 'data' => $r['sold'], 'borderColor' => '#5F7872', 'backgroundColor' => '#5F787233', 'tension' => 0.25, 'fill' => true],
                ['label' => __('Оплачено (за вычетом возвратов), ₸'), 'data' => $net, 'borderColor' => '#EAA55A', 'backgroundColor' => '#EAA55A33', 'tension' => 0.25],
                ['label' => __('Использовано, ₸'), 'data' => $r['used'], 'borderColor' => '#2f6f9f', 'backgroundColor' => '#2f6f9f33', 'tension' => 0.25],
                ['label' => __('Продано нарастающим итогом, ₸'), 'data' => $cumulative, 'borderColor' => '#8a9b97', 'borderDash' => [6, 4], 'pointRadius' => 0, 'tension' => 0.25, 'hidden' => true],
            ],
        ];
    }

    protected function getOptions(): array|RawJs|null
    {
        return RawJs::make(<<<'JS'
        {
            interaction: { mode: 'index', intersect: false },
            plugins: { tooltip: { callbacks: { label: (ctx) => ctx.dataset.label.replace(', ₸', '') + ': ' + new Intl.NumberFormat('ru-RU').format(ctx.parsed.y) + ' ₸' } } },
            scales: { y: { beginAtZero: true, ticks: { callback: (v) => new Intl.NumberFormat('ru-RU', { notation: 'compact' }).format(v) + ' ₸' } } },
        }
        JS);
    }
}
