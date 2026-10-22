<?php

namespace App\Filament\Pages;

use App\Enums\Permission;
use App\Filament\Support\TranslatesPageLabels;
use App\Models\AuditLog;
use App\Models\ShopSetting;
use App\Support\PhoneNumber;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * «Настройки сайта» — оформление публичной страницы: главное фото, заголовок, акцентный цвет (из брендбука).
 * Пустые поля — значения по умолчанию. Фото пакетов загружаются в карточке пакета.
 */
class ShopSettings extends Page
{
    use TranslatesPageLabels;

    protected string $view = 'filament.pages.shop-settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static string|UnitEnum|null $navigationGroup = 'Администрирование';

    protected static ?int $navigationSort = 40;

    protected static ?string $navigationLabel = 'Настройки сайта';

    protected static ?string $title = 'Настройки сайта';

    protected static ?string $slug = 'shop-settings';

    private const TRACKED = ['hero_image_path', 'hero_title', 'hero_subtitle', 'accent_color', 'ga4_id', 'metrika_id', 'whatsapp_phone', 'operator_name', 'offer_url'];

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can(Permission::ManageIntegrations);
    }

    public function mount(): void
    {
        $setting = ShopSetting::current();
        $this->form->fill([
            'hero_image_path' => $setting->hero_image_path ? ['shop/'.$setting->hero_image_path] : [],
            'hero_title' => $setting->hero_title, 'hero_subtitle' => $setting->hero_subtitle, 'accent_color' => $setting->accent(),
            'ga4_id' => $setting->ga4_id, 'metrika_id' => $setting->metrika_id, 'whatsapp_phone' => $setting->whatsapp_phone,
            'operator_name' => $setting->operator_name, 'offer_url' => $setting->offer_url,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Главная страница')->description(__('Что видит клиент на странице заявки: фото, заголовок и цвет бренда.'))->schema([
                FileUpload::make('hero_image_path')->label('Главное фото')->image()->disk('public')->directory('shop')->visibility('public')
                    ->maxSize(4096)->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])->maxFiles(1)
                    ->helperText(__('Фото отеля, лучше горизонтальное, не меньше 1600 px; до 4 МБ.')),
                TextInput::make('hero_title')->label('Заголовок')->maxLength(120)->placeholder(__('Подарок, который хочется открыть')),
                TextInput::make('hero_subtitle')->label('Подзаголовок')->maxLength(300),
                ColorPicker::make('accent_color')->label('Акцентный цвет')->helperText(__('Один цвет бренда для кнопок и линий; по умолчанию тёмно-зелёный из брендбука.')),
            ])->columns(1),
            Section::make('Документы')->description(__('Политика конфиденциальности на сайте ссылается на эти данные.'))->schema([
                TextInput::make('operator_name')->label('Оператор персональных данных')->maxLength(150)->placeholder(ShopSetting::DEFAULT_OPERATOR)
                    ->helperText(__('Пусто — :p1.', ['p1' => ShopSetting::DEFAULT_OPERATOR])),
                TextInput::make('offer_url')->label('Адрес публичной оферты')->url()->maxLength(255)->placeholder('https://…')
                    ->rule(fn () => fn (string $attribute, $value, \Closure $fail) => blank($value) || str_starts_with((string) $value, 'https://') ? null : $fail(__('Адрес должен начинаться с https://')))
                    ->helperText(__('Ссылка на уже действующий документ. Пусто — ссылка в политике не показывается.')),
            ])->columns(1),
            Section::make('Аналитика и связь')->description(__('Пусто — ничего не подключается. Скрипты аналитики грузятся только на публичной витрине.'))->schema([
                TextInput::make('ga4_id')->label('Google Analytics 4')->placeholder('G-XXXXXXXXXX')->maxLength(20)
                    ->regex('/^G-[A-Z0-9]{4,15}$/')->validationMessages(['regex' => __('Идентификатор вида G-XXXXXXXXXX.')]),
                TextInput::make('metrika_id')->label('Яндекс.Метрика (номер счётчика)')->placeholder('12345678')->maxLength(12)
                    ->regex('/^\d{4,12}$/')->validationMessages(['regex' => __('Только цифры, 4–12 знаков.')]),
                TextInput::make('whatsapp_phone')->label('Номер WhatsApp')->placeholder('+7 701 123 45 67')->maxLength(20)
                    ->rule(fn () => fn (string $attribute, $value, \Closure $fail) => PhoneNumber::isValid($value) ? null : $fail(__(PhoneNumber::HINT).'.'))
                    ->helperText(__('Кнопка «WhatsApp» на витрине. Цель «order_submitted» (заявка отправлена) уходит в Google Analytics и Метрику.')),
            ])->columns(1),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('save')->label('Сохранить')->icon('heroicon-o-check')->action(fn () => $this->save())];
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $setting = ShopSetting::current();
        $image = collect($state['hero_image_path'] ?? [])->first();
        $before = $setting->only(self::TRACKED);
        $setting->fill([
            'hero_image_path' => $image ? basename((string) $image) : null,
            'hero_title' => filled($state['hero_title'] ?? null) ? trim((string) $state['hero_title']) : null,
            'hero_subtitle' => filled($state['hero_subtitle'] ?? null) ? trim((string) $state['hero_subtitle']) : null,
            'accent_color' => filled($state['accent_color'] ?? null) ? $state['accent_color'] : null,
            'ga4_id' => filled($state['ga4_id'] ?? null) ? trim((string) $state['ga4_id']) : null,
            'metrika_id' => filled($state['metrika_id'] ?? null) ? trim((string) $state['metrika_id']) : null,
            'whatsapp_phone' => filled($state['whatsapp_phone'] ?? null) ? PhoneNumber::normalize($state['whatsapp_phone']) : null,
            'operator_name' => filled($state['operator_name'] ?? null) ? trim((string) $state['operator_name']) : null,
            'offer_url' => filled($state['offer_url'] ?? null) ? trim((string) $state['offer_url']) : null,
            'updated_by' => auth()->id(),
        ])->save();
        AuditLog::record('shop_settings_changed', $setting, $before, $setting->only(self::TRACKED));
        Notification::make()->success()->title(__('Настройки сохранены'))->send();
    }
}
