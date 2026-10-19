<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum CommissionType: string implements HasLabel
{
    case Percent = 'percent';
    case Fixed = 'fixed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Percent => 'Процент от цены продажи',
            self::Fixed => 'Фиксированная сумма',
        };
    }
}
