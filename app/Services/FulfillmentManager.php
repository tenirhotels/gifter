<?php

namespace App\Services;

use App\Enums\CertificateStatus;
use App\Enums\FulfillmentStatus;
use App\Enums\HistoryEvent;
use App\Exceptions\DomainRuleViolation;
use App\Models\Certificate;
use App\Models\ServiceFulfillment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Заявки на исполнение услуг сертификата (фаза 5).
 *
 * Заявки появляются при отметке брони — для каждой услуги с признаком «требует
 * подготовки» (решение 27.10.2026). Путь: Ожидает → Заявка отправлена → Время
 * назначено → Подтверждено → Выполнено; отменить можно на любом шаге до выполнения.
 * Статус заявки — подготовка услуги, а не частичное погашение: сертификат гасится
 * только целиком. Каждое изменение пишется в историю сертификата.
 */
class FulfillmentManager
{
    public function __construct(private readonly CertificateHistoryRecorder $history) {}

    /**
     * Держит заявки в соответствии со статусом сертификата: при брони и заезде —
     * открыты по всем услугам, требующим подготовки; при снятии брони, погашении,
     * истечении и аннулировании открытые заявки отменяются с причиной.
     */
    public function syncWithCertificate(Certificate $certificate, ?string $reason = null): void
    {
        if (in_array($certificate->status, [CertificateStatus::Reserved, CertificateStatus::InUse], true)) {
            $this->openFor($certificate);

            return;
        }
        $this->cancelOpen($certificate, $reason ?? match ($certificate->status) {
            CertificateStatus::Active => 'Бронь снята',
            CertificateStatus::Used => 'Сертификат погашен, услуга не выполнена',
            CertificateStatus::Expired => 'Срок действия сертификата истёк',
            CertificateStatus::Cancelled => 'Сертификат аннулирован',
            default => "Статус сертификата: {$certificate->status->getLabel()}",
        });
    }

    public function request(ServiceFulfillment $fulfillment, ?string $details = null): ServiceFulfillment
    {
        return $this->change($fulfillment, [FulfillmentStatus::Pending], FulfillmentStatus::Requested, function (ServiceFulfillment $f) use ($details) {
            return ['request_details' => $this->clean($details) ?? $f->request_details, 'requested_at' => now()];
        }, $this->clean($details));
    }

    /** Назначить (или перенести) дату и время. Перенос подтверждённой услуги снимает подтверждение. */
    public function schedule(ServiceFulfillment $fulfillment, Carbon $date, ?string $time = null, ?string $note = null): ServiceFulfillment
    {
        $time = $this->clean($time);
        if ($time !== null && ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            throw new DomainRuleViolation('Время — в формате ЧЧ:ММ, например 19:00.');
        }

        return $this->change($fulfillment, [FulfillmentStatus::Pending, FulfillmentStatus::Requested, FulfillmentStatus::Scheduled, FulfillmentStatus::Confirmed],
            FulfillmentStatus::Scheduled, function (ServiceFulfillment $f) use ($date, $time) {
                $validFrom = $f->certificate->valid_from->copy()->startOfDay();
                $validUntil = $f->certificate->valid_until->copy()->endOfDay();
                if ($date->lt($validFrom) || $date->gt($validUntil)) {
                    throw new DomainRuleViolation('Дата — в пределах срока действия сертификата: '
                        .$f->certificate->valid_from->format('d.m.Y').' — '.$f->certificate->valid_until->format('d.m.Y').'.');
                }

                return ['scheduled_date' => $date->toDateString(), 'scheduled_time' => $time, 'confirmed_at' => null,
                    'requested_at' => $f->requested_at ?? now()];
            }, trim($date->format('d.m.Y').' '.$time).($note ? ". {$note}" : ''));
    }

    public function confirm(ServiceFulfillment $fulfillment, ?string $note = null): ServiceFulfillment
    {
        return $this->change($fulfillment, [FulfillmentStatus::Requested, FulfillmentStatus::Scheduled], FulfillmentStatus::Confirmed,
            function (ServiceFulfillment $f) use ($note) {
                if ($f->requires_scheduling && ! $f->scheduled_date) {
                    throw new DomainRuleViolation('Сначала назначьте дату и время — услуга требует расписания.');
                }

                return ['confirmed_at' => now(), 'notes' => $this->appendNote($f->notes, $note)];
            }, $this->clean($note));
    }

    public function complete(ServiceFulfillment $fulfillment, ?string $note = null): ServiceFulfillment
    {
        return $this->change($fulfillment, [FulfillmentStatus::Requested, FulfillmentStatus::Scheduled, FulfillmentStatus::Confirmed],
            FulfillmentStatus::Completed, function (ServiceFulfillment $f) use ($note) {
                if ($f->certificate->status !== CertificateStatus::InUse) {
                    throw new DomainRuleViolation('Выполнение отмечается во время проживания (после «Заезд»).');
                }
                if ($f->requires_confirmation && $f->status !== FulfillmentStatus::Confirmed) {
                    throw new DomainRuleViolation('Услуга требует подтверждения поставщика — сначала «Подтверждено».');
                }

                return ['completed_at' => now(), 'notes' => $this->appendNote($f->notes, $note)];
            }, $this->clean($note));
    }

    public function cancel(ServiceFulfillment $fulfillment, string $reason): ServiceFulfillment
    {
        $reason = $this->clean($reason) ?? throw new DomainRuleViolation('Укажите причину отмены заявки.');

        return $this->change($fulfillment, FulfillmentStatus::open(), FulfillmentStatus::Cancelled,
            fn () => ['cancelled_at' => now(), 'cancellation_reason' => $reason], $reason, requireActiveCertificate: false);
    }

    /** Заявки по услугам «требует подготовки», у которых ещё нет открытой заявки. */
    private function openFor(Certificate $certificate): void
    {
        $open = $certificate->fulfillments()->whereIn('status', FulfillmentStatus::open())->pluck('certificate_item_id')->all();
        $items = $certificate->items()->where('requires_fulfillment', true)->whereNotIn('id', $open)->orderBy('id')->get();
        foreach ($items as $item) {
            $fulfillment = $certificate->fulfillments()->create([
                'certificate_item_id' => $item->id,
                'provider_id' => $item->provider_id,
                'status' => FulfillmentStatus::Pending,
                'requires_scheduling' => $item->requires_scheduling,
                'requires_confirmation' => $item->requires_confirmation,
                'updated_by' => auth()->id(),
            ]);
            $this->history->record($certificate, HistoryEvent::FulfillmentChanged, "{$fulfillment->serviceLabel()}: заявка создана",
                null, ['fulfillment_id' => $fulfillment->id, 'status' => FulfillmentStatus::Pending->value]);
        }
    }

    private function cancelOpen(Certificate $certificate, string $reason): void
    {
        foreach ($certificate->fulfillments()->whereIn('status', FulfillmentStatus::open())->get() as $fulfillment) {
            $from = $fulfillment->status;
            $fulfillment->update(['status' => FulfillmentStatus::Cancelled, 'cancelled_at' => now(), 'cancellation_reason' => $reason, 'updated_by' => auth()->id()]);
            $this->history->record($certificate, HistoryEvent::FulfillmentChanged,
                "{$fulfillment->serviceLabel()}: «{$from->getLabel()}» → «Отменено». {$reason}",
                ['fulfillment_id' => $fulfillment->id, 'status' => $from->value], ['fulfillment_id' => $fulfillment->id, 'status' => FulfillmentStatus::Cancelled->value]);
        }
    }

    /**
     * @param  list<FulfillmentStatus>  $allowedFrom
     * @param  callable(ServiceFulfillment): array<string, mixed>  $changes
     */
    private function change(ServiceFulfillment $fulfillment, array $allowedFrom, FulfillmentStatus $target, callable $changes,
        ?string $comment = null, bool $requireActiveCertificate = true): ServiceFulfillment
    {
        return DB::transaction(function () use ($fulfillment, $allowedFrom, $target, $changes, $comment, $requireActiveCertificate) {
            $fresh = ServiceFulfillment::query()->lockForUpdate()->with(['certificate', 'item'])->findOrFail($fulfillment->id);
            $from = $fresh->status;
            if (! in_array($from, $allowedFrom, true)) {
                throw new DomainRuleViolation("Заявка в статусе «{$from->getLabel()}»: действие недоступно.");
            }
            if ($requireActiveCertificate && ! in_array($fresh->certificate->status, [CertificateStatus::Reserved, CertificateStatus::InUse], true)) {
                throw new DomainRuleViolation("Сертификат в статусе «{$fresh->certificate->status->getLabel()}»: заявки ведутся только при брони и проживании.");
            }
            $fresh->update(['status' => $target, 'updated_by' => auth()->id()] + $changes($fresh));
            $this->history->record($fresh->certificate, HistoryEvent::FulfillmentChanged,
                "{$fresh->serviceLabel()}: «{$from->getLabel()}» → «{$target->getLabel()}»".($comment ? ". {$comment}" : ''),
                ['fulfillment_id' => $fresh->id, 'status' => $from->value], ['fulfillment_id' => $fresh->id, 'status' => $target->value]);
            $fulfillment->setRawAttributes($fresh->getAttributes(), true);

            return $fresh;
        });
    }

    private function clean(?string $text): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : $text;
    }

    private function appendNote(?string $notes, ?string $note): ?string
    {
        $note = $this->clean($note);

        return $note ? trim(($notes ? $notes."\n" : '').$note) : $notes;
    }
}
