<?php

namespace App\Services;

use App\Enums\CertificateKind;
use App\Enums\CertificateStatus;
use App\Enums\CommissionStatus;
use App\Models\Certificate;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Отчёт по продавцам за период для Финансов и директора: сводка, список сертификатов и разбивка по месяцам.
 * Срез «по использованию» — по месяцу, когда комиссия начислена (сертификат использован); «по продаже» — по дате продажи.
 * Только чтение: суммы берутся из снимков комиссий, ничего не пересчитывается.
 */
final class SellerReport
{
    public const USED = 'used';

    public const SOLD = 'sold';

    public function __construct(
        public readonly string $basis = self::USED,
        public readonly ?string $from = null,
        public readonly ?string $to = null,
        public readonly ?int $sellerId = null,
    ) {}

    /**
     * Границы периода по пресету: this, last, quarter, year, custom (свои даты), иначе — весь период.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function range(?string $preset, ?string $from = null, ?string $until = null): array
    {
        $now = now();

        return match ($preset) {
            'this' => [$now->copy()->startOfMonth()->toDateString(), $now->copy()->endOfMonth()->toDateString()],
            'last' => [$now->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(), $now->copy()->subMonthNoOverflow()->endOfMonth()->toDateString()],
            'quarter' => [$now->copy()->startOfQuarter()->toDateString(), $now->copy()->endOfQuarter()->toDateString()],
            'year' => [$now->copy()->startOfYear()->toDateString(), $now->copy()->endOfYear()->toDateString()],
            'custom' => [$from, $until],
            default => [null, null],
        };
    }

    /** @return Collection<int, Certificate> */
    private function certificates(): Collection
    {
        $between = fn (Builder $q, string $column) => $q->when($this->from, fn ($q) => $q->whereDate($column, '>=', $this->from))
            ->when($this->to, fn ($q) => $q->whereDate($column, '<=', $this->to));

        return Certificate::query()->where('kind', CertificateKind::Sale)->whereNotNull('issued_at')->whereNotNull('sold_by')
            ->where('status', '!=', CertificateStatus::Cancelled)
            ->when($this->sellerId, fn ($q) => $q->where('sold_by', $this->sellerId))
            ->when(
                $this->basis === self::USED,
                fn ($q) => $q->whereHas('commission', fn ($c) => $between($c->whereNotNull('earned_at'), 'earned_at')),
                fn ($q) => $between($q, 'sold_at'),
            )
            ->with(['seller', 'customer', 'package', 'commission.payments'])
            ->orderBy('sold_by')->orderBy('sold_at')->orderBy('id')->get();
    }

    /**
     * Строка на каждый сертификат.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function details(): Collection
    {
        return $this->certificates()->map(function (Certificate $c) {
            $commission = $c->commission;
            $payment = $commission?->payments->sortByDesc('paid_at')->first();

            return [
                'seller_id' => $c->sold_by,
                'seller' => $c->seller?->name ?? '—',
                'number' => $c->number,
                'sold_at' => $c->sold_at,
                'package' => $c->package?->name,
                'customer' => $c->customer?->displayName(),
                'status' => $c->status->getLabel(),
                'status_enum' => $c->status,
                'booking' => $c->pms_booking_number,
                'used' => $c->status === CertificateStatus::Used,
                'used_at' => $commission?->earned_at,
                'sale_minor' => Money::toMinor($c->total_amount),
                'commission_status' => $commission?->status->getLabel(),
                'commission_state' => $commission?->status,
                'commission_minor' => $commission && $commission->status !== CommissionStatus::Void ? Money::toMinor($commission->amount) : 0,
                'paid_at' => $payment?->paid_at,
                'reference' => $payment?->reference,
            ];
        });
    }

    /**
     * Итоги по продавцам.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function summary(): Collection
    {
        return $this->details()->groupBy('seller_id')->map(function (Collection $rows) {
            $earned = fn (Collection $r) => $r->filter(fn ($x) => $x['commission_state']?->isEarned());
            $of = fn (CommissionStatus $s) => $rows->where('commission_state', $s)->sum('commission_minor');

            return [
                'seller' => $rows->first()['seller'],
                'seller_id' => $rows->first()['seller_id'],
                'count' => $rows->count(),
                'sales_minor' => $rows->sum('sale_minor'),
                'used' => $rows->where('used', true)->count(),
                'not_used' => $rows->where('used', false)->count(),
                'expired' => $rows->where('status_enum', CertificateStatus::Expired)->count(),
                'no_reservation' => $rows->filter(fn ($x) => $x['status_enum'] === CertificateStatus::Active && blank($x['booking']))->count(),
                'earned_minor' => $earned($rows)->sum('commission_minor'),
                'to_approve_minor' => $of(CommissionStatus::Earned),
                'approved_minor' => $of(CommissionStatus::Approved),
                'paid_minor' => $of(CommissionStatus::Paid),
            ];
        })->sortBy('seller')->values();
    }

    /**
     * Начисленная комиссия: продавец × месяц (по выбранному срезу).
     *
     * @return array{months: list<string>, rows: list<array{seller_id: int, seller: string, values: array<string, int>, total: int}>}
     */
    public function months(): array
    {
        $details = $this->details()->filter(fn ($r) => $r['commission_state']?->isEarned());
        $key = fn (array $r) => ($this->basis === self::USED ? $r['used_at'] : $r['sold_at'])?->format('Y-m');
        $months = $details->map($key)->filter()->unique()->sort()->values()->all();
        $rows = $details->groupBy('seller_id')->map(function (Collection $items) use ($key, $months) {
            $seller = $items->first()['seller'];
            $values = array_fill_keys($months, 0);
            foreach ($items as $item) {
                if ($month = $key($item)) {
                    $values[$month] += $item['commission_minor'];
                }
            }

            return ['seller_id' => $items->first()['seller_id'], 'seller' => $seller, 'values' => $values, 'total' => array_sum($values)];
        })->sortBy('seller')->values()->all();

        return ['months' => $months, 'rows' => $rows];
    }

    /** Файл .xlsx с тремя листами: «Сводка», «Сертификаты», «По месяцам». Возвращает путь во временной папке. */
    public function toXlsx(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sellers_').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $head = (new Style)->setFontBold();
        $money = fn (int $minor) => (float) Money::fromMinor($minor);
        $date = fn ($d) => $d?->format('d.m.Y');

        $writer->getCurrentSheet()->setName(__('Сводка'));
        $writer->addRow(Row::fromValues([__('Отчёт по продавцам'), $this->periodLabel()], $head));
        $writer->addRow(Row::fromValues([__('Продавец'), __('Сертификатов'), __('Сумма продаж, ₸'), __('Использовано'), __('Не использовано'), __('Без брони'),
            __('Начислено, ₸'), __('К согласованию, ₸'), __('К выплате, ₸'), __('Выплачено, ₸')], $head));
        $summary = $this->summary();
        foreach ($summary as $s) {
            $writer->addRow(Row::fromValues([$s['seller'], $s['count'], $money($s['sales_minor']), $s['used'], $s['not_used'], $s['no_reservation'],
                $money($s['earned_minor']), $money($s['to_approve_minor']), $money($s['approved_minor']), $money($s['paid_minor'])]));
        }
        $writer->addRow(Row::fromValues([__('Итого'), $summary->sum('count'), $money($summary->sum('sales_minor')), $summary->sum('used'), $summary->sum('not_used'), $summary->sum('no_reservation'),
            $money($summary->sum('earned_minor')), $money($summary->sum('to_approve_minor')), $money($summary->sum('approved_minor')), $money($summary->sum('paid_minor'))], $head));

        $writer->addNewSheetAndMakeItCurrent()->setName(__('Сертификаты'));
        $writer->addRow(Row::fromValues([__('Продавец'), __('Номер'), __('Дата продажи'), __('Пакет'), __('Заказчик'), __('Статус сертификата'),
            __('Дата использования'), __('Сумма продажи, ₸'), __('Комиссия'), __('Комиссия, ₸'), __('Дата выплаты'), __('Номер платежа или ведомости')], $head));
        foreach ($this->details() as $r) {
            $writer->addRow(Row::fromValues([$r['seller'], $r['number'], $date($r['sold_at']), $r['package'], $r['customer'], $r['status'],
                $date($r['used_at']), $money($r['sale_minor']), $r['commission_status'], $money($r['commission_minor']), $date($r['paid_at']), $r['reference']]));
        }

        $writer->addNewSheetAndMakeItCurrent()->setName(__('По месяцам'));
        $months = $this->months();
        $writer->addRow(Row::fromValues([__('Продавец'), ...$months['months'], __('Итого')], $head));
        foreach ($months['rows'] as $r) {
            $writer->addRow(Row::fromValues([$r['seller'], ...array_map($money, array_values($r['values'])), $money($r['total'])]));
        }
        $writer->close();

        return $path;
    }

    public function periodLabel(): string
    {
        $basis = $this->basis === self::USED ? __('по использованию') : __('по дате продажи');
        $period = ($this->from || $this->to)
            ? ($this->from ? date('d.m.Y', strtotime($this->from)) : '…').' – '.($this->to ? date('d.m.Y', strtotime($this->to)) : '…')
            : __('весь период');

        return "{$basis}: {$period}";
    }
}
