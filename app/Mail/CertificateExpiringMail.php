<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Гостю: срок сертификата скоро истечёт. Текст на казахском и русском — язык гостя неизвестен. */
class CertificateExpiringMail extends Mailable
{
    public function __construct(public readonly string $number, public readonly string $validUntil, public readonly ?string $verifyUrl = null) {}

    public function envelope(): Envelope
    {
        $hotel = config('gifter.certificate.hotel_name');

        return new Envelope(subject: $hotel.': '.__('срок сертификата скоро истекает', [], 'kk').' / '.__('срок сертификата скоро истекает', [], 'ru'));
    }

    public function content(): Content
    {
        $contacts = config('gifter.certificate.hotel_contacts');
        $block = fn (string $lang) => '<p>'.e(__('Срок действия подарочного сертификата № :p1 истекает :p2.', ['p1' => $this->number, 'p2' => $this->validUntil], $lang)).'</p>'
            .'<p>'.e(__('Забронируйте визит заранее — после этой даты сертификат нельзя будет использовать.', [], $lang)).'</p>'
            .($this->verifyUrl ? '<p><a href="'.e($this->verifyUrl).'">'.e(__('Проверить сертификат', [], $lang)).'</a></p>' : '');

        return new Content(htmlString: $block('kk').'<hr>'.$block('ru').($contacts ? '<p>'.e($contacts).'</p>' : ''));
    }
}
