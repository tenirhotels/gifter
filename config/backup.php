<?php

use Spatie\Backup\Notifications\Notifiable;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\CleanupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification;
use Spatie\Backup\Tasks\Cleanup\Strategies\DefaultStrategy;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes;

// Кому писать о сбоях копирования: BACKUP_MAIL_TO → ERROR_MAIL_TO → GIFTER_ORDERS_MAIL_TO; нет адреса — письма не отправляются.
$backupMailTo = env('BACKUP_MAIL_TO') ?: (env('ERROR_MAIL_TO') ?: env('GIFTER_ORDERS_MAIL_TO'));

/*
 * Резервные копии Gifter (spatie/laravel-backup): только база данных — код лежит в git.
 * Архив zip зашифрован паролем BACKUP_ARCHIVE_PASSWORD (AES-256): без пароля дамп не открыть.
 * Копии — в storage/app/private/backups (диск «backups»); копия вне хостинга —
 * gifter:backup-mail (архив на почту BACKUP_MAIL_TO) или скачивание администратором.
 * Расписание — routes/console.php, инструкция — docs/security.md.
 */
return [

    'backup' => [
        'name' => 'gifter',

        'source' => [
            'files' => [
                'include' => [],
                'exclude' => [],
                'follow_links' => false,
                'ignore_unreadable_directories' => false,
                'relative_path' => null,
            ],
            'databases' => [
                env('DB_CONNECTION', 'mysql'),
            ],
        ],

        'database_dump_compressor' => null,
        'database_dump_file_timestamp_format' => null,
        'database_dump_filename_base' => 'database',
        'database_dump_file_extension' => '',

        'destination' => [
            'compression_method' => ZipArchive::CM_DEFAULT,
            'compression_level' => 9,
            'filename_prefix' => 'gifter-',
            'disks' => ['backups'],
            'continue_on_failure' => false,
        ],

        'temporary_directory' => storage_path('app/backup-temp'),
        'password' => env('BACKUP_ARCHIVE_PASSWORD'),
        'encryption' => 'default',
        'verify_backup' => false,
        'tries' => 1,
        'retry_delay' => 0,
    ],

    // Письмо — только о сбоях; получатель — BACKUP_MAIL_TO.
    'notifications' => [
        // Без адреса уведомления не отправляются вообще (на «отправителя по умолчанию» письма не уходят).
        'notifications' => [
            BackupHasFailedNotification::class => $backupMailTo ? ['mail'] : [],
            UnhealthyBackupWasFoundNotification::class => $backupMailTo ? ['mail'] : [],
            CleanupHasFailedNotification::class => $backupMailTo ? ['mail'] : [],
        ],
        'notifiable' => Notifiable::class,
        'mail' => [
            // Пакет требует корректный адрес даже при выключенных уведомлениях: заглушка с зарезервированной зоной .invalid, письма на неё не уходят.
            'to' => $backupMailTo ?: 'backup-alerts-disabled@example.invalid',
            'from' => [
                'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
                'name' => env('MAIL_FROM_NAME', 'Gifter'),
            ],
        ],
        'slack' => ['webhook_url' => '', 'channel' => null, 'username' => null, 'icon' => null],
        'discord' => ['webhook_url' => '', 'username' => '', 'avatar_url' => ''],
        'webhook' => ['url' => ''],
    ],

    'log_channel' => null,

    'monitor_backups' => [
        [
            'name' => 'gifter',
            'disks' => ['backups'],
            'health_checks' => [
                MaximumAgeInDays::class => 2,
                MaximumStorageInMegabytes::class => 2000,
            ],
        ],
    ],

    // Хранение: все копии — 7 дней, ежедневные — 14 дней, ежемесячные — 6 месяцев.
    'cleanup' => [
        'strategy' => DefaultStrategy::class,
        'default_strategy' => [
            'keep_all_backups_for_days' => 7,
            'keep_daily_backups_for_days' => 14,
            'keep_weekly_backups_for_weeks' => 4,
            'keep_monthly_backups_for_months' => 6,
            'keep_yearly_backups_for_years' => 1,
            'delete_oldest_backups_when_using_more_megabytes_than' => 2000,
        ],
        'tries' => 1,
        'retry_delay' => 0,
    ],
];
