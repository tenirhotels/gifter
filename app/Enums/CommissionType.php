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
            self::Percent => __('Процент от цены продажи'),
            self::Fixed => __('Фиксированная сумма'),
        };
    }
}
