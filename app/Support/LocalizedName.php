<?php

namespace App\Support;

/**
 * Название на языке интерфейса: запрошенный язык → русский → английский → казахский → основное название.
 * Основное название (`name`) — русское или единственное, что есть; переводы хранятся по кодам языков.
 */
class LocalizedName
{
    public const LANGS = ['kk', 'ru', 'en'];

    /** @param  array<string, mixed>|null  $translations */
    public static function pick(?array $translations, string $base, ?string $lang = null): string
    {
        $names = array_filter(array_map(fn ($v) => is_string($v) ? trim($v) : '', (array) $translations), fn ($v) => $v !== '');
        $names += ['ru' => $base];

        foreach ([$lang ?? app()->getLocale(), 'ru', 'en', 'kk'] as $code) {
            if (($names[$code] ?? '') !== '') {
                return $names[$code];
            }
        }

        return $base;
    }

    /** Убирает пустые переводы; пусто совсем — null. @param  array<string, mixed>|null  $translations @return array<string, string>|null */
    public static function clean(?array $translations): ?array
    {
        $clean = array_filter(array_map(fn ($v) => trim($v), array_filter((array) $translations, 'is_string')), fn ($v) => $v !== '');

        return $clean ?: null;
    }

    /**
     * Переводы из ответов PMS по языкам: оставляем непустые; если на всех языках одно и то же, переводов нет.
     *
     * @param  array<string, string>  $byLang  код языка → название
     * @return array<string, string>
     */
    public static function distinct(array $byLang): array
    {
        $byLang = array_filter(array_map(fn ($v) => trim((string) $v), $byLang), fn ($v) => $v !== '');

        return count(array_unique($byLang)) > 1 ? $byLang : [];
    }
}
