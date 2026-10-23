<?php if (PHP_SAPI !== "cli") { http_response_code(404); exit; } ?>
<?php
/**
 * CLI-тест защиты от буквальных дублей расписания.
 *
 * Проверяем:
 *   - dedupe_lessons() схлопывает одинаковые строки, но не трогает уроки,
 *     различающиеся кабинетом (lesson_key кабинет не учитывает намеренно);
 *   - archive_lessons() пишет в БД-архив только уникальные строки;
 *   - уникальный индекс не даёт вставить полный дубль;
 *   - миграция v11 удаляет уже накопленные дубли (оставляя свежую запись)
 *     и создаёт уникальные индексы.
 *
 * Запуск: php tests/import_dedupe_test.php
 */

$tmp = sys_get_temp_dir() . '/rweb_dedupe_test';
@mkdir($tmp, 0775, true);
@unlink($tmp . '/schedule.db');
@unlink($tmp . '/migrate.db');

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

function lesson(string $class, string $subject, string $room, ?string $group = null): array
{
    return [
        'sheet_name'     => 'ПТ 23.10',
        'date'           => '2026-10-23',
        'day_of_week'    => 'пятница',
        'class_name'     => $class,
        'lesson_num'     => 7,
        'time_start'     => '13:20',
        'time_end'       => '14:00',
        'subject'        => $subject,
        'teacher'        => 'Жаринова Л.А.',
        'room'           => $room,
        'parallel_group' => $group,
    ];
}

// 1. Дедупликация распарсенных строк
$dup = lesson('11А', 'Информатика', '111');
$unique = dedupe_lessons([$dup, $dup, $dup]);
check('dedupe_lessons: три одинаковые строки → одна', count($unique) === 1);

$differentRoom = lesson('11А', 'Информатика', '112');
$unique = dedupe_lessons([$dup, $differentRoom]);
check('dedupe_lessons: другой кабинет — не дубль', count($unique) === 2);

$group1 = lesson('11А', 'Информатика', '111', '1');
$group2 = lesson('11А', 'Информатика', '111', '2');
$unique = dedupe_lessons([$group1, $group2]);
check('dedupe_lessons: разные параллельные группы — не дубли', count($unique) === 2);

check(
    'dedupe_lessons: NULL-группа равна пустой',
    count(dedupe_lessons([lesson('11А', 'Информатика', '111', null), lesson('11А', 'Информатика', '111', '')])) === 1
);

// 2. БД-архив не пишет дубли
$pdo = get_db();
if ($pdo === null) {
    fwrite(STDERR, "DB fail\n");
    exit(1);
}
init_db($pdo);
check('новая БД: user_version = 11', (int)$pdo->query('PRAGMA user_version')->fetchColumn() === 11);
$idx = $pdo->query("SELECT name FROM sqlite_master WHERE type='index' AND name IN ('uniq_schedule_lesson','uniq_schedule_archive_lesson')")->fetchAll(PDO::FETCH_COLUMN);
sort($idx);
check('уникальные индексы созданы', $idx === ['uniq_schedule_archive_lesson', 'uniq_schedule_lesson']);

$n = archive_lessons($pdo, 8, 's8', [$dup, $dup, $dup, $differentRoom], '2026-10-23 00:00:00');
$archived = (int)$pdo->query("SELECT COUNT(*) FROM schedule_archive WHERE week_key = 's8'")->fetchColumn();
check('archive_lessons: вернул 2 записи', $n === 2);
check('archive_lessons: в архиве 2 записи без дублей', $archived === 2);

// 3. Уникальный индекс не даёт вставить полный дубль
$ins = $pdo->prepare("INSERT INTO schedule
    (sheet_name, date, day_of_week, class_name, lesson_num, time_start, time_end,
     subject, teacher, room, parallel_group, imported_at)
    VALUES ('ПТ 23.10', '2026-10-23', 'пятница', '11А', 7, '13:20', '14:00',
            'Информатика', 'Жаринова Л.А.', '111', '1', '2026-10-23 00:00:00')");
$ins->execute();
$blocked = false;
try {
    $ins->execute();
} catch (PDOException $e) {
    $blocked = true;
}
check('уникальный индекс блокирует повторную вставку', $blocked);

// 4. Миграция v11: удаляет накопленные дубли и восстанавливает индексы
@unlink($tmp . '/migrate.db');
$mig = new PDO('sqlite:' . $tmp . '/migrate.db');
$mig->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
init_db($mig);

// Эмулируем БД v10 с дублями: снимаем защиту и вставляем три копии
$mig->exec('DROP INDEX uniq_schedule_lesson');
$mig->exec('DROP INDEX uniq_schedule_archive_lesson');
$mig->exec("PRAGMA user_version = 10");
$ins = $mig->prepare("INSERT INTO schedule
    (sheet_name, date, day_of_week, class_name, lesson_num, time_start, time_end,
     subject, teacher, room, parallel_group, imported_at)
    VALUES ('ПТ 23.10', '2026-10-23', 'пятница', '11А', 7, '13:20', '14:00',
            'Информатика', 'Жаринова Л.А.', '111', NULL, :t)");
$ins->execute([':t' => '2026-10-22 11:40:03']);
$ins->execute([':t' => '2026-10-22 11:40:03']);
$ins->execute([':t' => '2026-10-22 11:41:00']);
$mig->prepare("INSERT INTO schedule_archive
    (source_id, week_key, date, day_of_week, class_name, lesson_num, time_start,
     time_end, subject, teacher, room, parallel_group, archived_at)
    VALUES (8, 's8', '2026-10-23', 'пятница', '11А', 7, '13:20', '14:00',
            'Информатика', 'Жаринова Л.А.', '111', NULL, ?)")->execute(['2026-10-23 00:00:00']);
$mig->prepare("INSERT INTO schedule_archive
    (source_id, week_key, date, day_of_week, class_name, lesson_num, time_start,
     time_end, subject, teacher, room, parallel_group, archived_at)
    VALUES (8, 's8', '2026-10-23', 'пятница', '11А', 7, '13:20', '14:00',
            'Информатика', 'Жаринова Л.А.', '111', NULL, ?)")->execute(['2026-10-23 00:01:00']);

migrate_unique_lessons($mig);
$mig->exec('PRAGMA user_version = 11');

$live = (int)$mig->query("SELECT COUNT(*) FROM schedule")->fetchColumn();
$arch = (int)$mig->query("SELECT COUNT(*) FROM schedule_archive")->fetchColumn();
$imported = $mig->query("SELECT imported_at FROM schedule")->fetchColumn();
check('миграция: в расписании осталась одна запись', $live === 1);
check('миграция: оставлена самая свежая запись', $imported === '2026-10-22 11:41:00');
check('миграция: в архиве осталась одна запись', $arch === 1);
$idx = $mig->query("SELECT name FROM sqlite_master WHERE type='index' AND name IN ('uniq_schedule_lesson','uniq_schedule_archive_lesson')")->fetchAll(PDO::FETCH_COLUMN);
check('миграция: уникальные индексы восстановлены', count($idx) === 2);

$blocked = false;
try {
    $mig->prepare("INSERT INTO schedule
        (sheet_name, date, day_of_week, class_name, lesson_num, time_start, time_end,
         subject, teacher, room, parallel_group, imported_at)
        VALUES ('ПТ 23.10', '2026-10-23', 'пятница', '11А', 7, '13:20', '14:00',
                'Информатика', 'Жаринова Л.А.', '111', NULL, '2026-10-23 00:00:00')")->execute();
} catch (PDOException $e) {
    $blocked = true;
}
check('миграция: дубль с NULL-группой тоже заблокирован', $blocked);

echo "\n" . ($failures === 0 ? "IMPORT DEDUPE TEST OK" : "FAILURES: {$failures}") . " ({$checks} проверок)\n";
exit($failures === 0 ? 0 : 1);
