<?php

namespace App\Support;

/**
 * Проверка БИН/ИИН Казахстана: 12 цифр и контрольный разряд.
 * Контрольная цифра — сумма цифр 1–11 с весами 1..11 по модулю 11;
 * если получилось 10 — повтор с весами 3..11,1,2; повторное 10 — номер недействителен.
 */
final class KazakhstanBin
{
    public static function isValid(?string $value): bool
    {
        if ($value === null || ! preg_match('/^\d{12}$/', $value)) {
            return false;
        }
        $digits = array_map('intval', str_split($value));
        foreach ([[1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11], [3, 4, 5, 6, 7, 8, 9, 10, 11, 1, 2]] as $weights) {
            $sum = 0;
            foreach ($weights as $i => $weight) {
                $sum += $digits[$i] * $weight;
            }
            $check = $sum % 11;
            if ($check !== 10) {
                return $check === $digits[11];
            }
        }

        return false;
    }
}
