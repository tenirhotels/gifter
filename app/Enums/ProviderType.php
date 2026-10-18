<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ProviderType: string implements HasLabel
{
    case Hotel = 'hotel';
    case Restaurant = 'restaurant';
    case InternalDepartment = 'internal_department';
    case ThirdParty = 'third_party';

    public function getLabel(): string
    {
        return match ($this) {
            self::Hotel => 'Отель',
            self::Restaurant => 'Ресторан отеля',
            self::InternalDepartment => 'Другой отдел отеля',
            self::ThirdParty => 'Сторонний поставщик',
        };
    }
}
