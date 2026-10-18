# Gifter

Система подарочных сертификатов для отелей. Работает автономно; интеграция с Hotel Hub — только после стабилизации внутренних процессов (с фазы 8).

Стек: PHP 8.3, Laravel 12, Filament 4, MySQL 8 (SQLite для разработки и тестов).

## Запуск для разработки

Быстрее всего — Docker: `docker compose up --build`, затем `docker compose exec gifter php artisan gifter:demo-users` и http://localhost:8000/admin.

Подробная инструкция (Docker или PHP напрямую на Windows / macOS / Linux, тестовые пользователи по ролям, что проверить): [docs/local-run.md](docs/local-run.md).

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed        # демо-каталог: поставщики, категории, услуги, цены, пакет
php artisan gifter:demo-users     # тестовые пользователи для всех ролей (только локально)
php artisan serve                 # панель: http://127.0.0.1:8000/admin
```

Тесты: `php artisan test`. Стиль кода: `vendor/bin/pint`.

Ежедневная задача (cron / планировщик Plesk): `php artisan schedule:run` каждую минуту, либо напрямую `php artisan gifter:expire-certificates` раз в сутки.

## Документы

- [Фаза 0 — аудит старого проекта](docs/phase-0-audit.md)
- [Фаза 1 — ядро: решения, жизненный цикл, правила](docs/phase-1-core.md)
- [Фаза 2 — пользователи, роли, деньги: сверка, возвраты, бесплатные сертификаты](docs/phase-2-rbac.md)
- [Фаза 3 — поставщики, категории, признаки исполнения, цены и себестоимость](docs/phase-3-catalog.md)
