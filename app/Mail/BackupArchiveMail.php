<?php

namespace App\Mail;

use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Резервная копия базы (зашифрованный архив) — для хранения вне хостинга. Пароль в письмо не попадает. */
class BackupArchiveMail extends Mailable
{
    public function __construct(public readonly string $path, public readonly string $date) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Gifter: резервная копия от {$this->date}");
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>Резервная копия базы Gifter от '.e($this->date).'.</p>'
            .'<p>Архив зашифрован; пароль хранится у владельца отдельно. Сохраните вложение вне хостинга.</p>');
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [Attachment::fromPath($this->path)];
    }
}
