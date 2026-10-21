<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PaymentMethod: string implements HasLabel
{
    case KaspiInvoice = 'kaspi_invoice';
    case HalykInvoice = 'halyk_invoice';
    /** Оплата картой по ссылке банка: платёж подтверждает API банка, вручную не отмечается. */
    case HalykEpay = 'halyk_epay';
    /** Перечисление на расчётный счёт (юрлица). Поступление подтверждают Финансы. */
    case BankTransfer = 'bank_transfer';

    /** Наличные в кассе. Поступление подтверждают Финансы. */
    case Cash = 'cash';

    public function getLabel(): string
    {
        return match ($this) {
            self::KaspiInvoice => __('Счёт Kaspi'),
            self::HalykInvoice => __('Счёт Halyk Bank'),
            self::HalykEpay => __('Оплата картой по ссылке'),
            self::BankTransfer => __('Перечисление на р/с'),
            self::Cash => __('Наличные'),
        };
    }

    /** Оплату подтверждает банк (API), а не сотрудник; сверка не нужна. */
    public function isGateway(): bool
    {
        return $this === self::HalykEpay;
    }

    /** Способы, которые выбирает сотрудник при выставлении счёта вручную (без оплаты картой по ссылке). */
    public static function manualOptions(): array
    {
        return collect(self::cases())->reject(fn (self $method) => $method->isGateway())->mapWithKeys(fn (self $method) => [$method->value => $method->getLabel()])->all();
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
