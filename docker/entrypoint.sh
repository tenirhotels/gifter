#!/bin/sh
# Первый запуск: .env с ключом и база в томе /data, миграции и демо-каталог.
# Следующие запуски: только новые миграции, данные сохраняются.
set -e
cd /app

if [ ! -f /data/.env ]; then
    cp .env.example /data/.env
fi
ln -sf /data/.env .env

# `artisan serve` не передаёт переменные окружения веб-серверу — пишем их в .env.
set_env() {
    if grep -qE "^#? *$1=" /data/.env; then
        sed -i -E "s|^#? *$1=.*|$1=$2|" /data/.env
    else
        echo "$1=$2" >> /data/.env
    fi
}
set_env APP_ENV "${APP_ENV:-local}"
set_env APP_URL "${APP_URL:-http://localhost:8000}"
set_env DB_CONNECTION sqlite
set_env DB_DATABASE "$DB_DATABASE"

if ! grep -q '^APP_KEY=base64:' /data/.env; then
    php artisan key:generate --force --no-interaction
fi

FIRST_RUN=0
if [ ! -f "$DB_DATABASE" ]; then
    touch "$DB_DATABASE"
    FIRST_RUN=1
fi

php artisan config:clear --no-interaction >/dev/null
php artisan migrate --force --no-interaction
if [ "$FIRST_RUN" = "1" ]; then
    php artisan db:seed --force --no-interaction
    echo ""
    echo "Gifter: база создана. Тестовые пользователи:"
    echo "  docker compose exec gifter php artisan gifter:demo-users"
    echo ""
fi

echo "Gifter: ${APP_URL:-http://localhost:8000}/admin"
exec php artisan serve --host=0.0.0.0 --port=8000 --no-reload
