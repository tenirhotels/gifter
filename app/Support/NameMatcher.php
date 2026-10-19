<?php

namespace App\Support;

/**
 * Сверка имени гостя в брони с фамилией и именем человека (фаза 6).
 *
 * Сравниваются фамилия и имя в любом порядке; отчество и лишние слова не мешают.
 * Кириллица (русская и казахская) переводится в латиницу, варианты латиницы
 * сводятся вместе: «Койчиманов Нуржан» = «KOICHIMANOV NURZHAN» = «Nurjan Koychimanov».
 */
final class NameMatcher
{
    private const CYRILLIC = [
        'а' => 'a', 'ә' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'ғ' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e',
        'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'i', 'і' => 'i', 'к' => 'k', 'қ' => 'k', 'л' => 'l', 'м' => 'm',
        'н' => 'n', 'ң' => 'n', 'о' => 'o', 'ө' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
        'ұ' => 'u', 'ү' => 'u', 'ф' => 'f', 'х' => 'kh', 'һ' => 'h', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh',
        'щ' => 'shch', 'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'iu', 'я' => 'ia',
    ];

    /** Разные способы записать один звук латиницей → один вариант. */
    private const LATIN = ['kh' => 'h', 'tz' => 'c', 'ts' => 'c', 'dzh' => 'j', 'zh' => 'j', 'dj' => 'j', 'ph' => 'f', 'ck' => 'k', 'q' => 'k', 'w' => 'v', 'y' => 'i', 'x' => 'h'];

    /** Совпадают ли фамилия и имя человека с именем в брони. */
    public static function matches(string $bookingName, ?string $lastName, ?string $firstName): bool
    {
        // Двойная фамилия («Петрова-Водкина») — все её части должны быть в брони.
        $required = [...self::tokens((string) $lastName), ...self::tokens((string) $firstName)];
        if (self::tokens((string) $lastName) === [] || self::tokens((string) $firstName) === []) {
            return false;
        }

        return array_diff($required, self::tokens($bookingName)) === [];
    }

    /** @return list<string> */
    public static function tokens(string $name): array
    {
        $parts = preg_split('/[\s\-]+/u', trim($name)) ?: [];

        return array_values(array_filter(array_map(self::token(...), $parts)));
    }

    private static function token(string $word): string
    {
        $word = strtr(mb_strtolower($word), self::CYRILLIC);
        $word = preg_replace('/[^a-z]/', '', $word) ?? '';
        $word = strtr($word, self::LATIN);
        // «Е» в начале слова в паспортах пишут как «Ye»: Ерлан = Yerlan, Елена = Yelena.
        $word = preg_replace('/^ie/', 'e', $word) ?? '';

        // Удвоенные буквы («Юлия» → iuliia, «Yuliya» → iuliia) пишут по-разному.
        return preg_replace('/(.)\1+/', '$1', $word) ?? '';
    }
}
