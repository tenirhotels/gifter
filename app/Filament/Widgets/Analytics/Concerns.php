<?php

namespace App\Filament\Widgets\Analytics;

use App\Services\AnalyticsReport;

/** Общее для виджетов аналитики: выбранный год из фильтра страницы и сами данные. */
trait Concerns
{
    /** @return array<string, mixed> */
    protected function report(): array
    {
        $year = $this->pageFilters['year'] ?? null;

        return app(AnalyticsReport::class)->build(filled($year) ? (int) $year : null);
    }
}
