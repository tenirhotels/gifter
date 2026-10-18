# Gifter

Система подарочных сертификатов для отелей. Работает автономно; интеграция с Hotel Hub — только после стабилизации внутренних процессов (с фазы 8).

Стек: PHP 8.3, Laravel 12, Filament 4, MySQL 8 (SQLite для разработки и тестов).

## Запуск для разработки

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed        # демо-каталог: услуги, цены, пакет
php artisan gifter:create-user    # первый пользователь; пароль вводится интерактивно
php artisan serve                 # панель: http://127.0.0.1:8000/admin
```

Тесты: `php artisan test`. Стиль кода: `vendor/bin/pint`.

Ежедневная задача (cron / планировщик Plesk): `php artisan schedule:run` каждую минуту, либо напрямую `php artisan gifter:expire-certificates` раз в сутки.

## Документы

- [Фаза 0 — аудит старого проекта](docs/phase-0-audit.md)
- [Фаза 1 — ядро: решения, жизненный цикл, правила](docs/phase-1-core.md)
