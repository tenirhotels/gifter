<?php

namespace App\Providers\Filament;

use App\Filament\Auth\EditProfile;
use App\Filament\Support\InitialsAvatarProvider;
use App\Http\Middleware\SetLocale;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            // Двухфакторный вход обязателен всем: без настроенного приложения в панель не попасть.
            ->profile(EditProfile::class, isSimple: false)
            ->multiFactorAuthentication([
                AppAuthentication::make()->brandName('Gifter')->recoverable(),
            ], isRequired: fn (): bool => config('gifter.security.require_two_factor') || ! app()->isLocal())
            ->brandName('Gifter')
            ->colors([
                'primary' => Color::Amber,
            ])
            ->navigationGroups(collect(['Продажи', 'Деньги', 'Каталог', 'Администрирование'])
                ->mapWithKeys(fn (string $name) => [$name => NavigationGroup::make()->label(fn () => __($name))])->all())
            // Рабочее приложение: плотность, скорость, предсказуемость — см. docs/ui-principles.md
            ->maxContentWidth(Width::Full)
            ->sidebarCollapsibleOnDesktop()
            ->spa()
            ->unsavedChangesAlerts()
            ->databaseTransactions()
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn (): View => view('filament.language-switcher'))
            ->renderHook(PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE, fn (): View => view('filament.language-switcher'))
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): View => view('filament.app-styles'))
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                SetLocale::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
