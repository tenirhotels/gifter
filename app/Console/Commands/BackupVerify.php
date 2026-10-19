<?php

namespace App\Console\Commands;

use App\Support\Backups;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use ZipArchive;

/**
 * Проверка, что последний архив базы открывается паролем и в нём читаемый дамп (во временной папке, боевая база не затрагивается).
 * Это не замена квартальному восстановлению в тестовую базу (docs/security.md), но ловит битые и «пустые» архивы.
 */
class BackupVerify extends Command
{
    public const KEY = 'gifter.backup-verify';

    protected $signature = 'gifter:backup-verify';

    protected $description = 'Проверить, что последняя резервная копия открывается паролем и содержит дамп базы';

    public function handle(): int
    {
        $result = $this->verify();
        Cache::forever(self::KEY, ['at' => now()->toIso8601String(), 'ok' => $result === null, 'error' => $result]);
        if ($result !== null) {
            $this->error($result);

            return self::FAILURE;
        }
        $this->info('Копия открывается, дамп читается.');

        return self::SUCCESS;
    }

    private function verify(): ?string
    {
        $backup = Backups::latest();
        if (! $backup) {
            return 'Резервных копий нет.';
        }
        $password = (string) config('backup.backup.password');
        if ($password === '') {
            return 'BACKUP_ARCHIVE_PASSWORD не задан.';
        }
        $zip = new ZipArchive;
        if ($zip->open(Backups::absolutePath($backup['path'])) !== true) {
            return 'Архив не открывается (повреждён).';
        }
        $zip->setPassword($password);
        $dir = storage_path('app/private/backup-verify-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($dir);
        try {
            $dump = null;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (str_starts_with($name, 'db-dumps/') && ! str_ends_with($name, '/')) {
                    $dump = $name;
                    break;
                }
            }
            if ($dump === null) {
                return 'В архиве нет файла дампа базы.';
            }
            if (! $zip->extractTo($dir, $dump)) {
                return 'Архив не открылся паролем: проверьте BACKUP_ARCHIVE_PASSWORD.';
            }
            $path = $dir.'/'.$dump;
            if (! is_file($path) || filesize($path) < 100) {
                return 'Дамп пустой или повреждён.';
            }
            $head = (string) file_get_contents($path, false, null, 0, 200_000);
            if (! preg_match('/CREATE TABLE|INSERT INTO|PRAGMA|BEGIN TRANSACTION/i', $head)) {
                return 'Дамп не похож на дамп базы данных.';
            }

            return null;
        } finally {
            $zip->close();
            File::deleteDirectory($dir);
        }
    }
}
