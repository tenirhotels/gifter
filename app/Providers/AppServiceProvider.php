<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Models\User;
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

        foreach (Permission::cases() as $permission) {
            Gate::define($permission->value, fn (User $user): bool => $user->hasPermission($permission));
        }
    }
}
