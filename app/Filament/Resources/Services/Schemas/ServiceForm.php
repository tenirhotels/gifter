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
            Section::make('Источник услуги')->description('Выберите услугу из Exely или создайте её вручную, если её нет в PMS.')->columns(2)->columnSpanFull()->visible(fn (string $operation) => $operation === 'create')->schema([
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
                    ->helperText('Услуга из Exely. Название можно изменить — связь сохранится.'),
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
                TextInput::make('name')->label('Название')->required()->maxLength(255)
                    ->helperText('Название услуги, которое увидят сотрудники и гости в Gifter.'),
                TextInput::make('unit')->label('Единица измерения')->placeholder('ночь, персона, сеанс, билет')->required()->maxLength(50)
                    ->helperText('В чём учитывается услуга: шт., ночь, человек, час и т. д.'),
                Select::make('category_id')->label('Категория')->relationship('category', 'name', fn ($query) => $query->where('is_active', true)->orderBy('position'))
                    ->preload()->required()->createOptionForm(ServiceCategoryForm::fields())
                    ->helperText('Определяет, к какому типу относится услуга: проживание, питание, услуга отеля или стороннего поставщика.'),
                Select::make('provider_id')->label('Поставщик')->relationship('provider', 'name', fn ($query) => $query->where('is_active', true))
                    ->searchable()->preload()->required()->createOptionForm(ProviderForm::fields())
                    ->helperText('Кто оказывает услугу: отель, ресторан, отдел или сторонняя компания.'),
                Textarea::make('description')->label('Описание')->columnSpanFull()
                    ->helperText('Кратко опишите услугу и условия её предоставления.'),
                Toggle::make('is_active')->label('Активна')->default(true)
                    ->helperText('Если выключить, услугу нельзя будет добавить в новые сертификаты. Уже выпущенные сертификаты не изменятся.'),
            ]),
            Section::make('Цена')->columns(3)->columnSpanFull()->visible(fn (string $operation) => $operation === 'create')
                ->description(fn () => app(ServicePricing::class)->needsApproval() ? 'Цену, введённую директором, подтверждает администратор: до подтверждения услуга не продаётся.' : null)
                ->schema([
                    TextInput::make('price')->label('Цена продажи, ₸')->numeric()->minValue(1)->dehydrated(true)
                        ->helperText('Стоимость услуги, которая используется при продаже сертификата.')
                        ->required(fn (Get $get) => $get('source') === 'pms'),
                    TextInput::make('cost')->label('Себестоимость, ₸')->numeric()->minValue(0)->visible(fn () => auth()->user()?->can(Permission::ViewCosts))
                        ->helperText('Внутренняя стоимость услуги для расчёта маржи. Гостю не показывается.'),
                    DatePicker::make('valid_from')->label('Цена действует с')->default(today())->required(fn (Get $get) => filled($get('price')))
                        ->helperText('Дата, с которой применяется эта цена для новых сертификатов.'),
                ]),
            Section::make('Исполнение')->columns(3)->columnSpanFull()
                ->description('Укажите, нужна ли подготовка или дополнительные действия перед предоставлением услуги. Эти условия фиксируются в сертификате при его выпуске.')
                ->schema([
                    Toggle::make('requires_fulfillment')->label('Предварительная подготовка')->live()
                        ->helperText('Услугу нужно подготовить или заказать заранее. Пример: ужин, трансфер, билеты.')
                        ->afterStateUpdated(function (?bool $state, Set $set) {
                            if (! $state) {
                                $set('requires_scheduling', false);
                                $set('requires_confirmation', false);
                            }
                        }),
                    Toggle::make('requires_scheduling')->label('Требуется запись')->live()
                        ->helperText('Для услуги необходимо выбрать дату и/или время.')
                        ->disabled(fn (Get $get) => ! $get('requires_fulfillment')),
                    Toggle::make('requires_confirmation')->label('Требуется подтверждение')->live()
                        ->helperText('Услуга предоставляется только после подтверждения поставщика.')
                        ->disabled(fn (Get $get) => ! $get('requires_fulfillment')),
                ]),
        ]);
    }
}
