<?php

namespace App\Services\Pms;

use Illuminate\Support\Carbon;

/**
 * Бронь из PMS в формате Exely (`{"booking": {...}}`), так отвечают и Hotel Hub, и прямой Exely.
 * Персональные контакты в ответе не приходят; хаб добавляет блок `hub` (возраст данных) и `roomStays[].hub.status`.
 */
final class PmsBooking
{
    /** @param  list<string>  $guestNames  «Фамилия Имя» гостей действующих комнат и заказчика */
    public function __construct(
        public readonly string $number,
        public readonly bool $cancelled,
        public readonly ?Carbon $arrival,
        public readonly ?Carbon $departure,
        public readonly array $guestNames,
        public readonly int $roomCount,
        public readonly bool $stale,
        public readonly ?int $dataAgeMinutes,
    ) {}

    /** @param  array<string, mixed>  $json */
    public static function fromResponse(array $json, string $requestedNumber): self
    {
        $booking = $json['booking'] ?? [];
        $stays = collect($booking['roomStays'] ?? []);
        // Отменённые комнаты не считаются: хаб отдаёт их статус в roomStays[].hub.status.
        $active = $stays->reject(fn (array $stay) => ($stay['hub']['status'] ?? null) === 'cancelled');
        $cancelled = ($booking['status'] ?? 'Active') === 'Cancelled' || ($stays->isNotEmpty() && $active->isEmpty());

        $arrivals = $active->map(fn (array $s) => $s['stayDates']['arrivalDateTime'] ?? null)->filter()->map(fn ($d) => Carbon::parse($d)->startOfDay());
        $departures = $active->map(fn (array $s) => $s['stayDates']['departureDateTime'] ?? null)->filter()->map(fn ($d) => Carbon::parse($d)->startOfDay());

        $names = $active->flatMap(fn (array $s) => $s['guests'] ?? [])
            ->push($booking['customer'] ?? null)
            ->filter(fn ($p) => is_array($p) && (filled($p['lastName'] ?? null) || filled($p['firstName'] ?? null)))
            ->map(fn (array $p) => trim(($p['lastName'] ?? '').' '.($p['firstName'] ?? '')))
            ->unique()->values()->all();

        return new self(
            number: (string) ($booking['number'] ?? $requestedNumber),
            cancelled: $cancelled,
            arrival: $arrivals->min(),
            departure: $departures->max(),
            guestNames: $names,
            roomCount: $active->count(),
            stale: (bool) ($json['hub']['stale'] ?? false),
            dataAgeMinutes: isset($json['hub']['data_age_minutes']) ? (int) $json['hub']['data_age_minutes'] : null,
        );
    }
}
