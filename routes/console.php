<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('gifter:expire-certificates')->dailyAt('00:10');

// Напоминания: заявки с сайта без ответа дольше 2 ч — сотрудникам; за 14 дней до конца срока — гостю.
Schedule::command('gifter:remind-online-orders')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('gifter:remind-expiring')->dailyAt('10:00')->withoutOverlapping();

// Оплата картой по ссылке: если уведомление банка потерялось — проверяем платежи сами; истёкшие ссылки отменяем.
Schedule::command('gifter:epay-reconcile')->everyTenMinutes()->withoutOverlapping();
Schedule::command('gifter:kaspi-yespay-reconcile')->everyTenMinutes()->withoutOverlapping();

// Резервные копии базы: зашифрованный архив каждую ночь, чистка старых, проверка свежести;
// раз в неделю — копия на почту BACKUP_MAIL_TO (вне хостинга). См. docs/security.md.
Schedule::command('backup:clean')->dailyAt('01:20');
Schedule::command('backup:run --only-db')->dailyAt('01:30');
Schedule::command('backup:monitor')->dailyAt('07:00');
Schedule::command('gifter:backup-mail')->weeklyOn(1, '02:00');
Schedule::command('gifter:backup-mail')->monthlyOn(1, '02:30');
Schedule::command('gifter:backup-verify')->weeklyOn(0, '03:30');

Schedule::command('gifter:heartbeat')->everyMinute();

// Очередь писем: на shared-хостинге нет постоянного воркера — разбираем очередь каждую минуту из cron schedule:run.
Schedule::command('queue:work --stop-when-empty --tries=3 --max-time=50')->everyMinute()->withoutOverlapping();
Schedule::command('gifter:security-check')->dailyAt('06:50');

// Брони по PMS в день заезда (мало запросов): утром — расхождения, в 16:00 (заселение в 15:30) и вечером — заселились ли.
foreach (config('gifter.pms.arrival_check_times') as $slot => $time) {
    Schedule::command("gifter:sync-arrivals --slot={$slot}")->dailyAt($time)->withoutOverlapping();
}

// Автопогашение после выезда (Hub — checked_out, Exely — на следующий день после выезда): раз в день после выезда гостей.
Schedule::command('gifter:sync-departures')->dailyAt(config('gifter.pms.departure_check_time'))->withoutOverlapping();

// Список услуг из PMS (Exely) — раз в ночь; цены вводятся в Gifter.
Schedule::command('gifter:sync-pms-catalog')->dailyAt('03:00')->withoutOverlapping();
