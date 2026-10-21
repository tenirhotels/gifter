<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Откуда пришла продажа: оформил продавец или заявка с сайта. */
enum SaleSource: string implements HasColor, HasLabel
{
    case Staff = 'staff';
    case Website = 'website';

    public function getLabel(): string
    {
        return match ($this) {
            self::Staff => __('Продавец'),
            self::Website => __('Сайт'),
        };
    }

    public function getColor(): string
    {
        return $this === self::Website ? 'info' : 'gray';
    }
}
