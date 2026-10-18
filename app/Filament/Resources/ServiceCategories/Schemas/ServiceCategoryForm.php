<?php

namespace App\Filament\Resources\ServiceCategories\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;

class ServiceCategoryForm
{
    /** @return array<int, Component> */
    public static function fields(): array
    {
        return [
            TextInput::make('name')->label('Название')->required()->maxLength(255)->unique(ignoreRecord: true),
            TextInput::make('position')->label('Порядок')->integer()->default(0),
            Toggle::make('is_active')->label('Активна')->default(true),
        ];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->components(self::fields());
    }
}
