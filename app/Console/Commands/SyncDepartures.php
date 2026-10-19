<?php

namespace App\Console\Commands;

use App\Services\ArrivalSync;
use Illuminate\Console\Command;

/**
 * Автопогашение после выезда: Hotel Hub — по `checked_out`, прямой Exely — на следующий день после планового выезда
 * (с проверкой брони по PMS). Раз в день, один запрос на бронь (см. ArrivalSync::syncDepartures).
 */
class SyncDepartures extends Command
{
    protected $signature = 'gifter:sync-departures';

    protected $description = 'Отметить сертификаты использованными после выезда гостя по данным PMS';

    public function handle(ArrivalSync $sync): int
    {
        $stats = $sync->syncDepartures();
        $this->info("Проверено: {$stats['checked']}, использовано: {$stats['redeemed']}, не заезд (закрыто): {$stats['no_show']}, расхождений: {$stats['discrepancies']}, с невыполненными услугами: {$stats['open_services']}, PMS недоступна: {$stats['unavailable']}.");

        return self::SUCCESS;
    }
}
