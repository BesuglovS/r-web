<?php if (PHP_SAPI !== "cli") { http_response_code(404); exit; } ?>
<?php
// Backoff после HTTP 429: схема v12, расчёт паузы, пропуск источника
// в do_check_all() без обращения к Яндекс.Диску.
putenv('SCHEDULE_DB_PATH=' . sys_get_temp_dir() . '/rweb_test_backoff/schedule.db');
$db_file = sys_get_temp_dir() . '/rweb_test_backoff/schedule.db';
@unlink($db_file);
@unlink($db_file . '-wal');
@unlink($db_file . '-shm');

require __DIR__ . '/../src/db.php';
require __DIR__ . '/../src/import.php';

$failures = 0;
$checks = 0;
function check(string $name, bool $ok): void
{
    global $failures, $checks;
    $checks++;
    if ($ok) {
        echo "  OK    $name\n";
    } else {
        $failures++;
        echo "  FAIL  $name\n";
    }
}

$pdo = get_db();
if ($pdo === null) {
    fwrite(STDERR, "DB fail\n");
    exit(1);
}
init_db($pdo);

check('новая БД: user_version = 12', (int)$pdo->query('PRAGMA user_version')->fetchColumn() === 12);

$cols = $pdo->query("PRAGMA table_info(schedule_sources)")->fetchAll(PDO::FETCH_COLUMN, 1);
check('есть backoff_level', in_array('backoff_level', $cols, true));
check('есть backoff_until', in_array('backoff_until', $cols, true));

// Экспоненциальная пауза: 10 → 20 → 40 → 60 (дальше 60)
check('пауза уровня 1 = 10 мин', source_backoff_minutes(1) === 10);
check('пауза уровня 2 = 20 мин', source_backoff_minutes(2) === 20);
check('пауза уровня 3 = 40 мин', source_backoff_minutes(3) === 40);
check('пауза уровня 4 = 60 мин', source_backoff_minutes(4) === 60);
check('пауза уровня 9 = 60 мин (потолок)', source_backoff_minutes(9) === 60);

$pdo->exec("INSERT INTO schedule_sources (url, label, is_active)
    VALUES ('https://disk.yandex.ru/d/backoff-test', 'тест', 1)");
$id = (int)$pdo->lastInsertId();

$until = set_source_backoff($pdo, $id, 2);
$row = $pdo->query("SELECT backoff_level, backoff_until FROM schedule_sources WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
check('set_source_backoff: уровень записан', (int)$row['backoff_level'] === 2);
check('set_source_backoff: until совпадает', $row['backoff_until'] === $until);

$delta = (strtotime($until . ' UTC') ?: 0) - time();
check('set_source_backoff: пауза ≈ 20 минут', $delta > 19 * 60 && $delta <= 20 * 60);

// Активный источник на паузе: do_check_all не должен идти в сеть
$result = do_check_all(6, $pdo);
check('do_check_all: источник пропущен', ($result['results'][0]['check'] ?? '') === 'backoff');
check('do_check_all: счётчик паузы', (int)($result['paused'] ?? -1) === 1);
check('do_check_all: ошибок нет', (int)$result['errors'] === 0);
check('do_check_all: успешных нет', (int)$result['success'] === 0);

// Успешная проверка/импорт снимают паузу
reset_source_backoff($pdo, $id);
$row = $pdo->query("SELECT backoff_level, backoff_until FROM schedule_sources WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
check('reset_source_backoff: уровень сброшен', (int)$row['backoff_level'] === 0);
check('reset_source_backoff: until очищен', $row['backoff_until'] === null);

set_source_backoff($pdo, $id, 1);
reset_source_backoff_by_url($pdo, 'https://disk.yandex.ru/d/backoff-test');
$row = $pdo->query("SELECT backoff_level, backoff_until FROM schedule_sources WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
check('reset_source_backoff_by_url: пауза снята', (int)$row['backoff_level'] === 0 && $row['backoff_until'] === null);

echo "\n" . ($failures === 0 ? "BACKOFF TEST OK" : "FAILURES: {$failures}") . " ({$checks} проверок)\n";
exit($failures === 0 ? 0 : 1);
