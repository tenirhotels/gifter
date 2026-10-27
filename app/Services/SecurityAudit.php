<?php

namespace App\Services;

use App\Console\Commands\BackupVerify;
use App\Console\Commands\Heartbeat;
use App\Models\User;
use App\Services\Epay\EpayClient;
use App\Services\KaspiYespay\KaspiYespayClient;
use App\Support\Backups;
use App\Support\TrustedProxies;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Проверка настроек безопасности рабочего сервера (shared-хостинг): режим, ключи, HTTPS,
 * cookies, база, доступность .env из интернета, права на storage, свежесть бэкапа.
 * Результат последней проверки хранится в кеше и показывается администратору на главной.
 */
class SecurityAudit
{
    public const CACHE_KEY = 'gifter.security-audit';

    public const OK = 'ok';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    /**
     * @param  bool  $checkHttp  запросить APP_URL/.env (медленно); без него — прошлый результат этой проверки
     * @return list<array{check: string, status: string, hint: string}>
     */
    public function run(bool $checkHttp = true): array
    {
        $production = app()->environment('production');
        $results = [];
        $add = function (string $check, bool $ok, string $hint, bool $critical = true) use (&$results) {
            $results[] = ['check' => $check, 'status' => $ok ? self::OK : ($critical ? self::FAIL : self::WARN), 'hint' => $ok ? '' : $hint];
        };

        $add('Режим production', $production, 'APP_ENV=production в .env.');
        $add('Отладка выключена', ! config('app.debug'), 'APP_DEBUG=false: иначе при ошибке видны пароли и ключи.');
        $add('Ключ приложения задан', filled(config('app.key')), 'php artisan key:generate --force (в .env должна быть строка APP_KEY=).');
        $hashKey = (string) config('gifter.security.contact_hash_key');
        $add('Отдельный ключ поиска контактов', filled($hashKey) && $hashKey !== config('app.key'),
            'CONTACT_HASH_KEY — длинная случайная строка, не равная APP_KEY.', false);
        $add('Адрес сайта на HTTPS', str_starts_with((string) config('app.url'), 'https://'), 'APP_URL=https://…');
        $add('Cookies только по HTTPS', (bool) config('session.secure'), 'SESSION_SECURE_COOKIE=true.');
        $add('Сессии зашифрованы', (bool) config('session.encrypt'), 'SESSION_ENCRYPT=true.', false);
        $add('Подробные логи выключены', config('logging.channels.single.level', 'debug') !== 'debug', 'LOG_LEVEL=warning.', false);
        $add('База — MySQL, не файл SQLite', config('database.default') !== 'sqlite', 'DB_CONNECTION=mysql: файл базы в папке сайта легко утечь.');
        $epay = filled(config('gifter.epay.client_id')) || filled(config('gifter.epay.client_secret')) || filled(config('gifter.epay.terminal'));
        $add('Оплата картой: ключи заданы полностью', ! $epay || EpayClient::configured(), 'EPAY_CLIENT_ID, EPAY_CLIENT_SECRET, EPAY_TERMINAL и EPAY_ENV (test или prod) — все вместе или ничего.', false);
        $kaspi = (bool) config('gifter.kaspi_yespay.enabled');
        $add('Счета Kaspi: ключи заданы', ! $kaspi || KaspiYespayClient::configured(), 'KASPI_YESPAY_API_KEY и KASPI_YESPAY_WEBHOOK_SECRET — оба, при KASPI_YESPAY_ENABLED=true.', false);
        $add('Счета Kaspi: боевой ключ', ! $kaspi || ! $production || ! KaspiYespayClient::sandbox(), 'На рабочем сервере ключ sk_test_… (песочница): настоящих платежей не будет.', false);
        $add('Оплата картой: боевой терминал', ! $epay || ! $production || config('gifter.epay.env') === 'prod', 'EPAY_ENV=prod: на рабочем сервере тестовый терминал платежи не принимает.', false);
        $add('Двухфакторный вход обязателен', (bool) config('gifter.security.require_two_factor'), 'Уберите GIFTER_REQUIRE_2FA=false из .env.');
        $withoutTwoFactor = User::query()->where('is_active', true)->whereNull('app_authentication_secret')->count();
        $add('Все активные пользователи настроили 2FA', $withoutTwoFactor === 0,
            "Без 2FA: {$withoutTwoFactor} — настроят при следующем входе.", false);
        $add('Часовой пояс отеля (Asia/Almaty)', config('app.timezone') === 'Asia/Almaty', 'APP_TIMEZONE=Asia/Almaty: иначе сроки сертификатов и расписание проверок сдвигаются.');
        $add('Выплата и выпуск: оплата подтверждается Финансами', (bool) config('gifter.require_payment_reconciled'), 'Уберите GIFTER_REQUIRE_PAYMENT_RECONCILED=false из .env: продавец сам отмечает оплату.', false);
        $add('Цены директора подтверждает администратор', (bool) config('gifter.require_price_approval'), 'Уберите GIFTER_REQUIRE_PRICE_APPROVAL=false из .env.', false);
        $add('Доверенные прокси ограничены (Cloudflare)', TrustedProxies::isRestricted(config('gifter.security.trusted_proxies')),
            'TRUSTED_PROXIES=cloudflare: иначе заголовок X-Forwarded-For можно подделать (обход ограничения попыток входа).', false);
        $add('Письма об ошибках сервера', filled(config('gifter.monitoring.error_mail_to')), 'ERROR_MAIL_TO — почта, куда придёт письмо о необработанной ошибке.', false);
        if ($production) {
            $beat = (int) Cache::get(Heartbeat::KEY, 0);
            $add('Планировщик работает (cron каждую минуту)', $beat > now()->subMinutes(10)->timestamp, 'Нет метки планировщика за 10 минут: проверьте задачу «php artisan schedule:run» в Plesk.');
        }
        $verified = Cache::get(BackupVerify::KEY);
        $add('Копия базы проверена (открывается, дамп читается)', ($verified['ok'] ?? false) && now()->parse($verified['at'])->gt(now()->subDays(10)),
            $verified && ! $verified['ok'] ? 'Последняя проверка: '.$verified['error'] : __('Проверка раз в неделю (gifter:backup-verify) ещё не выполнялась.'), false);
        $add('Папка storage доступна для записи', is_writable(storage_path('framework')) && is_writable(storage_path('logs')),
            'Права на storage/ и bootstrap/cache — запись для пользователя сайта.');

        $add('Пароль архива бэкапа задан', filled(config('backup.backup.password')), 'BACKUP_ARCHIVE_PASSWORD — длинная случайная строка.');
        $backup = Backups::latest();
        $add('Свежий бэкап (не старше 2 суток)', $backup !== null && $backup['created_at']->gt(now()->subDays(2)),
            $backup ? 'Последний: '.$backup['created_at']->format('d.m.Y H:i').'. Проверьте cron (schedule:run).' : __('Бэкапов нет: php artisan backup:run --only-db.'));
        $add('Копия вне хостинга', filled(config('gifter.backup.mail_to')),
            'BACKUP_MAIL_TO — почта, куда раз в неделю уходит архив; или скачивайте копию вручную.', false);

        $envCheck = '.env не отдаётся из интернета';
        if ($checkHttp && $production) {
            $add($envCheck, $this->envIsHidden(), 'Корень сайта (document root) должен быть папкой public/.');
        } elseif ($previous = collect($this->lastResult()['results'] ?? [])->firstWhere('check', $envCheck)) {
            $results[] = $previous; // быстрая проверка без запроса к сайту — берём прошлый результат
        }

        Cache::forever(self::CACHE_KEY, ['checked_at' => now()->toIso8601String(), 'results' => $results]);

        return $results;
    }

    /** @return array{checked_at: string, results: list<array{check: string, status: string, hint: string}>}|null */
    public function lastResult(): ?array
    {
        return Cache::get(self::CACHE_KEY);
    }

    private function envIsHidden(): bool
    {
        try {
            $response = Http::timeout(5)->withoutRedirecting()->get(rtrim((string) config('app.url'), '/').'/.env');

            return ! $response->successful() || ! str_contains($response->body(), 'APP_KEY');
        } catch (\Throwable) {
            return true; // нет исходящих запросов — проверить нельзя, не считаем ошибкой
        }
    }
}
