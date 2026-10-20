<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Необработанная ошибка рабочего сервера: класс, место, адрес без параметров. Данные клиентов и токены не передаются. */
class ErrorReportMail extends Mailable
{
    /** @param  array<string, string>  $info */
    public function __construct(public readonly array $info) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Gifter: ошибка на сервере — '.$this->info['class']);
    }

    public function content(): Content
    {
        $rows = collect($this->info)->map(fn ($value, $key) => '<tr><td><b>'.e(['class' => __('Класс ошибки'), 'file' => __('Файл'), 'url' => __('Адрес')][$key] ?? $key).'</b></td><td>'.e($value).'</td></tr>')->implode('');

        return new Content(htmlString: '<p>Необработанная ошибка. Подробности — в журнале сервера (storage/logs).</p><table cellpadding="4">'.$rows.'</table>');
    }
}
