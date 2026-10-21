<?php

namespace App\Services;

use App\Enums\DeliveryMethod;
use App\Enums\HistoryEvent;
use App\Enums\SaleSource;
use App\Exceptions\DomainRuleViolation;
use App\Mail\CertificateIssuedMail;
use App\Models\Certificate;
use App\Support\ContactMask;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Электронный сертификат: PDF на email гостя. Каждая отправка — в истории сертификата (адрес — замаскирован). */
class CertificateMailer
{
    public function __construct(private readonly CertificateHistoryRecorder $history) {}

    public function send(Certificate $certificate, string $email, string $lang = 'ru'): void
    {
        $email = trim($email);
        if (! $certificate->isPrintable()) {
            throw new DomainRuleViolation(__('PDF доступен только для выпущенного действующего сертификата.'));
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new DomainRuleViolation(__('Укажите правильный email.'));
        }
        $lang = array_key_exists($lang, config('gifter.locales')) ? $lang : config('gifter.default_locale');

        Mail::to($email)->send(new CertificateIssuedMail($certificate->id, $lang));
        $this->history->record($certificate, HistoryEvent::Emailed, ContactMask::email($email).' · '.strtoupper($lang));
    }

    /**
     * После выпуска: заявка с сайта со способом «Электронный сертификат на email» — письмо уходит само,
     * на языке заявки. Сбой почты не отменяет выпуск: сотрудник отправит из карточки.
     */
    public function sendAfterIssue(Certificate $certificate): void
    {
        $order = $certificate->source === SaleSource::Website ? $certificate->onlineOrder : null;
        if (! $order || $order->delivery_method !== DeliveryMethod::Pdf || ! $order->email) {
            return;
        }
        try {
            $this->send($certificate, $order->email, $order->locale);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Адрес по умолчанию для кнопки «Отправить на email»: из заявки с сайта, иначе заказчика, иначе получателя. */
    public function defaultEmail(Certificate $certificate): ?string
    {
        return $certificate->onlineOrder?->email ?: ($certificate->customer?->email ?: $certificate->recipient?->email);
    }
}
