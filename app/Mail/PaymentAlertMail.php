<?php

namespace App\Mail;

use App\Mail\Concerns\QueuedMail;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Ответственному: инцидент по онлайн-оплате (нужна проверка, расхождение суммы, вход кассира). Без телефонов и ключей. */
class PaymentAlertMail extends Mailable implements ShouldQueueAfterCommit
{
    use QueuedMail;

    public function __construct(public readonly string $subjectLine, public readonly string $text) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Gifter: '.$this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>'.e($this->text).'</p><p>Откройте Gifter и проверьте платёж в карточке сертификата (вкладка «Оплата»).</p>');
    }
}
