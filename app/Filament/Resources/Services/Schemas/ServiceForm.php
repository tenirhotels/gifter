<?php

namespace App\Filament\Resources\Services\Schemas;

use App\Filament\Resources\Providers\Schemas\ProviderForm;
use App\Filament\Resources\ServiceCategories\Schemas\ServiceCategoryForm;
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
