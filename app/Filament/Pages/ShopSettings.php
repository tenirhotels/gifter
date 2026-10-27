<?php

namespace App\Filament\Pages;

use App\Enums\DeliveryMethod;
use App\Enums\Permission;
use App\Filament\Support\TranslatesPageLabels;
use App\Models\AuditLog;
use App\Models\Package;
use App\Models\ShopSetting;
use App\Services\ShopAvailability;
use App\Support\Money;
use App\Support\PhoneNumber;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
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

    private const TRACKED = ['hero_image_path', 'hero_title', 'hero_subtitle', 'hero_intro', 'contact_invite', 'text_translations', 'delivery_methods', 'accent_color', 'ga4_id', 'metrika_id', 'whatsapp_phone', 'operator_name', 'offer_url', 'faq_items', 'terms_items'];

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
            'shop_order' => $this->shownPackages()->map(fn (Package $p) => ['id' => $p->id, 'label' => $this->orderLabel($p)])->all(),
            'faq_items' => self::faqState($setting->faq_items ?: ShopSetting::defaultFaqItems()),
            'terms_items' => self::termsState($setting->terms_items ?: ShopSetting::defaultTermsItems()),
        ]);
    }

    /** Пакеты, которые сейчас показываются на сайте, в порядке витрины. @return \Illuminate\Support\Collection<int, Package> */
    private function shownPackages(): Collection
    {
        $availability = app(ShopAvailability::class);

        return Package::query()->where('is_active', true)->where('is_complimentary', false)->where('sold_online', true)
            ->with('items.service')->orderByRaw('shop_position is null')->orderBy('shop_position')->orderBy('name')->get()
            ->filter(fn (Package $p) => $availability->problems($p) === [])->values();
    }

    private function orderLabel(Package $package): string
    {
        $price = app(ShopAvailability::class)->price($package);

        return $package->localizedName().' — '.($price['total'] !== null ? Money::format($price['total']) : '—').' · '.($package->isFixedPeriod() ? $package->validityText() : $package->validity_unit->format($package->validity_value));
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

    /** Вопросы для формы: `q`, `q_kk`, `q_en`, `a`, `a_kk`, `a_en`. @param  array<int, mixed>  $items @return list<array<string, ?string>> */
    private static function faqState(array $items): array
    {
        return array_values(array_map(fn ($i) => [
            'q' => $i['q']['ru'] ?? null, 'q_kk' => $i['q']['kk'] ?? null, 'q_en' => $i['q']['en'] ?? null,
            'a' => $i['a']['ru'] ?? null, 'a_kk' => $i['a']['kk'] ?? null, 'a_en' => $i['a']['en'] ?? null,
        ], $items));
    }

    /** @param  array<int, mixed>  $items @return list<array<string, ?string>> */
    private static function termsState(array $items): array
    {
        return array_values(array_map(fn ($i) => ['t' => $i['t']['ru'] ?? null, 't_kk' => $i['t']['kk'] ?? null, 't_en' => $i['t']['en'] ?? null], $items));
    }

    /** Пары рус/қаз/eng → {ru,kk,en}; пустое отбрасывается. @param  array<string, mixed>  $row @return array<string, string> */
    private static function packLangs(array $row, string $key): array
    {
        return array_filter(['ru' => trim((string) ($row[$key] ?? '')), 'kk' => trim((string) ($row[$key.'_kk'] ?? '')), 'en' => trim((string) ($row[$key.'_en'] ?? ''))], fn ($v) => $v !== '');
    }

    /** @param  array<int, mixed>  $rows @return ?list<array<string, array<string, string>>> */
    private static function packFaq(array $rows): ?array
    {
        $items = [];
        foreach ($rows as $row) {
            $q = self::packLangs((array) $row, 'q');
            $a = self::packLangs((array) $row, 'a');
            if ($q && $a) {
                $items[] = ['q' => $q, 'a' => $a];
            }
        }

        return $items ?: null;
    }

    /** @param  array<int, mixed>  $rows @return ?list<array<string, array<string, string>>> */
    private static function packTerms(array $rows): ?array
    {
        $items = [];
        foreach ($rows as $row) {
            if ($t = self::packLangs((array) $row, 't')) {
                $items[] = ['t' => $t];
            }
        }

        return $items ?: null;
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
            Tabs::make('shop')->persistTabInQueryString()->tabs([
                Tab::make(__('Оформление'))->schema([
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
                ]),
                Tab::make(__('Контент'))->schema([
                    Section::make('Порядок сертификатов на сайте')->description(__('Пакеты, которые сейчас продаются на сайте. Перетащите или используйте стрелки — на главной они идут в этом порядке. Не видите пакет — смотрите причину в списке «Пакеты сертификатов».'))->schema([
                        Repeater::make('shop_order')->hiddenLabel()->addable(false)->deletable(false)->reorderable()->reorderableWithButtons()
                            ->itemLabel(fn (array $state): ?string => $state['label'] ?? null)
                            ->schema([Hidden::make('id'), Hidden::make('label')]),
                    ])->columns(1),
                    Section::make('Сайт-визитка')->description(__('Если нет ни одного пакета на продажу, сайт показывает приглашение связаться вместо выбора сертификата.'))->schema([
                        ...self::trilingual('contact_invite', __('Приглашение связаться'), __('Подарочные сертификаты TENIR — свяжитесь с нами, и мы подберём и оформим подарок.'), long: true, max: 600),
                    ])->columns(1),
                    Section::make('Вопросы и ответы')->description(__('Страница «Вопросы и ответы» на сайте. Добавляйте, удаляйте и меняйте порядок; каждый пункт — на трёх языках. Нет перевода — показывается русский текст.'))->schema([
                        Repeater::make('faq_items')->hiddenLabel()->reorderable()->collapsible()->collapsed()->addActionLabel(__('Добавить вопрос'))
                            ->itemLabel(fn (array $state): ?string => filled($state['q'] ?? null) ? (string) $state['q'] : __('Новый вопрос'))
                            ->schema([
                                Grid::make(3)->schema([
                                    TextInput::make('q')->label(__('Вопрос').' (рус)')->required()->maxLength(255),
                                    TextInput::make('q_kk')->label(__('Вопрос').' (қаз)')->maxLength(255),
                                    TextInput::make('q_en')->label(__('Вопрос').' (eng)')->maxLength(255),
                                ]),
                                Grid::make(3)->schema([
                                    Textarea::make('a')->label(__('Ответ').' (рус)')->required()->rows(4)->maxLength(2000)->helperText(__('Новый абзац — с новой строки.')),
                                    Textarea::make('a_kk')->label(__('Ответ').' (қаз)')->rows(4)->maxLength(2000),
                                    Textarea::make('a_en')->label(__('Ответ').' (eng)')->rows(4)->maxLength(2000),
                                ]),
                            ]),
                    ])->columns(1),
                    Section::make('Условия сертификата')->description(__('Страница «Условия сертификата» на сайте (в PDF сертификата условия не меняются).'))->schema([
                        Repeater::make('terms_items')->hiddenLabel()->reorderable()->collapsible()->addActionLabel(__('Добавить пункт'))
                            ->itemLabel(fn (array $state): ?string => filled($state['t'] ?? null) ? mb_strimwidth((string) $state['t'], 0, 80, '…') : __('Новый пункт'))
                            ->schema([
                                Grid::make(3)->schema([
                                    Textarea::make('t')->label(__('Пункт').' (рус)')->required()->rows(3)->maxLength(600),
                                    Textarea::make('t_kk')->label(__('Пункт').' (қаз)')->rows(3)->maxLength(600),
                                    Textarea::make('t_en')->label(__('Пункт').' (eng)')->rows(3)->maxLength(600),
                                ]),
                            ]),
                    ])->columns(1),
                ]),
            ])->columnSpanFull(),
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

    /** Позиции 1…N у показываемых пакетов; остальные не трогаем (аудит — в модели пакета). @param  array<int, mixed>  $rows */
    private function saveShopOrder(array $rows): void
    {
        $ids = array_values(array_filter(array_map(fn ($r) => (int) ($r['id'] ?? 0), $rows)));
        $shown = $this->shownPackages()->keyBy('id');
        $position = 0;
        foreach ($ids as $id) {
            if ($package = $shown->get($id)) {
                $package->update(['shop_position' => ++$position]);
            }
        }
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $setting = ShopSetting::current();
        $this->saveShopOrder((array) ($state['shop_order'] ?? []));
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
            'faq_items' => self::packFaq((array) ($state['faq_items'] ?? [])),
            'terms_items' => self::packTerms((array) ($state['terms_items'] ?? [])),
            'updated_by' => auth()->id(),
        ])->save();
        AuditLog::record('shop_settings_changed', $setting, $before, $setting->only(self::TRACKED));
        Notification::make()->success()->title(__('Настройки сохранены'))->send();
    }
}
