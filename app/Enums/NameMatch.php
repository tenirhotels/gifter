<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** С кем совпало имя гостя в брони. */
enum NameMatch: string implements HasColor, HasLabel
{
    case Recipient = 'recipient';
    case Customer = 'customer';
    case Mismatch = 'mismatch';

    public function getLabel(): string
    {
        return match ($this) {
            self::Recipient => 'Совпадает с получателем',
            self::Customer => 'Совпадает с заказчиком',
            self::Mismatch => 'Не совпадает ни с получателем, ни с заказчиком',
        };
    }

    /** Короткая подпись для таблиц. */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Recipient => '= получатель',
            self::Customer => '= заказчик',
            self::Mismatch => 'имя не совпадает',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Recipient, self::Customer => 'success',
            self::Mismatch => 'danger',
        };
    }
}
