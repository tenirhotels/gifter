<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('gifter:expire-certificates')->dailyAt('00:10');

// Резервные копии базы: зашифрованный архив каждую ночь, чистка старых, проверка свежести;
// раз в неделю — копия на почту BACKUP_MAIL_TO (вне хостинга). См. docs/security.md.
Schedule::command('backup:clean')->dailyAt('01:20');
Schedule::command('backup:run --only-db')->dailyAt('01:30');
Schedule::command('backup:monitor')->dailyAt('07:00');
Schedule::command('gifter:backup-mail')->weeklyOn(1, '02:00');

Schedule::command('gifter:security-check')->dailyAt('06:50');
