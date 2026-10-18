<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PaymentMethod: string implements HasLabel
{
    case KaspiInvoice = 'kaspi_invoice';
    case HalykInvoice = 'halyk_invoice';
    /** Перечисление на расчётный счёт (юрлица). Поступление подтверждают Финансы. */
    case BankTransfer = 'bank_transfer';

    public function getLabel(): string
    {
        return match ($this) {
            self::KaspiInvoice => 'Счёт Kaspi',
            self::HalykInvoice => 'Счёт Halyk Bank',
            self::BankTransfer => 'Перечисление на р/с',
        };
    }

    /** Поступление по этому способу отмечают только Финансы по выписке. */
    public function requiresFinanceConfirmation(): bool
    {
        return $this === self::BankTransfer;
    }
}
