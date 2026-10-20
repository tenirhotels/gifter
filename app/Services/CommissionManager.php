<?php

namespace App\Services;

use App\Enums\CertificateKind;
use App\Enums\CertificateStatus;
use App\Enums\CommissionStatus;
use App\Enums\CommissionType;
use App\Exceptions\DomainRuleViolation;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\CertificateCommission;
use App\Models\CommissionRule;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Комиссия продавцов (решение владельца 19.10.2026).
 *
 * Комиссия начисляется не при продаже, а при погашении сертификата. При выпуске продажи
 * сохраняется снимок: правило на дату продажи, тип, ставка, цена и рассчитанная сумма — по будущим
 * правилам она не пересчитывается. Путь: Ждёт погашения → Начислена → Согласована (директор) →
 * Выплачена (Финансы). Истёк, аннулирован или возвращён — комиссия не начисляется.
 * Бесплатные сертификаты комиссию не дают. Каждый шаг пишется в журнал аудита.
 */
class CommissionManager
{
    // ——— Правила ———

    /**
     * Новая версия правила по пакету. Открытое правило (без даты окончания), начатое раньше,
     * закрывается днём до начала новой открытой версии; пересечение с другими версиями запрещено.
     *
     * @param  array{package_id:int, type:CommissionType|string, rate?:string|float|null, fixed_amount?:string|float|null, valid_from:string|Carbon, valid_until?:string|Carbon|null}  $data
     */
    public function addRule(array $data): CommissionRule
    {
        $type = $data['type'] instanceof CommissionType ? $data['type'] : CommissionType::from($data['type']);
        $from = Carbon::parse($data['valid_from'])->startOfDay();
        $until = empty($data['valid_until']) ? null : Carbon::parse($data['valid_until'])->startOfDay();
        if ($until && $until->lt($from)) {
            throw new DomainRuleViolation(__('Дата окончания раньше даты начала.'));
        }
        if ($type === CommissionType::Percent) {
            $rate = (float) ($data['rate'] ?? 0);
            if ($rate <= 0 || $rate > 100) {
                throw new DomainRuleViolation(__('Процент — от 0,01 до 100.'));
            }
        } elseif (Money::toMinor($data['fixed_amount'] ?? 0) <= 0) {
            throw new DomainRuleViolation(__('Фиксированная сумма должна быть больше нуля.'));
        }

        return DB::transaction(function () use ($data, $type, $from, $until) {
            $others = CommissionRule::query()->where('package_id', $data['package_id'])->lockForUpdate()->get();
            foreach ($others as $other) {
                $overlaps = $other->valid_from->lte($until ?? Carbon::create(9999, 12, 31))
                    && ($other->valid_until === null || $other->valid_until->gte($from));
                if (! $overlaps) {
                    continue;
                }
                // Новая открытая версия закрывает прежнее открытое правило днём раньше; версия с датой окончания
                // внутри чужого открытого периода оставила бы «дыру» без комиссии — её не допускаем.
                if ($until === null && $other->valid_until === null && $other->valid_from->lt($from)) {
                    $other->update(['valid_until' => $from->copy()->subDay()]);

                    continue;
                }
                throw new DomainRuleViolation(__('Период пересекается с правилом «:p1» (:p2 — :p3).', ['p1' => $other->describe(), 'p2' => $other->valid_from->format('d.m.Y'), 'p3' => $other->valid_until?->format('d.m.Y') ?? __('без окончания')]));
            }

            $rule = CommissionRule::create([
                'package_id' => $data['package_id'],
                'type' => $type,
                'rate' => $type === CommissionType::Percent ? Money::fromMinor((int) round(((float) $data['rate']) * 100)) : null,
                'fixed_amount' => $type === CommissionType::Fixed ? Money::fromMinor(Money::toMinor($data['fixed_amount'])) : null,
                'valid_from' => $from,
                'valid_until' => $until,
                'created_by' => auth()->id(),
            ]);
            AuditLog::record('commission_rule_added', $rule, null, $this->ruleSnapshot($rule));

            return $rule;
        });
    }

    public function ruleFor(int $packageId, Carbon $soldAt): ?CommissionRule
    {
        return CommissionRule::query()->where('package_id', $packageId)->effectiveOn($soldAt)->orderByDesc('valid_from')->first();
    }

    // ——— Жизненный цикл ———

    /** При выпуске продажи: снимок комиссии по правилу на дату продажи. Нет правила или сумма 0 — записи нет. */
    public function snapshotOnIssue(Certificate $certificate): ?CertificateCommission
    {
        if ($certificate->kind !== CertificateKind::Sale || $certificate->commission()->exists()) {
            return null;
        }
        $rule = $this->ruleFor($certificate->package_id, $certificate->sold_at);
        $sellerId = $certificate->sold_by ?? $certificate->created_by;
        if (! $rule || ! $sellerId) {
            return null;
        }
        $amount = $rule->calculate($certificate->total_amount);
        if (Money::toMinor($amount) <= 0) {
            return null;
        }

        $commission = $certificate->commission()->create([
            'seller_id' => $sellerId,
            'status' => CommissionStatus::PendingRedemption,
            'rule_id' => $rule->id,
            'rule_type' => $rule->type,
            'rule_rate' => $rule->rate,
            'rule_fixed_amount' => $rule->fixed_amount,
            'sale_price' => $certificate->total_amount,
            'amount' => $amount,
        ]);
        AuditLog::record('commission_created', $commission, null, $this->snapshot($commission));

        return $commission;
    }

    /** Сертификат погашен: комиссия начислена. */
    public function earn(Certificate $certificate): ?CertificateCommission
    {
        $commission = $certificate->commission()->lockForUpdate()->first();
        if (! $commission || $commission->status !== CommissionStatus::PendingRedemption) {
            return $commission;
        }

        return $this->move($commission, CommissionStatus::Earned, 'commission_earned', ['earned_at' => now()]);
    }

    /** Истёк, аннулирован или возвращён: комиссия не начисляется. Начисленную и далее не трогаем. */
    public function void(Certificate $certificate, string $reason): void
    {
        $commission = $certificate->commission()->lockForUpdate()->first();
        if ($commission && $commission->status === CommissionStatus::PendingRedemption) {
            $this->move($commission, CommissionStatus::Void, 'commission_void', ['void_reason' => $reason]);
        }
    }

    /** Администратор откатил погашение: начисленная комиссия возвращается в ожидание; согласованную и выплаченную откатывать нельзя. */
    public function revertEarned(Certificate $certificate): void
    {
        $commission = $certificate->commission()->lockForUpdate()->first();
        if (! $commission) {
            return;
        }
        if (in_array($commission->status, [CommissionStatus::Approved, CommissionStatus::Paid], true)) {
            throw new DomainRuleViolation(__('Комиссия по сертификату уже в статусе «:p1»: сначала отмените согласование или выплату в разделе «Комиссии».', ['p1' => mb_strtolower($commission->status->getLabel())]));
        }
        if ($commission->status === CommissionStatus::Earned) {
            $this->move($commission, CommissionStatus::PendingRedemption, 'commission_reverted', ['earned_at' => null]);
        }
    }

    /** Администратор вернул сертификат из «Аннулирован» / «Истёк» в работу: комиссия снова ждёт погашения. */
    public function reopen(Certificate $certificate): void
    {
        $commission = $certificate->commission()->lockForUpdate()->first();
        if ($commission && $commission->status === CommissionStatus::Void) {
            $this->move($commission, CommissionStatus::PendingRedemption, 'commission_reopened', ['void_reason' => null]);
        }
    }

    /** Согласование к выплате — директор по продажам. */
    public function approve(CertificateCommission $commission): CertificateCommission
    {
        return DB::transaction(function () use ($commission) {
            $fresh = $this->lock($commission);
            if ($fresh->status !== CommissionStatus::Earned) {
                throw new DomainRuleViolation(__('Комиссия в статусе «:p1» — согласовать нельзя.', ['p1' => $fresh->status->getLabel()]));
            }

            return $this->move($fresh, CommissionStatus::Approved, 'commission_approved', ['approved_by' => auth()->id(), 'approved_at' => now()]);
        });
    }

    /** Отмена согласования (до выплаты): комиссия возвращается в «Начислена». */
    public function unapprove(CertificateCommission $commission, string $reason): CertificateCommission
    {
        $reason = trim($reason) ?: throw new DomainRuleViolation(__('Укажите причину.'));

        return DB::transaction(function () use ($commission, $reason) {
            $fresh = $this->lock($commission);
            if ($fresh->status !== CommissionStatus::Approved) {
                throw new DomainRuleViolation(__('Согласование можно отменить только до выплаты.'));
            }

            return $this->move($fresh, CommissionStatus::Earned, 'commission_unapproved', ['approved_by' => null, 'approved_at' => null], $reason);
        });
    }

    /** Выплата — Финансы: сумма, дата, кто, номер или комментарий. */
    public function pay(CertificateCommission $commission, ?Carbon $paidAt = null, ?string $reference = null, ?string $comment = null): CertificateCommission
    {
        return DB::transaction(function () use ($commission, $paidAt, $reference, $comment) {
            $fresh = $this->lock($commission);
            if ($fresh->status !== CommissionStatus::Approved) {
                throw new DomainRuleViolation(__('Выплатить можно только комиссию, согласованную директором (сейчас: «:p1»).', ['p1' => $fresh->status->getLabel()]));
            }
            $payment = $fresh->payments()->create([
                'amount' => $fresh->amount,
                'paid_at' => $paidAt ?? now(),
                'paid_by' => auth()->id(),
                'reference' => $this->clean($reference),
                'comment' => $this->clean($comment),
            ]);
            AuditLog::record('commission_payment_made', $payment, null, ['commission_id' => $fresh->id, 'amount' => (string) $payment->amount,
                'paid_at' => $payment->paid_at->toDateTimeString(), 'reference' => $payment->reference]);

            return $this->move($fresh, CommissionStatus::Paid, 'commission_paid');
        });
    }

    /** Отмена выплаты — Финансы, с причиной: запись выплаты сохраняется, комиссия снова «Согласована». */
    public function reversePayment(CertificateCommission $commission, string $reason): CertificateCommission
    {
        $reason = trim($reason) ?: throw new DomainRuleViolation(__('Укажите причину отмены выплаты.'));

        return DB::transaction(function () use ($commission, $reason) {
            $fresh = $this->lock($commission);
            if ($fresh->status !== CommissionStatus::Paid) {
                throw new DomainRuleViolation(__('Отменить выплату можно только у выплаченной комиссии.'));
            }
            $payment = $fresh->activePayment();
            $payment?->update(['reversed_at' => now(), 'reversed_by' => auth()->id(), 'reversal_reason' => $reason]);
            AuditLog::record('commission_payment_reversed', $payment ?? $fresh, null, ['commission_id' => $fresh->id, 'reason' => $reason]);

            return $this->move($fresh, CommissionStatus::Approved, 'commission_payment_reversed_status', [], $reason);
        });
    }

    /**
     * Пачкой: каждая запись — своя транзакция; возвращает счётчики и тексты отказов.
     *
     * @param  iterable<CertificateCommission>  $commissions
     * @return array{done:int, errors:list<string>}
     */
    public function each(iterable $commissions, callable $action): array
    {
        $done = 0;
        $errors = [];
        foreach ($commissions as $commission) {
            try {
                $action($commission);
                $done++;
            } catch (DomainRuleViolation $e) {
                $errors[] = ($commission->certificate?->number ?? "№{$commission->id}").': '.$e->getMessage();
            }
        }

        return ['done' => $done, 'errors' => $errors];
    }

    // ——— Показатели ———

    /**
     * Сводка по продавцам (или одному): продано, погашено, не погашено, без брони, истекло, комиссия.
     * «Продано» — выпущенные продажи без аннулированных; «не погашено» — продано минус погашено;
     * «без брони» — «Активен» без брони. Период (`$from`–`$to`, даты) отбирает комиссии по месяцу погашения (`earned_at`).
     *
     * @return array<int, array<string, int|string>>
     */
    public function summary(?int $sellerId = null, ?string $from = null, ?string $to = null): array
    {
        $rows = [];
        $certificates = Certificate::query()->where('kind', CertificateKind::Sale)->whereNotNull('issued_at')
            ->where('status', '!=', CertificateStatus::Cancelled)
            ->when($sellerId, fn ($q) => $q->where('sold_by', $sellerId))
            ->get(['id', 'sold_by', 'status', 'total_amount', 'pms_booking_number']);
        foreach ($certificates as $certificate) {
            $r = &$rows[$certificate->sold_by];
            $r ??= $this->emptyRow();
            $r['sold']++;
            $r['sales_minor'] += Money::toMinor($certificate->total_amount);
            if ($certificate->status === CertificateStatus::Used) {
                $r['redeemed']++;
            } else {
                $r['not_redeemed']++;
                if ($certificate->status === CertificateStatus::Expired) {
                    $r['expired']++;
                }
                if ($certificate->status === CertificateStatus::Active && blank($certificate->pms_booking_number)) {
                    $r['no_reservation']++;
                }
            }
            unset($r);
        }

        $commissions = CertificateCommission::query()->when($sellerId, fn ($q) => $q->where('seller_id', $sellerId))
            ->when($from, fn ($q) => $q->whereDate('earned_at', '>=', $from))->when($to, fn ($q) => $q->whereDate('earned_at', '<=', $to))->get(['seller_id', 'status', 'amount']);
        foreach ($commissions as $commission) {
            $r = &$rows[$commission->seller_id];
            $r ??= $this->emptyRow();
            $minor = Money::toMinor($commission->amount);
            if ($commission->status->isEarned()) {
                $r['earned_minor'] += $minor;
            }
            if ($commission->status->isPendingPayment()) {
                $r['pending_minor'] += $minor;
            }
            if ($commission->status === CommissionStatus::Earned) {
                $r['to_approve_minor'] += $minor;
            }
            if ($commission->status === CommissionStatus::Approved) {
                $r['approved_minor'] += $minor;
            }
            if ($commission->status === CommissionStatus::Paid) {
                $r['paid_minor'] += $minor;
            }
            unset($r);
        }

        return $rows;
    }

    /** @return array<string, int> */
    private function emptyRow(): array
    {
        return ['sold' => 0, 'sales_minor' => 0, 'redeemed' => 0, 'not_redeemed' => 0, 'no_reservation' => 0, 'expired' => 0,
            'earned_minor' => 0, 'pending_minor' => 0, 'to_approve_minor' => 0, 'approved_minor' => 0, 'paid_minor' => 0];
    }

    // ——— Внутреннее ———

    private function lock(CertificateCommission $commission): CertificateCommission
    {
        return CertificateCommission::query()->lockForUpdate()->findOrFail($commission->id);
    }

    /** @param  array<string, mixed>  $changes */
    private function move(CertificateCommission $commission, CommissionStatus $to, string $event, array $changes = [], ?string $comment = null): CertificateCommission
    {
        $from = $commission->status;
        $commission->update(['status' => $to] + $changes);
        AuditLog::record($event, $commission, ['status' => $from->value], array_filter(['status' => $to->value, 'reason' => $comment, 'amount' => (string) $commission->amount, 'seller_id' => $commission->seller_id]));

        return $commission;
    }

    private function clean(?string $text): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : $text;
    }

    /** @return array<string, mixed> */
    private function ruleSnapshot(CommissionRule $rule): array
    {
        return ['package_id' => $rule->package_id, 'type' => $rule->type->value, 'rate' => $rule->rate, 'fixed_amount' => $rule->fixed_amount,
            'valid_from' => $rule->valid_from->toDateString(), 'valid_until' => $rule->valid_until?->toDateString()];
    }

    /** @return array<string, mixed> */
    private function snapshot(CertificateCommission $commission): array
    {
        return ['certificate_id' => $commission->certificate_id, 'seller_id' => $commission->seller_id, 'rule_id' => $commission->rule_id,
            'type' => $commission->rule_type->value, 'rate' => $commission->rule_rate, 'fixed_amount' => $commission->rule_fixed_amount,
            'sale_price' => (string) $commission->sale_price, 'amount' => (string) $commission->amount, 'status' => $commission->status->value];
    }
}
