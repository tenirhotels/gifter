<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Номер сертификата `СЛОВО-СЛОВО-ЦЦЦЦК`, например `TAU-KOL-48273`: два разных слова из NumberWords, четыре случайные цифры
 * и контрольная цифра К. К считается из слов и цифр с секретным ключом (HMAC): опечатка в номере видна сразу, а подобрать номер
 * вслепую втрое-вдесятеро труднее — без ключа верную последнюю цифру не вычислить. Порядок выдачи по номеру не угадать,
 * число продаж по нему не узнать. Год и дата продажи хранятся в самом сертификате.
 * Ключ — тот же, что у хешей контактов (CONTACT_HASH_KEY, иначе APP_KEY): менять его после выдачи номеров нельзя.
 */
final class CertificateNumber
{
    private const PATTERN = '/^([A-Z]{3})([A-Z]{3})(\d{4})(\d)$/';

    public static function random(): string
    {
        $count = count(NumberWords::LIST);
        $a = random_int(0, $count - 1);
        do {
            $b = random_int(0, $count - 1);
        } while ($b === $a);

        $first = NumberWords::LIST[$a];
        $second = NumberWords::LIST[$b];
        $digits = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        return "{$first}-{$second}-{$digits}".self::check($first, $second, $digits);
    }

    /** Контрольная цифра: HMAC от слов и четырёх цифр, остаток от деления на 10. */
    private static function check(string $first, string $second, string $digits): int
    {
        $key = (string) (config('gifter.security.contact_hash_key') ?: config('app.key'));

        return hexdec(substr(hash_hmac('sha256', "number:{$first}{$second}{$digits}", $key), 0, 8)) % 10;
    }

    /** Приводит ввод к каноническому виду (регистр, пробелы, точки и дефисы не важны); null — не похоже на номер. */
    public static function normalize(?string $input): ?string
    {
        $compact = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $input));

        return preg_match(self::PATTERN, $compact, $m) ? "{$m[1]}-{$m[2]}-{$m[3]}{$m[4]}" : null;
    }

    /** Форма верна, оба слова из списка и контрольная цифра сходится (опечатка видна сразу, без запроса в базу). */
    public static function isValid(?string $input): bool
    {
        $number = self::normalize($input);
        if ($number === null) {
            return false;
        }
        [$first, $second, $tail] = explode('-', $number);

        return $first !== $second && in_array($first, NumberWords::LIST, true) && in_array($second, NumberWords::LIST, true)
            && self::check($first, $second, substr($tail, 0, 4)) === (int) $tail[4];
    }

    /** Возможных номеров при текущем списке слов. */
    public static function space(): int
    {
        $count = count(NumberWords::LIST);

        return $count * ($count - 1) * 10000;
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
