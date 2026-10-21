<?php

namespace App\Mail;

use App\Mail\Concerns\QueuedMail;
use App\Models\ShopSetting;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Клиенту: заявка принята. Язык — тот, на котором оставлена заявка. */
class OnlineOrderReceivedMail extends Mailable implements ShouldQueueAfterCommit
{
    use QueuedMail;

    public function __construct(public readonly string $packageName, string $lang = 'ru')
    {
        $this->locale($lang);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: config('gifter.certificate.hotel_name').': '.__('заявка на сертификат принята'));
    }

    public function content(): Content
    {
        $contacts = config('gifter.certificate.hotel_contacts');
        $whatsapp = ShopSetting::current()->whatsapp();

        return new Content(htmlString: '<p>'.e(__('Спасибо! Мы получили заявку на сертификат «:p1».', ['p1' => $this->packageName])).'</p>'
            .'<p>'.e(__('Менеджер свяжется с вами для завершения заказа.')).'</p>'
            .($whatsapp ? '<p><a href="https://wa.me/'.$whatsapp.'?text='.rawurlencode(__('Здравствуйте! Хочу заказать подарочный сертификат.')).'">'.e(__('Написать в WhatsApp')).'</a></p>' : '')
            .($contacts ? '<p>'.e($contacts).'</p>' : ''));
    }
}
