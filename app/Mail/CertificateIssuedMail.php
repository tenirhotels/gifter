<?php

namespace App\Mail;

use App\Mail\Concerns\QueuedMail;
use App\Models\Certificate;
use App\Services\CertificateDocument;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Гостю: электронный сертификат — PDF во вложении и ссылка проверки. PDF собирается при отправке, на языке письма. */
class CertificateIssuedMail extends Mailable implements ShouldQueueAfterCommit
{
    use QueuedMail;

    public function __construct(public readonly int $certificateId, string $lang = 'ru')
    {
        $this->locale($lang);
    }

    private function certificate(): Certificate
    {
        return Certificate::query()->with(['recipient', 'package', 'items'])->findOrFail($this->certificateId);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: config('gifter.certificate.hotel_name').': '.__('ваш подарочный сертификат № :p1', ['p1' => $this->certificate()->number]));
    }

    public function content(): Content
    {
        $certificate = $this->certificate();
        $documents = app(CertificateDocument::class);
        $contacts = config('gifter.certificate.hotel_contacts');

        return new Content(htmlString: '<p>'.e(__('Здравствуйте! Во вложении — подарочный сертификат № :p1 «:p2».', ['p1' => $certificate->number, 'p2' => $certificate->package?->name])).'</p>'
            .'<p>'.e(__('Действует с :p1 по :p2 включительно. Проживание бронируется заранее по контактам отеля — назовите номер сертификата.', ['p1' => $certificate->valid_from->format('d.m.Y'), 'p2' => $certificate->valid_until->format('d.m.Y')])).'</p>'
            .'<p><a href="'.e($documents->verificationUrl($certificate)).'">'.e(__('Проверить сертификат')).'</a></p>'
            .($contacts ? '<p>'.e($contacts).'</p>' : ''));
    }

    public function attachments(): array
    {
        $certificate = $this->certificate();
        $documents = app(CertificateDocument::class);

        return [Attachment::fromData(fn () => $documents->pdf($certificate), $documents->filename($certificate))->withMime('application/pdf')];
    }
}
