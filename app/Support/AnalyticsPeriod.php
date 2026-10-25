<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Период аналитики: пресет («Этот год», «Прошлый год», «Последние 12 месяцев», «Всё время») или конкретный год.
 * Детализация — по месяцам, если период не длиннее 24 месяцев, иначе по годам.
 */
final class AnalyticsPeriod
{
    public const THIS_YEAR = 'this_year';

    public const LAST_YEAR = 'last_year';

    public const LAST_12 = 'last_12';

    public const ALL = 'all';

    private function __construct(
        public readonly string $key,
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly bool $monthly,
        public readonly bool $comparable,
    ) {}

    /** @param  list<int>  $years годы, по которым есть данные (для «Всё время») */
    public static function resolve(string|int|null $key, array $years = []): self
    {
        $today = today();
        $key = (string) ($key ?: self::ALL);

        return match (true) {
            $key === self::THIS_YEAR => self::make($key, $today->copy()->startOfYear(), $today->copy()->endOfYear(), true),
            $key === self::LAST_YEAR => self::make($key, $today->copy()->subYear()->startOfYear(), $today->copy()->subYear()->endOfYear(), true),
            $key === self::LAST_12 => self::make($key, $today->copy()->subMonthsNoOverflow(11)->startOfMonth(), $today->copy()->endOfMonth(), true),
            ctype_digit($key) && (int) $key >= 2000 && (int) $key <= 2100 => self::make($key, Carbon::create((int) $key, 1, 1)->startOfDay(), Carbon::create((int) $key, 12, 31)->endOfDay(), true),
            default => self::all($years),
        };
    }

    /** «Всё время» всегда по годам (для таблицы «По годам»). @param  list<int>  $years */
    public static function yearly(array $years): self
    {
        $first = $years ? min($years) : (int) today()->year;

        return new self(self::ALL, Carbon::create($first, 1, 1)->startOfDay(), today()->copy()->endOfYear(), false, false);
    }

    /** @param  list<int>  $years */
    private static function all(array $years): self
    {
        $first = $years ? min($years) : (int) today()->year;
        $from = Carbon::create($first, 1, 1)->startOfDay();
        $to = today()->copy()->endOfYear();

        return new self(self::ALL, $from, $to, self::months($from, $to) <= 24, false);
    }

    private static function make(string $key, Carbon $from, Carbon $to, bool $comparable): self
    {
        return new self($key, $from, $to, self::months($from, $to) <= 24, $comparable);
    }

    private static function months(Carbon $from, Carbon $to): int
    {
        return (int) ($from->diffInMonths($to)) + 1;
    }

    /** Предыдущий период той же длины (для сравнения); у «Всё время» его нет. */
    public function previous(): ?self
    {
        if (! $this->comparable) {
            return null;
        }
        $months = self::months($this->from, $this->to);
        $from = $this->from->copy()->subMonthsNoOverflow($months);
        $to = $from->copy()->addMonthsNoOverflow($months)->subDay()->endOfDay();

        return new self($this->key.':prev', $from, $to, $this->monthly, true);
    }

    /** @return array<string, string> ключ корзины => подпись */
    public function buckets(): array
    {
        $buckets = [];
        if ($this->monthly) {
            $multiYear = $this->from->year !== $this->to->year;
            for ($d = $this->from->copy()->startOfMonth(); $d->lte($this->to); $d->addMonthNoOverflow()) {
                $buckets[$d->format('Y-m')] = ucfirst($d->translatedFormat($multiYear ? 'M y' : 'M'));
            }
        } else {
            for ($y = $this->from->year; $y <= $this->to->year; $y++) {
                $buckets[(string) $y] = (string) $y;
            }
        }

        return $buckets;
    }

    /** Ключ корзины для даты или null, если дата вне периода. */
    public function bucketOf(?Carbon $date): ?string
    {
        if ($date === null || $date->lt($this->from) || $date->gt($this->to)) {
            return null;
        }

        return $this->monthly ? $date->format('Y-m') : (string) $date->year;
    }

    public function label(): string
    {
        return match (true) {
            $this->key === self::THIS_YEAR => __('Этот год'),
            $this->key === self::LAST_YEAR => __('Прошлый год'),
            $this->key === self::LAST_12 => __('Последние 12 месяцев'),
            $this->key === self::ALL => __('Всё время'),
            default => $this->from->format('Y').' '.__('год'),
        };
    }
}
