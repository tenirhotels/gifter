<?php

namespace App\Support;

/** Имя гостя из PMS для показа: при несовпадении с получателем и заказчиком — только имя и первая буква фамилии. */
final class GuestName
{
    public static function masked(?string $first, ?string $last): string
    {
        $first = trim((string) $first);
        $last = trim((string) $last);
        $initial = $last !== '' ? mb_strtoupper(mb_substr($last, 0, 1)).'.' : '';

        return trim($first.' '.$initial) ?: '—';
    }
}
