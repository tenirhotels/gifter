<?php

namespace App\Console\Commands;

use App\Mail\BackupArchiveMail;
use App\Support\Backups;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Копия вне хостинга: последний архив базы — на почту BACKUP_MAIL_TO.
 * Архив зашифрован паролем BACKUP_ARCHIVE_PASSWORD; пароль в письмо не попадает.
 */
class MailBackup extends Command
{
    protected $signature = 'gifter:backup-mail';

    protected $description = 'Отправить последнюю резервную копию базы (зашифрованный архив) на почту BACKUP_MAIL_TO';

    public function handle(): int
    {
        $to = config('gifter.backup.mail_to');
        if (blank($to)) {
            $this->warn('BACKUP_MAIL_TO не задан — отправлять некуда.');

            return self::SUCCESS;
        }
        if (blank(config('backup.backup.password'))) {
            $this->error('BACKUP_ARCHIVE_PASSWORD не задан: незашифрованный архив по почте не отправляется.');

            return self::FAILURE;
        }

        $backup = Backups::latest();
        if (! $backup) {
            $this->error('Резервных копий нет. Сначала: php artisan backup:run --only-db');

            return self::FAILURE;
        }
        if ($backup['size'] > config('gifter.backup.mail_max_mb') * 1024 * 1024) {
            $this->error('Архив слишком большой для почты — скачайте его в панели (Главная → Безопасность).');

            return self::FAILURE;
        }

        $date = $backup['created_at']->format('d.m.Y H:i');
        Mail::to($to)->send(new BackupArchiveMail(Backups::absolutePath($backup['path']), $date));

        $this->info("Копия от {$date} отправлена на {$to}.");

        return self::SUCCESS;
    }
}
