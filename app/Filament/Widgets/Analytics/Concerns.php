<?php

namespace App\Filament\Widgets\Analytics;

use App\Services\AnalyticsReport;
use App\Support\AnalyticsPeriod;
use App\Support\Money;

/** Общее для виджетов аналитики: период и сравнение из фильтров страницы, данные отчёта и форматирование. */
trait Concerns
{
    protected function period(): AnalyticsPeriod
    {
        return AnalyticsPeriod::resolve($this->pageFilters['period'] ?? AnalyticsPeriod::ALL, app(AnalyticsReport::class)->years());
    }

    /** @return array<string, mixed> */
    protected function report(): array
    {
        return app(AnalyticsReport::class)->build($this->period());
    }

    /** Отчёт за предыдущий период, если включено «Сравнить» и сравнение возможно. @return ?array<string, mixed> */
    protected function previousReport(): ?array
    {
        $previous = empty($this->pageFilters['compare']) ? null : $this->period()->previous();

        return $previous ? app(AnalyticsReport::class)->build($previous) : null;
    }

    protected function money(float|int $value): string
    {
        return Money::format(number_format((float) $value, 2, '.', ''));
    }

    /**
     * Изменение к прошлому периоду: [текст, цвет, значок]; `$higherIsBetter` — рост хорош (для расходов наоборот).
     *
     * @return array{0: string, 1: string, 2: string}|null
     */
    protected function delta(float|int $current, float|int|null $previous, bool $higherIsBetter = true): ?array
    {
        if ($previous === null) {
            return null;
        }
        if ((float) $previous == 0.0) {
            return (float) $current == 0.0 ? [__('без изменений'), 'gray', 'heroicon-m-minus'] : [__('нет данных за прошлый период'), 'gray', 'heroicon-m-minus'];
        }
        $pct = ($current - $previous) / abs($previous) * 100;
        $up = $pct >= 0;
        $good = $up === $higherIsBetter;

        return [($up ? '+' : '−').number_format(abs($pct), 1, ',', ' ').'% '.__('к прошлому периоду'), abs($pct) < 0.05 ? 'gray' : ($good ? 'success' : 'danger'), $up ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down'];
    }
}
