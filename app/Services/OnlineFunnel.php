<?php

namespace App\Services;

use App\Enums\CertificateStatus;
use App\Enums\PaymentStatus;
use App\Models\Certificate;
use App\Models\OnlineOrder;
use App\Support\Money;
use Illuminate\Support\Carbon;

/** Воронка продаж с сайта за период (по дате заявки): заявки → оформлены → оплачены → выпущены → использованы. */
class OnlineFunnel
{
    public const PERIODS = ['this' => 'Этот месяц', 'last' => 'Прошлый месяц', 'year' => 'Этот год'];

    /** @return array{steps: array<int, array{label: string, count: int, percent: ?int}>, amount: string} */
    public function build(string $period = 'this'): array
    {
        [$from, $until] = SellerReport::range(array_key_exists($period, self::PERIODS) ? $period : 'this');
        $orders = OnlineOrder::query()->whereBetween('created_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($until)->endOfDay()]);
        $certificates = Certificate::query()->whereIn('id', (clone $orders)->whereNotNull('certificate_id')->select('certificate_id'));
        $issued = (clone $certificates)->whereNotNull('issued_at')->where('status', '!=', CertificateStatus::Cancelled);

        $counts = [
            __('Заявки') => (clone $orders)->count(),
            __('Оформлены') => (clone $certificates)->count(),
            __('Оплачены') => (clone $certificates)->whereHas('payments', fn ($q) => $q->where('status', PaymentStatus::Paid))->count(),
            __('Выпущены') => (clone $issued)->count(),
            __('Использованы') => (clone $certificates)->where('status', CertificateStatus::Used)->count(),
        ];
        $first = reset($counts);

        return [
            'steps' => collect($counts)->map(fn (int $count, string $label) => [
                'label' => $label,
                'count' => $count,
                'percent' => $first > 0 ? (int) round($count * 100 / $first) : null,
            ])->values()->all(),
            'amount' => Money::format((clone $issued)->sum('total_amount')),
        ];
    }
}
