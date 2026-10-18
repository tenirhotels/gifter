<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum RefundStatus: string implements HasColor, HasLabel
{
    case Requested = 'requested';
    /** Согласовано: сертификат аннулирован, деньги ещё не возвращены. */
    case Approved = 'approved';
    case Completed = 'completed';
    case Rejected = 'rejected';

    public function getLabel(): string
    {
        return match ($this) {
            self::Requested => 'На согласовании',
            self::Approved => 'Согласован, ждёт выплаты',
            self::Completed => 'Возвращено',
            self::Rejected => 'Отклонён',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Requested => 'warning',
            self::Approved => 'info',
            self::Completed => 'success',
            self::Rejected => 'gray',
        };
    }
}
