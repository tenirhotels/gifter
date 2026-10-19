<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PaymentMethod: string implements HasLabel
{
    case KaspiInvoice = 'kaspi_invoice';
    case HalykInvoice = 'halyk_invoice';
    /** Перечисление на расчётный счёт (юрлица). Поступление подтверждают Финансы. */
    case BankTransfer = 'bank_transfer';

    /** Наличные в кассе. Поступление подтверждают Финансы. */
    case Cash = 'cash';

    public function getLabel(): string
    {
        return match ($this) {
            self::KaspiInvoice => __('Счёт Kaspi'),
            self::HalykInvoice => __('Счёт Halyk Bank'),
            self::BankTransfer => __('Перечисление на р/с'),
            self::Cash => __('Наличные'),
        };
    }

    /** Поступление по этому способу отмечают только Финансы по выписке. */
    public function requiresFinanceConfirmation(): bool
    {
        return in_array($this, self::financeConfirmed(), true);
    }

    /** @return list<self> */
    public static function financeConfirmed(): array
    {
        return [self::BankTransfer, self::Cash];
    }
}
