<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Язык интерфейса (решение владельца: порядок KK · RU · EN, язык определяет браузер, запасной — казахский).
 * Порядок выбора: явный выбор (?lang) → профиль пользователя → сессия → cookie gifter_lang → язык браузера
 * (Accept-Language) → config('gifter.default_locale').
 */
final class LocaleResolver
{
    public const COOKIE = 'gifter_lang';

    /** @return list<string> */
    public static function supported(): array
    {
        return array_keys(config('gifter.locales'));
    }

    /** Явный выбор в этом запросе: ?lang=kk|ru|en. */
    public static function explicit(Request $request): ?string
    {
        $lang = (string) $request->query('lang');

        return in_array($lang, self::supported(), true) ? $lang : null;
    }

    public static function resolve(Request $request): string
    {
        $candidates = [
            self::explicit($request),
            $request->user()?->locale,
            $request->hasSession() ? $request->session()->get('locale') : null,
            $request->cookie(self::COOKIE),
            self::browser($request),
        ];
        foreach ($candidates as $locale) {
            if (is_string($locale) && in_array($locale, self::supported(), true)) {
                return $locale;
            }
        }

        return (string) config('gifter.default_locale', 'kk');
    }

    /** Первый поддерживаемый язык из Accept-Language (с учётом приоритета q): «kk-KZ» → kk. */
    public static function browser(Request $request): ?string
    {
        foreach ($request->getLanguages() as $language) {
            $code = strtolower(substr(str_replace('_', '-', $language), 0, 2));
            if (in_array($code, self::supported(), true)) {
                return $code;
            }
        }

        return null;
    }
}
