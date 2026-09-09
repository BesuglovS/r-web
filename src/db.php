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
 * Читает одно значение из .env (единственный парсер .env в проекте —
 * используется и из src/auth.php, и из cron_notify.php).
 * Поддерживает комментарии, префикс export и кавычки значений.
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
            if (preg_match('/^(?:export\s+)?' . preg_quote($key, '/') . '\s*=\s*(.+)$/', $line, $m)) {
                $value = trim($m[1]);
                // Снимаем обрамляющие кавычки ("значение" / 'значение')
                $quoted = strlen($value) >= 2
                    && (($value[0] === '"' && $value[-1] === '"')
                        || ($value[0] === "'" && $value[-1] === "'"));
                if ($quoted) {
                    $value = substr($value, 1, -1);
                } else {
                    // Инлайн-комментарий вне кавычек — не часть значения
                    // (важно для bcrypt-хешей: ' # комментарий' сломал бы логин)
                    $hash_pos = strpos($value, ' #');
                    if ($hash_pos !== false) {
                        $value = rtrim(substr($value, 0, $hash_pos));
                    }
                }
                return $value;
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
    // Кешируем по конкретному PDO-соединению: init_db вызывается из десятков
    // мест за один запрос (get_sources, update_source_status, ...), и каждый
    // раз читать PRAGMA user_version незачем.
    static $inited = [];
    $key = spl_object_id($pdo);
    if (isset($inited[$key])) {
        return;
    }

    // user_version: 1 = миграция UTC выполнена, 2 = промежуточная (историческая),
    // 3 = актуальная схема (+check_log, md5-колонки в schedule_sources),
    // 4 = + колонка last_dates в schedule_sources (даты, покрытые источником),
    // 5 = + таблица аудиторий rooms (привязка к корпусам, сид начального списка).
    $ver = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    if ($ver >= 5) {
        $inited[$key] = true;
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

    migrate_rooms($pdo);

    migrate_link_txt($pdo);
    migrate_timestamps_to_utc($pdo);

    // Поднять версию схемы (после UTC-миграции, чтобы она успела отработать на старых БД)
    if ((int)$pdo->query('PRAGMA user_version')->fetchColumn() < 5) {
        $pdo->exec('PRAGMA user_version = 5');
    }

    $inited[$key] = true;
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
        // Даты, покрытые последним успешным полным импортом источника (JSON-массив).
        // Нужно do_check_all(): unchanged-источник подтверждает свои даты без импорта.
        "ALTER TABLE schedule_sources ADD COLUMN last_dates TEXT DEFAULT NULL",
    ];
    foreach ($additions as $sql) {
        if (preg_match('/ADD COLUMN (\w+)/', $sql, $m) && !isset($existing[$m[1]])) {
            $pdo->exec($sql);
        }
    }
}

/**
 * Таблица аудиторий с привязкой к корпусам (миграция v5).
 * Корпуса: 1 = Чапаевская, 2 = Молодогвардейская, 3 = Ярмарочная.
 * Сид начального списка — INSERT OR IGNORE, идемпотентно.
 */
function migrate_rooms(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS rooms (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            building INTEGER NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1
        )
    ");

    // Начальный список аудиторий снят с расписания (GROUP BY room) и
    // размечен по корпусам; аудитории без корпуса в таблицу не вносились.
    $rooms = [
        1 => [
            'Ч-2', 'Ч-3', 'Ч-3А', 'Ч-4', 'Ч-5', 'Ч-6', 'Ч-7', 'Ч-8', 'Ч-9',
            'Ч-10', 'Ч-11', 'Ч-14', 'Ч-15', 'Ч-16', 'Ч-17', 'Ч-18', 'Ч-19',
            'Ч-20', 'Ч-21',
            'Спортивный зал', 'Спортивный зал на Чапаевской',
            'Театральный зал на Чапаевской',
        ],
        2 => [
            '102', '110', '111', '114', '117', '124',
            '203', '204', '205', '206', '207', '208', '209', '211', '214',
            '219', '220',
            '301', '302', '303', '304', '305', '306', '307', '308', '311',
            'Лаборатория', 'Театральный зал', 'Конный клуб',
        ],
        3 => [
            'Я-1', 'Я-2', 'Я-3', 'Я-4', 'Я-5', 'Я-6', 'Я-16', 'Я-18', 'Я-21',
            'Спортивный зал на Ярмарочной',
        ],
    ];

    $stmt = $pdo->prepare("INSERT OR IGNORE INTO rooms (name, building) VALUES (?, ?)");
    foreach ($rooms as $building => $names) {
        foreach ($names as $name) {
            $stmt->execute([$name, $building]);
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
 * Чистка старых данных: журналы импортов/проверок — $days дней (по умолчанию 10),
 * история изменений расписания — дольше ($history_days, по умолчанию 90):
 * пользователи смотрят изменения недельной давности, а таблица крошечная.
 * Времена хранятся в UTC — сравниваем с UTC «сейчас».
 * Вызывается из do_import() и do_check_all() (индексы делают это дешёвым).
 */
function prune_old_data(PDO $pdo, int $days = 10, int $history_days = 90): void
{
    $pdo->prepare("DELETE FROM schedule_history WHERE changed_at < datetime('now', ?)")
        ->execute(['-' . $history_days . ' days']);
    $pdo->prepare("DELETE FROM import_log WHERE imported_at < datetime('now', ?)")
        ->execute(['-' . $days . ' days']);
    $pdo->prepare("DELETE FROM check_log WHERE checked_at < datetime('now', ?)")
        ->execute(['-' . $days . ' days']);
}

/**
 * Ежедневный бэкап БД через VACUUM INTO (sqlite >= 3.27, PHP 8.1 его включает).
 * Хранит до $keep копий в каталоге db/backups/ рядом с файлом БД.
 * Вызывается из cron_import.php. Возвращает путь к свежему бэкапу либо null.
 */
function backup_db(PDO $pdo, int $keep = 7): ?string
{
    try {
        $backup_dir = dirname(get_db_path()) . '/backups';
        if (!is_dir($backup_dir) && !@mkdir($backup_dir, 0775, true)) {
            return null;
        }
        $dest = $backup_dir . '/schedule-' . gmdate('Y-m-d') . '.db';

        // Один бэкап в день: если уже есть — не перезаписываем
        if (!file_exists($dest)) {
            $pdo->exec("VACUUM INTO " . $pdo->quote($dest));
        }

        // Ротация: удаляем самые старые сверх $keep
        $backups = glob($backup_dir . '/schedule-*.db') ?: [];
        sort($backups);
        while (count($backups) > $keep) {
            @unlink(array_shift($backups));
        }

        return $dest;
    } catch (Exception $e) {
        error_log('[backup_db] ' . $e->getMessage());
        return null;
    }
}

/**
 * DEPRECATED: разовая миграция link.txt в БД. Источники теперь управляются
 * через админ-панель; функция оставлена только для старых инсталляций.
 */
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
