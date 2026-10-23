<?php

namespace App\Services;

use App\Models\Certificate;
use App\Services\Pms\PmsClient;
use App\Services\Pms\PmsException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Проверка броней перенесённых сертификатов в PMS: только читает данные брони (заезд, выезд, гость, совпадение имени)
 * и ставит отметку проверки. Статус сертификата, проверка Финансами и заявки не затрагиваются — старые брони давно закрыты.
 * Недоступность PMS не ошибка: отметка «unavailable», такие брони можно проверить повторно (`$retry`).
 */
class LegacyBookingCheck
{
    public const OK = 'ok';

    public const CANCELLED = 'cancelled';

    public const UNAVAILABLE = 'unavailable';

    public function __construct(private readonly ReservationManager $reservations) {}

    /** @return Builder<Certificate> */
    public function pending(bool $retry = false)
    {
        return Certificate::query()->whereNotNull('legacy_key')->whereNotNull('pms_booking_number')
            ->where(fn ($q) => $q->whereNull('pms_checked_at')->when($retry, fn ($r) => $r->orWhere('pms_check_status', self::UNAVAILABLE)))
            ->orderBy('id');
    }

    /** @return string ok | cancelled | unavailable */
    public function check(Certificate $certificate): string
    {
        try {
            $booking = PmsClient::make()->find((string) $certificate->pms_booking_number);
        } catch (PmsException $e) {
            $certificate->forceFill(['pms_checked_at' => now(), 'pms_check_status' => self::UNAVAILABLE, 'pms_check_note' => mb_substr($e->getMessage(), 0, 250)])->save();

            return self::UNAVAILABLE;
        }
        if ($booking->cancelled) {
            $certificate->forceFill(['pms_checked_at' => now(), 'pms_check_status' => self::CANCELLED, 'pms_check_note' => null])->save();

            return self::CANCELLED;
        }
        $data = ['arrival_date' => $booking->arrival, 'departure_date' => $booking->departure, 'pms_checked_at' => now(), 'pms_check_status' => self::OK, 'pms_check_note' => null];
        try {
            $match = $this->reservations->evaluate($certificate, $booking);
            $data += ['booking_guest_name' => $match['guest'], 'name_match' => $match['match']];
        } catch (PmsException) {
            // нет имён гостей в брони — даты всё равно сохраняются
        }
        $certificate->forceFill($data)->save();

        return self::OK;
    }
}
