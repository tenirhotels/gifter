<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum CustomerType: string implements HasLabel
{
    case Individual = 'individual';
    case Company = 'company';

    public function getLabel(): string
    {
        return match ($this) {
            self::Individual => __('Физлицо'),
            self::Company => __('Компания'),
        };
    }
}
