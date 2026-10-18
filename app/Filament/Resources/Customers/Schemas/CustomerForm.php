<?php

namespace App\Filament\Resources\Customers\Schemas;

use App\Enums\CustomerType;
use App\Filament\Support\ContactFields;
use App\Support\KazakhstanBin;
use Closure;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class CustomerForm
{
    /** @return array<int, Component> */
    public static function fields(): array
    {
        $company = fn (Get $get): bool => self::isCompany($get('type'));

        return [
            ToggleButtons::make('type')->label('Покупатель')->options(CustomerType::class)->inline()
                ->default(CustomerType::Individual)->required()->live()->columnSpanFull(),
            Grid::make(2)->columnSpanFull()->visible($company)->schema([
                TextInput::make('company')->label('Компания')->required($company)->maxLength(255),
                TextInput::make('bin')->label('БИН')->required($company)->length(12)
                    ->rule(fn () => function (string $attribute, $value, Closure $fail) {
                        if (filled($value) && ! KazakhstanBin::isValid($value)) {
                            $fail('БИН введён с ошибкой: проверьте 12 цифр.');
                        }
                    }),
            ]),
            Grid::make(3)->columnSpanFull()->schema(ContactFields::nameInputs()),
            Grid::make(2)->columnSpanFull()->schema(ContactFields::contactInputs()),
            Textarea::make('notes')->label('Примечание')->columnSpanFull(),
        ];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->components(self::fields());
    }

    private static function isCompany(mixed $type): bool
    {
        return ($type instanceof CustomerType ? $type : CustomerType::tryFrom((string) $type)) === CustomerType::Company;
    }
}
