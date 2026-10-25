<?php

namespace App\Services;

use App\Enums\CertificateKind;
use App\Enums\CertificateStatus;
use App\Enums\HistoryEvent;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\Certificate;
use App\Models\CertificateHistory;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Refund;
use App\Support\AnalyticsPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Аналитика по сертификатам: продажа, оплата и использование (решение владельца 25.10.2026).
 * Продажа — по дате продажи (`sold_at`), оплата — по дате оплаты платежа (минус выплаченные возвраты), использование — по заезду
 * по брони, а если брони нет — по дате отметки «Использован» в истории. Использованные без известной даты считаются в итогах
 * «Всё время», но в графики по периодам не попадают. Аннулированные в продажи не входят; бесплатные — отдельно (количество и расход).
 * Период — пресет или год (`AnalyticsPeriod`), ряды по месяцам или по годам. Результат кэшируется на минуту. Персональных данных клиентов нет.
 */
class AnalyticsReport
{
    private const ZERO_TOTALS = ['sold' => 0.0, 'sold_count' => 0, 'paid' => 0.0, 'refunded' => 0.0, 'used' => 0.0, 'used_count' => 0, 'used_unknown_date' => 0, 'free_count' => 0, 'free_cost' => 0.0, 'cancelled' => 0, 'issued' => 0];

    /** @return list<int> годы, по которым есть продажи или оплаты, по возрастанию */
    public function years(): array
    {
        $years = Certificate::query()->whereNotNull('issued_at')->get(['sold_at'])->map(fn ($c) => $c->sold_at?->year)
            ->merge(Payment::query()->where('status', PaymentStatus::Paid->value)->whereNotNull('paid_at')->get(['paid_at'])->map(fn ($p) => $p->paid_at->year))
            ->filter()->unique()->sort()->values()->all();

        return array_map('intval', $years);
    }

    /** Отчёт за период с кэшем на минуту (ключ включает отпечаток данных, чтобы изменения были видны сразу). */
    public function build(AnalyticsPeriod $period): array
    {
        $fingerprint = implode('|', [Certificate::query()->count(), (string) Certificate::query()->max('updated_at'), Payment::query()->count(), (string) Payment::query()->max('updated_at'), Refund::query()->count()]);

        return Cache::remember('analytics:'.sha1($period->key.$period->from.$period->to.($period->monthly ? 'm' : 'y').$fingerprint), 60, fn () => $this->compute($period));
    }

    /**
     * @return array{
     *     labels: list<string>, sold: list<float>, sold_count: list<int>, paid: list<float>, refunded: list<float>, used: list<float>, used_count: list<int>,
     *     free_count: list<int>, free_cost: list<float>, totals: array<string, float|int>, packages: list<array{name: string, count: int, sum: float}>,
     *     channels: array<string, int>, methods: array<string, float>, statuses: array<string, int>, monthly: bool
     * }
     */
    private function compute(AnalyticsPeriod $period): array
    {
        $buckets = $period->buckets();
        $keys = array_keys($buckets);
        $zero = array_fill_keys($keys, 0);
        $series = ['sold' => $zero, 'sold_count' => $zero, 'paid' => $zero, 'refunded' => $zero, 'used' => $zero, 'used_count' => $zero, 'free_count' => $zero, 'free_cost' => $zero];
        $totals = self::ZERO_TOTALS;
        $packages = [];
        $channels = [];
        $statuses = [];

        $certificates = Certificate::query()->whereNotNull('issued_at')->get(['id', 'kind', 'status', 'source', 'package_id', 'sold_at', 'total_amount', 'marketing_cost', 'arrival_date']);
        $usedAt = $this->usedAt($certificates->where('status', CertificateStatus::Used));
        $names = Package::query()->pluck('name', 'id');

        foreach ($certificates as $certificate) {
            $soldKey = $period->bucketOf($certificate->sold_at);
            $isFree = $certificate->kind === CertificateKind::Complimentary;
            if ($soldKey !== null) {
                $totals['issued']++;
                $statuses[$certificate->status->getLabel()] = ($statuses[$certificate->status->getLabel()] ?? 0) + 1;
                if ($certificate->status === CertificateStatus::Cancelled) {
                    $totals['cancelled']++;
                } elseif ($isFree) {
                    $series['free_count'][$soldKey]++;
                    $series['free_cost'][$soldKey] += (float) $certificate->marketing_cost;
                    $totals['free_count']++;
                    $totals['free_cost'] += (float) $certificate->marketing_cost;
                    $channels[__('Бесплатные')] = ($channels[__('Бесплатные')] ?? 0) + 1;
                } else {
                    $amount = (float) $certificate->total_amount;
                    $series['sold'][$soldKey] += $amount;
                    $series['sold_count'][$soldKey]++;
                    $totals['sold'] += $amount;
                    $totals['sold_count']++;
                    $name = (string) ($names[$certificate->package_id] ?? '—');
                    $packages[$name] = ['name' => $name, 'count' => ($packages[$name]['count'] ?? 0) + 1, 'sum' => ($packages[$name]['sum'] ?? 0.0) + $amount];
                    $channel = $certificate->source->getLabel();
                    $channels[$channel] = ($channels[$channel] ?? 0) + 1;
                }
            }
            if ($certificate->status === CertificateStatus::Used && ! $isFree) {
                $date = $usedAt[$certificate->id] ?? null;
                if ($date === null) {
                    if ($period->key === AnalyticsPeriod::ALL) { // дата неизвестна: только в итогах «Всё время»
                        $totals['used'] += (float) $certificate->total_amount;
                        $totals['used_count']++;
                        $totals['used_unknown_date']++;
                    }

                    continue;
                }
                $key = $period->bucketOf($date);
                if ($key !== null) {
                    $series['used'][$key] += (float) $certificate->total_amount;
                    $series['used_count'][$key]++;
                    $totals['used'] += (float) $certificate->total_amount;
                    $totals['used_count']++;
                }
            }
        }

        $methods = [];
        foreach (Payment::query()->where('status', PaymentStatus::Paid->value)->whereNotNull('paid_at')->get(['paid_at', 'amount', 'method']) as $payment) {
            $key = $period->bucketOf($payment->paid_at);
            if ($key !== null) {
                $series['paid'][$key] += (float) $payment->amount;
                $totals['paid'] += (float) $payment->amount;
                $methods[$payment->method->getLabel()] = ($methods[$payment->method->getLabel()] ?? 0.0) + (float) $payment->amount;
            }
        }
        foreach (Refund::query()->where('status', RefundStatus::Completed->value)->whereNotNull('paid_at')->get(['paid_at', 'amount']) as $refund) {
            $key = $period->bucketOf($refund->paid_at);
            if ($key !== null) {
                $series['refunded'][$key] += (float) $refund->amount;
                $totals['refunded'] += (float) $refund->amount;
            }
        }

        usort($packages, fn ($a, $b) => [$b['count'], $b['sum']] <=> [$a['count'], $a['sum']]);
        arsort($channels);
        arsort($methods);
        arsort($statuses);

        return [
            'labels' => array_values($buckets), 'monthly' => $period->monthly,
            'sold' => array_values($series['sold']), 'sold_count' => array_values($series['sold_count']), 'paid' => array_values($series['paid']), 'refunded' => array_values($series['refunded']),
            'used' => array_values($series['used']), 'used_count' => array_values($series['used_count']), 'free_count' => array_values($series['free_count']), 'free_cost' => array_values($series['free_cost']),
            'totals' => $totals, 'packages' => array_slice($packages, 0, 10), 'channels' => $channels, 'methods' => $methods, 'statuses' => $statuses,
        ];
    }

    /** Есть ли в периоде хоть что-то (для пустого состояния). @param  array<string, mixed>  $report */
    public function isEmpty(array $report): bool
    {
        $t = $report['totals'];

        return $t['issued'] === 0 && $t['paid'] == 0 && $t['used_count'] === 0;
    }

    /**
     * Обязательства: действующие непогашенные продажи (выручка получена или продана, но услуга ещё не оказана) на сегодня.
     *
     * @return array{count: int, sum: float}
     */
    public function outstanding(): array
    {
        $rows = Certificate::query()->where('kind', CertificateKind::Sale->value)->whereNotNull('issued_at')
            ->whereIn('status', [CertificateStatus::Active->value, CertificateStatus::Reserved->value])->get(['total_amount']);

        return ['count' => $rows->count(), 'sum' => (float) $rows->sum('total_amount')];
    }

    /** Средний срок от продажи до использования, дней (только где дата использования известна). */
    public function averageDaysToUse(): ?float
    {
        $used = Certificate::query()->where('status', CertificateStatus::Used->value)->where('kind', CertificateKind::Sale->value)->whereNotNull('issued_at')->get(['id', 'sold_at', 'arrival_date']);
        $dates = $this->usedAt($used);
        $days = $used->map(fn ($c) => ($dates[$c->id] ?? null) && $c->sold_at ? max(0, $c->sold_at->diffInDays($dates[$c->id], false)) : null)->filter(fn ($d) => $d !== null);

        return $days->isEmpty() ? null : round((float) $days->avg(), 1);
    }

    /**
     * Когорты по году продажи: сколько продано, какая доля использована, истекла, аннулирована и ещё действует («несгоревший остаток»).
     *
     * @return list<array{year: int, count: int, sum: float, used: int, expired: int, cancelled: int, active: int, used_pct: float}>
     */
    public function cohorts(): array
    {
        $rows = [];
        foreach (Certificate::query()->where('kind', CertificateKind::Sale->value)->whereNotNull('issued_at')->get(['status', 'sold_at', 'total_amount']) as $c) {
            $year = $c->sold_at?->year;
            if (! $year) {
                continue;
            }
            $r = $rows[$year] ?? ['year' => $year, 'count' => 0, 'sum' => 0.0, 'used' => 0, 'expired' => 0, 'cancelled' => 0, 'active' => 0, 'used_pct' => 0.0];
            $r['count']++;
            $r['sum'] += (float) $c->total_amount;
            match ($c->status) {
                CertificateStatus::Used => $r['used']++,
                CertificateStatus::Expired => $r['expired']++,
                CertificateStatus::Cancelled => $r['cancelled']++,
                default => $r['active']++,
            };
            $rows[$year] = $r;
        }
        ksort($rows);

        return array_values(array_map(fn ($r) => array_merge($r, ['used_pct' => $r['count'] > 0 ? round($r['used'] / $r['count'] * 100, 1) : 0.0]), $rows));
    }

    /** Сезонность: среднее число платных продаж по календарным месяцам за все годы. @return list<float> */
    public function seasonality(): array
    {
        $byMonth = array_fill(1, 12, 0);
        $years = [];
        foreach (Certificate::query()->where('kind', CertificateKind::Sale->value)->whereNotNull('issued_at')->where('status', '!=', CertificateStatus::Cancelled->value)->get(['sold_at']) as $c) {
            if ($c->sold_at) {
                $byMonth[$c->sold_at->month]++;
                $years[$c->sold_at->year] = true;
            }
        }
        $n = max(1, count($years));

        return array_values(array_map(fn ($v) => round($v / $n, 1), $byMonth));
    }

    /**
     * Дата использования: заезд по брони, иначе дата последней отметки «Использован» в истории; нет ни того ни другого — null.
     *
     * @param  Collection<int, Certificate>  $used
     * @return array<int, ?Carbon>
     */
    private function usedAt($used): array
    {
        $result = [];
        foreach ($used as $certificate) {
            $result[$certificate->id] = $certificate->arrival_date;
        }
        $missing = array_keys(array_filter($result, fn ($d) => $d === null));
        foreach (array_chunk($missing, 500) as $chunk) {
            CertificateHistory::query()->whereIn('certificate_id', $chunk)->where('event', HistoryEvent::StatusChanged->value)->orderBy('id')
                ->get(['certificate_id', 'after', 'created_at'])
                ->each(function ($row) use (&$result) {
                    if (($row->after['status'] ?? null) === CertificateStatus::Used->value) {
                        $result[$row->certificate_id] = $row->created_at;
                    }
                });
        }

        return $result;
    }
}
