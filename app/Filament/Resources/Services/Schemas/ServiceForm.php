<?php

namespace App\Filament\Resources\Services\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ServiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Название')->required()->maxLength(255),
            TextInput::make('unit')->label('Единица')->placeholder('ночь, персона, сеанс')->required()->maxLength(50),
            Textarea::make('description')->label('Описание')->columnSpanFull(),
            Toggle::make('is_active')->label('Активна')->default(true)
                ->helperText('Неактивную услугу нельзя добавить в новый сертификат; выпущенные не меняются.'),
        ]);
    }
}
