<?php
/**
 * CLI-тесты статистики педагогов (src/stats.php) и БД-архива
 * (schedule_archive, backfill_archive_from_files()).
 *
 * Запуск: php tests/teacher_stats_test.php
 */

$tmp = sys_get_temp_dir() . '/rweb_stats_test';
@mkdir($tmp, 0775, true);
@mkdir($tmp . '/archive', 0775, true);
@unlink($tmp . '/schedule.db');
array_map('unlink', glob($tmp . '/archive/*') ?: []);

putenv('SCHEDULE_DB_PATH=' . $tmp . '/schedule.db');
putenv('SCHEDULE_ARCHIVE_DIR=' . $tmp . '/archive');

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/parser.php';
require_once __DIR__ . '/../src/import.php';
require_once __DIR__ . '/../src/stats.php';

$failures = 0;
$checks = 0;

function check(string $name, bool $cond): void
{
    global $failures, $checks;
    $checks++;
    echo ($cond ? "  OK    " : "  FAIL  ") . $name . "\n";
    if (!$cond) $failures++;
}

const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
const NS_REL  = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

function cell_xml(string $ref, string $value, bool $shared = true): string
{
    if ($value === '') return '';
    $t = $shared ? ' t="s"' : '';
    return '<c r="' . $ref . '"' . $t . '><v>' . htmlspecialchars($value) . '</v></c>';
}

/**
 * Лист «ПН 02.09», два класса (A–D = 5А, E–H = 5Б):
 *   Иванов: Математика в 5А и 5Б (весь класс), Русский в 5А в группах 1.1 и 2.1.
 *   Петров: Физика в 5А. Смирнов: История в 5Б. Итого 6 уроков.
 */
function build_stats_fixture(string $path): void
{
    $strings = [
        0 => 'ПОНЕДЕЛЬНИК', 1 => '5А', 2 => '08:00-08:40', 3 => '09:00-10:00',
        4 => 'Математика/Иванов (204)', 5 => 'Русский язык/Иванов (101)/(1.1)',
        6 => 'Физика/Петров (205)', 7 => '10:10-11:00',
        8 => 'Русский язык/Иванов (102)/(2.1)', 9 => '5Б', 10 => 'История/Смирнов (301)',
    ];
    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="' . NS_MAIN . '"><sheetData>'
        . '<row r="1">' . cell_xml('A1', 0) . cell_xml('C1', 1) . cell_xml('E1', 0) . cell_xml('G1', 9) . '</row>'
        . '<row r="2">' . cell_xml('A2', 2) . cell_xml('B2', '1', false) . cell_xml('C2', 4) . cell_xml('E2', 2) . cell_xml('F2', '1', false) . cell_xml('G2', 4) . '</row>'
        . '<row r="3">' . cell_xml('A3', 3) . cell_xml('B3', '2', false) . cell_xml('C3', 5) . cell_xml('D3', 6) . cell_xml('E3', 3) . cell_xml('F3', '2', false) . cell_xml('G3', 10) . '</row>'
        . '<row r="4">' . cell_xml('A4', 7) . cell_xml('B4', '3', false) . cell_xml('C4', 8) . '</row>'
        . '</sheetData></worksheet>';
    $shared = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<sst xmlns="' . NS_MAIN . '" count="' . count($strings) . '" uniqueCount="' . count($strings) . '">';
    foreach ($strings as $s) $shared .= '<si><t>' . htmlspecialchars($s) . '</t></si>';
    $shared .= '</sst>';
    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="' . NS_MAIN . '" xmlns:r="' . NS_REL . '"><sheets>'
        . '<sheet name="ПН 02.09" sheetId="1" r:id="rId1"/></sheets></workbook>';
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '</Relationships>';

    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Не удалось создать тестовый XLSX');
    }
    $zip->addFromString('xl/workbook.xml', $workbook);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $rels);
    $zip->addFromString('xl/sharedStrings.xml', $shared);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $zip->close();
}

/* ── Миграция и ключи ── */
$pdo = get_db();
init_db($pdo);
check('user_version = 9', (int)$pdo->query('PRAGMA user_version')->fetchColumn() === 9);
$hasArchive = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='schedule_archive'")->fetchColumn();
check('таблица schedule_archive создана', $hasArchive === 1);

echo "archive_week_key / stats_week_label_from_dates:\n";
check('ключ по источнику', archive_week_key(3, ['2026-09-14', '2026-09-17']) === 's3');
check('ключ по диапазону дат без источника', archive_week_key(0, ['2026-09-17', '2026-09-14']) === 'd_2026-09-14_2026-09-17');
check('заголовок недели с id', stats_week_label_from_dates(1, ['2026-09-02', '2026-09-05']) === '#1 02.09–05.09');
check('заголовок недели одна дата', stats_week_label_from_dates(4, ['2026-09-21']) === '#4 21.09');
check('заголовок без источника', stats_week_label_from_dates(0, ['2026-09-07', '2026-09-12']) === '07.09–12.09');

/* ── Бэкфилл из файла в БД ── */
echo "backfill_archive_from_files:\n";
build_stats_fixture($tmp . '/archive/1_test_2026-09-02_2026-09-02.xlsx');
$res = backfill_archive_from_files($pdo);
check('бэкфилл: 1 неделя', ($res['weeks'] ?? 0) === 1);
check('бэкфилл: 6 уроков', ($res['lessons'] ?? 0) === 6);
check('в БД 6 строк архива', (int)$pdo->query('SELECT COUNT(*) FROM schedule_archive')->fetchColumn() === 6);
check('source_id определён из имени файла', (int)$pdo->query('SELECT source_id FROM schedule_archive LIMIT 1')->fetchColumn() === 1);

/* ── Статистика из БД ── */
echo "collect_teacher_stats (из БД):\n";
$s = collect_teacher_stats($pdo);
check('одна неделя', count($s['weeks']) === 1);
check('уроков всего 6', $s['total']['lessons'] === 6);
check('label недели', ($s['weeks'][0]['label'] ?? '') === '#1 02.09');
check('Иванов: 4 урока', ($s['matrix']['Иванов']['s1'] ?? 0) === 4);
check('Петров: 1 урок', ($s['matrix']['Петров']['s1'] ?? 0) === 1);
check('Смирнов: 1 урок', ($s['matrix']['Смирнов']['s1'] ?? 0) === 1);

$d = $s['details']['Иванов']['s1'] ?? [];
check('детализация: Математика/5А/весь класс = 1', ($d['Математика']['5А'][''] ?? 0) === 1);
check('детализация: Математика/5Б/весь класс = 1', ($d['Математика']['5Б'][''] ?? 0) === 1);
check('детализация: Русский/5А/группа 1.1 = 1', ($d['Русский язык']['5А']['1.1'] ?? 0) === 1);
check('детализация: Русский/5А/группа 2.1 = 1', ($d['Русский язык']['5А']['2.1'] ?? 0) === 1);
check('детализация: Математика в 2 классах', count($d['Математика'] ?? []) === 2);

/* ── Без педагога ── */
archive_lessons($pdo, 1, 's1', [
    ['date' => '2026-09-02', 'day_of_week' => 'среда', 'class_name' => '5А', 'lesson_num' => 9,
     'time_start' => '12:30', 'time_end' => '13:10', 'subject' => 'Классный час', 'teacher' => '',
     'room' => '2', 'parallel_group' => ''],
], gmdate('Y-m-d H:i:s'));
$s = collect_teacher_stats($pdo);
check('уроки без педагога учтены отдельно', $s['no_teacher']['lessons'] === 1);
check('без педагога не попали в разбивку', !isset($s['matrix']['']));

/* ── БД — источник, файл не нужен ── */
unlink($tmp . '/archive/1_test_2026-09-02_2026-09-02.xlsx');
$s = collect_teacher_stats($pdo);
check('статистика читается из БД даже без файла', $s['total']['lessons'] === 1 && count($s['matrix']) === 0);

/* Уборка */
@unlink($tmp . '/schedule.db');
array_map('unlink', glob($tmp . '/archive/*') ?: []);
@rmdir($tmp . '/archive');
@rmdir($tmp);

echo "\nИтого: {$checks} проверок, {$failures} провалено\n";
exit($failures > 0 ? 1 : 0);
