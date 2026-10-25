<?php

namespace App\Filament\Widgets\Analytics;

use App\Services\AnalyticsReport;
use Filament\Widgets\Widget;

/** Цифры по годам: продажа, оплата, использование, бесплатные. */
class AnalyticsYearsTable extends Widget
{
    protected static bool $isDiscovered = false;

    protected string $view = 'filament.widgets.analytics-years';

    protected int|string|array $columnSpan = 'full';

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $report = app(AnalyticsReport::class)->build(null);

        return ['report' => $report];
    }
}
