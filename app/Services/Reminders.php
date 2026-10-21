<?php

namespace App\Services;

use App\Enums\CertificateStatus;
use App\Enums\HistoryEvent;
use App\Enums\OnlineOrderStatus;
use App\Mail\CertificateExpiringMail;
use App\Mail\OnlineOrdersReminderMail;
use App\Models\Certificate;
use App\Models\OnlineOrder;
use Illuminate\Support\Facades\Mail;

/** Напоминания по расписанию: заявки с сайта без ответа и сертификаты, у которых скоро истечёт срок. */
class Reminders
{
    public const ORDER_WAIT_HOURS = 2;

    public const EXPIRY_DAYS = 14;

    public function __construct(private readonly CertificateHistoryRecorder $history) {}

    /** Новые заявки старше двух часов — одно письмо сотрудникам; каждая заявка напоминается один раз. */
    public function onlineOrders(): int
    {
        $to = config('gifter.online.mail_to');
        $orders = OnlineOrder::query()->with('package')->where('status', OnlineOrderStatus::New)->whereNull('reminded_at')
            ->where('created_at', '<=', now()->subHours(self::ORDER_WAIT_HOURS))->orderBy('id')->get();
        if (! $to || $orders->isEmpty()) {
            return 0;
        }

        Mail::to($to)->send(new OnlineOrdersReminderMail($orders->mapWithKeys(fn (OnlineOrder $o) => [$o->id => (string) $o->package?->name])->all()));
        OnlineOrder::query()->whereKey($orders->modelKeys())->update(['reminded_at' => now()]);

        return $orders->count();
    }

    /** Выпущенные неиспользованные сертификаты, которым осталось 14 дней: письмо заказчику и получателю (у кого есть email). */
    public function expiring(): int
    {
        $count = 0;
        Certificate::query()->with(['customer', 'recipient'])->where('status', CertificateStatus::Active)->whereNull('expiry_reminded_at')
            ->whereDate('valid_until', '>=', today()->toDateString())
            ->whereDate('valid_until', '<=', today()->addDays(self::EXPIRY_DAYS)->toDateString())
            ->each(function (Certificate $certificate) use (&$count) {
                $emails = collect([$certificate->customer?->email, $certificate->recipient?->email])
                    ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))->map(fn ($email) => mb_strtolower($email))->unique();
                if ($emails->isEmpty()) {
                    return;
                }

                $url = $certificate->verification_token ? route('certificates.verify', $certificate->verification_token) : null;
                foreach ($emails as $email) {
                    Mail::to($email)->send(new CertificateExpiringMail($certificate->number, $certificate->valid_until->format('d.m.Y'), $url));
                }
                $certificate->forceFill(['expiry_reminded_at' => now()])->saveQuietly();
                $this->history->record($certificate, HistoryEvent::ExpiryReminded, __('Письмо на :p1 адрес(а)', ['p1' => $emails->count()]));
                $count++;
            });

        return $count;
    }
}
