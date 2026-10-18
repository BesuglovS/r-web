<?php if (PHP_SAPI !== "cli") { http_response_code(404); exit; } ?>
<?php
/**
 * CLI-тест автоочистки устаревших дат: collect_keep_dates() + cleanup_stale_dates().
 *
 * Правило: недели выключенных, удалённых источников и ad-hoc импорта
 * (source_id = 0) остаются на сайте навсегда; удаляется только дата,
 * исчезнувшая из файла АКТИВНОГО источника.
 *
 * Дополнительно: try_archive_source() при ошибке не пробрасывает исключение,
 * а пишет её в check_log (result = 'archive_error').
 *
 * Запуск: php tests/archive_keep_test.php
 */

$tmp = sys_get_temp_dir() . '/rweb_keep_test';
@mkdir($tmp, 0775, true);
@mkdir($tmp . '/archive', 0775, true);
@unlink($tmp . '/schedule.db');
array_map('unlink', glob($tmp . '/archive/*') ?: []);

putenv('SCHEDULE_DB_PATH=' . $tmp . '/schedule.db');
putenv('SCHEDULE_ARCHIVE_DIR=' . $tmp . '/archive');

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/import.php';

$failures = 0;
$checks = 0;

function check(string $name, bool $cond): void
{
    global $failures, $checks;
    $checks++;
    echo ($cond ? "  OK    " : "  FAIL  ") . $name . "\n";
    if (!$cond) $failures++;
}

$pdo = get_db();
if ($pdo === null) {
    fwrite(STDERR, "DB fail\n");
    exit(1);
}
init_db($pdo);
$pdo->exec("DELETE FROM schedule");
$pdo->exec("DELETE FROM schedule_sources");
$pdo->exec("DELETE FROM schedule_archive");
$pdo->exec("DELETE FROM schedule_history");
$pdo->exec("DELETE FROM check_log");

function add_lesson(PDO $pdo, string $date): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO schedule
            (sheet_name, date, day_of_week, class_name, lesson_num, time_start,
             time_end, subject, teacher, room, parallel_group, imported_at)
         VALUES ('ПН 07.09', :d, 'ПН', '5А', 1, '08:00', '08:40',
                 'Математика', 'Иванов', '204', '', '2026-09-01 00:00:00')"
    );
    $stmt->execute([':d' => $date]);
}

function add_archived_week(PDO $pdo, int $source_id, string $date): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO schedule_archive
            (source_id, week_key, date, day_of_week, class_name, lesson_num,
             time_start, time_end, subject, teacher, room, parallel_group, archived_at)
         VALUES (?, ?, ?, 'ПН', '5А', 1, '08:00', '08:40',
                 'Математика', 'Иванов', '204', '', '2026-09-01 00:00:00')"
    );
    $stmt->execute([$source_id, $source_id > 0 ? 's' . $source_id : 'd_' . $date, $date]);
}

// Источники:
//   1 — активный (даёт свои даты через импорт)
//   2 — выключенный, last_dates известны
//   3 — выключенный, last_dates пусты (даты есть только в schedule_archive)
//   99 — удалённый (строки нет, осталась только неделя в schedule_archive)
$pdo->exec("INSERT INTO schedule_sources (url, label, is_active, last_dates)
    VALUES ('https://disk.yandex.ru/d/a', 'активный', 1, '[\"2026-09-07\"]')");
$pdo->exec("INSERT INTO schedule_sources (url, label, is_active, last_dates)
    VALUES ('https://disk.yandex.ru/d/b', 'выкл', 0, '[\"2026-09-14\"]')");
$pdo->exec("INSERT INTO schedule_sources (url, label, is_active, last_dates)
    VALUES ('https://disk.yandex.ru/d/c', 'выкл без дат', 0, NULL)");

foreach (['2026-09-07', '2026-09-14', '2026-09-21', '2026-09-28', '2026-10-05'] as $d) {
    add_lesson($pdo, $d);
}
// Дата активного источника, исчезнувшая из файла: ни в last_dates, ни в архиве
add_lesson($pdo, '2026-10-12');

add_archived_week($pdo, 3, '2026-09-21');   // выключенный без last_dates
add_archived_week($pdo, 99, '2026-09-28');  // источник удалён
add_archived_week($pdo, 0, '2026-10-05');   // ad-hoc импорт по ссылке

// Активный источник отдал только одну дату
$keep = collect_keep_dates($pdo, ['2026-09-07']);

check('keep содержит дату активного источника', in_array('2026-09-07', $keep, true));
check('keep содержит last_dates выключенного источника', in_array('2026-09-14', $keep, true));
check('keep содержит неделю выключенного источника без last_dates (из архива)',
    in_array('2026-09-21', $keep, true));
check('keep содержит неделю удалённого источника', in_array('2026-09-28', $keep, true));
check('keep содержит ad-hoc неделю (source_id = 0)', in_array('2026-10-05', $keep, true));
check('keep НЕ содержит дату, исчезнувшую из активного файла',
    !in_array('2026-10-12', $keep, true));

$res = cleanup_stale_dates($pdo, $keep);

check('удалена только исчезнувшая дата', ($res['removed_dates'] ?? []) === ['2026-10-12']);

$dates = $pdo->query("SELECT DISTINCT date FROM schedule ORDER BY date")->fetchAll(PDO::FETCH_COLUMN);
check('недели выключенных/удалённых источников остались в schedule',
    $dates === ['2026-09-07', '2026-09-14', '2026-09-21', '2026-09-28', '2026-10-05']);

$hist = $pdo->query("SELECT change_type, date FROM schedule_history")->fetchAll(PDO::FETCH_ASSOC);
check('удаление записано в историю как removed',
    count($hist) === 1 && $hist[0]['change_type'] === 'removed' && $hist[0]['date'] === '2026-10-12');

// try_archive_source(): ошибка архивации не летит наружу, пишется в check_log
$err = try_archive_source($pdo, [
    'id'    => 1,
    'url'   => 'https://example.com/not-yandex.xlsx',
    'label' => 'тест',
]);
check('try_archive_source возвращает ошибку, а не бросает', is_string($err) && $err !== '');
$logged = (int)$pdo->query("SELECT COUNT(*) FROM check_log WHERE result = 'archive_error'")->fetchColumn();
check('ошибка архивации записана в check_log', $logged === 1);

echo "\n" . ($failures === 0 ? "ARCHIVE KEEP TEST OK" : "FAILURES: {$failures}") . " ({$checks} проверок)\n";
exit($failures === 0 ? 0 : 1);
