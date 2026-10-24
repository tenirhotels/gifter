<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Номер сертификата `СЛОВО-ЦЦЦЦЦК`, например `TAU-482731`: одно слово из NumberWords, пять случайных цифр и контрольная цифра К.
 * К считается из слова и цифр с секретным ключом (HMAC): опечатка в номере видна сразу, а подобрать номер вслепую в десять раз
 * труднее — без ключа верную последнюю цифру не вычислить. Порядок выдачи по номеру не угадать, число продаж по нему не узнать.
 * Год и дата продажи хранятся в самом сертификате.
 * Ключ — тот же, что у хешей контактов (CONTACT_HASH_KEY, иначе APP_KEY): менять его после выдачи номеров нельзя.
 */
final class CertificateNumber
{
    private const PATTERN = '/^([A-Z]{3})(\d{5})(\d)$/';

    public static function random(): string
    {
        $word = NumberWords::LIST[random_int(0, count(NumberWords::LIST) - 1)];
        $digits = str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);

        return "{$word}-{$digits}".self::check($word, $digits);
    }

    /** Контрольная цифра: HMAC от слова и пяти цифр, остаток от деления на 10. */
    private static function check(string $word, string $digits): int
    {
        $key = (string) (config('gifter.security.contact_hash_key') ?: config('app.key'));

        return hexdec(substr(hash_hmac('sha256', "number:{$word}{$digits}", $key), 0, 8)) % 10;
    }

    /** Приводит ввод к каноническому виду (регистр, пробелы, точки и дефисы не важны); null — не похоже на номер. */
    public static function normalize(?string $input): ?string
    {
        $compact = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $input));

        return preg_match(self::PATTERN, $compact, $m) ? "{$m[1]}-{$m[2]}{$m[3]}" : null;
    }

    /** Форма верна, слово из списка и контрольная цифра сходится (опечатка видна сразу, без запроса в базу). */
    public static function isValid(?string $input): bool
    {
        $number = self::normalize($input);
        if ($number === null) {
            return false;
        }
        [$word, $tail] = explode('-', $number);

        return in_array($word, NumberWords::LIST, true) && self::check($word, substr($tail, 0, 5)) === (int) $tail[5];
    }

    /** Возможных номеров при текущем списке слов. */
    public static function space(): int
    {
        return count(NumberWords::LIST) * 100000;
    }

    /**
     * Условие поиска по номеру для колонок таблиц: часть номера как есть либо номер, набранный в другом регистре и без дефисов.
     *
     * @return \Closure(Builder, string): Builder
     */
    public static function searchQuery(string $column = 'number'): \Closure
    {
        return function (Builder $query, string $search) use ($column) {
            // Номер с неверной контрольной цифрой не ищем: он заведомо не существует.
            $canonical = self::isValid($search) ? self::normalize($search) : null;

            return $query->where(fn ($q) => $q->where($column, 'like', '%'.trim($search).'%')->when($canonical, fn ($w) => $w->orWhere($column, $canonical)));
        };
    }
}
