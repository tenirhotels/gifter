<?php

namespace App\Filament\Pages;

use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\PmsSetting;
use App\Services\Pms\PmsClient;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Подключение к PMS: адрес Hotel Hub (или прямого Exely), код отеля и токен с правом «bookings».
 * Пока подключение выключено — бронь вносится вручную; включено — бронь подтверждается по PMS строго.
 * Токен хранится зашифрованным и не показывается; пустое поле оставляет прежний токен.
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
        $setting = PmsSetting::current();
        $this->form->fill(['enabled' => $setting->enabled, 'base_url' => $setting->base_url, 'property_code' => $setting->property_code, 'token' => null]);
    }

    public function form(Schema $schema): Schema
    {
        $hasToken = filled(PmsSetting::current()->token);

        return $schema->statePath('data')->components([
            Section::make('Hotel Hub или Exely')->description('Gifter только читает брони по номеру и ничего не создаёт в PMS. Хаб и прямой Exely отвечают в одном формате.')->schema([
                Toggle::make('enabled')->label('Проверять брони по PMS')
                    ->helperText('Включено: номер брони подтверждается в PMS, гость и даты берутся оттуда; без подтверждения бронь не сохраняется. Выключено: бронь вносится вручную.'),
                TextInput::make('base_url')->label('Адрес')->url()->placeholder('https://hub.example.com')->maxLength(255)
                    ->helperText('Без «/v1/…»: путь /v1/properties/{код}/bookings/{номер} добавляется автоматически.'),
                TextInput::make('property_code')->label('Код отеля')->maxLength(64),
                TextInput::make('token')->label('Токен')->password()->autocomplete('off')->maxLength(500)
                    ->placeholder($hasToken ? '•••••••• сохранён — оставьте пустым, чтобы не менять' : '')
                    ->helperText('Токен с правом «bookings» (хаб: «API и токены»). Хранится зашифрованным, в панели не показывается.'),
                Text::make($hasToken ? 'Токен сохранён.' : 'Токен ещё не задан.'),
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
        $before = ['enabled' => $setting->enabled, 'base_url' => $setting->base_url, 'property_code' => $setting->property_code];
        $setting->fill([
            'enabled' => (bool) ($state['enabled'] ?? false),
            'base_url' => filled($state['base_url'] ?? null) ? rtrim((string) $state['base_url'], '/') : null,
            'property_code' => filled($state['property_code'] ?? null) ? trim((string) $state['property_code']) : null,
            'updated_by' => auth()->id(),
        ]);
        $tokenChanged = filled($state['token'] ?? null);
        if ($tokenChanged) {
            $setting->token = trim((string) $state['token']);
        }
        if ($setting->enabled && ! ($setting->base_url && $setting->property_code && filled($setting->token))) {
            Notification::make()->danger()->title('Чтобы включить проверку, укажите адрес, код отеля и токен.')->send();

            return;
        }
        $setting->save();
        // Токен в аудит не пишется — только факт смены.
        AuditLog::record('pms_settings_changed', $setting, $before, [
            'enabled' => $setting->enabled, 'base_url' => $setting->base_url, 'property_code' => $setting->property_code, 'token_changed' => $tokenChanged,
        ]);
        $this->form->fill(['enabled' => $setting->enabled, 'base_url' => $setting->base_url, 'property_code' => $setting->property_code, 'token' => null]);
        Notification::make()->success()->title('Настройки сохранены')->send();
    }
}
