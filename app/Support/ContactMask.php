<?php

namespace App\Support;

/** Маскирование контактов в списках и карточках: полностью — только по кнопке «Показать». */
final class ContactMask
{
    public static function phone(?string $phone): ?string
    {
        if (blank($phone)) {
            return null;
        }
        $digits = preg_replace('/\D/', '', $phone);

        return strlen($digits) <= 4 ? '••••' : '•••• '.substr($digits, -4);
    }

    public static function email(?string $email): ?string
    {
        if (blank($email) || ! str_contains($email, '@')) {
            return blank($email) ? null : '•••';
        }
        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 1).'•••@'.$domain;
    }
}
