# Локальный запуск для теста

Два способа: **Docker** (ничего, кроме Docker, ставить не нужно) или **PHP напрямую**. База в обоих случаях — SQLite-файл, MySQL для теста не нужен.

## Способ 1 — Docker

Нужен [Docker Desktop](https://www.docker.com/products/docker-desktop/) (Windows / macOS) или Docker Engine с плагином compose (Linux).

```bash
git clone -b claude/charming-gauss-o7jrdg https://github.com/tenirhotels/gifter.git
cd gifter
docker compose up --build
```

Первая сборка — несколько минут (скачиваются PHP и пакеты). При первом запуске контейнер сам создаёт ключ приложения и базу, применяет миграции и загружает демо-каталог. Когда в логе появится `Server running on [http://0.0.0.0:8000]`, в **другом окне терминала** создайте тестовых пользователей (команда спросит общий пароль):

```bash
docker compose exec gifter php artisan gifter:demo-users
```

Открыть **http://localhost:8000/admin**. Логины — в разделе «Тестовые пользователи» ниже.

| Действие | Команда |
|---|---|
| Остановить | `Ctrl+C` или `docker compose down` |
| Запустить снова (данные сохраняются) | `docker compose up` |
| После обновления кода (`git pull`) | `docker compose up --build` — новые миграции применятся сами |
| Начать с чистой базы | `docker compose down -v`, затем `docker compose up` и снова `gifter:demo-users` |
| Другой порт, если 8000 занят | `APP_PORT=8080 docker compose up` (PowerShell: `$env:APP_PORT=8080; docker compose up`) |
| Автотесты в контейнере | `docker compose exec gifter php artisan test` |
| Любая команда artisan | `docker compose exec gifter php artisan …` |

База и `.env` хранятся в томе Docker `gifter-data`, а не в папке проекта.

## Способ 2 — PHP напрямую

Нужны **PHP 8.3+**, **Composer** и **Git**.

### 1. Установить PHP и Composer

**Windows — проще всего [Laragon](https://laragon.org/download/)** (в нём сразу PHP, Composer и Git). После установки: правый клик по значку Laragon → PHP → выбрать версию 8.3 или новее.

Если PHP ставится вручную — в `php.ini` должны быть включены (раскомментированы) расширения:

```
extension=fileinfo
extension=intl
extension=mbstring
extension=openssl
extension=pdo_sqlite
extension=sqlite3
extension=zip
```

**macOS:** `brew install php composer`. **Linux (Ubuntu):** `sudo apt install php8.3-cli php8.3-sqlite3 php8.3-intl php8.3-mbstring php8.3-xml php8.3-zip php8.3-curl composer`.

Проверка: `php -v` (8.3+) и `php -m` (в списке есть `intl`, `pdo_sqlite`, `zip`).

### 2. Скачать проект

```bash
git clone -b claude/charming-gauss-o7jrdg https://github.com/tenirhotels/gifter.git
cd gifter
```

### 3. Установить и подготовить

```bash
composer install
cp .env.example .env          # Windows (cmd): copy .env.example .env
php artisan key:generate
```

Создать пустой файл базы `database/database.sqlite`:

```bash
touch database/database.sqlite                 # macOS / Linux / Git Bash
type nul > database\database.sqlite            # Windows (cmd)
```

Таблицы и демо-каталог (поставщики, категории, 6 услуг с ценами и себестоимостью, пакет «Романтический уикенд»):

```bash
php artisan migrate --seed
```

Тестовые пользователи — по одному на каждую роль, с общим паролем, который команда спросит:

```bash
php artisan gifter:demo-users
```

### 4. Запустить

```bash
php artisan serve
```

Открыть **http://127.0.0.1:8000/admin** и войти любым из тестовых пользователей.

## Тестовые пользователи

Создаются командой `gifter:demo-users` (в Docker — через `docker compose exec gifter …`), пароль общий — тот, что ввели.

| Email | Роль |
|---|---|
| admin@gifter.local | Администратор |
| sales@gifter.local | Продажи |
| manager@gifter.local | Менеджер продаж |
| director@gifter.local | Директор по продажам |
| frontoffice@gifter.local | Front Office / Бронирование |
| finance@gifter.local | Финансы |

Удобно держать два браузера (или обычное окно + инкогнито), чтобы смотреть один сертификат глазами разных ролей.

## Что проверить

1. **Продажа:** `sales` → Сертификаты → «Новый сертификат» → покупатель, получатель, пакет → «Выставить счёт» (вкладка «Оплата») → «Оплачен» → «Выпустить».
2. **Сверка:** `finance` → Деньги → Оплаты → «Сверено».
3. **Юрлицо:** `sales` выставляет счёт «Перечисление на р/с» → отметить оплату может только `finance` (Деньги → Оплаты → «Поступило на р/с»).
4. **Видимость:** второй продавец не видит чужие сертификаты; `manager` видит все.
5. **Бесплатный:** `manager` → новый сертификат, тип «Бесплатный (маркетинг)» → `director` → «Согласовать бесплатный» → `manager` → «Выпустить».
6. **Возврат:** `manager` на оплаченном сертификате → Ещё → «Возврат и аннулирование» → `finance` → Деньги → Возвраты → «Согласовать» → «Деньги возвращены».
7. **Заезд:** `frontoffice` → «Отметить бронь» → «Заезд» → «Погасить».
8. **Каталог:** `director` → Каталог → Услуги → новая цена с будущей датой; поставщики и категории.
9. **Покупка нескольких:** `sales` → «Новый сертификат» → количество 2 → счёт → откроется «Покупка №…» → «Оплачен» → «Выпустить» → «PDF всех». Бесплатные — так же у `manager`, согласует `director`.
10. **История и журнал:** вкладка «История» в сертификате; `admin` → Администрирование → Журнал аудита.

## Полезное

- Начать с чистой базы: `php artisan migrate:fresh --seed`, затем снова `php artisan gifter:demo-users`.
- Автотесты: `php artisan test`.
- Истечение сроков вручную: `php artisan gifter:expire-certificates`.
- Команда `gifter:demo-users` работает только при `APP_ENV=local` и для рабочего сервера не предназначена; там первого пользователя создаёт `php artisan gifter:create-user`.
