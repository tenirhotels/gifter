<?php

namespace App\Mail;

use App\Mail\Concerns\QueuedMail;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Сотрудникам: пришла заявка с сайта. Контакты клиента в письмо не попадают — они в Gifter. */
class OnlineOrderMail extends Mailable implements ShouldQueueAfterCommit
{
    use QueuedMail;

    public function __construct(public readonly int $orderId, public readonly string $packageName) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Gifter: заявка с сайта № {$this->orderId}");
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>Новая заявка с сайта № '.$this->orderId.': '.e($this->packageName).'.</p>'
            .'<p>Откройте Gifter → Продажи → Заявки с сайта, свяжитесь с клиентом и оформите заявку.</p>');
    }
}
