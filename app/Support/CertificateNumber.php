<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Номер сертификата `СЛОВО-СЛОВО-ЦЦЦЦ`, например `TAU-KOL-4827`: два разных слова из NumberWords и четыре случайные цифры.
 * Порядок выдачи по номеру не угадать, число продаж по нему не узнать. Год и дата продажи хранятся в самом сертификате.
 */
final class CertificateNumber
{
    private const PATTERN = '/^([A-Z]{3})([A-Z]{3})(\d{4})$/';

    public static function random(): string
    {
        $count = count(NumberWords::LIST);
        $a = random_int(0, $count - 1);
        do {
            $b = random_int(0, $count - 1);
        } while ($b === $a);

        return NumberWords::LIST[$a].'-'.NumberWords::LIST[$b].'-'.str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    }

    /** Приводит ввод к каноническому виду (регистр, пробелы, точки и дефисы не важны); null — не похоже на номер. */
    public static function normalize(?string $input): ?string
    {
        $compact = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $input));

        return preg_match(self::PATTERN, $compact, $m) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
    }

    /** Форма верна и оба слова из списка (опечатка в слове видна сразу, без запроса в базу). */
    public static function isValid(?string $input): bool
    {
        $number = self::normalize($input);
        if ($number === null) {
            return false;
        }
        [$first, $second] = explode('-', $number);

        return $first !== $second && in_array($first, NumberWords::LIST, true) && in_array($second, NumberWords::LIST, true);
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
            $canonical = self::normalize($search);

            return $query->where(fn ($q) => $q->where($column, 'like', '%'.trim($search).'%')->when($canonical, fn ($w) => $w->orWhere($column, $canonical)));
        };
    }
}
