<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Таблицы: плотные, с чередованием строк, по 25 записей — для операционной работы.
        Table::configureUsing(fn (Table $table) => $table
            ->striped()
            ->paginationPageOptions([25, 50, 100])
            ->defaultPaginationPageOption(25));

        // Даты — в одном формате ДД.ММ.ГГГГ во всех полях (а не по языку браузера), неделя с понедельника.
        DatePicker::configureUsing(fn (DatePicker $picker) => $picker
            ->native(false)->displayFormat('d.m.Y')->firstDayOfWeek(1)->closeOnDateSelection());
        DateTimePicker::configureUsing(fn (DateTimePicker $picker) => $picker
            ->native(false)->displayFormat('d.m.Y H:i')->firstDayOfWeek(1)->seconds(false));

        foreach (Permission::cases() as $permission) {
            Gate::define($permission->value, fn (User $user): bool => $user->hasPermission($permission));
        }
    }
}
