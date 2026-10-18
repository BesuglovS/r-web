<?php if (PHP_SAPI !== "cli") { http_response_code(404); exit; } ?>
<?php
/**
 * CLI-тест восстановления расписания прошлых недель из БД-архива
 * (restore_schedule_from_archive).
 *
 * Проверяем:
 *   - даты неактивных/удалённых источников и ad-hoc импорта возвращаются;
 *   - даты активных источников не восстанавливаются (ими управляет импорт);
 *   - уже существующие в schedule даты не дублируются;
 *   - дубликаты слота схлопываются по самой свежей записи архива;
 *   - dry-run ничего не меняет;
 *   - повторный запуск ничего не делает (идемпотентность).
 *
 * Запуск: php tests/restore_archive_test.php
 */

$tmp = sys_get_temp_dir() . '/rweb_restore_test';
@mkdir($tmp, 0775, true);
@mkdir($tmp . '/archive', 0775, true);
@unlink($tmp . '/schedule.db');

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

function add_live_lesson(PDO $pdo, string $date, string $class, string $subject): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO schedule
            (sheet_name, date, day_of_week, class_name, lesson_num, time_start,
             time_end, subject, teacher, room, parallel_group, imported_at)
         VALUES ('XX 01.01', :d, 'понедельник', :c, 1, '08:00', '08:40',
                 :s, 'Иванов', '204', '', '2026-01-01 00:00:00')"
    );
    $stmt->execute([':d' => $date, ':c' => $class, ':s' => $subject]);
}

function add_archived_lesson(PDO $pdo, int $source_id, string $date, string $week_key,
                             string $class, string $subject, string $archived_at, int $lesson_num = 1): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO schedule_archive
            (source_id, week_key, date, day_of_week, class_name, lesson_num,
             time_start, time_end, subject, teacher, room, parallel_group, archived_at)
         VALUES (?, ?, ?, 'понедельник', ?, ?, '08:00', '08:40', ?, 'Иванов', '204', '', ?)"
    );
    $stmt->execute([$source_id, $week_key, $date, $class, $lesson_num, $subject, $archived_at]);
}

function dates_in_schedule(PDO $pdo): array
{
    return $pdo->query("SELECT DISTINCT date FROM schedule ORDER BY date")->fetchAll(PDO::FETCH_COLUMN);
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

// Источник 1 — активный (его даты восстанавливать нельзя)
$pdo->exec("INSERT INTO schedule_sources (url, label, is_active) VALUES ('https://disk.yandex.ru/d/a', 'активный', 1)");
// Источник 2 — выключенный (с известным временем последнего импорта)
$pdo->exec("INSERT INTO schedule_sources (url, label, is_active, last_imported_at) VALUES ('https://disk.yandex.ru/d/b', 'выкл', 0, '2026-09-17 17:35:03')");

// Живая дата, которая не должна дублироваться
add_live_lesson($pdo, '2026-09-07', '5А', 'Живой урок');
add_archived_lesson($pdo, 2, '2026-09-07', 's2', '5А', 'Математика', '2026-09-07 10:00:00');

// Неделя выключенного источника — восстанавливается
add_archived_lesson($pdo, 2, '2026-09-14', 's2', '5А', 'Физика', '2026-09-14 10:00:00');
add_archived_lesson($pdo, 2, '2026-09-14', 's2', '5А', 'История', '2026-09-14 10:00:00', 2);

// Неделя удалённого источника (строки в schedule_sources нет) — восстанавливается
add_archived_lesson($pdo, 77, '2026-09-28', 's77', '5Б', 'Химия', '2026-09-28 10:00:00');

// Ad-hoc импорт по ссылке (source_id = 0) — восстанавливается
add_archived_lesson($pdo, 0, '2026-10-05', 'd_2026-10-05_2026-10-05', '5В', 'География', '2026-10-05 10:00:00');

// Неделя АКТИВНОГО источника — НЕ восстанавливается
add_archived_lesson($pdo, 1, '2026-11-02', 's1', '5Г', 'Биология', '2026-11-02 10:00:00');

// Дубликат слота в двух архивных неделях: побеждает свежая запись
add_archived_lesson($pdo, 2, '2026-04-06', 's2', '5А', 'Старый предмет', '2026-04-06 09:00:00', 3);
add_archived_lesson($pdo, 77, '2026-04-06', 's77', '5А', 'Новый предмет', '2026-04-07 09:00:00', 3);

// dry-run: новая дата, ничего менять нельзя
add_archived_lesson($pdo, 2, '2026-12-07', 's2', '5А', 'Астрономия', '2026-12-07 10:00:00');

$dry = restore_schedule_from_archive($pdo, true);
check('dry-run отдаёт даты и уроки', $dry['dry_run'] === true && count($dry['dates']) > 0 && $dry['lessons'] > 0);
check('dry-run ничего не записал', !in_array('2026-12-07', dates_in_schedule($pdo), true));

$res = restore_schedule_from_archive($pdo);
check('восстановлена неделя выключенного источника', in_array('2026-09-14', $res['dates'], true));
check('восстановлена неделя удалённого источника', in_array('2026-09-28', $res['dates'], true));
check('восстановлена ad-hoc неделя', in_array('2026-10-05', $res['dates'], true));
check('восстановлена неделя с дубликатом слота', in_array('2026-04-06', $res['dates'], true));
check('dry-run дата восстановлена только сейчас', in_array('2026-12-07', $res['dates'], true));
check('неделя активного источника не восстановлена', !in_array('2026-11-02', $res['dates'], true));

$dates = dates_in_schedule($pdo);
check('в schedule все нужные даты', $dates === ['2026-04-06', '2026-09-07', '2026-09-14', '2026-09-28', '2026-10-05', '2026-12-07']);

$live_0709 = (int)$pdo->query("SELECT COUNT(*) FROM schedule WHERE date = '2026-09-07'")->fetchColumn();
check('существующая дата не продублирована', $live_0709 === 1);

$dup = $pdo->query("SELECT subject FROM schedule WHERE date = '2026-04-06'")->fetchAll(PDO::FETCH_COLUMN);
check('дубликат слота схлопнут до свежей записи', $dup === ['Новый предмет']);

check('уроков в restored 09-14 = 2', (int)$pdo->query("SELECT COUNT(*) FROM schedule WHERE date = '2026-09-14'")->fetchColumn() === 2);

$imp = $pdo->query("SELECT DISTINCT imported_at FROM schedule WHERE date = '2026-09-14'")->fetchAll(PDO::FETCH_COLUMN);
check('«Изменено» = время последнего импорта источника', $imp === ['2026-09-17 17:35:03']);

$again = restore_schedule_from_archive($pdo);
check('повторный запуск идемпотентен', $again['dates'] === [] && $again['lessons'] === 0);

echo "\n" . ($failures === 0 ? "RESTORE ARCHIVE TEST OK" : "FAILURES: {$failures}") . " ({$checks} проверок)\n";
exit($failures === 0 ? 0 : 1);
