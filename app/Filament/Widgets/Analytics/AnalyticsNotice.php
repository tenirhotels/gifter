<?php

namespace App\Filament\Widgets\Analytics;

use App\Services\AnalyticsReport;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

/** Пустое состояние периода и предупреждение о неполных данных (нет даты использования). */
class AnalyticsNotice extends Widget
{
    use Concerns;
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected string $view = 'filament.widgets.analytics-notice';

    protected int|string|array $columnSpan = 'full';

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $report = $this->report();

        return [
            'empty' => app(AnalyticsReport::class)->isEmpty($report),
            'unknownDate' => (int) $report['totals']['used_unknown_date'],
            'period' => $this->period()->label(),
        ];
    }
}
