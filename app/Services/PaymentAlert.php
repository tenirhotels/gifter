<?php

namespace App\Services;

use App\Mail\PaymentAlertMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Инцидент по онлайн-оплате доходит до человека письмом (а не только записью в журнал).
 * Адрес: KASPI_YESPAY_ALERT_MAIL_TO → GIFTER_ORDERS_MAIL_TO → ERROR_MAIL_TO. Если ни одного нет — запись critical в журнал
 * (gifter:security-check предупреждает об этом). В тексте только номера счетов и суммы, без контактов клиента.
 */
class PaymentAlert
{
    public function send(string $subject, string $text): void
    {
        $to = config('gifter.kaspi_yespay.alert_mail_to') ?: (config('gifter.online.mail_to') ?: config('gifter.monitoring.error_mail_to'));
        if (blank($to)) {
            Log::critical('Оплата: некому отправить уведомление — '.$subject, ['текст' => $text]);

            return;
        }
        try {
            Mail::to($to)->send(new PaymentAlertMail($subject, $text));
        } catch (\Throwable $e) {
            Log::critical('Оплата: уведомление не отправлено — '.$subject, ['причина' => $e::class, 'текст' => $text]);
        }
    }

    /** Письмо сотрудникам — всегда на русском, независимо от языка запроса. @param  array<string, string>  $replace */
    public function sendRussian(string $subject, string $text, array $replace = []): void
    {
        $previous = app()->getLocale();
        app()->setLocale('ru');
        try {
            $this->send(__($subject), __($text, $replace));
        } finally {
            app()->setLocale($previous);
        }
    }

    public static function hasRecipient(): bool
    {
        return filled(config('gifter.kaspi_yespay.alert_mail_to') ?: (config('gifter.online.mail_to') ?: config('gifter.monitoring.error_mail_to')));
    }
}
