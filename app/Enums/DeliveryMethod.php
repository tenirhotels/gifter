<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Как клиент получает физический сертификат после оплаты счёта. */
enum DeliveryMethod: string implements HasLabel
{
    case Pickup = 'pickup';
    case Courier = 'courier';
    case Pdf = 'pdf';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pickup => __('Самовывоз в отеле'),
            self::Courier => __('Курьер по городу'),
            self::Pdf => __('Печатный PDF на email'),
        };
    }
}
