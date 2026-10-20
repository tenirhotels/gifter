<?php

namespace App\Filament\Pages;

use App\Enums\Permission;
use App\Filament\Support\TranslatesPageLabels;
use App\Models\AuditLog;
use App\Models\ShopSetting;
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
        $before = $setting->only(['hero_image_path', 'hero_title', 'hero_subtitle', 'accent_color']);
        $setting->fill([
            'hero_image_path' => $image ? basename((string) $image) : null,
            'hero_title' => filled($state['hero_title'] ?? null) ? trim((string) $state['hero_title']) : null,
            'hero_subtitle' => filled($state['hero_subtitle'] ?? null) ? trim((string) $state['hero_subtitle']) : null,
            'accent_color' => filled($state['accent_color'] ?? null) ? $state['accent_color'] : null,
            'updated_by' => auth()->id(),
        ])->save();
        AuditLog::record('shop_settings_changed', $setting, $before, $setting->only(['hero_image_path', 'hero_title', 'hero_subtitle', 'accent_color']));
        Notification::make()->success()->title(__('Настройки сохранены'))->send();
    }
}
