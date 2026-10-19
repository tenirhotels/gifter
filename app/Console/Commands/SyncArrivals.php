<?php

namespace App\Console\Commands;

use App\Services\ArrivalSync;
use Illuminate\Console\Command;

/**
 * Проверка броней по PMS в день заезда: утром — расхождения, в 16:00 и вечером — заселились ли гости.
 * Запросов мало: только сертификаты с заездом сегодня, один запрос на бронь, без повторов (см. ArrivalSync).
 */
class SyncArrivals extends Command
{
    protected $signature = 'gifter:sync-arrivals {--slot=afternoon : morning, afternoon или evening}';

    protected $description = 'Проверить по PMS брони с заездом сегодня и отметить заезд тех, кто заселился (Hotel Hub)';

    public function handle(ArrivalSync $sync): int
    {
        $slot = (string) $this->option('slot');
        if (! in_array($slot, ['morning', 'afternoon', 'evening'], true)) {
            $this->error('slot: morning, afternoon или evening.');

            return self::FAILURE;
        }
        $stats = $sync->syncToday($slot);
        $this->info("Проверено: {$stats['checked']}, заезд отмечен: {$stats['checked_in']}, расхождений: {$stats['discrepancies']}, PMS недоступна: {$stats['unavailable']}.");

        return self::SUCCESS;
    }
}
