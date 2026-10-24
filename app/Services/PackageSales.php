<?php

namespace App\Services;

use App\Enums\CertificateStatus;
use App\Enums\OnlineOrderStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Certificate;
use App\Models\OnlineOrder;
use App\Models\Package;
use Illuminate\Support\Carbon;

/**
 * Ограничения продажи пакета (необязательные, включаются тумблером в карточке): лимит по числу и окно продаж
 * (например, Black Friday). Лимит пуст или 0 — без ограничений; окно — можно задать одну границу.
 * Лимит считается по всем каналам (сайт и продавцы): все сертификаты, кроме аннулированных (черновик тоже занимает место), и новые заявки с сайта.
 * Когда продажа закрыта, оформить сертификат нельзя ни на сайте, ни в админке; снять ограничение может директор, изменив пакет.
 */
class PackageSales
{
    public const OPEN = 'open';

    public const NOT_STARTED = 'not_started';

    public const ENDED = 'ended';

    public const SOLD_OUT = 'sold_out';

    public function limited(Package $package): bool
    {
        return (bool) $package->sales_limits_enabled;
    }

    public function quota(Package $package): ?int
    {
        return $this->limited($package) && (int) $package->sales_quota > 0 ? (int) $package->sales_quota : null;
    }

    /** Сколько уже продано или зарезервировано (все каналы). */
    public function sold(Package $package): int
    {
        $certificates = Certificate::query()->where('package_id', $package->id)
            ->where('status', '!=', CertificateStatus::Cancelled->value)
            ->count();
        $orders = OnlineOrder::query()->where('package_id', $package->id)->where('status', OnlineOrderStatus::New->value)->whereNull('certificate_id')->count();

        return $certificates + $orders;
    }

    public function remaining(Package $package): ?int
    {
        $quota = $this->quota($package);

        return $quota === null ? null : max(0, $quota - $this->sold($package));
    }

    /** open | not_started | ended | sold_out */
    public function state(Package $package, ?Carbon $on = null): string
    {
        if (! $this->limited($package)) {
            return self::OPEN;
        }
        $day = ($on ?? today())->copy()->startOfDay();
        if ($package->sales_from && $day->lt($package->sales_from)) {
            return self::NOT_STARTED;
        }
        if ($package->sales_until && $day->gt($package->sales_until)) {
            return self::ENDED;
        }

        return $this->remaining($package) === 0 ? self::SOLD_OUT : self::OPEN;
    }

    /**
     * Можно ли оформить ещё `$quantity` сертификатов. `$fromOrder` — сертификат оформляется из заявки с сайта, которая уже учтена в лимите.
     *
     * @throws DomainRuleViolation
     */
    public function assertCanSell(Package $package, int $quantity = 1, bool $fromOrder = false): void
    {
        $state = $this->state($package);
        if ($state === self::NOT_STARTED) {
            throw new DomainRuleViolation(__('Продажа пакета «:p1» начнётся :p2.', ['p1' => $package->name, 'p2' => $package->sales_from->format('d.m.Y')]));
        }
        if ($state === self::ENDED) {
            throw new DomainRuleViolation(__('Продажа пакета «:p1» завершена :p2. Изменить ограничения может директор в карточке пакета.', ['p1' => $package->name, 'p2' => $package->sales_until->format('d.m.Y')]));
        }
        $remaining = $this->remaining($package);
        if ($remaining !== null) {
            $remaining += $fromOrder ? 1 : 0;
            if ($remaining < $quantity) {
                throw new DomainRuleViolation(__('Лимит продаж пакета «:p1» исчерпан (осталось :p2). Изменить лимит может директор в карточке пакета.', ['p1' => $package->name, 'p2' => $remaining]));
            }
        }
    }

    /** Строка для карточки и списка: «Открыта · продано 12 из 50», «Завершена», «Начнётся с …». */
    public function label(Package $package): string
    {
        if (! $this->limited($package)) {
            return __('Без ограничений');
        }
        $parts = [match ($this->state($package)) {
            self::NOT_STARTED => __('Начнётся :p1', ['p1' => $package->sales_from->format('d.m.Y')]),
            self::ENDED => __('Завершена'),
            self::SOLD_OUT => __('Лимит исчерпан'),
            default => __('Идёт'),
        }];
        if ($quota = $this->quota($package)) {
            $parts[] = __('продано :p1 из :p2', ['p1' => $this->sold($package), 'p2' => $quota]);
        }
        if ($package->sales_from || $package->sales_until) {
            $parts[] = trim(($package->sales_from ? __('с :p1', ['p1' => $package->sales_from->format('d.m.Y')]) : '').' '.($package->sales_until ? __('по :p1', ['p1' => $package->sales_until->format('d.m.Y')]) : ''));
        }

        return implode(' · ', $parts);
    }
}
