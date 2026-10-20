<?php

namespace App\Services;

use App\Enums\CertificateStatus;
use App\Enums\ExceptionStatus;
use App\Enums\HistoryEvent;
use App\Exceptions\DomainRuleViolation;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\ReservationException;
use App\Services\Pms\PmsClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Бронь по исключению (решение владельца 19.10.2026): сертификат истёк, а брони не было.
 * Front Office (супервайзер) оформляет заявку — бронь на свободную и доступную дату, номер PMS, гость, причина;
 * директор по продажам согласует. После согласования сертификат возвращается в работу: срок продлевается до
 * выезда, бронь привязывается, дальше обычный путь «Заезд → Погашен». Комиссия продавца, не начисленная из-за
 * истечения, снова ждёт погашения и начисляется как обычно. Заявки и решения сохраняются, всё — в истории и аудите.
 */
class ReservationExceptionManager
{
    public function __construct(
        private readonly CertificateHistoryRecorder $history,
        private readonly ReservationManager $reservations,
        private readonly CommissionManager $commissions,
    ) {}

    /** @param  array{booking_number:string, guest_name:string, arrival:mixed, departure:mixed, reason:string}  $data */
    public function request(Certificate $certificate, array $data): ReservationException
    {
        return DB::transaction(function () use ($certificate, $data) {
            $fresh = Certificate::query()->lockForUpdate()->findOrFail($certificate->id);
            if ($fresh->status !== CertificateStatus::Expired) {
                throw new DomainRuleViolation(__('Бронь по исключению — только для истёкшего сертификата.'));
            }
            if ($fresh->hasPendingException()) {
                throw new DomainRuleViolation(__('Заявка по этому сертификату уже ждёт решения директора.'));
            }
            if (! $fresh->recipient_id) {
                throw new DomainRuleViolation(__('Для брони укажите получателя (гостя).'));
            }
            $booking = trim((string) ($data['booking_number'] ?? ''));
            $reason = trim((string) ($data['reason'] ?? ''));
            if (PmsClient::make()->isActive()) {
                // Бронь подтверждается по PMS: гость и даты — оттуда.
                if ($booking === '') {
                    throw new DomainRuleViolation(__('Укажите номер брони PMS.'));
                }
                $pms = $this->reservations->resolveFromPms($fresh, $booking, fresh: true);
                $guest = $pms['guest'];
                $arrival = $pms['booking']->arrival;
                $departure = $pms['booking']->departure;
            } else {
                $guest = trim((string) ($data['guest_name'] ?? ''));
                if ($booking === '' || $guest === '') {
                    throw new DomainRuleViolation(__('Укажите номер брони PMS и имя гостя, как в брони.'));
                }
                $arrival = Carbon::parse($data['arrival'])->startOfDay();
                $departure = Carbon::parse($data['departure'])->startOfDay();
            }
            if ($reason === '') {
                throw new DomainRuleViolation(__('Укажите причину исключения для директора.'));
            }
            if ($departure->lte($arrival)) {
                throw new DomainRuleViolation(__('Дата выезда должна быть позже даты заезда.'));
            }
            if ($arrival->lt(today())) {
                throw new DomainRuleViolation(__('Бронь по исключению — на свободную дату не раньше сегодняшней.'));
            }
            $this->reservations->assertBookingFree($fresh, $booking);

            $exception = $fresh->reservationExceptions()->create([
                'status' => ExceptionStatus::Pending, 'booking_number' => $booking, 'guest_name' => $guest,
                'arrival_date' => $arrival, 'departure_date' => $departure, 'reason' => $reason, 'requested_by' => auth()->id(),
            ]);
            $this->history->record($fresh, HistoryEvent::ReservationChanged,
                "Заявка на бронь по исключению: № {$booking}, {$arrival->format('d.m')}–{$departure->format('d.m.Y')}. {$reason}");
            AuditLog::record('reservation_exception_requested', $exception, null, ['certificate_id' => $fresh->id, 'booking_number' => $booking, 'reason' => $reason]);

            return $exception;
        });
    }

    public function approve(ReservationException $exception, ?string $comment = null): ReservationException
    {
        return DB::transaction(function () use ($exception, $comment) {
            $exception = ReservationException::query()->lockForUpdate()->findOrFail($exception->id);
            if ($exception->status !== ExceptionStatus::Pending) {
                throw new DomainRuleViolation(__('Заявка уже рассмотрена.'));
            }
            $certificate = Certificate::query()->lockForUpdate()->findOrFail($exception->certificate_id);
            if ($certificate->status !== CertificateStatus::Expired) {
                throw new DomainRuleViolation(__('Сертификат в статусе «:p1» — исключение уже не нужно.', ['p1' => $certificate->status->getLabel()]));
            }
            if ($exception->arrival_date->lt(today())) {
                throw new DomainRuleViolation(__('Дата заезда в заявке уже прошла — отклоните заявку, пусть Front Office оформит новую.'));
            }

            // Возврат в работу: срок — до выезда, затем обычная отметка брони (спор по имени остаётся проверкой Финансов).
            $before = ['status' => $certificate->status->value, 'valid_until' => $certificate->valid_until->toDateString()];
            $certificate->update([
                'status' => CertificateStatus::Active,
                'valid_until' => $exception->departure_date,
                'custom_validity' => true,
                'updated_by' => auth()->id(),
            ]);
            $this->history->record($certificate, HistoryEvent::StatusChanged,
                'Исключение по согласованию директора: сертификат возвращён в работу, срок до '.$exception->departure_date->format('d.m.Y').trim('. '.($comment ?? '')),
                $before, ['status' => CertificateStatus::Active->value, 'valid_until' => $exception->departure_date->toDateString()]);
            $this->commissions->reopen($certificate);
            $this->reservations->save($certificate->refresh(), [
                'booking_number' => $exception->booking_number, 'guest_name' => $exception->guest_name,
                'arrival' => $exception->arrival_date, 'departure' => $exception->departure_date,
                'note' => 'Бронь по исключению: '.$exception->reason, 'skip_risk_review' => true,
            ]);

            $exception->update(['status' => ExceptionStatus::Approved, 'decided_by' => auth()->id(), 'decided_at' => now(), 'decision_comment' => $this->clean($comment)]);
            AuditLog::record('reservation_exception_approved', $exception, ['status' => 'pending'], ['status' => 'approved', 'certificate_id' => $certificate->id]);

            return $exception;
        });
    }

    public function reject(ReservationException $exception, string $comment): ReservationException
    {
        $comment = trim($comment) ?: throw new DomainRuleViolation(__('Укажите причину отказа.'));

        return DB::transaction(function () use ($exception, $comment) {
            $exception = ReservationException::query()->lockForUpdate()->findOrFail($exception->id);
            if ($exception->status !== ExceptionStatus::Pending) {
                throw new DomainRuleViolation(__('Заявка уже рассмотрена.'));
            }
            $exception->update(['status' => ExceptionStatus::Rejected, 'decided_by' => auth()->id(), 'decided_at' => now(), 'decision_comment' => $comment]);
            $this->history->record($exception->certificate, HistoryEvent::ReservationChanged, "Бронь по исключению отклонена директором. {$comment}");
            AuditLog::record('reservation_exception_rejected', $exception, ['status' => 'pending'], ['status' => 'rejected', 'reason' => $comment]);

            return $exception;
        });
    }

    private function clean(?string $text): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : $text;
    }
}
