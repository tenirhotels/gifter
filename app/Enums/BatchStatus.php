<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum BatchStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Issued = 'issued';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Ждёт согласования',
            self::Approved => 'Согласован',
            self::Issued => 'Выпущен',
            self::Cancelled => 'Аннулирован',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'warning',
            self::Approved => 'info',
            self::Issued => 'success',
            self::Cancelled => 'danger',
        };
    }
}
