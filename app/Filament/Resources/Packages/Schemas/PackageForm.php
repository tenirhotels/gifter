<?php

namespace App\Filament\Resources\Packages\Schemas;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class PackageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Название')->required()->maxLength(255),
            Toggle::make('is_active')->label('Активен')->default(true),
            Textarea::make('description')->label('Описание')->columnSpanFull(),
            Repeater::make('items')->label('Состав')->relationship()
                ->helperText('Шаблон: изменение пакета не меняет уже созданные сертификаты.')
                ->orderColumn('position')
                ->schema([
                    Select::make('service_id')->label('Услуга')
                        ->relationship('service', 'name', fn ($query) => $query->where('is_active', true))
                        ->required()->distinct()->searchable()->preload(),
                    TextInput::make('quantity')->label('Количество')->integer()->minValue(1)->default(1)->required(),
                ])
                ->columns(2)->columnSpanFull()->minItems(1),
        ]);
    }
}
