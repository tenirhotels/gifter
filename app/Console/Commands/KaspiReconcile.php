<?php

namespace App\Console\Commands;

use App\Services\Kaspi\KaspiClient;
use App\Services\KaspiPayments;
use Illuminate\Console\Command;

class KaspiReconcile extends Command
{
    protected $signature = 'gifter:kaspi-reconcile';

    protected $description = 'Проверить у посредника ожидающие счета Kaspi и отменить просроченные';

    public function handle(KaspiPayments $payments): int
    {
        if (! KaspiClient::configured()) {
            return self::SUCCESS;
        }
        $result = $payments->reconcilePending();
        $this->info("Оплачено: {$result['paid']}, счетов истекло: {$result['expired']}");

        return self::SUCCESS;
    }
}
