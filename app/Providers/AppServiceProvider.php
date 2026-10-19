<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // На рабочем сервере — только HTTPS.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Пароли: от 12 символов, буквы и цифры. Проверка по базам утечек не включена:
        // на shared-хостинге исходящие запросы к внешним сервисам могут быть закрыты.
        Password::defaults(fn () => Password::min(12)->letters()->numbers());

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
