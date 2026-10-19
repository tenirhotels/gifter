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
            self::Recipient => __('Совпадает с получателем'),
            self::Customer => __('Совпадает с заказчиком'),
            self::Mismatch => __('Не совпадает ни с получателем, ни с заказчиком'),
        };
    }

    /** Короткая подпись для таблиц. */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Recipient => __('= получатель'),
            self::Customer => __('= заказчик'),
            self::Mismatch => __('имя не совпадает'),
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
