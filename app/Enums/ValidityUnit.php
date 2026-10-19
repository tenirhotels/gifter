<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Carbon;

enum ValidityUnit: string implements HasLabel
{
    case Days = 'days';
    case Months = 'months';

    public function getLabel(): string
    {
        return match ($this) {
            self::Days => __('дней'),
            self::Months => __('месяцев'),
        };
    }

    /** «1 месяц», «2 месяца», «5 месяцев», «21 день». */
    public function format(int $value): string
    {
        [$one, $few, $many] = match ($this) {
            self::Days => ['день', 'дня', 'дней'],
            self::Months => ['месяц', 'месяца', 'месяцев'],
        };
        $mod100 = $value % 100;
        $mod10 = $value % 10;
        $word = match (true) {
            $mod100 >= 11 && $mod100 <= 14 => $many,
            $mod10 === 1 => $one,
            $mod10 >= 2 && $mod10 <= 4 => $few,
            default => $many,
        };

        return "{$value} {$word}";
    }

    /** Последний день действия: «2 месяца с 18.10» → по 18.12 включительно. */
    public function validUntil(Carbon $from, int $value): Carbon
    {
        return match ($this) {
            self::Days => $from->copy()->addDays($value),
            self::Months => $from->copy()->addMonthsNoOverflow($value),
        };
    }
}
