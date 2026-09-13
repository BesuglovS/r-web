<?php
// Смоук SQL-логики last_import: checked_at по всем источникам (не только
// активным) + фолбек на журнал проверок — та же форма запросов, что в api.php.
putenv('SCHEDULE_DB_PATH=' . sys_get_temp_dir() . '/rweb_test_lastimport/schedule.db');
require __DIR__ . '/../src/db.php';

$pdo = get_db();
if ($pdo === null) { fwrite(STDERR, "DB fail\n"); exit(1); }
init_db($pdo);
$pdo->exec("DELETE FROM schedule_sources");
$pdo->exec("DELETE FROM check_log");
$pdo->exec("DELETE FROM import_log");

// 1) Пустые таблицы: MAX даёт null, фолбек тоже пуст → checked_at = null
$chk = $pdo->query("SELECT MAX(last_checked_at) c FROM schedule_sources")->fetch(PDO::FETCH_ASSOC);
if (!empty($chk['c'])) { fwrite(STDERR, "FAIL: empty sources\n"); exit(1); }
$chk = $pdo->query("SELECT MAX(checked_at) c FROM check_log")->fetch(PDO::FETCH_ASSOC);
if (!empty($chk['c'])) { fwrite(STDERR, "FAIL: empty check_log\n"); exit(1); }

// 2) Неактивный источник с последней проверкой — должен попасть в MAX
$pdo->exec("INSERT INTO schedule_sources (url, label, is_active, last_checked_at)
    VALUES ('https://disk.yandex.ru/x', 'тест', 0, '2026-09-13 07:20:02')");
$chk = $pdo->query("SELECT MAX(last_checked_at) c FROM schedule_sources")->fetch(PDO::FETCH_ASSOC);
if ($chk['c'] !== '2026-09-13 07:20:02') { fwrite(STDERR, "FAIL: inactive source ignored\n"); exit(1); }

// 3) Фолбек на check_log, когда источников нет вовсе
$pdo->exec("DELETE FROM schedule_sources");
$pdo->exec("INSERT INTO check_log (source_id, url, result, checked_at) VALUES (1, 'x', 'unchanged', '2026-09-13 07:25:00')");
$chk = $pdo->query("SELECT MAX(last_checked_at) c FROM schedule_sources")->fetch(PDO::FETCH_ASSOC);
if (!empty($chk['c'])) { fwrite(STDERR, "FAIL: sources should be empty\n"); exit(1); }
$chk = $pdo->query("SELECT MAX(checked_at) c FROM check_log")->fetch(PDO::FETCH_ASSOC);
if ($chk['c'] !== '2026-09-13 07:25:00') { fwrite(STDERR, "FAIL: check_log fallback\n"); exit(1); }

echo "LAST_IMPORT SMOKE OK\n";
