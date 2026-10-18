<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PaymentMethod: string implements HasLabel
{
    case KaspiInvoice = 'kaspi_invoice';
    case HalykInvoice = 'halyk_invoice';

    public function getLabel(): string
    {
        return match ($this) {
            self::KaspiInvoice => 'Счёт Kaspi',
            self::HalykInvoice => 'Счёт Halyk Bank',
        };
    }
}
