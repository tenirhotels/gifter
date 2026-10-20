<?php

namespace App\Filament\Resources\Services\Schemas;

use App\Enums\Permission;
use App\Filament\Resources\Providers\Schemas\ProviderForm;
use App\Filament\Resources\ServiceCategories\Schemas\ServiceCategoryForm;
use App\Models\PmsService;
use App\Models\Service;
use App\Services\ServicePricing;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class ServiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            // Создание: услуга из списка PMS (название и ID подставляются) или вручную. Правка: показываем связь с PMS.
            Section::make('Источник')->columns(2)->columnSpanFull()->visible(fn (string $operation) => $operation === 'create')->schema([
                Radio::make('source')->label('Откуда услуга')->options(['pms' => 'Из списка PMS (Exely)', 'manual' => 'Вручную (нет в PMS)'])
                    ->default(fn () => request()->query('pms') ? 'pms' : 'manual')->live()->required()->inline(),
                Select::make('pms_service_id')->label('Услуга из PMS')->searchable()->live()
                    ->visible(fn (Get $get) => $get('source') === 'pms')->required(fn (Get $get) => $get('source') === 'pms')
                    ->default(fn () => request()->query('pms'))
                    ->options(fn () => PmsService::query()->whereNull('service_id')->whereNull('missing_since')->orderBy('name')->get()
                        ->mapWithKeys(fn (PmsService $p) => [$p->external_id => $p->name.' · ID '.$p->external_id])->all())
                    ->afterStateUpdated(function (?string $state, Set $set) {
                        $pms = $state ? PmsService::where('external_id', $state)->first() : null;
                        if ($pms) {
                            $set('name', $pms->name);
                            $set('unit', 'шт');
                        }
                    })
                    ->helperText('Показаны услуги из PMS, которых ещё нет в Gifter. Название можно переименовать или перевести — связь идёт по ID.'),
            ]),
            Section::make('Из PMS')->columns(2)->columnSpanFull()
                ->visible(fn (string $operation, ?Service $record) => $operation === 'edit' && $record?->pmsService !== null)->schema([
                    Placeholder::make('pms_name')->label('Название в PMS')->content(fn (?Service $record) => $record?->pmsService?->name),
                    Placeholder::make('pms_id')->label('ID в PMS')->content(fn (?Service $record) => $record?->pmsService?->external_id),
                    Toggle::make('pms_allowed')->label('Можно включать в сертификат')->dehydrated(false)
                        ->formatStateUsing(fn (?Service $record) => (bool) $record?->pmsService?->allowed_in_certificate)
                        ->disabled(fn (?Service $record) => ! $record || ! app(ServicePricing::class)->hasApprovedPrice($record) || $record->pmsService?->isMissing())
                        ->helperText(fn (?Service $record) => $record && app(ServicePricing::class)->hasApprovedPrice($record)
                            ? 'Включено — услуга предлагается при составлении сертификата.' : 'Включить можно после того, как цена на сегодня утверждена администратором.'),
                ]),
            Section::make('Услуга')->columns(2)->columnSpanFull()->schema([
                TextInput::make('name')->label('Название')->required()->maxLength(255),
                TextInput::make('unit')->label('Единица')->placeholder('ночь, персона, сеанс, билет')->required()->maxLength(50),
                Select::make('category_id')->label('Категория')->relationship('category', 'name', fn ($query) => $query->where('is_active', true)->orderBy('position'))
                    ->preload()->required()->createOptionForm(ServiceCategoryForm::fields()),
                Select::make('provider_id')->label('Поставщик')->relationship('provider', 'name', fn ($query) => $query->where('is_active', true))
                    ->searchable()->preload()->required()->createOptionForm(ProviderForm::fields())
                    ->helperText('Кто оказывает услугу: отель, ресторан, отдел или сторонняя компания.'),
                Textarea::make('description')->label('Описание')->columnSpanFull(),
                Toggle::make('is_active')->label('Активна')->default(true)
                    ->helperText('Неактивную услугу нельзя добавить в новый сертификат; выпущенные не меняются.'),
            ]),
            Section::make('Цена')->columns(3)->columnSpanFull()->visible(fn (string $operation) => $operation === 'create')
                ->description(fn () => app(ServicePricing::class)->needsApproval() ? 'Цену, введённую директором, подтверждает администратор: до подтверждения услуга не продаётся.' : null)
                ->schema([
                    TextInput::make('price')->label('Цена продажи, ₸')->numeric()->minValue(1)->dehydrated(true)
                        ->required(fn (Get $get) => $get('source') === 'pms'),
                    TextInput::make('cost')->label('Себестоимость, ₸')->numeric()->minValue(0)->visible(fn () => auth()->user()?->can(Permission::ViewCosts)),
                    DatePicker::make('valid_from')->label('Цена действует с')->default(today())->required(fn (Get $get) => filled($get('price'))),
                ]),
            Section::make('Исполнение')->columns(3)->columnSpanFull()
                ->description('Что нужно сделать перед заездом гостя. Признаки фиксируются в сертификате на дату продажи.')
                ->schema([
                    Toggle::make('requires_fulfillment')->label('Требует подготовки')->live()
                        ->helperText('Нужна заявка поставщику (ужин, трансфер, билеты). Завтрак обычно — нет.')
                        ->afterStateUpdated(function (?bool $state, Set $set) {
                            if (! $state) {
                                $set('requires_scheduling', false);
                                $set('requires_confirmation', false);
                            }
                        }),
                    Toggle::make('requires_scheduling')->label('Нужны дата и время')->live()
                        ->disabled(fn (Get $get) => ! $get('requires_fulfillment')),
                    Toggle::make('requires_confirmation')->label('Нужно подтверждение поставщика')->live()
                        ->disabled(fn (Get $get) => ! $get('requires_fulfillment')),
                ]),
        ]);
    }
}
