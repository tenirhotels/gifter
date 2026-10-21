<?php

namespace App\Console\Commands;

use App\Services\Reminders;
use Illuminate\Console\Command;

class RemindOnlineOrders extends Command
{
    protected $signature = 'gifter:remind-online-orders';

    protected $description = 'Напомнить сотрудникам о заявках с сайта, которые ждут ответа больше двух часов';

    public function handle(Reminders $reminders): int
    {
        $this->info('Напомнено о заявках: '.$reminders->onlineOrders());

        return self::SUCCESS;
    }
}
