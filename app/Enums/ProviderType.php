<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ProviderType: string implements HasLabel
{
    case Rooms = 'rooms';
    case FoodBeverage = 'food_beverage';
    case OtherDepartment = 'other_department';
    case External = 'external';

    public function getLabel(): string
    {
        return match ($this) {
            self::Rooms => 'Rooms — Служба размещения',
            self::FoodBeverage => 'F&B — Ресторанная служба',
            self::OtherDepartment => 'Other Hotel Department — Другой отдел отеля',
            self::External => 'External Provider — Внешний поставщик',
        };
    }
}
