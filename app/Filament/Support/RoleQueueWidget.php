<?php

namespace App\Filament\Support;

use App\Enums\Role;
use App\Support\DashboardQueries;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

/**
 * Очередь работы для роли: только то, что требует действия сейчас, каждая
 * плитка ведёт в отфильтрованный список. Ненулевые «требует действия» — цветом.
 */
abstract class RoleQueueWidget extends StatsOverviewWidget
{
    protected ?string $pollingInterval = '60s';

    /** @return list<Role> */
    abstract protected static function roles(): array;

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->hasRole(...static::roles());
    }

    protected function q(): DashboardQueries
    {
        return new DashboardQueries(auth()->user());
    }

    /** Плитка-счётчик; при значении > 0 окрашивается в $color. */
    protected function counter(string $label, Builder $query, ?string $url, string $color = 'warning', ?string $hint = null): Stat
    {
        $count = $query->count();

        return Stat::make($label, $count)
            ->description($hint)
            ->color($count > 0 ? $color : 'gray')
            ->url($url);
    }
}
