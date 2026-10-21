<?php

namespace App\Console\Commands;

use App\Services\Reminders;
use Illuminate\Console\Command;

class RemindExpiring extends Command
{
    protected $signature = 'gifter:remind-expiring';

    protected $description = 'Напомнить гостям о сертификатах, срок которых истекает через 14 дней';

    public function handle(Reminders $reminders): int
    {
        $this->info('Напомнено о сертификатах: '.$reminders->expiring());

        return self::SUCCESS;
    }
}
