<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/** Подготовка одной услуги сертификата. Не означает частичного погашения. */
enum FulfillmentStatus: string implements HasColor, HasIcon, HasLabel
{
    case Pending = 'pending';
    case Requested = 'requested';
    case Scheduled = 'scheduled';
    case Confirmed = 'confirmed';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Ожидает',
            self::Requested => 'Заявка отправлена',
            self::Scheduled => 'Время назначено',
            self::Confirmed => 'Подтверждено',
            self::Completed => 'Выполнено',
            self::Cancelled => 'Отменено',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Requested, self::Scheduled => 'info',
            self::Confirmed, self::Completed => 'success',
            self::Cancelled => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Pending => 'heroicon-m-clock',
            self::Requested => 'heroicon-m-paper-airplane',
            self::Scheduled => 'heroicon-m-calendar',
            self::Confirmed => 'heroicon-m-check',
            self::Completed => 'heroicon-m-check-badge',
            self::Cancelled => 'heroicon-m-x-mark',
        };
    }

    public function isOpen(): bool
    {
        return ! in_array($this, [self::Completed, self::Cancelled], true);
    }

    /** @return list<self> */
    public static function open(): array
    {
        return [self::Pending, self::Requested, self::Scheduled, self::Confirmed];
    }
}
