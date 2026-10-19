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
     * @param  list<string>  $ratePlans  названия и коды тарифов действующих комнат; у комнаты без тарифа — пустая строка
     */
    public function __construct(
        public readonly string $number,
        public readonly bool $cancelled,
        public readonly ?Carbon $arrival,
        public readonly ?Carbon $departure,
        public readonly array $guests,
        public readonly array $stayRatePlans,
        public readonly array $ratePlanNames,
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

    /** Все действующие комнаты забронированы по тарифу, название или код которого содержит $needle (без учёта регистра). */
    public function allStaysOnRatePlan(string $needle): bool
    {
        $needle = mb_strtolower(trim($needle));
        if ($this->stayRatePlans === []) {
            return false;
        }
        foreach ($this->stayRatePlans as $plans) {
            if (! collect($plans)->contains(fn (string $p) => str_contains(mb_strtolower($p), $needle))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Все действующие комнаты заселены (или уже выехали) по данным PMS. Статус комнаты отдаёт только Hotel Hub
     * (`roomStays[].hub.status`); прямой Exely его не даёт — тогда всегда false и заезд отмечает сотрудник.
     */
    public function checkedIn(): bool
    {
        return $this->stayStatuses !== [] && collect($this->stayStatuses)->every(fn ($status) => in_array($status, ['checked_in', 'checked_out'], true));
    }

    /** Названия тарифов брони — для сообщения. */
    public function ratePlanLabel(): string
    {
        return implode(', ', $this->ratePlanNames) ?: 'не указан';
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
        // Тарифы по каждой действующей комнате: [[названия и коды], …].
        $ratePlanNames = $active->flatMap(fn (array $s) => collect($s['ratePlans'] ?? [])->pluck('name'))->filter()->map(fn ($n) => (string) $n)->unique()->values()->all();
        $stayStatuses = $active->map(fn (array $s) => $s['hub']['status'] ?? null)->all();
        $stayRatePlans = $active->map(fn (array $s) => collect($s['ratePlans'] ?? [])
            ->flatMap(fn (array $r) => [(string) ($r['name'] ?? ''), (string) ($r['id'] ?? '')])->filter()->values()->all())->values()->all();

        return new self(
            number: (string) ($booking['number'] ?? $requestedNumber),
            cancelled: $cancelled,
            arrival: $arrivals->min(),
            departure: $departures->max(),
            guests: $guests,
            stayRatePlans: $stayRatePlans,
            ratePlanNames: $ratePlanNames,
            stayStatuses: $stayStatuses,
            roomCount: $active->count(),
            stale: (bool) ($json['hub']['stale'] ?? false),
            dataAgeMinutes: isset($json['hub']['data_age_minutes']) ? (int) $json['hub']['data_age_minutes'] : null,
        );
    }
}
