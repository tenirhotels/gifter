<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Проверка брони Финансами: несовпадение имени или бронь в последние дни срока. */
enum ReservationReview: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Disputed = 'disputed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => __('На проверке Финансов'),
            self::Approved => __('Проверено Финансами'),
            self::Disputed => __('Оспорено Финансами'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved => 'success',
            self::Disputed => 'danger',
        };
    }
}
