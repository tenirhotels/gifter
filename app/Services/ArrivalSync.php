<?php

namespace App\Services;

use App\Enums\CertificateStatus;
use App\Enums\FulfillmentStatus;
use App\Enums\HistoryEvent;
use App\Enums\ReservationReview;
use App\Exceptions\DomainRuleViolation;
use App\Models\Certificate;
use App\Models\PmsSetting;
use App\Services\Pms\PmsBooking;
use App\Services\Pms\PmsClient;
use App\Services\Pms\PmsException;
use Illuminate\Support\Facades\DB;

/**
 * Заезд по данным PMS (решение владельца 19.10.2026): в день заезда Gifter повторно запрашивает PMS, чтобы
 * убедиться в брони и не вносить заезд вручную. Запросов мало и только по делу: утром (расхождения), в 16:00
 * (заселение в отеле в 15:30), вечером — только не заселённые; кнопка «Заезд» — один запрос по нажатию.
 *
 * Расхождение (бронь отменена в PMS, изменились даты или гость, условия сертификата не соблюдены) заезд не
 * отмечает: бронь уходит на проверку Финансам («Бронь изменена в PMS»), у Front Office — «нужно внимание».
 * Заселение отдаёт только Hotel Hub (`checked_in`); у прямого Exely заезд отмечает сотрудник кнопкой.
 */
class ArrivalSync
{
    public const OK = 'ok';

    public const CHANGED = 'changed';

    public const DISCREPANCY = 'discrepancy';

    public const CANCELLED = 'cancelled';

    public const UNAVAILABLE = 'unavailable';

    public function __construct(
        private readonly ReservationManager $reservations,
        private readonly CertificateManager $certificates,
        private readonly CertificateHistoryRecorder $history,
    ) {}

    /** Бронь подтверждена по PMS — только такие проверяются. */
    public function applies(Certificate $certificate): bool
    {
        return $certificate->pms_verified_at !== null && PmsClient::make()->isActive();
    }

    /**
     * Свежий запрос брони и сверка с сохранённым. Даты и гость обновляются по PMS; отмена и расхождения — на проверку Финансам.
     *
     * @return array{status: string, message: ?string, checked_in: bool}
     */
    public function inspect(Certificate $certificate, ?PmsBooking $booking = null): array
    {
        if (! $booking) {
            try {
                $booking = PmsClient::make()->find((string) $certificate->pms_booking_number);
            } catch (PmsException $e) {
                $certificate->forceFill(['pms_checked_at' => now(), 'pms_check_status' => self::UNAVAILABLE, 'pms_check_note' => $e->getMessage()])->save();

                return ['status' => self::UNAVAILABLE, 'message' => $e->getMessage(), 'checked_in' => false];
            }
        }

        return DB::transaction(function () use ($certificate, $booking) {
            $fresh = Certificate::query()->lockForUpdate()->with(['recipient', 'customer'])->findOrFail($certificate->id);
            $result = $booking->cancelled
                ? $this->applyCancelled($fresh)
                : $this->applyBooking($fresh, $booking);
            $certificate->setRawAttributes($fresh->getAttributes(), true);

            return $result + ['checked_in' => ! $booking->cancelled && $booking->checkedIn()];
        });
    }

    /**
     * Отметить заезд. Вручную (`$auto = false`): проверка и заезд; расхождение — отказ с причиной.
     * Автоматически: заезд ставится только если PMS (хаб) показывает заселение и расхождений нет.
     */
    public function checkIn(Certificate $certificate, bool $auto = false, ?PmsBooking $booking = null): bool
    {
        if ($certificate->status !== CertificateStatus::Reserved) {
            throw new DomainRuleViolation("Заезд — из статуса «Забронирован» (сейчас: «{$certificate->status->getLabel()}»).");
        }
        // Раньше даты заезда запрос в PMS не нужен.
        if ($certificate->arrival_date->gt(today()->addDay())) {
            throw new DomainRuleViolation('Заезд по брони — с '.$certificate->arrival_date->format('d.m.Y').'.');
        }

        $result = $this->inspect($certificate, $booking);
        if (in_array($result['status'], [self::CANCELLED, self::DISCREPANCY], true)) {
            if ($auto) {
                return false;
            }
            throw new DomainRuleViolation('Заезд не отмечен: '.$result['message'].' Бронь отправлена на проверку Финансам.');
        }
        if ($result['status'] === self::UNAVAILABLE) {
            if ($auto) {
                return false;
            }
            throw new DomainRuleViolation($result['message'].' Заезд не отмечен.');
        }
        if ($auto && ! $result['checked_in']) {
            return false;
        }
        // Даты в PMS могли сдвинуться — повторная проверка окна заезда уже по обновлённой брони.
        if ($certificate->arrival_date->gt(today()->addDay())) {
            if ($auto) {
                return false;
            }
            throw new DomainRuleViolation('Даты брони в PMS изменились: заезд — с '.$certificate->arrival_date->format('d.m.Y').'.');
        }

        $this->certificates->transition($certificate, CertificateStatus::InUse,
            $auto ? 'Заезд по данным PMS (автоматически)' : 'Заезд, бронь проверена по PMS');

        return true;
    }

    /**
     * Автопроверка в день заезда. Слот: morning — расхождения (и просроченные заезды раз в день), afternoon и
     * evening — заселение. Один запрос на номер брони (общая бронь группы); недавно проверенные и уже решённые пропускаются.
     *
     * @return array{checked: int, checked_in: int, discrepancies: int, unavailable: int}
     */
    public function syncToday(string $slot): array
    {
        $stats = ['checked' => 0, 'checked_in' => 0, 'discrepancies' => 0, 'unavailable' => 0];
        if (! PmsClient::make()->isActive()) {
            return $stats;
        }

        $recheck = now()->subMinutes((int) config('gifter.pms.arrival_recheck_minutes', 120));
        $query = Certificate::query()->where('status', CertificateStatus::Reserved)->whereNotNull('pms_verified_at')
            ->whereNotNull('pms_booking_number')
            ->whereDate('arrival_date', $slot === 'morning' ? '<=' : '=', today()->toDateString())
            // Решённые не опрашиваем: отмена и расхождение уже у Финансов, спор — у Финансов.
            ->where(fn ($q) => $q->whereNull('pms_check_status')->orWhereNotIn('pms_check_status', [self::CANCELLED, self::DISCREPANCY]))
            ->where(fn ($q) => $q->whereNull('reservation_review_status')->orWhere('reservation_review_status', '!=', ReservationReview::Disputed))
            ->where(fn ($q) => $q->whereNull('pms_checked_at')->orWhere('pms_checked_at', '<', $recheck));

        $bookings = [];
        $client = PmsClient::make();
        foreach ($query->orderBy('id')->get() as $certificate) {
            $number = (string) $certificate->pms_booking_number;
            if (! array_key_exists($number, $bookings)) {
                try {
                    $bookings[$number] = $client->find($number);
                    usleep(250_000); // не чаще 4 запросов в секунду
                } catch (PmsException $e) {
                    $bookings[$number] = $e;
                }
            }
            $booking = $bookings[$number];
            $stats['checked']++;
            if ($booking instanceof PmsException) {
                $certificate->forceFill(['pms_checked_at' => now(), 'pms_check_status' => self::UNAVAILABLE, 'pms_check_note' => $booking->getMessage()])->save();
                $stats['unavailable']++;

                continue;
            }
            try {
                $entered = $this->checkIn($certificate, auto: true, booking: $booking);
            } catch (DomainRuleViolation) {
                $entered = false;
            }
            $entered ? $stats['checked_in']++ : null;
            if (in_array($certificate->refresh()->pms_check_status, [self::CANCELLED, self::DISCREPANCY], true)) {
                $stats['discrepancies']++;
            }
        }

        return $stats;
    }

    /**
     * Автопогашение после выезда (решение владельца 19.10.2026). Hotel Hub: гашение по `checked_out` из PMS.
     * Прямой Exely статуса выезда не отдаёт: на следующий день после запланированного выезда бронь сверяется
     * с PMS (не отменена, даты не изменились) и выезд считается состоявшимся. Расхождение — на проверку Финансам.
     * С невыполненными заявками по услугам сертификат не гасится — его гасит сотрудник («нужно внимание»).
     * Один запрос на номер брони; уже решённые и недавно проверенные пропускаются.
     *
     * @return array{checked: int, redeemed: int, discrepancies: int, unavailable: int, open_services: int, no_show: int}
     */
    public function syncDepartures(): array
    {
        $stats = ['checked' => 0, 'redeemed' => 0, 'discrepancies' => 0, 'unavailable' => 0, 'open_services' => 0, 'no_show' => 0];
        $stats['no_show'] = $this->closeNoShows();
        if (! PmsClient::make()->isActive()) {
            return $stats;
        }

        $hub = ! PmsSetting::current()->isExely();
        $recheck = now()->subMinutes((int) config('gifter.pms.arrival_recheck_minutes', 120));
        $query = Certificate::query()->where('status', CertificateStatus::InUse)->whereNotNull('pms_verified_at')->whereNotNull('pms_booking_number')
            ->whereDate('departure_date', $hub ? '<=' : '<', today()->toDateString())
            ->where(fn ($q) => $q->whereNull('pms_check_status')->orWhereNotIn('pms_check_status', [self::CANCELLED, self::DISCREPANCY]))
            ->where(fn ($q) => $q->whereNull('reservation_review_status')->orWhere('reservation_review_status', '!=', ReservationReview::Disputed))
            ->where(fn ($q) => $q->whereNull('pms_checked_at')->orWhere('pms_checked_at', '<', $recheck));

        $bookings = [];
        $client = PmsClient::make();
        foreach ($query->orderBy('id')->get() as $certificate) {
            $number = (string) $certificate->pms_booking_number;
            if (! array_key_exists($number, $bookings)) {
                try {
                    $bookings[$number] = $client->find($number);
                    usleep(250_000); // не чаще 4 запросов в секунду
                } catch (PmsException $e) {
                    $bookings[$number] = $e;
                }
            }
            $booking = $bookings[$number];
            $stats['checked']++;
            if ($booking instanceof PmsException) {
                $certificate->forceFill(['pms_checked_at' => now(), 'pms_check_status' => self::UNAVAILABLE, 'pms_check_note' => $booking->getMessage()])->save();
                $stats['unavailable']++;

                continue;
            }
            $result = $this->inspect($certificate, $booking);
            if (in_array($result['status'], [self::CANCELLED, self::DISCREPANCY], true)) {
                $stats['discrepancies']++;

                continue;
            }
            // Даты в PMS могли сдвинуться (продлили проживание) — выезд ещё не состоялся.
            $departed = $hub ? $booking->checkedOut() : $certificate->departure_date->lt(today());
            if (! $departed) {
                continue;
            }
            if ($certificate->fulfillments()->whereIn('status', FulfillmentStatus::open())->exists()) {
                $stats['open_services']++;

                continue;
            }
            try {
                $this->certificates->transition($certificate, CertificateStatus::Used,
                    $hub ? 'Отмечен использованным автоматически: выезд по данным PMS' : 'Отмечен использованным автоматически: день после планового выезда, бронь сверена по PMS');
                $stats['redeemed']++;
            } catch (DomainRuleViolation) {
                // Спор, возврат на рассмотрении и т. п. — оставляем сотруднику.
            }
        }

        return $stats;
    }

    /**
     * Не заезд (no-show), решение владельца 19.10.2026: бронь прошла, а заезда не было — на следующий день после планового
     * выезда сертификат закрывается как использованный (деньги получены, услуга «сгорела»), комиссия начисляется.
     * С включённой PMS бронь сверяется: отмена и расхождение — на проверку Финансам, не закрываем. Возвращает число закрытых.
     */
    private function closeNoShows(): int
    {
        $closed = 0;
        $client = PmsClient::make();
        $bookings = [];
        $candidates = Certificate::query()->where('status', CertificateStatus::Reserved)->whereNotNull('departure_date')
            ->whereDate('departure_date', '<', today()->toDateString())
            ->where(fn ($q) => $q->whereNull('reservation_review_status')->orWhere('reservation_review_status', '!=', ReservationReview::Disputed))
            ->where(fn ($q) => $q->whereNull('pms_check_status')->orWhereNotIn('pms_check_status', [self::CANCELLED, self::DISCREPANCY]))
            ->orderBy('id')->get();

        foreach ($candidates as $certificate) {
            if ($this->applies($certificate)) {
                $number = (string) $certificate->pms_booking_number;
                if (! array_key_exists($number, $bookings)) {
                    try {
                        $bookings[$number] = $client->find($number);
                        usleep(250_000);
                    } catch (PmsException) {
                        $bookings[$number] = null;
                    }
                }
                if ($bookings[$number] === null) {
                    continue; // PMS недоступна — повтор завтра
                }
                $result = $this->inspect($certificate, $bookings[$number]);
                if (in_array($result['status'], [self::CANCELLED, self::DISCREPANCY], true)) {
                    continue;
                }
            }
            try {
                $this->certificates->transition($certificate, CertificateStatus::InUse, 'Заезд не подтверждён: гость не приехал (no-show)');
                $this->certificates->transition($certificate->refresh(), CertificateStatus::Used,
                    'Не заезд (no-show): день после планового выезда, сертификат закрыт автоматически как использованный');
                $closed++;
            } catch (DomainRuleViolation) {
                // Спор, возврат на рассмотрении, заезд раньше срока и т. п. — оставляем сотруднику.
            }
        }

        return $closed;
    }

    /** @return array{status: string, message: ?string} */
    private function applyCancelled(Certificate $certificate): array
    {
        $message = 'Бронь № '.$certificate->pms_booking_number.' отменена в PMS.';
        $this->flag($certificate, self::CANCELLED, $message, 'Бронь отменена в PMS');

        return ['status' => self::CANCELLED, 'message' => $message];
    }

    /** @return array{status: string, message: ?string} */
    private function applyBooking(Certificate $certificate, PmsBooking $booking): array
    {
        if (! $booking->arrival || ! $booking->departure) {
            $message = 'В брони PMS нет дат проживания.';
            $this->flag($certificate, self::DISCREPANCY, $message, 'Бронь изменена в PMS');

            return ['status' => self::DISCREPANCY, 'message' => $message];
        }

        $found = $this->reservations->evaluate($certificate, $booking);
        $changes = [];
        if (! $certificate->arrival_date->isSameDay($booking->arrival) || ! $certificate->departure_date->isSameDay($booking->departure)) {
            $changes[] = 'Даты изменены в PMS: '.$certificate->arrival_date->format('d.m').'–'.$certificate->departure_date->format('d.m.Y')
                .' → '.$booking->arrival->format('d.m').'–'.$booking->departure->format('d.m.Y');
        }
        if ($certificate->booking_guest_name !== $found['guest']) {
            $changes[] = 'Гость в брони изменён в PMS';
        }
        $discrepancies = collect($found['checks'])->reject(fn (array $c) => $c['ok'])->pluck('text')->all();

        $update = ['pms_checked_at' => now(), 'pms_data_age_minutes' => $booking->dataAgeMinutes,
            'pms_check_status' => $changes !== [] ? self::CHANGED : self::OK, 'pms_check_note' => $changes !== [] ? implode('; ', $changes).'.' : null];
        if ($changes !== [] || $certificate->name_match !== $found['match']) {
            $update += ['arrival_date' => $booking->arrival->toDateString(), 'departure_date' => $booking->departure->toDateString(),
                'booking_guest_name' => $found['guest'], 'name_match' => $found['match']];
        }
        $certificate->forceFill($update)->save();
        if ($changes !== []) {
            $this->history->record($certificate, HistoryEvent::ReservationChanged, implode('. ', $changes).'. Обновлено по PMS');
        }

        // Даты и гость обновлены по PMS; Финансам — только расхождения с условиями сертификата и отмена.
        if ($discrepancies !== []) {
            $message = implode('; ', array_merge($changes, $discrepancies)).'.';
            $this->flag($certificate, self::DISCREPANCY, $message, 'Бронь изменена в PMS');

            return ['status' => self::DISCREPANCY, 'message' => $message];
        }

        return ['status' => $changes !== [] ? self::CHANGED : self::OK, 'message' => $changes !== [] ? implode('; ', $changes).'.' : null];
    }

    /** Отметка проверки и отправка брони Финансам (проверка броней) с причиной. */
    private function flag(Certificate $certificate, string $status, string $message, string $title): void
    {
        $certificate->forceFill([
            'pms_checked_at' => now(), 'pms_check_status' => $status, 'pms_check_note' => $message,
            'reservation_review_status' => ReservationReview::Pending,
            'reservation_reviewed_by' => null, 'reservation_reviewed_at' => null, 'reservation_review_comment' => null,
            'reservation_note' => str_contains((string) $certificate->reservation_note, $message)
                ? $certificate->reservation_note : trim($title.': '.$message.' '.(string) $certificate->reservation_note),
        ])->save();
        $this->history->record($certificate, HistoryEvent::ReservationChanged, "{$title}: {$message} На проверке Финансов");
    }
}
