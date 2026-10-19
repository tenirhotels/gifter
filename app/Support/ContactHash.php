<?php

namespace App\Support;

/**
 * Хеш контакта для поиска: телефон и email в базе зашифрованы, поэтому искать можно
 * только по точному совпадению хеша. Номер приводится к цифрам (8 7xx… → 7 7xx…), email — к нижнему регистру.
 */
final class ContactHash
{
    public static function phone(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);
        if (strlen($digits) === 11 && str_starts_with($digits, '8')) {
            $digits = '7'.substr($digits, 1);
        }

        return strlen($digits) < 5 ? null : self::hash('phone:'.$digits);
    }

    public static function email(?string $email): ?string
    {
        $email = mb_strtolower(trim((string) $email));

        return str_contains($email, '@') ? self::hash('email:'.$email) : null;
    }

    /** Похоже ли на телефон или email — тогда поиск идёт по хешу. */
    public static function forSearch(string $search): ?array
    {
        $search = trim($search);
        if (str_contains($search, '@')) {
            return ['email_hash', self::email($search)];
        }
        if (preg_match('/^[\d\s()+\-]+$/', $search) && strlen(preg_replace('/\D/', '', $search)) >= 10) {
            return ['phone_hash', self::phone($search)];
        }

        return null;
    }

    public static function usesDedicatedKey(): bool
    {
        return filled(config('gifter.security.contact_hash_key'));
    }

    private static function hash(string $value): string
    {
        return hash_hmac('sha256', $value, (string) (config('gifter.security.contact_hash_key') ?: config('app.key')));
    }
}
