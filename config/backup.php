<?php

use Spatie\Backup\Notifications\Notifiable;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\CleanupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification;
use Spatie\Backup\Tasks\Cleanup\Strategies\DefaultStrategy;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes;

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
        'notifications' => [
            BackupHasFailedNotification::class => ['mail'],
            UnhealthyBackupWasFoundNotification::class => ['mail'],
            CleanupHasFailedNotification::class => ['mail'],
        ],
        'notifiable' => Notifiable::class,
        'mail' => [
            // Пустое значение в .env (BACKUP_MAIL_TO=) не должно становиться адресом: берём отправителя по умолчанию.
            'to' => env('BACKUP_MAIL_TO') ?: env('MAIL_FROM_ADDRESS', 'hello@example.com'),
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
