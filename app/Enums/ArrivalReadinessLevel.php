<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/** Готовность к заезду гостя по сертификату (фаза 6). */
enum ArrivalReadinessLevel: string implements HasColor, HasIcon, HasLabel
{
    case Ready = 'ready';
    case Action = 'action';
    case Exception = 'exception';

    public function getLabel(): string
    {
        return match ($this) {
            self::Ready => __('Готов'),
            self::Action => __('Требует действия'),
            self::Exception => __('Исключение'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Ready => 'success',
            self::Action => 'warning',
            self::Exception => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Ready => 'heroicon-m-check-circle',
            self::Action => 'heroicon-m-exclamation-circle',
            self::Exception => 'heroicon-m-exclamation-triangle',
        };
    }
}
