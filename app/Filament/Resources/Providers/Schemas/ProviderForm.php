<?php

namespace App\Filament\Resources\Providers\Schemas;

use App\Enums\ProviderType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;

class ProviderForm
{
    /** @return array<int, Component> */
    public static function fields(): array
    {
        return [
            TextInput::make('name')->label('Название')->required()->maxLength(255),
            Select::make('type')->label('Тип')->options(ProviderType::class)->required(),
            TextInput::make('contact_person')->label('Контактное лицо')->maxLength(255),
            TextInput::make('phone')->label('Телефон')->tel()->maxLength(32),
            TextInput::make('email')->label('Email')->email()->maxLength(255),
            Toggle::make('is_active')->label('Активен')->default(true),
            Textarea::make('notes')->label('Примечание (условия, как заказывать)')->columnSpanFull(),
        ];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->components(self::fields());
    }
}
