<?php

namespace App\Filament\Pages;

use App\Enums\Permission;
use App\Filament\Support\TranslatesPageLabels;
use App\Models\AuditLog;
use App\Models\PmsSetting;
use App\Services\Pms\PmsCatalogSync;
use App\Services\Pms\PmsClient;
use App\Services\Pms\PmsException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Подключение к PMS на выбор: Hotel Hub (адрес, код отеля, токен с правом «bookings») или родной Exely
 * (Read Reservation API: идентификатор отеля, client_id, client_secret).
 * Пока подключение выключено — бронь вносится вручную; включено — бронь подтверждается по PMS строго.
 * Токен и client_secret хранятся зашифрованными и не показываются; пустое поле оставляет прежнее значение.
 */
class PmsSettings extends Page
{
    use TranslatesPageLabels;

    protected string $view = 'filament.pages.pms-settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static string|UnitEnum|null $navigationGroup = 'Администрирование';

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationLabel = 'Подключение к PMS';

    protected static ?string $title = 'Подключение к PMS';

    protected static ?string $slug = 'pms-settings';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can(Permission::ManageIntegrations);
    }

    public function mount(): void
    {
        $this->form->fill($this->formState(PmsSetting::current()));
    }

    /** @return array<string, mixed> */
    private function formState(PmsSetting $setting): array
    {
        return ['enabled' => $setting->enabled, 'provider' => $setting->provider ?: PmsSetting::HUB, 'base_url' => $setting->base_url,
            'property_code' => $setting->property_code, 'token' => null, 'client_id' => $setting->client_id, 'client_secret' => null];
    }

    public function form(Schema $schema): Schema
    {
        $current = PmsSetting::current();
        $isExely = fn (Get $get): bool => $get('provider') === PmsSetting::EXELY;

        return $schema->statePath('data')->components([
            Section::make('Источник броней')->description(__('Gifter только читает брони по номеру и ничего не создаёт в PMS. Выберите один источник.'))->schema([
                Toggle::make('enabled')->label('Проверять брони по PMS')
                    ->helperText(__('Включено: бронь подтверждается в PMS, гость и даты берутся оттуда. Выключено: бронь вносится вручную.')),
                Radio::make('provider')->label('Источник')->options([
                    PmsSetting::HUB => __('Hotel Hub — свой сервис отеля (адрес и токен)'),
                    PmsSetting::EXELY => __('Exely напрямую (client_id и client_secret)'),
                ])->default(PmsSetting::HUB)->live()->required(),
                TextInput::make('base_url')->label(fn (Get $get) => $isExely($get) ? __('Адрес Exely') : __('Адрес хаба'))->url()->maxLength(255)
                    ->rule(fn () => app()->isProduction() ? 'starts_with:https://' : 'nullable')
                    ->placeholder(fn (Get $get) => $isExely($get) ? PmsSetting::EXELY_DEFAULT_URL : 'https://hub.example.com')
                    ->helperText(fn (Get $get) => $isExely($get) ? __('Можно оставить пустым — используется :p1.', ['p1' => PmsSetting::EXELY_DEFAULT_URL]) : __('Только адрес сервиса, без «/v1/…» — путь добавится сам.')),
                TextInput::make('property_code')->label(fn (Get $get) => $isExely($get) ? __('Идентификатор отеля в Exely (propertyId)') : __('Код отеля'))->maxLength(64),
                TextInput::make('token')->label('Токен хаба')->password()->autocomplete('off')->maxLength(500)
                    ->visible(fn (Get $get) => ! $isExely($get))
                    ->placeholder(filled($current->token) ? __('•••••••• сохранён — оставьте пустым, чтобы не менять') : '')
                    ->helperText(__('Выдаётся в Hotel Hub, раздел «API и токены». Хранится зашифрованным и не показывается.')),
                TextInput::make('client_id')->label('client_id')->maxLength(255)->autocomplete('off')
                    ->visible($isExely)->helperText(__('Ключи из Exely: «Настройка гостиницы → Подключение API». Доступ к броням включается в Exely отдельно.')),
                TextInput::make('client_secret')->label('client_secret')->password()->autocomplete('off')->maxLength(500)
                    ->visible($isExely)
                    ->placeholder(filled($current->client_secret) ? __('•••••••• сохранён — оставьте пустым, чтобы не менять') : '')
                    ->helperText(__('Хранится зашифрованным и не показывается. Пропуск Gifter получает и обновляет сам.')),
            ])->columns(1),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')->label('Сохранить')->icon('heroicon-o-check')->action(fn () => $this->save()),
            Action::make('syncServices')->label('Обновить услуги из PMS')->icon('heroicon-o-arrow-path')->color('gray')->action(function () {
                try {
                    $stats = app(PmsCatalogSync::class)->run();
                    Notification::make()->success()->title(__('Услуг в PMS: :p1, новых: :p2, изменено: :p3, пропало: :p4', ['p1' => $stats['total'], 'p2' => $stats['new'], 'p3' => $stats['updated'], 'p4' => $stats['missing']]))->send();
                } catch (PmsException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                }
            })->tooltip(__('Загрузит список услуг из Exely. Включать их в сертификаты — в разделе «Услуги PMS»')),
            Action::make('ping')->label('Проверить связь')->icon('heroicon-o-signal')->color('gray')->action(function () {
                $result = PmsClient::make()->ping();
                $note = Notification::make()->title($result['message']);
                $result['ok'] ? $note->success() : $note->danger();
                $note->send();
            })->tooltip(__('Сначала сохраните настройки. Ответ «бронь не найдена» значит, что связь работает')),
        ];
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $setting = PmsSetting::current();
        $before = ['enabled' => $setting->enabled, 'provider' => $setting->provider, 'base_url' => $setting->base_url, 'property_code' => $setting->property_code, 'client_id' => $setting->client_id];
        $setting->fill([
            'enabled' => (bool) ($state['enabled'] ?? false),
            'provider' => in_array($state['provider'] ?? null, [PmsSetting::HUB, PmsSetting::EXELY], true) ? $state['provider'] : PmsSetting::HUB,
            'base_url' => filled($state['base_url'] ?? null) ? rtrim((string) $state['base_url'], '/') : null,
            'property_code' => filled($state['property_code'] ?? null) ? trim((string) $state['property_code']) : null,
            'client_id' => filled($state['client_id'] ?? null) ? trim((string) $state['client_id']) : $setting->client_id,
            'updated_by' => auth()->id(),
        ]);
        $secretsChanged = [];
        foreach (['token', 'client_secret'] as $secret) {
            if (filled($state[$secret] ?? null)) {
                $setting->{$secret} = trim((string) $state[$secret]);
                $secretsChanged[] = $secret;
            }
        }
        if ($setting->enabled && ! $setting->isActive()) {
            Notification::make()->danger()->title($setting->isExely()
                ? __('Чтобы включить проверку, укажите идентификатор отеля, client_id и client_secret.')
                : __('Чтобы включить проверку, укажите адрес, код отеля и токен.'))->send();

            return;
        }
        $setting->save();
        // Секреты в аудит не пишутся — только факт смены.
        AuditLog::record('pms_settings_changed', $setting, $before, [
            'enabled' => $setting->enabled, 'provider' => $setting->provider, 'base_url' => $setting->base_url, 'property_code' => $setting->property_code,
            'client_id' => $setting->client_id, 'secrets_changed' => $secretsChanged,
        ]);
        $this->form->fill($this->formState($setting));
        Notification::make()->success()->title(__('Настройки сохранены'))->send();
    }
}
