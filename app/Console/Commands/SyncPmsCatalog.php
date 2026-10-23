<?php

namespace App\Console\Commands;

use App\Services\Pms\PmsCatalogSync;
use App\Services\Pms\PmsException;
use App\Support\ErrorReporter;
use Illuminate\Console\Command;

/** Ночное обновление списка услуг из PMS (Exely). Цен нет: цены вводятся в Gifter. */
class SyncPmsCatalog extends Command
{
    protected $signature = 'gifter:sync-pms-catalog';

    protected $description = 'Обновить список услуг из PMS (Exely)';

    public function handle(PmsCatalogSync $sync): int
    {
        try {
            $stats = $sync->run();
        } catch (PmsException $e) {
            $this->error($e->getMessage());
            ErrorReporter::report($e);

            return self::FAILURE;
        }
        $this->info("Услуг в PMS: {$stats['total']}, новых: {$stats['new']}, изменено: {$stats['updated']}, пропало: {$stats['missing']}; ".PmsCatalogSync::languagesNote($stats).'.');

        return self::SUCCESS;
    }
}
