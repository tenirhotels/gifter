<?php

namespace App\Support;

use App\Exceptions\DomainRuleViolation;

/**
 * Телефон в международном формате (E.164): «+», код страны и номер, 8–15 цифр.
 * Пробелы, дефисы и скобки допускаются при вводе и убираются; казахстанский «8 7xx…» → «+7 7xx…».
 */
final class PhoneNumber
{
    public const HINT = 'В международном формате: +7 701 123 45 67';

    public static function normalize(?string $phone): ?string
    {
        $phone = trim((string) $phone);
        if ($phone === '') {
            return null;
        }
        $compact = preg_replace('/[\s()\-.]/', '', $phone);
        if (preg_match('/^8\d{10}$/', $compact)) {
            $compact = '+7'.substr($compact, 1);
        }

        return $compact;
    }

    public static function isValid(?string $phone): bool
    {
        $phone = self::normalize($phone);

        return $phone === null || (bool) preg_match('/^\+[1-9]\d{7,14}$/', $phone);
    }

    /** Для показа: казахстанские и российские номера — «+7 701 123 45 67», остальные — как есть. */
    public static function format(?string $phone): ?string
    {
        $phone = self::normalize($phone);
        if ($phone !== null && preg_match('/^\+7(\d{3})(\d{3})(\d{2})(\d{2})$/', $phone, $m)) {
            return "+7 {$m[1]} {$m[2]} {$m[3]} {$m[4]}";
        }

        return $phone;
    }

    public static function assertValid(?string $phone): void
    {
        if (! self::isValid($phone)) {
            throw new DomainRuleViolation(__('Телефон :p1.', ['p1' => __(self::HINT)]));
        }
    }
}
