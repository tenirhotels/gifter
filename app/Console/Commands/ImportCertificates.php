<?php

namespace App\Console\Commands;

use App\Enums\CertificateStatus;
use App\Exceptions\DomainRuleViolation;
use App\Services\LegacyCertificateImport;
use Illuminate\Console\Command;

/**
 * Перенос исторических сертификатов из Excel. Сначала `--dry-run` (ничего не записывает, пишет отчёт), потом боевой запуск.
 * Файл кладётся в storage/app/imports/. Отчёт — CSV рядом с файлом; контактов клиентов в нём нет.
 */
class ImportCertificates extends Command
{
    protected $signature = 'gifter:import-certificates {file : путь к xlsx (например storage/app/imports/Database.xlsx)} {--dry-run : только проверить и написать отчёт}';

    protected $description = 'Перенести исторические сертификаты из таблицы Excel (с отчётом о строках с ошибками)';

    public function handle(LegacyCertificateImport $import): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path) && is_file(base_path($path))) {
            $path = base_path($path);
        }
        if (! is_file($path)) {
            $this->error("Файл не найден: {$path}");

            return self::FAILURE;
        }
        $dry = (bool) $this->option('dry-run');

        try {
            $result = $import->run($path, $dry);
        } catch (DomainRuleViolation $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $report = storage_path('app/imports/report-'.now()->format('Ymd-His').($dry ? '-dry' : '').'.csv');
        if (! is_dir(dirname($report))) {
            mkdir(dirname($report), 0775, true);
        }
        $handle = fopen($report, 'w');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['Строка в файле', 'Прежний №', 'Результат', 'Причина'], ';');
        foreach ($result['rows'] as $row) {
            fputcsv($handle, [$row['row'], $row['number'], $row['result'], $row['reason']], ';');
        }
        $lines = $this->summaryLines($result['summary']);
        fputcsv($handle, [], ';');
        foreach ($lines as $line) {
            fputcsv($handle, [$line], ';');
        }
        fclose($handle);

        $c = $result['counts'];
        $this->info(($dry ? 'СУХОЙ ПРОГОН (ничего не записано). ' : '')."Перенесено: {$c['imported']} (с замечаниями: {$c['warnings']}), пропущено: {$c['skipped']}, уже были: {$c['duplicate']}.");
        $this->line('Отчёт: '.$report);
        $this->line('');
        foreach ($lines as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }

    /**
     * Сводка для сверки с таблицей (без данных клиентов).
     *
     * @param  array<string, mixed>  $summary
     * @return list<string>
     */
    private function summaryLines(array $summary): array
    {
        $lines = ['СВОДКА (перенесённые строки)'];
        $labels = collect(CertificateStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->getLabel()])->all();
        $lines[] = 'По статусам: '.collect($summary['status'])->map(fn ($n, $k) => ($labels[$k] ?? $k).' — '.$n)->implode('; ');
        $lines[] = 'По видам: '.collect($summary['kind'])->map(fn ($n, $k) => "{$k} — {$n}")->implode('; ');
        foreach ($summary['paid_by_year'] as $year => $row) {
            $lines[] = "Платные продажи {$year}: {$row['count']} шт., ".number_format($row['sum'], 0, '', ' ').' ₸';
        }
        foreach ($summary['warnings'] as $text => $n) {
            $lines[] = "Замечание «{$text}»: {$n}";
        }

        return $lines;
    }
}
