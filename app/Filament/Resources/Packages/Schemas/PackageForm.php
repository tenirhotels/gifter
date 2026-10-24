<?php

namespace App\Filament\Resources\Packages\Schemas;

use App\Enums\ComplimentaryPurpose;
use App\Enums\PackageValidityType;
use App\Enums\ValidityUnit;
use App\Models\Package;
use App\Services\ShopAvailability;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;

class PackageForm
{
    /** Состояние переключателя — строка или enum (при открытии существующего пакета). */
    private static function isPeriod(mixed $state): bool
    {
        return ($state instanceof PackageValidityType ? $state : PackageValidityType::tryFrom((string) $state)) === PackageValidityType::Period;
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Название пакета')->required()->maxLength(255)
                ->helperText(__('Основное (русское) название. Переводы на казахский и английский — в разделе «Каталог → Переводы».')),
            Toggle::make('is_active')->label('Активен')->default(true),
            Placeholder::make('shop_status')->label('На сайте')->visible(fn (?Package $record) => $record?->exists === true)
                ->content(function (?Package $record) {
                    $problems = $record ? app(ShopAvailability::class)->problems($record) : [];

                    return $problems === [] ? __('Показывается на главной странице сайта') : new HtmlString('<span>'.__('Не показывается').':</span><ul style="margin:4px 0 0 18px;list-style:disc">'.collect($problems)->map(fn ($p) => '<li>'.e($p).'</li>')->implode('').'</ul>');
                }),
            Toggle::make('sold_online')->label('Продаётся онлайн')->visible(fn (Get $get) => ! $get('is_complimentary'))
                ->helperText(__('Показывается на публичной странице: клиент оставляет заявку.')),
            Grid::make(2)->columnSpanFull()->schema([
                Toggle::make('is_complimentary')->label('Бесплатный пакет')->live()
                    ->helperText(__('Выпуск без оплаты, после согласования директора. Продавцу пакет не виден.')),
                Select::make('complimentary_purpose')->label('Цель по умолчанию')->options(ComplimentaryPurpose::class)
                    ->visible(fn (Get $get) => (bool) $get('is_complimentary'))->required(fn (Get $get) => (bool) $get('is_complimentary')),
            ]),
            ToggleButtons::make('validity_type')->label('Как задан срок')->options(PackageValidityType::class)->default(PackageValidityType::Term->value)->required()->live()->inline()->grouped()
                ->helperText(__('«Фиксированный срок» — отсчёт от даты продажи (месяцы или дни); «Фиксированный период» — сертификат действует в заданные даты (например, зимние каникулы), после окончания пакет не продаётся.')),
            Grid::make(2)->schema([
                TextInput::make('validity_value')->label('Срок действия')->integer()->minValue(1)->maxValue(1000)->default(2)->required(fn (Get $get) => ! self::isPeriod($get('validity_type'))),
                Select::make('validity_unit')->label('Единица')->options(ValidityUnit::class)->default(ValidityUnit::Months)->required(fn (Get $get) => ! self::isPeriod($get('validity_type'))),
            ])->columnSpan(1)->visible(fn (Get $get) => self::isPeriod($get('validity_type')) === false),
            Placeholder::make('validity_hint')->label(' ')->visible(fn (Get $get) => self::isPeriod($get('validity_type')) === false)
                ->content(__('Считается от даты продажи. Продавец срок не меняет; исключения — менеджер продаж с указанием причины. Новый срок действует только для новых сертификатов.')),
            Grid::make(2)->schema([
                DatePicker::make('period_from')->label('Период с')->required(fn (Get $get) => self::isPeriod($get('validity_type'))),
                DatePicker::make('period_until')->label('Период по')->required(fn (Get $get) => self::isPeriod($get('validity_type')))->afterOrEqual('period_from'),
            ])->columnSpanFull()->visible(fn (Get $get) => self::isPeriod($get('validity_type'))),
            Toggle::make('sales_limits_enabled')->label('Дополнительные ограничения продажи')->live()->columnSpanFull()
                ->helperText(__('Лимит по количеству и/или окно продаж (например, Black Friday). Когда продажа закрыта, оформить сертификат нельзя ни на сайте, ни в админке; на сайте пакет остаётся с пометкой «Продажа завершена». Снять ограничения — выключить тумблер.')),
            Grid::make(3)->columnSpanFull()->visible(fn (Get $get) => (bool) $get('sales_limits_enabled'))->schema([
                TextInput::make('sales_quota')->label('Лимит продаж, шт.')->integer()->minValue(0)->maxValue(100000)
                    ->helperText(__('Пусто или 0 — без ограничений. Считаются сертификаты всех каналов.')),
                DatePicker::make('sales_from')->label('Продаётся с'),
                DatePicker::make('sales_until')->label('Продаётся по')->afterOrEqual('sales_from'),
            ]),
            Textarea::make('description')->label('Описание')->columnSpanFull()
                ->helperText(__('Основное (русское) описание. Переводы — в разделе «Каталог → Переводы».')),
            FileUpload::make('image_path')->label('Фото для сайта')->image()->disk('public')->directory('shop')->visibility('public')
                ->maxSize(4096)->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])->columnSpanFull()
                ->helperText(__('Показывается на публичной странице; лучше горизонтальное, до 4 МБ.'))
                ->formatStateUsing(fn ($state) => $state ? ['shop/'.basename((string) $state)] : [])
                ->dehydrateStateUsing(fn ($state) => ($f = collect(Arr::wrap($state))->first()) ? basename((string) $f) : null),
            Repeater::make('items')->label('Состав сертификата')->relationship()
                ->helperText(__('Входит в каждый сертификат пакета. Уже выпущенные сертификаты не меняются.'))
                ->orderColumn('position')
                ->schema([
                    Select::make('service_id')->label('Услуга')
                        ->relationship('service', 'name', fn ($query) => $query->where('is_active', true))
                        ->required()->distinct()->searchable()->preload(),
                    TextInput::make('quantity')->label('Количество')->integer()->minValue(1)->default(1)->required(),
                ])
                ->columns(2)->columnSpanFull(),
        ]);
    }
}
