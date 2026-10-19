<?php

namespace App\Filament\Pages;

use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\PmsSetting;
use App\Services\Pms\PmsClient;
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
            Section::make('Источник броней')->description('Gifter только читает брони по номеру и ничего не создаёт в PMS. Оба источника отвечают в одном формате — выберите один.')->schema([
                Toggle::make('enabled')->label('Проверять брони по PMS')
                    ->helperText('Включено: номер брони подтверждается в PMS, гость и даты берутся оттуда; без подтверждения бронь не сохраняется. Выключено: бронь вносится вручную.'),
                Radio::make('provider')->label('Источник')->options([
                    PmsSetting::HUB => 'Hotel Hub — свой сервис отеля (адрес и токен)',
                    PmsSetting::EXELY => 'Exely напрямую — Read Reservation API (client_id и client_secret)',
                ])->default(PmsSetting::HUB)->live()->required(),
                TextInput::make('base_url')->label(fn (Get $get) => $isExely($get) ? 'Адрес Exely' : 'Адрес хаба')->url()->maxLength(255)
                    ->placeholder(fn (Get $get) => $isExely($get) ? PmsSetting::EXELY_DEFAULT_URL : 'https://hub.example.com')
                    ->helperText(fn (Get $get) => $isExely($get) ? 'Можно оставить пустым — используется '.PmsSetting::EXELY_DEFAULT_URL.'.' : 'Без «/v1/…»: путь /v1/properties/{код}/bookings/{номер} добавляется автоматически.'),
                TextInput::make('property_code')->label(fn (Get $get) => $isExely($get) ? 'Идентификатор отеля в Exely (propertyId)' : 'Код отеля')->maxLength(64),
                TextInput::make('token')->label('Токен хаба')->password()->autocomplete('off')->maxLength(500)
                    ->visible(fn (Get $get) => ! $isExely($get))
                    ->placeholder(filled($current->token) ? '•••••••• сохранён — оставьте пустым, чтобы не менять' : '')
                    ->helperText('Токен с правом «bookings» (хаб: «API и токены»). Хранится зашифрованным, в панели не показывается.'),
                TextInput::make('client_id')->label('client_id')->maxLength(255)->autocomplete('off')
                    ->visible($isExely)->helperText('Ключи подключения Exely: «Настройка гостиницы → Подключение API». Доступ к Read Reservation API включается в Exely отдельно.'),
                TextInput::make('client_secret')->label('client_secret')->password()->autocomplete('off')->maxLength(500)
                    ->visible($isExely)
                    ->placeholder(filled($current->client_secret) ? '•••••••• сохранён — оставьте пустым, чтобы не менять' : '')
                    ->helperText('Хранится зашифрованным, в панели не показывается. Токен доступа Gifter получает сам и обновляет каждые 15 минут.'),
            ])->columns(1),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')->label('Сохранить')->icon('heroicon-o-check')->action(fn () => $this->save()),
            Action::make('ping')->label('Проверить связь')->icon('heroicon-o-signal')->color('gray')->action(function () {
                $result = PmsClient::make()->ping();
                $note = Notification::make()->title($result['message']);
                $result['ok'] ? $note->success() : $note->danger();
                $note->send();
            })->tooltip('Сохраните настройки, затем проверьте: запрашивается несуществующая бронь, ответ «не найдена» означает, что связь и токен в порядке'),
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
                ? 'Чтобы включить проверку, укажите идентификатор отеля, client_id и client_secret.'
                : 'Чтобы включить проверку, укажите адрес, код отеля и токен.')->send();

            return;
        }
        $setting->save();
        // Секреты в аудит не пишутся — только факт смены.
        AuditLog::record('pms_settings_changed', $setting, $before, [
            'enabled' => $setting->enabled, 'provider' => $setting->provider, 'base_url' => $setting->base_url, 'property_code' => $setting->property_code,
            'client_id' => $setting->client_id, 'secrets_changed' => $secretsChanged,
        ]);
        $this->form->fill($this->formState($setting));
        Notification::make()->success()->title('Настройки сохранены')->send();
    }
}
