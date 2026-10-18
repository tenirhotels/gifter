# Образ для локального теста Gifter: PHP 8.3 + SQLite, встроенный сервер.
# Для рабочего сервера (PS.kz) не используется.
FROM php:8.3-cli-bookworm

# pdo_sqlite и mbstring уже есть в официальном образе; Filament нужны intl и zip.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libicu-dev libzip-dev \
    && docker-php-ext-install intl zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Сначала зависимости — слой кешируется, пока не меняется composer.lock.
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-scripts --no-autoloader --prefer-dist

COPY . .
RUN composer dump-autoload --optimize \
    && php artisan package:discover \
    && php artisan filament:assets \
    && chmod +x docker/entrypoint.sh

ENV APP_ENV=local \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/data/database.sqlite \
    PHP_CLI_SERVER_WORKERS=4

VOLUME /data
EXPOSE 8000

ENTRYPOINT ["docker/entrypoint.sh"]
