<?php

namespace App\Mail;

use App\Mail\Concerns\QueuedMail;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Клиенту: ссылка на оплату сертификата картой. Банк в письме не называется. Язык — по заявке или выбору сотрудника. */
class EpayPaymentLinkMail extends Mailable implements ShouldQueueAfterCommit
{
    use QueuedMail;

    public function __construct(public readonly string $url, public readonly string $amount, public readonly string $number, public readonly string $validUntil, string $lang = 'ru')
    {
        $this->locale($lang);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: config('gifter.certificate.hotel_name').': '.__('оплата сертификата № :p1', ['p1' => $this->number]));
    }

    public function content(): Content
    {
        $contacts = config('gifter.certificate.hotel_contacts');

        return new Content(htmlString: '<p>'.e(__('Здравствуйте! Для вашего подарочного сертификата № :p1 выставлена оплата: :p2.', ['p1' => $this->number, 'p2' => $this->amount])).'</p>'
            .'<p><a href="'.e($this->url).'">'.e(__('Оплатить картой')).'</a></p>'
            .'<p>'.e(__('Ссылка действует до :p1. После оплаты сертификат будет выпущен, мы пришлём его вам.', ['p1' => $this->validUntil])).'</p>'
            .'<p>'.e(__('Если вы не заказывали сертификат, просто проигнорируйте это письмо.')).'</p>'
            .($contacts ? '<p>'.e($contacts).'</p>' : ''));
    }
}
