<?php
/**
 * Подключение к SQLite и вспомогательные функции.
 * БД: /var/www/r.nayanovaacademy.ru/db/schedule.db (уровнем выше public/)
 */

/**
 * Путь к файлу БД.
 * Приоритет:
 *  1) переменная окружения SCHEDULE_DB_PATH (или из .env рядом с проектом);
 *  2) <корень сайта>/db/schedule.db (прод: /var/www/r.nayanovaacademy.ru/db/);
 *  3) если каталог выше недоступен — <проект>/db/schedule.db (локальная разработка).
 */
function get_db_path(): string
{
    // 1. Явная настройка окружения
    $env = getenv('SCHEDULE_DB_PATH');
    if ($env === false || $env === '') {
        $env = load_env_value('SCHEDULE_DB_PATH') ?? '';
    }
    if ($env !== '') {
        return $env;
    }

    // 2. Каталог db рядом с docroot (на уровень выше public/) — только если он
    // уже существует (на проде его создаёт deploy.ps1). Иначе локальная
    // разработка не должна мусорить каталогом выше проекта.
    $path = dirname(__DIR__, 2) . '/db/schedule.db';
    if (is_dir(dirname($path))) {
        return $path;
    }

    // 3. Fallback: db внутри самого проекта (локальная разработка)
    $fallback = dirname(__DIR__) . '/db';
    @mkdir($fallback, 0775, true);
    return $fallback . '/schedule.db';
}

/**
 * Читает одно значение из .env (без зависимостей).
 */
function load_env_value(string $key): ?string
{
    foreach ([dirname(__DIR__), dirname(__DIR__, 2)] as $env_path) {
        $env_file = $env_path . '/.env';
        if (!file_exists($env_file)) continue;
        $lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            if (preg_match('/^' . preg_quote($key, '/') . '\s*=\s*(.+)$/', $line, $m)) {
                return trim($m[1]);
            }
        }
    }
    return null;
}

function get_db(): ?PDO
{
    $path = get_db_path();
    $dir = dirname($path);

    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (!is_dir($dir)) {
        $fallback = dirname(__DIR__) . '/db';
        @mkdir($fallback, 0775, true);
        if (is_dir($fallback)) {
            $path = $fallback . '/schedule.db';
            $dir = $fallback;
        }
    }

    if (!is_dir($dir)) {
        return null;
    }

    try {
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA foreign_keys=ON');
        // Ждём освобождения блокировки при параллельных импортах (cron + ручной)
        $pdo->exec('PRAGMA busy_timeout=10000');
        return $pdo;
    } catch (PDOException $e) {
        return null;
    }
}

function init_db(PDO $pdo): void
{
    // Инициализация схемы — дорогая; выполняем только один раз на БД.
    // user_version: 1 = миграция UTC выполнена, 2 = промежуточная (историческая),
    // 3 = актуальная схема (+check_log, md5-колонки в schedule_sources).
    $ver = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    if ($ver >= 3) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS schedule (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sheet_name TEXT NOT NULL,
            date TEXT NOT NULL,
            day_of_week TEXT NOT NULL,
            class_name TEXT NOT NULL,
            lesson_num INTEGER NOT NULL,
            time_start TEXT NOT NULL,
            time_end TEXT NOT NULL,
            subject TEXT NOT NULL,
            teacher TEXT NOT NULL,
            room TEXT DEFAULT '',
            parallel_group TEXT,
            imported_at TEXT NOT NULL
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS schedule_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            version INTEGER NOT NULL,
            change_type TEXT NOT NULL,
            date TEXT NOT NULL,
            day_of_week TEXT NOT NULL,
            class_name TEXT NOT NULL,
            lesson_num INTEGER NOT NULL,
            time_start TEXT NOT NULL,
            time_end TEXT NOT NULL,
            subject TEXT NOT NULL,
            teacher TEXT NOT NULL,
            room TEXT DEFAULT '',
            old_room TEXT DEFAULT '',
            parallel_group TEXT,
            changed_at TEXT NOT NULL
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS import_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            url TEXT NOT NULL,
            filename TEXT DEFAULT '',
            imported_at TEXT NOT NULL,
            lessons_count INTEGER DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'ok',
            error_message TEXT DEFAULT ''
        )
    ");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_schedule_class ON schedule(class_name)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_schedule_teacher ON schedule(teacher)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_schedule_date ON schedule(date)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_schedule_date_class ON schedule(date, class_name)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_schedule_date_time ON schedule(date, time_start)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_history_version ON schedule_history(version)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_history_date ON schedule_history(date)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_history_changed_at ON schedule_history(changed_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_import_log_imported_at ON import_log(imported_at)");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS schedule_sources (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            url TEXT NOT NULL UNIQUE,
            label TEXT NOT NULL DEFAULT '',
            is_active INTEGER NOT NULL DEFAULT 1,
            last_imported_at TEXT DEFAULT NULL,
            last_import_status TEXT DEFAULT NULL,
            last_error TEXT DEFAULT '',
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )
    ");

    // Колонки для отслеживания изменений файла на Яндекс.Диске (лёгкий опрос метаданных)
    migrate_source_columns($pdo);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS check_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            source_id INTEGER DEFAULT NULL,
            url TEXT NOT NULL,
            checked_at TEXT NOT NULL,
            result TEXT NOT NULL,
            old_md5 TEXT DEFAULT '',
            new_md5 TEXT DEFAULT '',
            message TEXT DEFAULT ''
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_check_log_checked_at ON check_log(checked_at)");

    migrate_link_txt($pdo);
    migrate_timestamps_to_utc($pdo);

    // Поднять версию схемы (после UTC-миграции, чтобы она успела отработать на старых БД)
    if ((int)$pdo->query('PRAGMA user_version')->fetchColumn() < 3) {
        $pdo->exec('PRAGMA user_version = 3');
    }
}

/**
 * Добавляет недостающие колонки в schedule_sources (идемпотентно).
 */
function migrate_source_columns(PDO $pdo): void
{
    $existing = [];
    foreach ($pdo->query("PRAGMA table_info(schedule_sources)")->fetchAll(PDO::FETCH_ASSOC) as $col) {
        $existing[$col['name']] = true;
    }

    $additions = [
        "ALTER TABLE schedule_sources ADD COLUMN last_checked_at TEXT DEFAULT NULL",
        "ALTER TABLE schedule_sources ADD COLUMN last_md5 TEXT DEFAULT NULL",
        "ALTER TABLE schedule_sources ADD COLUMN last_modified TEXT DEFAULT NULL",
        "ALTER TABLE schedule_sources ADD COLUMN last_size INTEGER DEFAULT NULL",
    ];
    foreach ($additions as $sql) {
        if (preg_match('/ADD COLUMN (\w+)/', $sql, $m) && !isset($existing[$m[1]])) {
            $pdo->exec($sql);
        }
    }
}

/**
 * Старые записи хранили время как «UTC+4» (самарское). Переводим в настоящий UTC.
 * Одноразовая миграция с флагом PRAGMA user_version.
 */
function migrate_timestamps_to_utc(PDO $pdo): void
{
    $ver = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    if ($ver >= 1) {
        return;
    }

    $pdo->exec("UPDATE import_log SET imported_at = datetime(imported_at, '-4 hours')");
    $pdo->exec("UPDATE schedule_history SET changed_at = datetime(changed_at, '-4 hours')");
    $pdo->exec("UPDATE schedule_sources SET last_imported_at = datetime(last_imported_at, '-4 hours') WHERE last_imported_at IS NOT NULL");
    $pdo->exec('PRAGMA user_version = 1');
}

/**
 * Конвертирует метку времени из UTC в самарское время (Europe/Samara, UTC+4).
 * Возвращает строку в формате Y-m-d H:i:s либо вход как есть при ошибке разбора.
 */
function utc_to_samara(?string $utc): ?string
{
    if ($utc === null || $utc === '') {
        return $utc;
    }
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $utc, new DateTimeZone('UTC'));
    if ($dt === false) {
        return $utc;
    }
    $dt->setTimezone(new DateTimeZone('Europe/Samara'));
    return $dt->format('Y-m-d H:i:s');
}

/**
 * Чистка старых данных: удаляет записи истории, журнала импортов и проверок,
 * которые старше $days дней. Времена хранятся в UTC — сравниваем с UTC «сейчас».
 * Вызывается из do_check_all() при каждом запуске cron (индексы делают это дешёвым).
 */
function prune_old_data(PDO $pdo, int $days = 10): void
{
    $pdo->prepare("DELETE FROM schedule_history WHERE changed_at < datetime('now', ?)")
        ->execute(['-' . $days . ' days']);
    $pdo->prepare("DELETE FROM import_log WHERE imported_at < datetime('now', ?)")
        ->execute(['-' . $days . ' days']);
    $pdo->prepare("DELETE FROM check_log WHERE checked_at < datetime('now', ?)")
        ->execute(['-' . $days . ' days']);
}

function migrate_link_txt(PDO $pdo): void
{
    $count = $pdo->query("SELECT COUNT(*) FROM schedule_sources")->fetchColumn();
    if ($count > 0) {
        return;
    }

    $linkFile = dirname(__DIR__) . '/link.txt';
    if (!file_exists($linkFile)) {
        return;
    }

    $url = trim(file_get_contents($linkFile));
    if ($url === '') {
        return;
    }

    try {
        $stmt = $pdo->prepare("INSERT OR IGNORE INTO schedule_sources (url, label, is_active) VALUES (?, ?, 1)");
        $stmt->execute([$url, 'Текущее расписание']);
    } catch (PDOException $e) {
        // ignore duplicate
    }
}

function clear_schedule(PDO $pdo): void
{
    $pdo->exec('DELETE FROM schedule');
}

function get_next_version(PDO $pdo): int
{
    $max = $pdo->query("SELECT COALESCE(MAX(version), 0) FROM schedule_history")->fetchColumn();
    return (int)$max + 1;
}
