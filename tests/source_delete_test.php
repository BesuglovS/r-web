<?php if (PHP_SAPI !== "cli") { http_response_code(404); exit; } ?>
<?php
/**
 * CLI-тест удаления источника (delete_source): файлы на Яндекс.Диске
 * удаляются, поэтому источник нельзя удалить, пока его последняя версия
 * не сохранена в архив.
 *
 *  1. Ссылка не с Яндекс.Диска и архива нет → удаление заблокировано.
 *  2. Ссылка не с Яндекс.Диска, но архив есть → всё равно заблокировано
 *     (URL проверяется до обращения к архиву).
 *  3. Валидная ссылка на Яндекс.Диск и архив уже есть → удаление проходит
 *     (метаданные недоступны/битые → archive_source_file() оставляет копию).
 *     Файл архива и строки schedule_archive при удалении сохраняются.
 *
 * Запуск: php tests/source_delete_test.php
 */

$tmp = sys_get_temp_dir() . '/rweb_delete_test';
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
$pdo->exec("DELETE FROM schedule_sources");
$pdo->exec("DELETE FROM schedule_archive");

$archive_dir = get_archive_dir();

function mk_source(PDO $pdo, string $url, string $label): int
{
    $stmt = $pdo->prepare("INSERT INTO schedule_sources (url, label, is_active) VALUES (?, ?, 1)");
    $stmt->execute([$url, $label]);
    return (int)$pdo->lastInsertId();
}

function attempt_delete(int $id): bool
{
    try {
        delete_source($id);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function source_present(PDO $pdo, int $id): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM schedule_sources WHERE id = ?");
    $stmt->execute([$id]);
    return (int)$stmt->fetchColumn() === 1;
}

// 1. Ссылка не Яндекс, архива нет
$id1 = mk_source($pdo, 'https://example.com/week1.xlsx', 'чужой хост');
check('удаление заблокировано без архива', attempt_delete($id1) === false);
check('источник остался в БД', source_present($pdo, $id1));

// 2. Ссылка не Яндекс, но архив уже есть — URL проверяется до архива
file_put_contents($archive_dir . '/' . $id1 . '_week1_2026-09-07.xlsx', 'stub');
check('удаление заблокировано при неверной ссылке', attempt_delete($id1) === false);
check('источник остался в БД (2)', source_present($pdo, $id1));

// 3. Валидная ссылка + архив есть → удаление проходит, копии сохраняются
$id2 = mk_source($pdo, 'https://disk.yandex.ru/d/definitely-not-existing-test-key', 'недоступный файл');
file_put_contents($archive_dir . '/' . $id2 . '_week2_2026-09-14.xlsx', 'stub');
$pdo->prepare("INSERT INTO schedule_archive
    (source_id, week_key, date, day_of_week, class_name, lesson_num, time_start,
     time_end, subject, teacher, room, parallel_group, archived_at)
    VALUES (?, 's2', '2026-09-14', 'ПН', '5А', 1, '08:00', '08:40',
            'Математика', 'Иванов', '204', '', '2026-09-01 00:00:00')")->execute([$id2]);

check('удаление проходит, когда архив есть', attempt_delete($id2) === true);
check('источник удалён', !source_present($pdo, $id2));
check('XLSX-файл архива сохранён', is_file($archive_dir . '/' . $id2 . '_week2_2026-09-14.xlsx'));
$archived = (int)$pdo->query("SELECT COUNT(*) FROM schedule_archive WHERE source_id = " . $id2)->fetchColumn();
check('неделя осталась в schedule_archive', $archived === 1);

echo "\n" . ($failures === 0 ? "SOURCE DELETE TEST OK" : "FAILURES: {$failures}") . " ({$checks} проверок)\n";
exit($failures === 0 ? 0 : 1);
