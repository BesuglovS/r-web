<?php
/**
 * CLI-тесты парсера XLSX и вспомогательных функций импорта.
 *
 * Запуск: php tests/parser_test.php
 * Не имеет внешних зависимостей: минимальный XLSX-фикстур собирается
 * на месте через ZipArchive + строковые XML-шаблоны.
 */

require_once __DIR__ . '/../src/parser.php';
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

const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
const NS_REL  = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

function cell_xml(string $ref, string $value, bool $shared = true): string
{
    if ($value === '') return '';
    if ($shared) {
        return '<c r="' . $ref . '" t="s"><v>' . htmlspecialchars($value) . '</v></c>';
    }
    return '<c r="' . $ref . '"><v>' . htmlspecialchars($value) . '</v></c>';
}

/**
 * Собирает минимальный XLSX с листом «ПН 02.09» (и «ВТ» без даты).
 * Формат bands: колонка A — день/время, B — номер урока, C — предмет,
 * D — альтернативный предмет.
 */
function build_test_xlsx(string $path): void
{
    $strings = [
        0 => 'ПОНЕДЕЛЬНИК',
        1 => '5А',
        2 => '9:00-10:45',
        3 => 'Математика/Иванова (204)',
        4 => 'Русский язык/Петрова (101)',
        5 => 'Физика/Сидоров (205)/(1.2)',
        6 => 'ВТОРНИК',
        7 => '6Б',
    ];

    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="' . NS_MAIN . '">'
        . '<sheetData>'
        . '<row r="1">' . cell_xml('A1', 0) . cell_xml('C1', 1) . '</row>'
        . '<row r="2">' . cell_xml('A2', 2) . cell_xml('B2', '1', false) . cell_xml('C2', 3) . '</row>'
        . '<row r="3">' . cell_xml('A3', 2) . cell_xml('B3', '2', false) . cell_xml('C3', 4) . cell_xml('D3', 5) . '</row>'
        . '</sheetData>'
        . '</worksheet>';

    $sheet2 = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="' . NS_MAIN . '"><sheetData>'
        . '<row r="1">' . cell_xml('A1', 6) . cell_xml('C1', 7) . '</row>'
        . '</sheetData></worksheet>';

    $shared = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<sst xmlns="' . NS_MAIN . '" count="' . count($strings) . '" uniqueCount="' . count($strings) . '">';
    foreach ($strings as $s) {
        $shared .= '<si><t>' . htmlspecialchars($s) . '</t></si>';
    }
    $shared .= '</sst>';

    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="' . NS_MAIN . '" xmlns:r="' . NS_REL . '">'
        . '<sheets>'
        . '<sheet name="ПН 02.09" sheetId="1" r:id="rId1"/>'
        . '<sheet name="ВТ" sheetId="2" r:id="rId2"/>'
        . '</sheets>'
        . '</workbook>';

    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
        . '</Relationships>';

    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Не удалось создать тестовый XLSX');
    }
    $zip->addFromString('xl/workbook.xml', $workbook);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $rels);
    $zip->addFromString('xl/sharedStrings.xml', $shared);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $zip->addFromString('xl/worksheets/sheet2.xml', $sheet2);
    $zip->close();
}

/* ── Тесты парсера XLSX ── */
echo "parse_xlsx (собранный фикстур):\n";
$tmp = tempnam(sys_get_temp_dir(), 'xlsx_test_') . '.xlsx';
build_test_xlsx($tmp);
$lessons = parse_xlsx($tmp);
check('лист «ВТ» без даты пропущен, уроки с «ПН 02.09»', !empty($lessons) && $lessons[0]['sheet_name'] === 'ПН 02.09');
check('распознано 3 урока (2 обычных + 1 альт-колонка)', count($lessons) === 3);
check('класс 5А', ($lessons[0]['class_name'] ?? '') === '5А');
check('урок 1: предмет', ($lessons[0]['subject'] ?? '') === 'Математика');
check('урок 1: учитель', ($lessons[0]['teacher'] ?? '') === 'Иванова');
check('урок 1: кабинет', ($lessons[0]['room'] ?? '') === '204');
check('урок 2: альт-колонка с группой (1.2)', ($lessons[2]['parallel_group'] ?? '') === '1.2');
check('урок 2: группа отрезана от предмета', ($lessons[2]['subject'] ?? '') === 'Физика');
check('урок 2: время', ($lessons[2]['time_start'] ?? '') === '9:00');
unlink($tmp);

/* ── parse_sheet_date ── */
echo "parse_sheet_date:\n";
$d = parse_sheet_date('ПН 02.09');
check('день недели', $d['day_of_week'] === 'понедельник');
check('дата распознана (YYYY-MM-DD)', (bool)preg_match('/^\d{4}-09-02$/', $d['date']));
check('мусорная дата отсекается', parse_sheet_date('ПН 45.67')['date'] === '');
check('лист без даты', parse_sheet_date('вт')['date'] === '');
check('31.02 (несуществующая) отсекается', parse_sheet_date('СР 31.02')['date'] === '');

/* ── parse_lesson_cell ── */
echo "parse_lesson_cell:\n";
$p = parse_lesson_cell('Химия/Кузнецова (12)');
check('кабинет из скобок', $p['room'] === '12' && $p['teacher'] === 'Кузнецова');
$p = parse_lesson_cell('Физика/Иванов (205)/(1.1)');
check('группа распознана', $p['group'] === '1.1');
check('кабинет при наличии группы', $p['room'] === '205');
$p = parse_lesson_cell('Физкультура/Петров/Сидоров (зал)');
check('несколько «/»: последнее — учитель', $p['teacher'] === 'Сидоров' && $p['subject'] === 'Физкультура/Петров');
check('пустая ячейка → null', parse_lesson_cell('') === null);
check('предмет без учителя', parse_lesson_cell('Классный час')['teacher'] === '');

/* ── Вспомогательные функции импорта ── */
echo "import helpers:\n";
check('SSRF: злой суффикс-хост отвергнут', !is_allowed_download_host('https://evil-yandex.attacker.com/f'));
check('SSRF: точный хост принят', is_allowed_download_host('https://downloader.disk.yandex.ru/xy'));
check('SSRF: не-HTTPS отвергнут', !is_allowed_download_host('http://downloader.disk.yandex.ru/xy'));
check('SSRF: не-яндекс отвергнут', !is_allowed_download_host('https://example.com/f'));

$many = array_map(fn($i) => sprintf('2026-%02d-%02d', intdiv($i, 28) + 1, $i % 28 + 1), range(1, 600));
$batches = date_batches($many);
check('батчинг: 600 уникальных дат → 2 батча по ≤500', count($batches) === 2 && max(array_map('count', $batches)) <= 500);
check('батчинг: даты уникализируются', count(date_batches(['2026-01-01', '2026-01-01', '2026-01-02'])) === 1);
check('батчинг: пустой список', date_batches([]) === []);

echo "\nИтого: {$checks} проверок, {$failures} провалено\n";
exit($failures > 0 ? 1 : 0);