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
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Аналитика по сертификатам: продажа, оплата и использование (решение владельца 25.10.2026).
 * Продажа — по дате продажи (`sold_at`), оплата — по дате оплаты платежа (минус выплаченные возвраты), использование — по заезду
 * по брони, а если брони нет — по дате отметки «Использован» в истории. Использованные без известной даты считаются в итогах,
 * но в графики по периодам не попадают. Аннулированные в продажи не входят; бесплатные — отдельно (количество и расход).
 * Период — календарный год или все годы (тогда ряды по годам). Персональных данных клиентов нет.
 */
class AnalyticsReport
{
    /** @return list<int> годы, по которым есть продажи или оплаты, по возрастанию */
    public function years(): array
    {
        $years = Certificate::query()->whereNotNull('issued_at')->get(['sold_at'])->map(fn ($c) => $c->sold_at?->year)
            ->merge(Payment::query()->where('status', PaymentStatus::Paid->value)->whereNotNull('paid_at')->get(['paid_at'])->map(fn ($p) => $p->paid_at->year))
            ->filter()->unique()->sort()->values()->all();

        return array_map('intval', $years);
    }

    /**
     * @return array{
     *     labels: list<string>, sold: list<float>, sold_count: list<int>, paid: list<float>, refunded: list<float>, used: list<float>, used_count: list<int>,
     *     free_count: list<int>, free_cost: list<float>, totals: array<string, float|int>, packages: list<array{name: string, count: int, sum: float}>,
     *     channels: array<string, int>, methods: array<string, float>, statuses: array<string, int>
     * }
     */
    public function build(?int $year = null): array
    {
        $keys = $year ? range(1, 12) : ($this->years() ?: [(int) today()->year]);
        $labels = $year ? array_map(fn ($m) => Carbon::create($year, $m, 1)->translatedFormat('M'), $keys) : array_map('strval', $keys);
        $zero = array_fill_keys($keys, 0);
        $series = ['sold' => $zero, 'sold_count' => $zero, 'paid' => $zero, 'refunded' => $zero, 'used' => $zero, 'used_count' => $zero, 'free_count' => $zero, 'free_cost' => $zero];
        $bucket = fn (?Carbon $date): ?int => $date === null ? null : ($year ? ($date->year === $year ? $date->month : null) : $date->year);
        $totals = ['sold' => 0.0, 'sold_count' => 0, 'paid' => 0.0, 'refunded' => 0.0, 'used' => 0.0, 'used_count' => 0, 'used_unknown_date' => 0, 'free_count' => 0, 'free_cost' => 0.0, 'cancelled' => 0, 'issued' => 0];
        $packages = [];
        $channels = [];
        $statuses = [];

        $certificates = Certificate::query()->whereNotNull('issued_at')->get(['id', 'kind', 'status', 'source', 'package_id', 'sold_at', 'total_amount', 'marketing_cost', 'arrival_date']);
        $usedAt = $this->usedAt($certificates->where('status', CertificateStatus::Used));
        $names = Package::query()->pluck('name', 'id');

        foreach ($certificates as $certificate) {
            $soldKey = $bucket($certificate->sold_at);
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
                    // Дата использования неизвестна: в итогах только при «все годы».
                    if (! $year) {
                        $totals['used'] += (float) $certificate->total_amount;
                        $totals['used_count']++;
                        $totals['used_unknown_date']++;
                    }

                    continue;
                }
                $key = $bucket($date);
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
            $key = $bucket($payment->paid_at);
            if ($key !== null) {
                $series['paid'][$key] += (float) $payment->amount;
                $totals['paid'] += (float) $payment->amount;
                $methods[$payment->method->getLabel()] = ($methods[$payment->method->getLabel()] ?? 0.0) + (float) $payment->amount;
            }
        }
        foreach (Refund::query()->where('status', RefundStatus::Completed->value)->whereNotNull('paid_at')->get(['paid_at', 'amount']) as $refund) {
            $key = $bucket($refund->paid_at);
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
            'labels' => $labels,
            'sold' => array_values($series['sold']), 'sold_count' => array_values($series['sold_count']), 'paid' => array_values($series['paid']), 'refunded' => array_values($series['refunded']),
            'used' => array_values($series['used']), 'used_count' => array_values($series['used_count']), 'free_count' => array_values($series['free_count']), 'free_cost' => array_values($series['free_cost']),
            'totals' => $totals, 'packages' => array_slice($packages, 0, 10), 'channels' => $channels, 'methods' => $methods, 'statuses' => $statuses,
        ];
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
        if ($missing) {
            foreach (array_chunk($missing, 500) as $chunk) {
                CertificateHistory::query()->whereIn('certificate_id', $chunk)->where('event', HistoryEvent::StatusChanged->value)->orderBy('id')
                    ->get(['certificate_id', 'after', 'created_at'])
                    ->each(function ($row) use (&$result) {
                        if (($row->after['status'] ?? null) === CertificateStatus::Used->value) {
                            $result[$row->certificate_id] = $row->created_at;
                        }
                    });
            }
        }

        return $result;
    }
}
