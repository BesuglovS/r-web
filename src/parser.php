<?php
/**
 * Минимальный парсер XLSX для расписания.
 * Читает ZIP-архив, sharedStrings, workbook, sheets.
 * Использует DOMDocument+XPath для надёжного разбора XML с namespace-префиксами.
 */

define('ML_NS', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
define('REL_NS', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

function parse_xlsx(string $filepath): array
{
    $zip = new ZipArchive();
    if ($zip->open($filepath) !== true) {
        throw new RuntimeException("Не удалось открыть XLSX: $filepath");
    }

    $strings = read_shared_strings($zip);
    $sheet_map = read_sheet_map($zip);
    $results = [];

    foreach ($sheet_map as $info) {
        $name = $info['name'];
        $file = $info['file'];
        $data = $zip->getFromName("xl/$file");
        if ($data === false) continue;

        $lessons = parse_sheet($data, $strings, $name);
        $results = array_merge($results, $lessons);
    }

    $zip->close();
    return $results;
}

function xpath_first(DOMXPath $xpath, string $query, ?DOMNode $context = null): ?DOMNode
{
    $list = $xpath->query($query, $context);
    return $list->length > 0 ? $list->item(0) : null;
}

function get_text(DOMXPath $xpath, DOMNode $node): string
{
    $t = xpath_first($xpath, './/s:t', $node);
    return $t ? $t->textContent : '';
}

function read_shared_strings(ZipArchive $zip): array
{
    $data = $zip->getFromName('xl/sharedStrings.xml');
    if ($data === false) return [];

    $doc = new DOMDocument();
    if (!$doc->loadXML($data)) return [];

    $xpath = new DOMXPath($doc);
    $xpath->registerNamespace('s', ML_NS);

    $strings = [];
    $si_list = $xpath->query('//s:si');
    for ($i = 0; $i < $si_list->length; $i++) {
        $strings[] = get_text($xpath, $si_list->item($i));
    }
    return $strings;
}

function read_sheet_map(ZipArchive $zip): array
{
    $wb = $zip->getFromName('xl/workbook.xml');
    if ($wb === false) return [];

    $rels_data = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($rels_data === false) return [];

    $rid_map = [];
    $doc = new DOMDocument();
    if ($doc->loadXML($rels_data)) {
        foreach ($doc->getElementsByTagName('Relationship') as $rel) {
            $rid_map[$rel->getAttribute('Id')] = $rel->getAttribute('Target');
        }
    }

    $doc2 = new DOMDocument();
    if (!$doc2->loadXML($wb)) return [];

    $xpath = new DOMXPath($doc2);
    $xpath->registerNamespace('s', ML_NS);
    $xpath->registerNamespace('r', REL_NS);

    $sheets = [];
    foreach ($xpath->query('//s:sheet') as $sheet) {
        $name = $sheet->getAttribute('name');
        $rid = $sheet->getAttributeNS(REL_NS, 'id');
        $file = $rid_map[$rid] ?? '';
        if ($file) {
            $sheets[] = ['name' => $name, 'file' => $file];
        }
    }
    return $sheets;
}

function col_to_index(string $col): int
{
    $col = strtoupper(trim($col));
    $result = 0;
    for ($i = 0; $i < strlen($col); $i++) {
        $result = $result * 26 + (ord($col[$i]) - ord('A') + 1);
    }
    return $result - 1;
}

function parse_sheet(string $xml_data, array $strings, string $sheet_name): array
{
    $doc = new DOMDocument();
    if (!$doc->loadXML($xml_data)) return [];

    $xpath = new DOMXPath($doc);
    $xpath->registerNamespace('s', ML_NS);

    $rows = [];
    $row_list = $xpath->query('//s:row');
    for ($ri = 0; $ri < $row_list->length; $ri++) {
        $row = $row_list->item($ri);
        $rn = (int)$row->getAttribute('r');
        $cells = [];

        $c_list = $xpath->query('.//s:c', $row);
        for ($ci = 0; $ci < $c_list->length; $ci++) {
            $c = $c_list->item($ci);
            $ref = $c->getAttribute('r');
            $col_letter = preg_replace('/\d+/', '', $ref);
            $col_idx = col_to_index($col_letter);
            $type = $c->getAttribute('t');
            $v = '';

            $v_node = xpath_first($xpath, './/s:v', $c);
            if ($v_node) {
                $v = $v_node->textContent;
                if ($type === 's' && is_numeric($v)) {
                    $idx = (int)$v;
                    $v = $strings[$idx] ?? '';
                }
            }
            $cells[$col_idx] = $v;
        }
        $rows[$rn] = $cells;
    }
    ksort($rows);

    // Handle merged cells: fill empty cells with value from top-left of merge range
    // Only vertical merges (consecutive identical lessons) — skip horizontal to avoid duplicates
    $merge_list = $xpath->query('//s:mergeCells/s:mergeCell');
    if ($merge_list && $merge_list->length > 0) {
        for ($mi = 0; $mi < $merge_list->length; $mi++) {
            $ref = $merge_list->item($mi)->getAttribute('ref');
            if (!preg_match('/^([A-Z]+)(\d+):([A-Z]+)(\d+)$/', $ref, $m)) continue;

            $start_col = col_to_index($m[1]);
            $start_row = (int)$m[2];
            $end_col   = col_to_index($m[3]);
            $end_row   = (int)$m[4];

            // Skip horizontal-only merges
            if ($start_row === $end_row) continue;

            $val = $rows[$start_row][$start_col] ?? '';
            if ($val === '') continue;

            for ($r = $start_row + 1; $r <= $end_row; $r++) {
                if (!isset($rows[$r])) $rows[$r] = [];
                if (empty($rows[$r][$start_col])) {
                    $rows[$r][$start_col] = $val;
                }
            }
        }
    }

    $blocks = extract_blocks($rows);
    $lessons = [];

    foreach ($blocks as $block) {
        $class_name = $block['class'];
        foreach ($block['lessons'] as $lesson) {
            $parsed = parse_lesson_cell($lesson['cell'] ?? '');
            if (!$parsed) continue;

            $lessons[] = [
                'sheet_name'    => $sheet_name,
                'class_name'    => $class_name,
                'lesson_num'    => $lesson['num'],
                'time_start'    => $lesson['time_start'],
                'time_end'      => $lesson['time_end'],
                'subject'       => $parsed['subject'],
                'teacher'       => $parsed['teacher'],
                'room'          => $parsed['room'],
                'parallel_group'=> $parsed['group'],
            ];
        }
    }

    return $lessons;
}

/**
 * Извлекает блоки уроков из строк таблицы.
 * Каждый "пояс" колонок (0-3, 4-7, 8-11, ...) — отдельный класс.
 * Строка-заголовок содержит имена классов в каждом поясе.
 * Строка-урок содержит время, номер и предмет в каждом поясе.
 * Пустая строка — разделитель блоков (все пояса).
 */
function extract_blocks(array $rows): array
{
    $time_pattern = '/^\d{2}:\d{2}-\d{2}:\d{2}$/';
    $day_pattern = '/^(ПОНЕДЕЛЬНИК|ВТОРНИК|СРЕДА|ЧЕТВЕРГ|ПЯТНИЦА|СУББОТА|ВОСКРЕСЕНЬЕ)$/u';
    $class_pattern = '/^\d{1,2}[А-Я]$/u';

    // Each "band" of 4 columns represents one class
    // Band 0: cols 0-3 (A-D), Band 1: cols 4-7 (E-H), etc.
    $bands = [
        ['offset' => 0,  'class' => null, 'lessons' => []],
        ['offset' => 4,  'class' => null, 'lessons' => []],
        ['offset' => 8,  'class' => null, 'lessons' => []],
        ['offset' => 12, 'class' => null, 'lessons' => []],
        ['offset' => 16, 'class' => null, 'lessons' => []],
        ['offset' => 20, 'class' => null, 'lessons' => []],
    ];

    $completed_blocks = [];

    foreach ($rows as $rn => $cells) {
        // Check if this is a header row (any band has day_name + class_name)
        $is_header = false;
        $band_classes = [];
        foreach ($bands as $bi => $band) {
            $go = $band['offset'];
            $cell0 = $cells[$go] ?? '';
            $cell2 = $cells[$go + 2] ?? '';
            if ($cell0 && preg_match($day_pattern, $cell0)
                && $cell2 && preg_match($class_pattern, $cell2)) {
                $band_classes[$bi] = $cell2;
                $is_header = true;
            } else {
                $band_classes[$bi] = null;
            }
        }

        if ($is_header) {
            // Save any active bands that have lessons
            foreach ($bands as $bi => $band) {
                if ($band['class'] && !empty($band['lessons'])) {
                    $completed_blocks[] = [
                        'class'   => $band['class'],
                        'lessons' => $band['lessons'],
                    ];
                }
                $bands[$bi]['class'] = $band_classes[$bi];
                $bands[$bi]['lessons'] = [];
            }
            continue;
        }

        // Check if this is an empty row (no time in any band)
        $has_time = false;
        foreach ($bands as $band) {
            $go = $band['offset'];
            $cell0 = $cells[$go] ?? '';
            if ($cell0 && preg_match($time_pattern, $cell0)) {
                $has_time = true;
                break;
            }
        }

        if (!$has_time) {
            // Separator: save all active bands
            foreach ($bands as $bi => $band) {
                if ($band['class'] && !empty($band['lessons'])) {
                    $completed_blocks[] = [
                        'class'   => $band['class'],
                        'lessons' => $band['lessons'],
                    ];
                    $bands[$bi]['lessons'] = [];
                }
            }
            continue;
        }

        // Data row: extract lessons from each band
        foreach ($bands as $bi => $band) {
            if (!$band['class']) continue;

            $go = $band['offset'];
            $time_cell = $cells[$go] ?? '';
            if (!$time_cell || !preg_match($time_pattern, $time_cell)) continue;

            $num_cell = $cells[$go + 1] ?? '';
            $lesson_cell = $cells[$go + 2] ?? '';
            $alt_cell = $cells[$go + 3] ?? '';

            if (!$lesson_cell && !$alt_cell) continue;

            [$time_start, $time_end] = explode('-', $time_cell);
            $num = is_numeric($num_cell) ? (int)$num_cell : 0;

            if ($lesson_cell) {
                $bands[$bi]['lessons'][] = [
                    'num'        => $num,
                    'time_start' => $time_start,
                    'time_end'   => $time_end,
                    'cell'       => $lesson_cell,
                ];
            }
            if ($alt_cell) {
                $bands[$bi]['lessons'][] = [
                    'num'        => $num,
                    'time_start' => $time_start,
                    'time_end'   => $time_end,
                    'cell'       => $alt_cell,
                ];
            }
        }
    }

    // Save remaining active bands
    foreach ($bands as $bi => $band) {
        if ($band['class'] && !empty($band['lessons'])) {
            $completed_blocks[] = [
                'class'   => $band['class'],
                'lessons' => $band['lessons'],
            ];
        }
    }

    return $completed_blocks;
}

function parse_lesson_cell(string $cell): ?array
{
    $cell = trim($cell);
    if ($cell === '') return null;

    $parts = preg_split('/\//', $cell, -1, PREG_SPLIT_NO_EMPTY);
    if (count($parts) < 2) {
        return [
            'subject' => $cell,
            'teacher' => '',
            'room'    => '',
            'group'   => null,
        ];
    }

    $teacher_part = array_pop($parts);
    $subject_part = implode('/', $parts);

    $teacher = $teacher_part;
    $room = '';
    $group = null;

    if (preg_match('/^(.+?)\s*\(([^)]+)\)\s*$/', $teacher_part, $m)) {
        $teacher = trim($m[1]);
        $room = trim($m[2]);
    }

    if (preg_match('/\(([А-Яа-яёЁ0-9]+\.[А-Яа-яёЁ0-9]+)\)\s*$/', $subject_part, $gm)) {
        $group = $gm[1];
        $subject_part = trim(preg_replace('/\(' . preg_quote($gm[1], '/') . '\)\s*$/', '', $subject_part));
    }

    return [
        'subject' => $subject_part,
        'teacher' => $teacher,
        'room'    => $room,
        'group'   => $group,
    ];
}

function parse_sheet_date(string $sheet_name): array
{
    $day_map = [
        'пн' => 'понедельник', 'вт' => 'вторник', 'ср' => 'среда',
        'чт' => 'четверг',     'пт' => 'пятница', 'сб' => 'суббота',
        'вс' => 'воскресенье',
    ];

    $short = mb_strtolower(mb_substr($sheet_name, 0, 2));
    $day_of_week = $day_map[$short] ?? '';

    if (preg_match('/(\d{1,2})\.(\d{1,2})/', $sheet_name, $dm)) {
        $day = str_pad($dm[1], 2, '0', STR_PAD_LEFT);
        $month = str_pad($dm[2], 2, '0', STR_PAD_LEFT);
        $year = date('Y');
        $date = "$year-$month-$day";
    } else {
        $date = '';
    }

    return ['date' => $date, 'day_of_week' => $day_of_week];
}
