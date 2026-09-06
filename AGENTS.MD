# AGENTS.MD

## Project Overview

**r-web** — веб-приложение расписания занятий для **Академии Наяновой** (`r.nayanovaacademy.ru`). Отображает расписание для учеников и учителей, с админ-панелью для импорта данных из XLSX-файлов с Яндекс Диска.

**Стек:** PHP 8.1 (vanilla) + SQLite + vanilla HTML/CSS/JS. Никаких фреймворков, зависимостей, сборщиков.

**Репозиторий:** `https://github.com/BesuglovS/r-web.git` (ветка `main`)

## Architecture

### Структура файлов

```
├── index.html          # Главная — расписание учеников
├── teachers.html       # Расписание учителей
├── admin.php           # Админ-панель (вход + импорт + управление источниками)
├── api.php             # JSON API (вся бэкенд-логика)
├── app.js              # Фронтенд-логика (fetch, рендеринг, тема)
├── style.css           # Вся стилистика (тёмная/светлая тема, адаптив)
├── cron_import.php     # CLI: проверка источников каждые 5 минут (cron)
├── cron_notify.php     # Email-уведомления об ошибках (с дедупликацией раз в 6 ч)
├── src/
│   ├── auth.php        # Авторизация администратора (сессия, CSRF, throttle по IP)
│   ├── db.php          # Подключение SQLite, схема, миграции, очистка логов
│   ├── import.php      # Логика импорта с Яндекс Диска (md5-опрос, diff, история)
│   └── parser.php      # Минимальный XLSX-парсер (ZipArchive + DOMDocument)
├── deploy.ps1          # Скрипт деплоя (PowerShell, SSH)
├── r.nayanovaacademy.ru  # Конфиг Nginx
├── favicon.svg         # SVG favicon
├── .env                # Конфиг деплоя + пароль админа (bcrypt-хеш)
├── .gitignore
└── link.txt            # Ссылка на XLSX на Яндекс Диске (мигрирует в БД)
```

### Database (SQLite)

Путь БД (приоритет): `SCHEDULE_DB_PATH` (env/.env) → `<корень сайта>/db/schedule.db` (прод, каталог создаёт deploy.ps1) → `<проект>/db/schedule.db` (локальная разработка).

Таблицы:
- **`schedule`** — текущее расписание
- **`schedule_history`** — история изменений между импортами (version, change_type: added/removed/room_changed, old_room)
- **`import_log`** — журнал импортов (url, filename, lessons_count, status, error_message)
- **`schedule_sources`** — источники XLSX с Яндекс Диска (md5/modified/size, is_active, last_error)
- **`check_log`** — журнал лёгких проверок метаданных (cron каждые 5 минут)

Очистка: `prune_old_data($pdo, 10)` удаляет записи всех логов/истории старше **10 дней** (вызывается из `do_import()` и `do_check_all()`).

Индексы: `idx_schedule_class`, `idx_schedule_teacher`, `idx_schedule_date`, `idx_schedule_date_class`, `idx_schedule_date_time`, `idx_history_version`, `idx_history_date`, `idx_history_changed_at`, `idx_import_log_imported_at`, `idx_check_log_checked_at`

### API Endpoints (`api.php`)

| Action | Метод | Авторизация | Описание |
|---|---|---|---|
| `classes` | GET | Нет | Список классов |
| `teachers` | GET | Нет | Список учителей |
| `weeks` | GET | Нет | Список недель |
| `dates` | GET | Нет | Даты для недели |
| `schedule` | GET | Нет | Расписание для класса/учителя по дате (date — YYYY-MM-DD) |
| `history` | GET | Нет | История изменений (последние 20 версий) |
| `last_import` | GET | Нет | Дата и статус последнего импорта |
| `import_log` | GET | Да | Журнал импортов |
| `check_log` | GET | Да | Журнал проверок источников |
| `sources` | GET | Да | Список источников |
| `import` | POST | Да + CSRF | Импорт одного источника |
| `import_all` | POST | Да + CSRF | Импорт всех активных источников |
| `source_add` / `source_delete` / `source_toggle` | POST | Да + CSRF | Управление источниками |

Схема миграций: `PRAGMA user_version` (3 = актуальная). Транзакции импорта — `BEGIN IMMEDIATE` (защита от гонки версий истории при параллельных импортах).

## Development Commands

Проект **не имеет** package.json, composer.json, тестов, линтеров, typecheck. Это zero-dependency vanilla проект.

### Локальный запуск

```bash
php -S localhost:8000
```

### Деплой

```powershell
.\deploy.ps1
```

Скрипт читает `.env`, архивирует проект, отправляет по SSH на сервер `79.143.31.184`, деплоит Nginx-конфиг, проверяет доступность сайта.

## Code Conventions

- **Без фреймворков и зависимостей** — весь код hand-written
- **Язык интерфейса:** русский
- **Бэкенд:** чистый PHP без структуры классов (процедурный стиль)
- **Время:** в БД всё хранится в UTC (`gmdate`), для отображения — `utc_to_samara()`
- **Пароль админа:** `.env` содержит bcrypt-хеш; plaintext тоже поддерживается (`verify_admin_password`)
- **Фронтенд:** vanilla ES6+ JS, `fetch()` API, `localStorage` для настроек
- **CSS:** кастомные свойства (переменные) для тем, mobile-first адаптив
  - Мобильная версия: карточки
  - Десктоп (900px+): таблицы
- **БД:** SQLite через PDO, prepared statements
- **Безопасность:**
  - SQL-инъекции: защищены через prepared statements
  - XSS: `htmlspecialchars()` в PHP, `esc()` (textContent) в JS; редактирование источников в admin.php — через DOM API
  - Авторизация: `password_verify()` (bcrypt/argon2) или `hash_equals()` для plaintext; throttle неудачных логинов по IP (10 попыток / 15 мин) + по сессии; сессия админа истекает через 12 часов
  - CSRF: токен обязателен для всех POST (логин, admin.php, API)
  - Сессионные cookie: `HttpOnly`, `Secure`, `SameSite=Lax` (src/auth.php, используются и в api.php)
  - Ошибки API: детали — в error_log, клиенту — обобщённое сообщение
  - SSRF: URL только на Яндекса (allowlist хостов), ссылка на скачивание — только HTTPS yandex
  - Nginx блокирует доступ к `/src/`, `/cron_*` и dotfiles; security-заголовки (HSTS, XFO, nosniff, Referrer-Policy, CSP) дублируются в location статики (особенность наследования add_header); включён gzip

## Security Notes

- `.env` содержит bcrypt-хеш пароля админа (пароль `nayanova2026` — смените на свой и перегенерируйте хеш), SSH-хост/юзер — **не удалять из репозитория** (это сделано намеренно)
- Nginx блокирует доступ к `/src/`, `/cron_*` и файлам с префиксом `.`

## Common Tasks

### Добавить новое поле в расписание
1. Добавить колонку в таблицу `schedule` в `src/db.php`
2. Обновить `INSERT` / `SELECT` в `api.php`
3. Обновить логику парсинга в `src/parser.php` если поле извлекается из XLSX
4. Обновить фронтенд в `app.js` и `style.css`

### Изменить формат парсинга XLSX
Редактировать `src/parser.php`. Парсер использует:
- `ZipArchive` для распаковки XLSX
- `DOMDocument` + `XPath` для парсинга XML
- Формат: листы именованы как "ПН 01.09", bands по 4 колонки на класс

### Добавить API endpoint
Добавить новый `case` в `switch($_GET['action'])` в `api.php`. Использовать prepared statements для запросов к БД.

### Изменить тему оформления
Редактировать CSS-переменные в `:root` и `[data-theme="dark"]` в `style.css`.

## File Reference

| Файл | Назначение |
|---|---|
| `api.php` | Единая точка входа для всех API-запросов |
| `src/db.php` | Подключение к SQLite, миграции схемы |
| `src/auth.php` | Авторизация ( session + password_hash ) |
| `src/import.php` | Скачивание XLSX с Яндекс Диска, diff, сохранение |
| `src/parser.php` | XLSX парсер без внешних зависимостей |
| `app.js` | Вся клиентская логика (fetch, render, theme, localStorage) |
| `style.css` | Вся стилистика (темы, адаптив, компоненты) |
| `deploy.ps1` | PowerShell деплой-скрипт (SSH + tar) |
| `.env` | Конфигурация деплоя и пароль админа |
