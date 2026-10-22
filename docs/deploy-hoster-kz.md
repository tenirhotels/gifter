# Развёртывание на Hoster.kz (Plesk, MySQL, без SSH)

Сайт: `https://gift.tenirhotels.com` (раньше — `gift.tenirhotels.com`). Хостинг — Plesk; доступ через Cloudflare.

## 0. Первая установка на новый домен (Git, deploy key)

1. Plesk → «Сайты и домены» → добавить домен/поддомен, включить SSL (Let's Encrypt); PHP **8.3**, OPcache включён. Корневая папка сайта должна быть пустой (удалить `index.html`, `cgi-bin`).
2. Плитка «Git» → «Добавить репозиторий» → «Удалённый репозиторий»: `git@github.com:tenirhotels/gifter.git`. Plesk покажет **публичный ключ**: GitHub → репозиторий → Settings → Deploy keys → Add deploy key (без «Allow write access»). Ветка `claude/charming-gauss-o7jrdg`, режим «Вручную», папка на сайте — корень сайта. Кнопка «Развернуть сейчас».
3. **Корневая папка сайта должна быть `public`** — иначе сайт отвечает 403. Три способа, в порядке удобства:
   - Plesk → Laravel (Laravel Toolkit): «Добавить/найти приложение» — он сам указывает `public`;
   - Plesk → «Настройки хостинга» → «Корневая папка документов» = `public` (папка внутри проекта);
   - если поля нет, в корне проекта лежит запасной `.htaccess`: он отправляет все запросы в `public/` и запрещает `.env`, `.git` и листинг папок (нужен Apache; проверка — `/.env` должен давать 403/404, а `/admin` открываться).
4. Дальше — по порядку разделы 1–6 (`.env`, база, команды Artisan, планировщик, Cloudflare). Установка зависимостей — Laravel Toolkit или «PHP Composer»: `composer install --no-dev --optimize-autoloader`.

## 1. Файл `.env`

Файл лежит в **корне проекта** (рядом с `artisan`, не в `public/`). Plesk → Файлы. Значения ниже — пример, свои ключи и пароли не публиковать:

```
APP_NAME=Gifter
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://gift.tenirhotels.com
APP_LOCALE=ru
APP_TIMEZONE=Asia/Almaty
LOG_LEVEL=warning

SESSION_DRIVER=database
SESSION_LIFETIME=60
SESSION_EXPIRE_ON_CLOSE=true
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true

CONTACT_HASH_KEY=длинная-случайная-строка-1
BACKUP_ARCHIVE_PASSWORD=длинная-случайная-строка-2
BACKUP_MAIL_TO=owner@example.com
```

- Строка `APP_KEY=` должна быть (пусть пустая): `key:generate` только заполняет её. Ошибка «No APP_KEY variable was found in the .env file» — этой строки нет.
- `CONTACT_HASH_KEY` и `BACKUP_ARCHIVE_PASSWORD` — свои случайные строки от 32 символов (например, из менеджера паролей). Все ключи — в менеджер паролей владельца, подробно — `docs/security.md`.
- С `APP_DEBUG=true` любая ошибка показывает всем пути на сервере, настройки и SQL-запросы.
- Почта (`MAIL_*`) — для писем о сбое бэкапа и недельной копии: почтовый ящик в Plesk, `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT=465`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`.

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

Plesk → сайт → «Настройки хостинга» → «Корневая папка документов»: `gift.tenirhotels.com/public`.

Проверка: `https://gift.tenirhotels.com/.env` должен отвечать 403 или 404, а не показывать файл.

## 4. Команды без SSH — Plesk → Laravel (Laravel Toolkit) → Artisan

По очереди:

1. `config:clear`, затем `key:generate --force` — только при первой установке. Ключ шифрует сессии, контакты и секреты 2FA: сохраните строку `APP_KEY` из `.env` в менеджере паролей. Без него зашифрованные данные не прочитать; после начала работы ключ не менять.
2. `migrate --force` — таблицы. После каждого обновления кода — снова.
3. `db:seed --force` — **по желанию**, демо-каталог (услуги, пакет) для проверки. На рабочем сервере каталог лучше завести вручную.
4. `gifter:create-user --name="Администратор" --email=ваш@email --role=super_admin` — первый пользователь. Пароль сгенерируется и покажется **один раз** в выводе — скопируйте и смените после входа (Профиль). При первом входе — настройка двухфакторного входа (QR в приложении Google Authenticator, коды восстановления сохранить). Остальных пользователей удобнее заводить в панели: «Администрирование → Пользователи»; лучше два администратора — сбросить 2FA может только другой администратор.
5. `optimize` — кеш настроек и маршрутов (после каждого изменения `.env` — снова).
6. `backup:run --only-db` — первый бэкап (проверка, что `mysqldump` доступен).
7. `gifter:security-check` — проверка настроек; ошибок быть не должно. Итог виден и в панели: Администрирование → Безопасность.

Если Laravel Toolkit в панели нет — Plesk → «Запланированные задачи» → задача «Выполнить PHP-скрипт» с путём `artisan` и аргументами команды, запуск «Выполнить сейчас».

## 5. Планировщик

Plesk → «Запланированные задачи» → каждую минуту: `php artisan schedule:run` (в Laravel Toolkit — переключатель «Планировщик»). Нужен для автоматического истечения сроков сертификатов, ночных бэкапов (01:30), недельной копии на почту и ежедневной проверки безопасности.

## 6. HTTPS и Cloudflare

- SSL в Plesk (Let's Encrypt) и в Cloudflare режим **Full (strict)**.
- Приложение доверяет заголовкам прокси (`X-Forwarded-Proto`) и на рабочем сервере строит ссылки только с `https://`.

## 7. Обновление кода

Plesk → Git (ветка `claude/charming-gauss-o7jrdg`) → «Получить» → в Laravel Toolkit: `composer install --no-dev`, `migrate --force`, `optimize`.

Перед обновлением, которое меняет базу, — `backup:run --only-db`.

## 8. Доступ к хостингу

- Двухфакторный вход в личный кабинет Hoster.kz и в Plesk; доступ к панели — 1–2 человека.
- FTP выключить (файлы — через файловый менеджер Plesk).
- phpMyAdmin — только при необходимости; контакты в базе всё равно зашифрованы.
- PHP 8.4 (или 8.3; минимум 8.2), `display_errors=Off`, OPcache включён, расширения: `bcmath`, `dom`, `fileinfo`, `gd`, `intl`, `mbstring`, `mysqlnd` + `nd_pdo_mysql`, `xml`, `xmlreader`, `xmlwriter`, `zip`; в `disable_functions` не должно быть `proc_open`, `symlink`, `putenv` (Plesk → PHP → настройки).

## Проверка после установки

- `https://gift.tenirhotels.com/admin` открывает вход;
- ошибка (например, несуществующая страница) показывает короткое сообщение без путей и SQL;
- `/.env` — 403/404;
- вход требует код из приложения;
- Администрирование → Безопасность — без ошибок, есть свежая резервная копия.

Что и как защищено — `docs/security.md`.
