<?php

namespace App\Console\Commands;

use App\Services\SecurityAudit;
use Illuminate\Console\Command;

/** Проверка настроек безопасности сервера; запуск из Plesk (Laravel → Artisan) и ежедневно по расписанию. */
class SecurityCheck extends Command
{
    protected $signature = 'gifter:security-check';

    protected $description = 'Проверить настройки безопасности: режим, ключи, HTTPS, cookies, база, .env, бэкапы';

    public function handle(SecurityAudit $audit): int
    {
        $results = $audit->run();
        $marks = [SecurityAudit::OK => 'OK', SecurityAudit::WARN => 'Внимание', SecurityAudit::FAIL => 'ОШИБКА'];
        $this->table(['Проверка', 'Итог', 'Что сделать'], array_map(fn (array $row) => [$row['check'], $marks[$row['status']], $row['hint']], $results));

        $failed = collect($results)->where('status', SecurityAudit::FAIL)->count();
        $failed ? $this->error("Ошибок: {$failed}. Исправьте их до работы с реальными данными.") : $this->info('Критичных ошибок нет.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
