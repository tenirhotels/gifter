<?php

namespace App\Mail;

use App\Mail\Concerns\QueuedMail;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Сотрудникам: заявки с сайта ждут ответа дольше двух часов. Без контактов клиента — они в Gifter. */
class OnlineOrdersReminderMail extends Mailable implements ShouldQueueAfterCommit
{
    use QueuedMail;

    /** @param  array<int, string>  $orders  номер заявки => пакет */
    public function __construct(public readonly array $orders) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Gifter: заявки с сайта ждут ответа ('.count($this->orders).')');
    }

    public function content(): Content
    {
        $list = collect($this->orders)->map(fn (string $package, int $id) => '<li>№ '.$id.': '.e($package).'</li>')->implode('');

        return new Content(htmlString: '<p>Эти заявки с сайта ждут ответа больше двух часов:</p><ul>'.$list.'</ul>'
            .'<p>Откройте Gifter → Продажи → Заявки с сайта, свяжитесь с клиентом и оформите или отклоните заявку.</p>');
    }
}
