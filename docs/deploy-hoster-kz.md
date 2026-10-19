# Развёртывание на Hoster.kz (Plesk, MySQL, без SSH)

Сайт: `https://gifter.tenirmoments.com`. Хостинг — Plesk; доступ через Cloudflare.

## 1. Сразу: выключить режим отладки

В файле `.env` в папке сайта (Plesk → Файлы):

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://gifter.tenirmoments.com
LOG_LEVEL=warning
```

С `APP_DEBUG=true` любая ошибка показывает всем пути на сервере, настройки и SQL-запросы.

## 2. База MySQL

Plesk → «Базы данных» → «Добавить базу данных»: имя (например, `gifter`), новый пользователь, длинный случайный пароль (кнопка «Сгенерировать»), доступ — только локальный.

В `.env` (значения — свои, это пример):

```
DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=gifter
DB_USERNAME=gifter_user
DB_PASSWORD=пароль-из-plesk
```

Строку `DB_DATABASE=…/database.sqlite`, если есть, удалить. Ошибка «Database file … database.sqlite does not exist» означает, что в `.env` осталась база SQLite.

## 3. Корневая папка сайта — `public`

Plesk → сайт → «Настройки хостинга» → «Корневая папка документов»: `gifter.tenirmoments.com/public`.

Проверка: `https://gifter.tenirmoments.com/.env` должен отвечать 403 или 404, а не показывать файл.

## 4. Команды без SSH — Plesk → Laravel (Laravel Toolkit) → Artisan

По очереди:

1. `key:generate --force` — только при первой установке. Ключ шифрует сессии, а позже и контакты: сохраните строку `APP_KEY` из `.env` в менеджере паролей. Без него зашифрованные данные не прочитать.
2. `migrate --force` — таблицы. После каждого обновления кода — снова.
3. `db:seed --force` — **по желанию**, демо-каталог (услуги, пакет) для проверки. На рабочем сервере каталог лучше завести вручную.
4. `gifter:create-user --name="Администратор" --email=ваш@email --role=super_admin` — первый пользователь. Пароль сгенерируется и покажется **один раз** в выводе — скопируйте и смените после входа. Остальных пользователей удобнее заводить в панели: «Администрирование → Пользователи».
5. `optimize` — кеш настроек и маршрутов (после каждого изменения `.env` — снова).

Если Laravel Toolkit в панели нет — Plesk → «Запланированные задачи» → задача «Выполнить PHP-скрипт» с путём `artisan` и аргументами команды, запуск «Выполнить сейчас».

## 5. Планировщик

Plesk → «Запланированные задачи» → каждую минуту: `php artisan schedule:run` (в Laravel Toolkit — переключатель «Планировщик»). Нужен для автоматического истечения сроков сертификатов.

## 6. HTTPS и Cloudflare

- SSL в Plesk (Let's Encrypt) и в Cloudflare режим **Full (strict)**.
- Приложение доверяет заголовкам прокси (`X-Forwarded-Proto`) и на рабочем сервере строит ссылки только с `https://`.

## 7. Обновление кода

Plesk → Git (ветка `claude/charming-gauss-o7jrdg`) → «Получить» → в Laravel Toolkit: `composer install --no-dev`, `migrate --force`, `optimize`.

## Проверка после установки

- `https://gifter.tenirmoments.com/admin` открывает вход;
- ошибка (например, несуществующая страница) показывает короткое сообщение без путей и SQL;
- `/.env` — 403/404.

Дальнейшая защита (шифрование контактов, двухфакторный вход для всех, бэкапы вне хостинга) — по плану «Защита данных на shared-хостинге».
