<?php

namespace App\Services;

use App\Enums\CertificateStatus;
use App\Enums\HistoryEvent;
use App\Enums\NameMatch;
use App\Enums\ReservationReview;
use App\Exceptions\DomainRuleViolation;
use App\Models\Certificate;
use App\Services\Pms\PmsBooking;
use App\Services\Pms\PmsClient;
use App\Services\Pms\PmsException;
use App\Support\GuestName;
use App\Support\NameMatcher;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Бронь по сертификату (фаза 6). Front Office заводит бронь в PMS — Gifter брони
 * в PMS не создаёт — и вносит сюда номер брони, гостя как в брони и даты.
 *
 * Имя гостя сверяется с получателем и заказчиком (NameMatcher). Несовпадение
 * разрешено с причиной, но бронь уходит на проверку Финансам (решение 19.10.2026);
 * так же — бронь в последние RISK_DAYS дней срока. Оспоренную Финансами бронь
 * погасить нельзя. С фазы 9 номер, гость и даты будут сверяться с PMS через Hotel Hub.
 */
class ReservationManager
{
    /** Бронь за столько дней до конца срока — повод для проверки. */
    public const RISK_DAYS = 14;

    public function __construct(
        private readonly CertificateHistoryRecorder $history,
        private readonly FulfillmentManager $fulfillments,
    ) {}

    /** С кем совпадает имя гостя в брони. */
    public function matchName(Certificate $certificate, string $guestName): NameMatch
    {
        $recipient = $certificate->recipient;
        if ($recipient && NameMatcher::matches($guestName, $recipient->last_name, $recipient->first_name)) {
            return NameMatch::Recipient;
        }
        $customer = $certificate->customer;
        if ($customer && NameMatcher::matches($guestName, $customer->last_name, $customer->first_name)) {
            return NameMatch::Customer;
        }

        return NameMatch::Mismatch;
    }

    /**
     * Бронь из PMS и сверка с сертификатом. Гость: из гостей и заказчика брони тот, кто совпал с получателем,
     * затем с заказчиком сертификата; при несовпадении показываются только имя и первая буква фамилии.
     * Отменённая бронь не подходит. `fresh` — без кеша (для сохранения).
     *
     * Сверка (`checks`): имя гостя; заезд в срок сертификата (иначе бронь не принимается); число ночей не больше
     * ночей в составе сертификата (ночи пакета + доп. ночи; выезд = заезд + ночи); тариф «Сертификат» (название задаётся в настройках PMS). Расхождение — причина обязательна,
     * бронь уходит на проверку Финансам.
     *
     * @return array{booking: PmsBooking, guest: string, match: NameMatch, checks: list<array{key: string, ok: bool, text: string}>}
     */
    public function resolveFromPms(Certificate $certificate, string $number, bool $fresh = false): array
    {
        $client = PmsClient::make();
        $booking = $fresh ? $client->find($number) : $client->preview($number);
        if ($booking->cancelled) {
            throw new PmsException("Бронь № {$number} отменена в PMS — привязать её нельзя.");
        }
        if (! $booking->arrival || ! $booking->departure) {
            throw new PmsException("В брони № {$number} нет дат проживания — обратитесь в бронирование.");
        }

        return $this->evaluate($certificate, $booking);
    }

    /**
     * Сверка уже полученной брони PMS с сертификатом (без запроса): гость, имя, проверки.
     *
     * @return array{booking: PmsBooking, guest: string, match: NameMatch, checks: list<array{key: string, ok: bool, text: string}>}
     */
    public function evaluate(Certificate $certificate, PmsBooking $booking): array
    {
        $number = $booking->number;
        $certificate->loadMissing(['recipient', 'customer']);
        $best = null;
        foreach ($booking->guests as $i => $person) {
            $name = $booking->guestNames()[$i];
            $match = $this->matchName($certificate, $name);
            if ($match === NameMatch::Recipient || ($match === NameMatch::Customer && ($best === null || $best['match'] === NameMatch::Mismatch))) {
                $best = ['guest' => $name, 'match' => $match];
                if ($match === NameMatch::Recipient) {
                    break;
                }
            } elseif ($best === null) {
                $best = ['guest' => $name, 'match' => $match, 'person' => $person];
            }
        }
        if ($best === null) {
            throw new PmsException("В брони № {$number} нет имени гостя — обратитесь в бронирование.");
        }
        if ($best['match'] === NameMatch::Mismatch) {
            $best['guest'] = GuestName::masked($best['person']['first'], $best['person']['last']);
        }
        unset($best['person']);

        return ['booking' => $booking] + $best + ['checks' => $this->pmsChecks($certificate, $booking, $best['match'])];
    }

    /** @return list<array{key: string, ok: bool, text: string}> */
    private function pmsChecks(Certificate $certificate, PmsBooking $booking, NameMatch $match): array
    {
        $arrivalOk = ! $booking->arrival->lt($certificate->valid_from) && ! $booking->arrival->gt($certificate->valid_until);
        // Ночи по сертификату: ночи пакета и доп. ночи в составе. Больше — расхождение; меньше — не расхождение.
        $included = $certificate->nightsIncluded();
        $nights = (int) $booking->arrival->diffInDays($booking->departure);
        $nightsOk = $included === null || $nights <= $included;
        $checks = [
            ['key' => 'name', 'ok' => $match !== NameMatch::Mismatch, 'text' => $match === NameMatch::Mismatch
                ? 'Имя гостя не совпадает ни с получателем, ни с заказчиком' : $match->getLabel()],
            ['key' => 'arrival', 'ok' => $arrivalOk, 'text' => $arrivalOk
                ? 'Заезд '.$booking->arrival->format('d.m.Y').' — в сроке сертификата (до '.$certificate->valid_until->format('d.m.Y').')'
                : 'Заезд '.$booking->arrival->format('d.m.Y').' вне срока сертификата ('.$certificate->valid_from->format('d.m.Y').' — '.$certificate->valid_until->format('d.m.Y').')'],
        ];
        if ($included !== null) {
            $word = fn (int $n) => $n.' '.($n % 10 === 1 && $n % 100 !== 11 ? 'ночь' : (in_array($n % 10, [2, 3, 4], true) && ! in_array($n % 100, [12, 13, 14], true) ? 'ночи' : 'ночей'));
            $checks[] = ['key' => 'nights', 'ok' => $nightsOk, 'text' => $nightsOk
                ? 'В брони '.$word($nights).' ('.$booking->arrival->format('d.m').'–'.$booking->departure->format('d.m').') — в рамках сертификата (до '.$word($included).')'
                : 'В брони '.$word($nights).' ('.$booking->arrival->format('d.m').'–'.$booking->departure->format('d.m').'), а сертификат даёт '.$word($included).' (с учётом доп. ночей)'];
        }

        return $checks;
    }

    /** Бронь попадает в последние дни срока — нужна проверка. */
    public function inRiskWindow(Certificate $certificate): bool
    {
        return today()->diffInDays($certificate->valid_until, false) <= self::RISK_DAYS;
    }

    /**
     * Отметить бронь (сертификат «Активен») или изменить её («Забронирован», «В использовании»).
     *
     * @param  array{booking_number:string, guest_name:string, arrival:mixed, departure:mixed, note?:string|null}  $data
     */
    public function save(Certificate $certificate, array $data): Certificate
    {
        // Один номер брони — одна привязка за раз: проверка «бронь свободна» не должна гоняться с параллельным сохранением.
        $number = trim((string) ($data['booking_number'] ?? ''));
        if ($number === '') {
            return $this->saveLocked($certificate, $data);
        }
        try {
            return Cache::lock('gifter:booking:'.$number, 20)->block((int) config('gifter.pms.booking_lock_wait', 10), fn () => $this->saveLocked($certificate, $data));
        } catch (LockTimeoutException) {
            throw new DomainRuleViolation('Эту бронь сейчас привязывает другой сотрудник — повторите через несколько секунд.');
        }
    }

    /** @param  array<string, mixed>  $data */
    private function saveLocked(Certificate $certificate, array $data): Certificate
    {
        return DB::transaction(function () use ($certificate, $data) {
            $fresh = Certificate::query()->lockForUpdate()->with(['recipient', 'customer'])->findOrFail($certificate->id);
            if ($fresh->hasPendingRefund()) {
                throw new DomainRuleViolation('По сертификату ждёт решения запрос на возврат — действия заблокированы.');
            }
            $from = $fresh->status;
            $isNew = $from === CertificateStatus::Active;
            if (! $isNew && ! in_array($from, [CertificateStatus::Reserved, CertificateStatus::InUse], true)) {
                throw new DomainRuleViolation("Сертификат в статусе «{$from->getLabel()}»: бронь отметить нельзя.");
            }
            if (! $fresh->recipient_id) {
                throw new DomainRuleViolation('Для брони укажите получателя (гостя).');
            }

            $booking = trim((string) ($data['booking_number'] ?? ''));
            $note = trim((string) ($data['note'] ?? ''));
            $pms = null;
            if (PmsClient::make()->isActive()) {
                // Интеграция включена: бронь подтверждается по PMS строго — гость и даты берутся из PMS, а не вводятся.
                if ($booking === '') {
                    throw new DomainRuleViolation('Укажите номер брони PMS.');
                }
                $pms = $this->resolveFromPms($fresh, $booking, fresh: true);
                $guest = $pms['guest'];
                $arrival = $pms['booking']->arrival;
                $departure = $pms['booking']->departure;
            } else {
                $guest = trim(preg_replace('/\s+/u', ' ', (string) ($data['guest_name'] ?? '')) ?? '');
                if ($booking === '' || $guest === '') {
                    throw new DomainRuleViolation('Укажите номер брони PMS и имя гостя, как в брони.');
                }
                $arrival = Carbon::parse($data['arrival'])->startOfDay();
                $departure = Carbon::parse($data['departure'])->startOfDay();
            }
            $this->assertDates($fresh, $arrival, $departure, $isNew);
            $this->assertBookingFree($fresh, $booking);

            $match = $pms['match'] ?? $this->matchName($fresh, $guest);
            $risk = $isNew && empty($data['skip_risk_review']) && $this->inRiskWindow($fresh);
            // Расхождения сверки с PMS (кроме заезда вне срока — он блокирует, см. assertDates).
            $discrepancies = $pms ? collect($pms['checks'])->reject(fn (array $c) => $c['ok'] || $c['key'] === 'arrival')->pluck('text')->all() : [];
            $needsReview = $match === NameMatch::Mismatch || $risk || $discrepancies !== [];
            if ($needsReview && $note === '') {
                throw new DomainRuleViolation($discrepancies !== [] || $match === NameMatch::Mismatch
                    ? 'Есть расхождения: '.($discrepancies !== [] ? implode('; ', $discrepancies) : 'имя в брони не совпадает ни с получателем, ни с заказчиком').' — укажите причину (её проверят Финансы).'
                    : 'Бронь в последние '.self::RISK_DAYS.' дней срока — укажите комментарий (бронь проверят Финансы).');
            }
            if ($discrepancies !== []) {
                $note = trim('Расхождения: '.implode('; ', $discrepancies).'. '.$note);
            }

            $before = $this->snapshot($fresh);
            $review = $needsReview || $fresh->reservation_review_status === ReservationReview::Disputed
                ? ReservationReview::Pending
                : ($isNew ? null : $fresh->reservation_review_status);
            $fresh->update([
                'status' => $isNew ? CertificateStatus::Reserved : $from,
                'pms_booking_number' => $booking,
                'booking_guest_name' => $guest,
                'arrival_date' => $arrival->toDateString(),
                'departure_date' => $departure->toDateString(),
                'name_match' => $match,
                'pms_verified_at' => $pms ? now() : null,
                'pms_data_age_minutes' => $pms['booking']->dataAgeMinutes ?? null,
                'reservation_note' => $note !== '' ? $note : null,
                'reservation_review_status' => $review,
                'reservation_reviewed_by' => $review === ReservationReview::Pending ? null : $fresh->reservation_reviewed_by,
                'reservation_reviewed_at' => $review === ReservationReview::Pending ? null : $fresh->reservation_reviewed_at,
                'reservation_review_comment' => $review === ReservationReview::Pending ? null : $fresh->reservation_review_comment,
                'updated_by' => auth()->id(),
            ]);
            $description = $fresh->reservationLabel().'. '.$match->getLabel()
                .($pms ? '. Подтверждена по PMS'.($pms['booking']->stale ? ' (данные устарели: '.($pms['booking']->dataAgeMinutes ?? '?').' мин)' : '') : '')
                .($risk ? '. За '.max(0, today()->diffInDays($fresh->valid_until, false)).' дн. до конца срока' : '')
                .($note !== '' ? ". {$note}" : '').($review === ReservationReview::Pending ? '. На проверке Финансов' : '');
            $this->history->record($fresh, $isNew ? HistoryEvent::Reserved : HistoryEvent::ReservationChanged, $description, $before, $this->snapshot($fresh));
            if ($isNew) {
                $this->fulfillments->syncWithCertificate($fresh);
            }
            $certificate->setRawAttributes($fresh->getAttributes(), true);

            return $fresh;
        });
    }

    /** Снять бронь: сертификат снова «Активен», данные брони остаются только в истории. */
    public function release(Certificate $certificate, string $reason): Certificate
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainRuleViolation('Укажите причину снятия брони.');
        }

        return DB::transaction(function () use ($certificate, $reason) {
            $fresh = Certificate::query()->lockForUpdate()->findOrFail($certificate->id);
            if ($fresh->status !== CertificateStatus::Reserved) {
                throw new DomainRuleViolation("Сертификат в статусе «{$fresh->status->getLabel()}»: снять бронь нельзя.");
            }
            $before = $this->snapshot($fresh);
            $label = $fresh->reservationLabel();
            $fresh->update(['status' => CertificateStatus::Active, 'updated_by' => auth()->id()] + $this->cleared());
            $this->history->record($fresh, HistoryEvent::ReservationReleased, "{$label}. {$reason}", $before, $this->snapshot($fresh));
            $this->fulfillments->syncWithCertificate($fresh, 'Бронь снята');
            $certificate->setRawAttributes($fresh->getAttributes(), true);

            return $fresh;
        });
    }

    /** Финансы подтверждают бронь на проверке. */
    public function approve(Certificate $certificate, ?string $comment = null): Certificate
    {
        return $this->review($certificate, ReservationReview::Approved, trim((string) $comment));
    }

    /** Финансы оспаривают бронь: погасить сертификат нельзя, пока спор не снят. */
    public function dispute(Certificate $certificate, string $comment): Certificate
    {
        $comment = trim($comment);
        if ($comment === '') {
            throw new DomainRuleViolation('Укажите, что не так с бронью.');
        }

        return $this->review($certificate, ReservationReview::Disputed, $comment);
    }

    private function review(Certificate $certificate, ReservationReview $decision, string $comment): Certificate
    {
        return DB::transaction(function () use ($certificate, $decision, $comment) {
            $fresh = Certificate::query()->lockForUpdate()->findOrFail($certificate->id);
            $allowed = $decision === ReservationReview::Approved
                ? [ReservationReview::Pending, ReservationReview::Disputed]
                : [ReservationReview::Pending, ReservationReview::Approved];
            if (! in_array($fresh->reservation_review_status, $allowed, true)) {
                throw new DomainRuleViolation('Бронь не на проверке.');
            }
            $from = $fresh->reservation_review_status;
            $fresh->update([
                'reservation_review_status' => $decision,
                'reservation_reviewed_by' => auth()->id(),
                'reservation_reviewed_at' => now(),
                'reservation_review_comment' => $comment !== '' ? $comment : null,
            ]);
            $this->history->record($fresh, HistoryEvent::ReservationReviewed,
                $decision->getLabel().($comment !== '' ? ". {$comment}" : ''),
                ['reservation_review' => $from->value], ['reservation_review' => $decision->value]);
            $certificate->setRawAttributes($fresh->getAttributes(), true);

            return $fresh;
        });
    }

    private function assertDates(Certificate $certificate, Carbon $arrival, Carbon $departure, bool $isNew): void
    {
        if ($departure->lte($arrival)) {
            throw new DomainRuleViolation('Дата выезда должна быть позже даты заезда.');
        }
        if ($arrival->lt($certificate->valid_from) || $arrival->gt($certificate->valid_until)) {
            throw new DomainRuleViolation('Заезд — в пределах срока действия сертификата: '
                .$certificate->valid_from->format('d.m.Y').' — '.$certificate->valid_until->format('d.m.Y').'.');
        }
        if ($isNew && $arrival->lt(today()->subDay())) {
            throw new DomainRuleViolation('Дата заезда уже прошла — проверьте даты брони.');
        }
    }

    /** Один номер брони — один сертификат; исключение — сертификаты одной группы (несколько номеров в одной брони). */
    public function assertBookingFree(Certificate $certificate, string $booking): void
    {
        $taken = Certificate::query()->where('pms_booking_number', $booking)->whereKeyNot($certificate->id)
            ->whereIn('status', [CertificateStatus::Reserved, CertificateStatus::InUse])
            ->when($certificate->batch_id, fn ($q) => $q->where(fn ($q) => $q->whereNull('batch_id')->orWhere('batch_id', '!=', $certificate->batch_id)))
            ->value('number');
        if ($taken) {
            throw new DomainRuleViolation("Бронь № {$booking} уже привязана к сертификату {$taken}.");
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(Certificate $certificate): array
    {
        return [
            'status' => $certificate->status->value,
            'pms_booking_number' => $certificate->pms_booking_number,
            'booking_guest_name' => $certificate->booking_guest_name,
            'arrival_date' => $certificate->arrival_date?->toDateString(),
            'departure_date' => $certificate->departure_date?->toDateString(),
            'name_match' => $certificate->name_match?->value,
        ];
    }

    /** @return array<string, null> */
    private function cleared(): array
    {
        return array_fill_keys(['pms_booking_number', 'booking_guest_name', 'arrival_date', 'departure_date', 'name_match',
            'reservation_note', 'reservation_review_status', 'reservation_reviewed_by', 'reservation_reviewed_at', 'reservation_review_comment'], null);
    }
}
