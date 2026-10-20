<?php

namespace App\Support;

use App\Enums\ArrivalReadinessLevel;
use App\Enums\FulfillmentStatus;
use App\Enums\ReservationReview;
use App\Models\Certificate;
use App\Models\ServiceFulfillment;
use Illuminate\Database\Eloquent\Builder;

/**
 * Готовность к заезду — одно место для правил (фаза 6):
 *  - «Исключение»: бронь на проверке или оспорена Финансами, срок сертификата кончается до выезда;
 *  - «Требует действия»: есть заявка на исполнение, которую не отправили, которой не назначили
 *    нужное время или не получили нужное подтверждение;
 *  - «Готов»: всё остальное.
 * Те же правила — в виде условий запроса (для фильтров таблиц).
 */
final class ArrivalReadiness
{
    /** @return array{level: ArrivalReadinessLevel, reasons: list<string>} */
    public static function for(Certificate $certificate): array
    {
        $exceptions = array_values(array_filter([
            $certificate->reservation_review_status === ReservationReview::Pending ? __('бронь на проверке Финансов') : null,
            $certificate->reservation_review_status === ReservationReview::Disputed ? __('бронь оспорена Финансами') : null,
            $certificate->departure_date && $certificate->departure_date->gt($certificate->valid_until)
                ? __('срок сертификата кончается :p1, до выезда', ['p1' => $certificate->valid_until->format('d.m.Y')]) : null,
        ]));
        if ($exceptions !== []) {
            return ['level' => ArrivalReadinessLevel::Exception, 'reasons' => $exceptions];
        }

        $actions = $certificate->fulfillments->filter(fn (ServiceFulfillment $f) => self::needsAction($f))
            ->map(fn (ServiceFulfillment $f) => "{$f->serviceLabel()}: {$f->status->getLabel()}")->values()->all();

        return $actions === []
            ? ['level' => ArrivalReadinessLevel::Ready, 'reasons' => []]
            : ['level' => ArrivalReadinessLevel::Action, 'reasons' => $actions];
    }

    public static function needsAction(ServiceFulfillment $fulfillment): bool
    {
        return $fulfillment->status->isOpen() && (
            in_array($fulfillment->status, [FulfillmentStatus::Pending, FulfillmentStatus::Requested], true)
            || ($fulfillment->requires_scheduling && ! $fulfillment->scheduled_date)
            || ($fulfillment->requires_confirmation && $fulfillment->status !== FulfillmentStatus::Confirmed)
        );
    }

    public static function scope(Builder $query, ArrivalReadinessLevel $level): Builder
    {
        // NULL-безопасно: у брони без проверки статус пустой, и NOT(NULL IN …) не должен выкидывать строку.
        $exceptionSql = "(coalesce(certificates.reservation_review_status, '') in (?, ?)"
            .' or (certificates.departure_date is not null and certificates.departure_date > certificates.valid_until))';
        $bindings = [ReservationReview::Pending->value, ReservationReview::Disputed->value];
        $action = fn (Builder $q) => $q->whereIn('status', FulfillmentStatus::open())->where(fn (Builder $q) => $q
            ->whereIn('status', [FulfillmentStatus::Pending, FulfillmentStatus::Requested])
            ->orWhere(fn (Builder $q) => $q->where('requires_scheduling', true)->whereNull('scheduled_date'))
            ->orWhere(fn (Builder $q) => $q->where('requires_confirmation', true)->where('status', '!=', FulfillmentStatus::Confirmed)));

        return match ($level) {
            ArrivalReadinessLevel::Exception => $query->whereRaw($exceptionSql, $bindings),
            ArrivalReadinessLevel::Action => $query->whereRaw("not {$exceptionSql}", $bindings)->whereHas('fulfillments', $action),
            ArrivalReadinessLevel::Ready => $query->whereRaw("not {$exceptionSql}", $bindings)->whereDoesntHave('fulfillments', $action),
        };
    }
}
