<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Путь комиссии: продан → ждёт погашения → начислена → согласована к выплате → выплачена. */
enum CommissionStatus: string implements HasColor, HasLabel
{
    case PendingRedemption = 'pending_redemption';
    case Earned = 'earned';
    case Approved = 'approved';
    case Paid = 'paid';
    case Void = 'void';

    public function getLabel(): string
    {
        return match ($this) {
            self::PendingRedemption => 'Ждёт погашения',
            self::Earned => 'Начислена',
            self::Approved => 'Согласована к выплате',
            self::Paid => 'Выплачена',
            self::Void => 'Не начисляется',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::PendingRedemption => 'gray',
            self::Earned => 'warning',
            self::Approved => 'info',
            self::Paid => 'success',
            self::Void => 'danger',
        };
    }

    /** Начислена (в т. ч. согласованная и выплаченная). */
    public function isEarned(): bool
    {
        return in_array($this, [self::Earned, self::Approved, self::Paid], true);
    }

    /** Начислена, но ещё не выплачена. */
    public function isPendingPayment(): bool
    {
        return in_array($this, [self::Earned, self::Approved], true);
    }
}
