<?php

namespace App\Mail\Concerns;

use Illuminate\Bus\Queueable;

/**
 * Письмо уходит через очередь (QUEUE_CONNECTION=database): страница не ждёт почтовый сервер,
 * сбой почты повторяется — 3 попытки через 1 и 5 минут. Очередь разбирает планировщик (routes/console.php).
 */
trait QueuedMail
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];
}
