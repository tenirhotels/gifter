<?php

namespace App\Filament\Resources\Recipients\Schemas;

use App\Filament\Support\ContactFields;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;

class RecipientForm
{
    /** @return array<int, Component> */
    public static function fields(): array
    {
        return [
            Grid::make(3)->columnSpanFull()->schema(ContactFields::nameInputs()),
            Grid::make(2)->columnSpanFull()->schema(ContactFields::contactInputs()),
            Textarea::make('notes')->label('Примечание')->columnSpanFull(),
        ];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->components(self::fields());
    }
}
