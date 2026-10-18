<?php

namespace App\Console\Commands;

use App\Services\CertificateManager;
use Illuminate\Console\Command;

class ExpireCertificates extends Command
{
    protected $signature = 'gifter:expire-certificates';

    protected $description = 'Перевести активные сертификаты с истёкшим сроком в статус «Истёк»';

    public function handle(CertificateManager $certificates): int
    {
        $count = $certificates->expireOverdue();
        $this->info("Истёкших сертификатов: {$count}");

        return self::SUCCESS;
    }
}
