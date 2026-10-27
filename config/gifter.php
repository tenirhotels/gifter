<?php

return [

    // Языки интерфейса (код => название). Тексты — lang/{код}.json, ключ — русский текст; не переведённое показывается по-русски.
    // Выпуск сертификата с платной частью — только после подтверждения оплаты Финансами (сверка). Аудит 19.10.2026, К1.
    'require_payment_reconciled' => (bool) env('GIFTER_REQUIRE_PAYMENT_RECONCILED', true),

    // Цены, добавленные директором, вступают в силу после подтверждения администратором. Аудит 19.10.2026, В7.
    'require_price_approval' => (bool) env('GIFTER_REQUIRE_PRICE_APPROVAL', true),

    // Порядок KK · RU · EN — везде. Язык определяет браузер; запасной — default_locale (в тестах ru).
    'locales' => ['kk' => 'Қазақша', 'ru' => 'Русский', 'en' => 'English'],
    'default_locale' => env('GIFTER_DEFAULT_LOCALE', 'kk'),
    'currency' => 'KZT',

    // Срок действия задаётся в пакете (продукте), а не здесь.

    // Печатная форма сертификата (PDF) и страница проверки по QR.
    'certificate' => [
        'hotel_name' => env('GIFTER_HOTEL_NAME', 'TENIR'),
        'hotel_contacts' => env('GIFTER_HOTEL_CONTACTS', ''),
        'terms' => [
            'Сертификат используется целиком: услуги нельзя обменять на деньги или использовать по отдельности.',
            'Неиспользованные услуги не компенсируются.',
            'Проживание бронируется заранее по контактам отеля — назовите номер сертификата.',
            'Сертификат действителен до указанной даты включительно.',
        ],
    ],

    // Онлайн-заявки с публичной страницы: куда отправлять уведомление о новой заявке (без контактов клиента).
    'online' => [
        'mail_to' => env('GIFTER_ORDERS_MAIL_TO'),
    ],

    // Счёт Kaspi Pay на телефон клиента через посредника yespay (не официальный API Kaspi). Ключи — только в .env; выключено по умолчанию.
    'kaspi_yespay' => [
        'enabled' => (bool) env('KASPI_YESPAY_ENABLED', false),
        'base_url' => env('KASPI_YESPAY_BASE_URL', 'https://api.getyespay.com'),
        'api_key' => env('KASPI_YESPAY_API_KEY'),
        'webhook_secret' => env('KASPI_YESPAY_WEBHOOK_SECRET'),
        'expire_minutes' => (int) env('KASPI_YESPAY_EXPIRE_MINUTES', 1440),
    ],

    // Оплата картой по ссылке (эквайринг Halyk Epay). Ключи — только в .env; пусто — функция выключена.
    'epay' => [
        'env' => env('EPAY_ENV', 'test'), // test | prod
        'client_id' => env('EPAY_CLIENT_ID'),
        'client_secret' => env('EPAY_CLIENT_SECRET'),
        'terminal' => env('EPAY_TERMINAL'),
        'shop_id' => env('EPAY_SHOP_ID'), // ID магазина для счетов; пусто — берётся EPAY_TERMINAL
        'expire_period' => env('EPAY_EXPIRE_DAYS', 3),
        'hosts' => [
            'test' => ['oauth' => 'https://test-epay-oauth.epayment.kz', 'api' => 'https://test-epay-api.epayment.kz'],
            'prod' => ['oauth' => 'https://epay-oauth.homebank.kz', 'api' => 'https://epay-api.homebank.kz'],
        ],
    ],

    'number' => [
        // words — непредсказуемый номер из казахского слова, 4 цифр и контрольной цифры (TAU-48273); sequence — порядковый по шаблону ниже.
        'style' => env('GIFTER_NUMBER_STYLE', 'words'),
        // Шаблон номера: {PREFIX}, {YYYY}, {YY}, {SEQ}. Если в шаблоне есть год,
        // нумерация начинается заново каждый год (по дате продажи).
        'pattern' => env('GIFTER_NUMBER_PATTERN', '{PREFIX}-{SEQ}'),
        'prefix' => env('GIFTER_NUMBER_PREFIX', 'GIF'),
        'padding' => (int) env('GIFTER_NUMBER_PADDING', 6),
    ],

    'security' => [
        // Ключ для хешей поиска по телефону и email (контакты в базе зашифрованы).
        // Отдельный от APP_KEY; без него поиск по контактам строится на APP_KEY — проверка безопасности предупредит.
        'contact_hash_key' => env('CONTACT_HASH_KEY'),
        // Двухфакторный вход обязателен всем. Отключать можно только при локальной проверке
        // (APP_ENV=local); на рабочем сервере он обязателен всегда.
        // Не более стольких разных клиентов в день, чьи контакты сотрудник может открыть (0 — без ограничения; администратор не ограничен).
        'reveal_daily_limit' => (int) env('GIFTER_REVEAL_DAILY_LIMIT', 30),
        // Доверенные прокси: пусто — любые (как раньше), `cloudflare` — только Cloudflare и внутренние адреса, или список сетей через запятую.
        'trusted_proxies' => env('TRUSTED_PROXIES'),
        'require_two_factor' => env('GIFTER_REQUIRE_2FA', true),
    ],

    'pms' => [
        // Проверка брони по PMS в день заезда (команда gifter:sync-arrivals, время отеля): мало запросов, только по делу.
        // Утром — расхождения (отмена, даты, гость); в 16:00 — заселились ли (заселение в отеле в 15:30); вечером — только не заселённые.
        'arrival_check_times' => ['morning' => '08:00', 'afternoon' => '16:00', 'evening' => '19:00'],
        // Бронь, проверенную недавно (в том числе кнопкой «Заезд»), повторно не запрашиваем.
        'arrival_recheck_minutes' => 120,
        // Автопогашение после выезда (gifter:sync-departures): выезд в отеле до 12:00.
        'departure_check_time' => '14:00',
        // Сколько секунд ждать, пока другой сотрудник привязывает ту же бронь.
        'booking_lock_wait' => 10,
    ],

    'monitoring' => [
        // Почта для писем о необработанных ошибках сервера (без данных клиентов). Пусто — не отправлять.
        'error_mail_to' => env('ERROR_MAIL_TO'),
    ],

    'backup' => [
        // Куда отправлять зашифрованный архив базы (копия вне хостинга). Пусто — не отправлять.
        'mail_to' => env('BACKUP_MAIL_TO'),
        // Архив больше этого размера по почте не отправляется (ограничение почтовых серверов).
        'mail_max_mb' => (int) env('BACKUP_MAIL_MAX_MB', 15),
    ],
];
