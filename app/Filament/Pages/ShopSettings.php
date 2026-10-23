<?php

namespace App\Filament\Pages;

use App\Enums\DeliveryMethod;
use App\Enums\Permission;
use App\Filament\Support\TranslatesPageLabels;
use App\Models\AuditLog;
use App\Models\ShopSetting;
use App\Support\PhoneNumber;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
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

    private const TRACKED = ['hero_image_path', 'hero_title', 'hero_subtitle', 'hero_intro', 'contact_invite', 'text_translations', 'delivery_methods', 'accent_color', 'ga4_id', 'metrika_id', 'whatsapp_phone', 'operator_name', 'offer_url'];

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
            'accent_color' => $setting->accent(), 'delivery_methods' => array_map(fn (DeliveryMethod $m) => $m->value, $setting->deliveryMethods()),
            ...$this->textState($setting),
            'ga4_id' => $setting->ga4_id, 'metrika_id' => $setting->metrika_id, 'whatsapp_phone' => $setting->whatsapp_phone,
            'operator_name' => $setting->operator_name, 'offer_url' => $setting->offer_url,
        ]);
    }

    /** Тексты на трёх языках: `hero_title` (рус), `hero_title_kk`, `hero_title_en` и т. д. @return array<string, ?string> */
    private function textState(ShopSetting $setting): array
    {
        $state = [];
        foreach (ShopSetting::TEXTS as $key) {
            $state[$key] = $setting->{$key};
            $state[$key.'_kk'] = $setting->text_translations[$key]['kk'] ?? null;
            $state[$key.'_en'] = $setting->text_translations[$key]['en'] ?? null;
        }

        return $state;
    }

    /** Три поля одного текста: рус · қаз · eng. @return array<int, mixed> */
    private static function trilingual(string $key, string $label, string $placeholder, bool $long = false, int $max = 120): array
    {
        $make = fn (string $name, string $suffix) => ($long ? Textarea::make($name)->rows(3) : TextInput::make($name))
            ->label($label.' ('.$suffix.')')->maxLength($max);

        return [Grid::make(3)->schema([
            $make($key, 'рус')->placeholder($placeholder),
            $make($key.'_kk', 'қаз'),
            $make($key.'_en', 'eng'),
        ])];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Главная страница')->description(__('Что видит клиент на странице заявки: фото, заголовок и цвет бренда.'))->schema([
                FileUpload::make('hero_image_path')->label('Главное фото')->image()->disk('public')->directory('shop')->visibility('public')
                    ->maxSize(4096)->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])->maxFiles(1)
                    ->helperText(__('Фото отеля, лучше горизонтальное, не меньше 1600 px; до 4 МБ.')),
                ...self::trilingual('hero_title', __('Заголовок'), __('Подарите время, которое запомнится')),
                ...self::trilingual('hero_subtitle', __('Подзаголовок'), __('Подарочные сертификаты на отдых в горах'), max: 300),
                ...self::trilingual('hero_intro', __('Вступление'), __('Проживание в Tenir Eco Hotel, завтраки и особенные моменты среди гор. Выберите готовый сертификат — мы красиво оформим его и подготовим к вручению.'), long: true, max: 600),
                ColorPicker::make('accent_color')->label('Акцентный цвет')->helperText(__('Один цвет бренда для кнопок и линий; по умолчанию тёмно-зелёный из брендбука.')),
            ])->columns(1),
            Section::make('Получение сертификата')->description(__('Какие способы получения клиент видит в форме заявки. Названия способов переводятся автоматически.'))->schema([
                CheckboxList::make('delivery_methods')->label('Активные способы получения')->options(
                    collect(DeliveryMethod::cases())->mapWithKeys(fn (DeliveryMethod $m) => [$m->value => $m->getLabel()])->all()
                )->required()->minItems(1)->columns(1)
                    ->validationMessages(['required' => __('Выберите хотя бы один способ получения.'), 'min' => __('Выберите хотя бы один способ получения.')]),
            ])->columns(1),
            Section::make('Сайт-визитка')->description(__('Если нет ни одного пакета на продажу, сайт показывает приглашение связаться вместо выбора сертификата.'))->schema([
                ...self::trilingual('contact_invite', __('Приглашение связаться'), __('Подарочные сертификаты TENIR — свяжитесь с нами, и мы подберём и оформим подарок.'), long: true, max: 600),
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

    /** Русский текст — в колонку, переводы kk/en — в `text_translations`. @return array<string, mixed> */
    private function textColumns(array $state): array
    {
        $columns = [];
        $translations = [];
        foreach (ShopSetting::TEXTS as $key) {
            $columns[$key] = filled($state[$key] ?? null) ? trim((string) $state[$key]) : null;
            $pair = array_filter(['kk' => trim((string) ($state[$key.'_kk'] ?? '')), 'en' => trim((string) ($state[$key.'_en'] ?? ''))], fn ($v) => $v !== '');
            if ($pair) {
                $translations[$key] = $pair;
            }
        }
        $columns['text_translations'] = $translations ?: null;

        return $columns;
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $setting = ShopSetting::current();
        $image = collect($state['hero_image_path'] ?? [])->first();
        $before = $setting->only(self::TRACKED);
        $setting->fill([
            'hero_image_path' => $image ? basename((string) $image) : null,
            ...$this->textColumns($state),
            'delivery_methods' => array_values(array_intersect(array_map(fn (DeliveryMethod $m) => $m->value, DeliveryMethod::cases()), (array) ($state['delivery_methods'] ?? []))) ?: null,
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
