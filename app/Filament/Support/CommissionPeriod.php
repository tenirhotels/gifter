<?php

namespace App\Filament\Support;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/** Фильтр «Период погашения»: комиссии выплачиваются в месяц погашения сертификата (`earned_at`). */
class CommissionPeriod
{
    /** @return array{0: ?string, 1: ?string} */
    public static function range(?array $data): array
    {
        $preset = $data['preset'] ?? null;
        if ($preset === 'this') {
            return [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()];
        }
        if ($preset === 'last') {
            return [now()->subMonthNoOverflow()->startOfMonth()->toDateString(), now()->subMonthNoOverflow()->endOfMonth()->toDateString()];
        }

        return [$data['from'] ?? null, $data['until'] ?? null];
    }

    /** Фильтр по дате погашения; `$column` — столбец с датой начисления, `$relation` — связь, если таблица не комиссий. */
    public static function filter(string $column = 'earned_at', ?string $relation = null): Filter
    {
        return Filter::make('period')->label('Период использования')
            ->schema([
                Select::make('preset')->label('Месяц использования')->options(['this' => 'Этот месяц', 'last' => 'Прошлый месяц'])->placeholder('Свой период'),
                DatePicker::make('from')->label('С')->native(false)->displayFormat('d.m.Y'),
                DatePicker::make('until')->label('По')->native(false)->displayFormat('d.m.Y'),
            ])
            ->query(function (Builder $query, array $data) use ($column, $relation) {
                [$from, $to] = self::range($data);
                $apply = fn ($q) => $q->when($from, fn ($q) => $q->whereDate($column, '>=', $from))->when($to, fn ($q) => $q->whereDate($column, '<=', $to));

                return $relation ? $query->whereHas($relation, $apply) : $apply($query);
            })
            ->indicateUsing(function (array $data): ?string {
                [$from, $to] = self::range($data);

                return $from || $to ? 'Использовано: '.($from ? Carbon::parse($from)->format('d.m.Y') : '…').' – '.($to ? Carbon::parse($to)->format('d.m.Y') : '…') : null;
            });
    }
}
