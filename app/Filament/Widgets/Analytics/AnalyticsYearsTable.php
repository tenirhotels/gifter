<?php

namespace App\Filament\Widgets\Analytics;

use App\Services\AnalyticsReport;
use App\Support\AnalyticsPeriod;
use App\Support\Money;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Цифры по годам: продажа, оплата, использование, бесплатные; динамика к прошлому году и итого. */
class AnalyticsYearsTable extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = null;

    /** @return array<int|string, array<string, mixed>> */
    public function rows(): array
    {
        $service = app(AnalyticsReport::class);
        $r = $service->build(AnalyticsPeriod::yearly($service->years()));
        $rows = [];
        $total = ['label' => __('Итого'), 'sold' => 0.0, 'count' => 0, 'paid' => 0.0, 'used' => 0.0, 'used_count' => 0, 'free' => 0, 'free_cost' => 0.0, 'delta' => null];
        foreach ($r['labels'] as $i => $label) {
            $paid = $r['paid'][$i] - $r['refunded'][$i];
            $prevSold = $i > 0 ? $r['sold'][$i - 1] : null;
            $rows[$label] = [
                'label' => (string) $label, 'sold' => $r['sold'][$i], 'count' => $r['sold_count'][$i], 'paid' => $paid, 'used' => $r['used'][$i], 'used_count' => $r['used_count'][$i],
                'free' => $r['free_count'][$i], 'free_cost' => $r['free_cost'][$i],
                'delta' => $prevSold ? round(($r['sold'][$i] - $prevSold) / $prevSold * 100, 1) : null,
            ];
            $total['sold'] += $r['sold'][$i];
            $total['count'] += $r['sold_count'][$i];
            $total['paid'] += $paid;
            $total['used'] += $r['used'][$i];
            $total['used_count'] += $r['used_count'][$i];
            $total['free'] += $r['free_count'][$i];
            $total['free_cost'] += $r['free_cost'][$i];
        }
        $rows = array_reverse($rows, true);
        $rows['total'] = $total;

        return $rows;
    }

    public function table(Table $table): Table
    {
        $money = fn ($v) => Money::format(number_format((float) $v, 2, '.', ''));

        return $table
            ->heading(__('По годам'))
            ->description(__('Продажа — по дате продажи, оплата — по дате оплаты (за вычетом возвратов), использование — по заезду или отметке в системе.'))
            ->records(fn () => $this->rows())
            ->paginated(false)
            ->emptyStateHeading(__('Нет данных'))
            ->columns([
                TextColumn::make('label')->label('Год')->weight('bold'),
                TextColumn::make('sold')->label('Продано')->alignEnd()->formatStateUsing($money),
                TextColumn::make('count')->label('Шт.')->alignEnd(),
                TextColumn::make('delta')->label('К прошлому году')->alignEnd()->placeholder('—')
                    ->formatStateUsing(fn ($state) => ($state >= 0 ? '+' : '−').number_format(abs((float) $state), 1, ',', ' ').'%')
                    ->color(fn ($state) => $state === null ? 'gray' : ($state >= 0 ? 'success' : 'danger')),
                TextColumn::make('paid')->label('Оплачено')->alignEnd()->formatStateUsing($money),
                TextColumn::make('used')->label('Использовано')->alignEnd()->formatStateUsing($money),
                TextColumn::make('used_count')->label('Использовано, шт.')->alignEnd(),
                TextColumn::make('free')->label('Бесплатных')->alignEnd(),
                TextColumn::make('free_cost')->label('Расход на бесплатные')->alignEnd()->formatStateUsing($money),
            ]);
    }
}
