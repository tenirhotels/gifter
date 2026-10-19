<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/** Метка «планировщик жив»: пишется каждую минуту; если она устарела, «Безопасность» показывает ошибку. */
class Heartbeat extends Command
{
    public const KEY = 'gifter.heartbeat';

    protected $signature = 'gifter:heartbeat';

    protected $description = 'Отметить, что планировщик (cron schedule:run) работает';

    public function handle(): int
    {
        Cache::forever(self::KEY, now()->timestamp);

        return self::SUCCESS;
    }
}
