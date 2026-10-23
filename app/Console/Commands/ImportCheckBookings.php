<?php

namespace App\Console\Commands;

use App\Services\LegacyBookingCheck;
use App\Services\Pms\PmsClient;
use Illuminate\Console\Command;

/** Проверить в PMS брони перенесённых сертификатов (после gifter:import-certificates), порциями и с паузой — лимиты запросов Exely не повышаем. */
class ImportCheckBookings extends Command
{
    protected $signature = 'gifter:import-check-bookings {--limit=100 : сколько броней проверить за запуск} {--pause=1 : пауза между запросами, секунд} {--retry : повторить брони, которые были недоступны}';

    protected $description = 'Проверить в PMS брони перенесённых из таблицы сертификатов (порциями)';

    public function handle(LegacyBookingCheck $check): int
    {
        if (! PmsClient::make()->isActive()) {
            $this->error('Интеграция с PMS не настроена.');

            return self::FAILURE;
        }
        $query = $check->pending((bool) $this->option('retry'));
        $left = (clone $query)->count();
        $stats = ['ok' => 0, 'cancelled' => 0, 'unavailable' => 0];
        foreach ($query->limit(max(1, (int) $this->option('limit')))->get() as $certificate) {
            $stats[$check->check($certificate)]++;
            if ((int) $this->option('pause') > 0) {
                sleep((int) $this->option('pause'));
            }
        }
        $done = array_sum($stats);
        $this->info("Проверено: {$done} (найдено: {$stats['ok']}, отменено в PMS: {$stats['cancelled']}, PMS не ответила: {$stats['unavailable']}). Осталось: ".max(0, $left - $done).'.');

        return self::SUCCESS;
    }
}
