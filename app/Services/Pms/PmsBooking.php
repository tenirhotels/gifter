<?php

namespace App\Services\Pms;

use Illuminate\Support\Carbon;

/**
 * Бронь из PMS в формате Exely (`{"booking": {...}}`), так отвечают и Hotel Hub, и прямой Exely.
 * Персональные контакты в ответе не приходят; хаб добавляет блок `hub` (возраст данных) и `roomStays[].hub.status`.
 */
final class PmsBooking
{
    /**
     * @param  list<array{last: string, first: string}>  $guests  гости действующих комнат и заказчик
     */
    public function __construct(
        public readonly string $number,
        public readonly bool $cancelled,
        public readonly ?Carbon $arrival,
        public readonly ?Carbon $departure,
        public readonly array $guests,
        public readonly array $stayStatuses,
        public readonly int $roomCount,
        public readonly bool $stale,
        public readonly ?int $dataAgeMinutes,
    ) {}

    /** «Фамилия Имя» гостей — для сверки с получателем и заказчиком. @return list<string> */
    public function guestNames(): array
    {
        return array_map(fn (array $p) => trim($p['last'].' '.$p['first']), $this->guests);
    }

    /**
     * Все действующие комнаты заселены (или уже выехали) по данным PMS. Статус комнаты отдаёт только Hotel Hub
     * (`roomStays[].hub.status`); прямой Exely его не даёт — тогда всегда false и заезд отмечает сотрудник.
     */
    public function checkedIn(): bool
    {
        return $this->stayStatuses !== [] && collect($this->stayStatuses)->every(fn ($status) => in_array($status, ['checked_in', 'checked_out'], true));
    }

    /** Все действующие комнаты выехали по данным PMS (только Hotel Hub, `roomStays[].hub.status` = checked_out). */
    public function checkedOut(): bool
    {
        return $this->stayStatuses !== [] && collect($this->stayStatuses)->every(fn ($status) => $status === 'checked_out');
    }

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

        $guests = $active->flatMap(fn (array $s) => $s['guests'] ?? [])
            ->push($booking['customer'] ?? null)
            ->filter(fn ($p) => is_array($p) && (filled($p['lastName'] ?? null) || filled($p['firstName'] ?? null)))
            ->map(fn (array $p) => ['last' => trim((string) ($p['lastName'] ?? '')), 'first' => trim((string) ($p['firstName'] ?? ''))])
            ->unique(fn (array $p) => $p['last'].'|'.$p['first'])->values()->all();
        $stayStatuses = $active->map(fn (array $s) => $s['hub']['status'] ?? null)->all();

        return new self(
            number: (string) ($booking['number'] ?? $requestedNumber),
            cancelled: $cancelled,
            arrival: $arrivals->min(),
            departure: $departures->max(),
            guests: $guests,
            stayStatuses: $stayStatuses,
            roomCount: $active->count(),
            stale: (bool) ($json['hub']['stale'] ?? false),
            dataAgeMinutes: isset($json['hub']['data_age_minutes']) ? (int) $json['hub']['data_age_minutes'] : null,
        );
    }
}
