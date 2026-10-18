<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Подписи только на русском; переводы — в отдельной фазе мультиязычности. */
enum ProviderType: string implements HasLabel
{
    case Rooms = 'rooms';
    case FoodBeverage = 'food_beverage';
    case OtherDepartment = 'other_department';
    case External = 'external';

    public function getLabel(): string
    {
        return match ($this) {
            self::Rooms => 'Служба размещения',
            self::FoodBeverage => 'Ресторанная служба',
            self::OtherDepartment => 'Другой отдел отеля',
            self::External => 'Внешний поставщик',
        };
    }
}
