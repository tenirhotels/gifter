<?php

namespace App\Console\Commands;

use App\Services\Epay\EpayClient;
use App\Services\EpayPayments;
use Illuminate\Console\Command;

class EpayReconcile extends Command
{
    protected $signature = 'gifter:epay-reconcile';

    protected $description = 'Проверить в банке ожидающие оплаты картой и отменить просроченные ссылки';

    public function handle(EpayPayments $payments): int
    {
        if (! EpayClient::configured()) {
            return self::SUCCESS;
        }
        $result = $payments->reconcilePending();
        $this->info("Оплачено: {$result['paid']}, ссылок истекло: {$result['expired']}");

        return self::SUCCESS;
    }
}
