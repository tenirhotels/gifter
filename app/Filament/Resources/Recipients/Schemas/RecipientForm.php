<?php

namespace App\Filament\Resources\Recipients\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;

class RecipientForm
{
    /** @return array<int, Component> */
    public static function fields(): array
    {
        return [
            TextInput::make('full_name')->label('ФИО')->required()->maxLength(255),
            TextInput::make('phone')->label('Телефон')->tel()->maxLength(32),
            TextInput::make('email')->label('Email')->email()->maxLength(255),
            Textarea::make('notes')->label('Примечание')->columnSpanFull(),
        ];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->components(self::fields());
    }
}
