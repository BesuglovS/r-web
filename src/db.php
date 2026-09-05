<?php
/**
 * Подключение к SQLite и вспомогательные функции.
 * БД: /var/www/r.nayanovaacademy.ru/db/schedule.db (уровнем выше public/)
 */

function get_db_path(): string
{
    return dirname(__DIR__, 2) . '/db/schedule.db';
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
        return $pdo;
    } catch (PDOException $e) {
        return null;
    }
}

function init_db(PDO $pdo): void
{
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
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_history_version ON schedule_history(version)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_history_date ON schedule_history(date)");
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
