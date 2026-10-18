<?php

namespace App\Support;

/**
 * Денежная арифметика в тиынах (целые числа), чтобы не копить ошибки float.
 * В базе суммы хранятся как decimal(12,2), наружу отдаются строкой "12345.00".
 */
final class Money
{
    public static function toMinor(string|int|float|null $amount): int
    {
        return (int) round(((float) ($amount ?? 0)) * 100);
    }

    public static function fromMinor(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $minor = abs($minor);

        return sprintf('%s%d.%02d', $sign, intdiv($minor, 100), $minor % 100);
    }

    public static function multiply(string|int|float $amount, int $quantity): string
    {
        return self::fromMinor(self::toMinor($amount) * $quantity);
    }

    public static function format(string|int|float|null $amount): string
    {
        return number_format(self::toMinor($amount) / 100, 0, ',', ' ').' ₸';
    }
}
