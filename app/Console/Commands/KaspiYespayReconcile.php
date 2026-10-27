<?php

namespace App\Console\Commands;

use App\Services\KaspiYespay\KaspiYespayClient;
use App\Services\KaspiYespayPayments;
use Illuminate\Console\Command;

class KaspiYespayReconcile extends Command
{
    protected $signature = 'gifter:kaspi-yespay-reconcile';

    protected $description = 'Проверить у посредника ожидающие счета Kaspi и отменить просроченные';

    public function handle(KaspiYespayPayments $payments): int
    {
        if (! KaspiYespayClient::configured()) {
            return self::SUCCESS;
        }
        $result = $payments->reconcilePending();
        $this->info("Оплачено: {$result['paid']}, счетов истекло: {$result['expired']}");

        return self::SUCCESS;
    }
}
