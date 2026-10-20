<?php

namespace App\Filament\Support;

use App\Enums\Role;
use App\Support\DashboardQueries;
use Filament\Schemas\Components\Component;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

/**
 * Очередь работы для роли: только то, что требует действия сейчас, каждая
 * плитка ведёт в отфильтрованный список. Плитка с ненулевым «требует действия» —
 * с тонкой рамкой и иконкой своего цвета; ноль — приглушён. Стили — resources/views/filament/app-styles.blade.php.
 */
abstract class RoleQueueWidget extends StatsOverviewWidget
{
    protected ?string $pollingInterval = '60s';

    protected int|array|null $columns = ['default' => 1, 'md' => 2, 'xl' => 4];

    /** @return list<Role> */
    abstract protected static function roles(): array;

    /** Иконка заголовка секции роли. */
    abstract protected function headingIcon(): string;

    protected function getHeading(): ?string
    {
        return $this->heading ? __($this->heading) : null;
    }

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->hasRole(...static::roles());
    }

    public function getSectionContentComponent(): Component
    {
        return parent::getSectionContentComponent()->icon($this->headingIcon())->iconColor('primary');
    }

    protected function q(): DashboardQueries
    {
        return new DashboardQueries(auth()->user());
    }

    /** Плитка-счётчик; при значении > 0 окрашивается в $color (серый — без полосы), ноль приглушён. */
    protected function counter(string $label, Builder $query, ?string $url, string $color, ?string $hint, string $icon): Stat
    {
        $count = $query->count();

        return $this->tile(Stat::make(__($label), $count)->description($hint ? __($hint) : null)->url($url), $icon, $count > 0 ? $color : 'zero', $count > 0 && $color !== 'gray');
    }

    /** Показатель (сумма, количество за период) — без «требует действия». */
    protected function metric(string $label, string|int $value, ?string $hint, string $icon): Stat
    {
        return $this->tile(Stat::make(__($label), $value)->description($hint ? __($hint) : null), $icon, 'metric', false);
    }

    protected function tile(Stat $stat, string $icon, string $tone, bool $active): Stat
    {
        return $stat->icon($icon)
            ->color(in_array($tone, ['metric', 'zero'], true) ? 'gray' : $tone)
            ->extraAttributes(array_filter([
                'class' => 'gf-stat',
                'data-tone' => $tone,
                'data-active' => $active ? 'true' : null,
            ]));
    }
}
