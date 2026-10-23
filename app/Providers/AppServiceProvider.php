<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Http\Controllers\PublicShopController;
use App\Models\User;
use App\Support\TrustedProxies;
use Filament\Actions\Action as BaseAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Schemas\Components\Component as SchemaComponent;
use Filament\Tables\Columns\Column;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Table;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
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
        // Каким прокси доверять заголовки X-Forwarded-* (настройка TRUSTED_PROXIES; по умолчанию как раньше).
        TrustProxies::at(TrustedProxies::resolve(config('gifter.security.trusted_proxies')));

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

        // Подписи колонок, фильтров, полей, разделов и кнопок переводятся по русскому тексту (lang/kk.json, lang/en.json).
        Column::configureUsing(fn (Column $column) => $column->translateLabel());
        BaseFilter::configureUsing(fn (BaseFilter $filter) => $filter->translateLabel());
        SchemaComponent::configureUsing(fn (SchemaComponent $component) => method_exists($component, 'translateLabel') ? $component->translateLabel() : $component);
        BaseAction::configureUsing(fn (BaseAction $action) => $action->translateLabel());

        // Сайт-визитка: нет пакетов на продажу — публичные страницы без выбора сертификата (считается один раз на запрос).
        View::composer('shop.*', function ($view) {
            static $visitka = null;
            $visitka ??= app(PublicShopController::class)->offers()->isEmpty();
            $view->with('visitka', $visitka);
        });

        foreach (Permission::cases() as $permission) {
            Gate::define($permission->value, fn (User $user): bool => $user->hasPermission($permission));
        }
    }
}
