<?php

namespace App\Filament\Resources\Packages\Schemas;

use App\Enums\ComplimentaryPurpose;
use App\Enums\ValidityUnit;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class PackageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Название пакета')->required()->maxLength(255),
            Toggle::make('is_active')->label('Активен')->default(true),
            Grid::make(2)->columnSpanFull()->schema([
                Toggle::make('is_complimentary')->label('Бесплатный пакет')->live()
                    ->helperText('Выпуск без оплаты, после согласования директора. Продавцу пакет не виден.'),
                Select::make('complimentary_purpose')->label('Цель по умолчанию')->options(ComplimentaryPurpose::class)
                    ->visible(fn (Get $get) => (bool) $get('is_complimentary'))->required(fn (Get $get) => (bool) $get('is_complimentary')),
            ]),
            Grid::make(2)->schema([
                TextInput::make('validity_value')->label('Срок действия')->integer()->minValue(1)->maxValue(1000)->default(2)->required(),
                Select::make('validity_unit')->label('Единица')->options(ValidityUnit::class)->default(ValidityUnit::Months)->required(),
            ])->columnSpan(1),
            Placeholder::make('validity_hint')->label(' ')
                ->content('Считается от даты продажи. Продавец срок не меняет; исключения — менеджер продаж с указанием причины. Новый срок действует только для новых сертификатов.'),
            Textarea::make('description')->label('Описание')->columnSpanFull(),
            Repeater::make('items')->label('Состав сертификата')->relationship()
                ->helperText('Входит в каждый сертификат пакета. Уже выпущенные сертификаты не меняются.')
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
