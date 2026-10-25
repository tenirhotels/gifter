<?php

namespace App\Filament\Widgets\Analytics;

use App\Services\AnalyticsReport;
use App\Support\Money;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Что стало с сертификатами, проданными в каждом году: использованы, истекли, аннулированы или ещё действуют. */
class AnalyticsCohortsTable extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Судьба сертификатов по году продажи'))
            ->description(__('Доля использованных и истёкших («несгоревший остаток») показывает, сколько проданных сертификатов реально превращается в проживание.'))
            ->records(fn () => collect(app(AnalyticsReport::class)->cohorts())->reverse()->mapWithKeys(fn ($r) => [$r['year'] => $r + ['label' => (string) $r['year']]])->all())
            ->paginated(false)
            ->emptyStateHeading(__('Нет данных'))
            ->columns([
                TextColumn::make('label')->label('Год продажи')->weight('bold'),
                TextColumn::make('count')->label('Продано, шт.')->alignEnd(),
                TextColumn::make('sum')->label('На сумму')->alignEnd()->formatStateUsing(fn ($v) => Money::format(number_format((float) $v, 2, '.', ''))),
                TextColumn::make('used')->label('Использовано')->alignEnd()->description(fn ($record) => $record['used_pct'].'%'),
                TextColumn::make('expired')->label('Истекло')->alignEnd(),
                TextColumn::make('cancelled')->label('Аннулировано')->alignEnd(),
                TextColumn::make('active')->label('Ещё действуют')->alignEnd(),
            ]);
    }
}
