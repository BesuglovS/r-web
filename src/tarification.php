<?php
/**
 * Тарификация: нужное число часов по предметам у педагогов.
 *
 * Источник — файл .xlsx/.xlsm с листом «Тарификация» (столбцы: ФИО учителя,
 * Предмет, Класс, Часы, Методический отдел, Бюджет). Разбирается и хранится
 * в таблице `tarification`; читается страницей педагога
 * (admin_stats_teacher.php).
 */

require_once __DIR__ . '/parser.php';
require_once __DIR__ . '/db.php';

/**
 * «Фамилия Имя Отчество» → «Фамилия И.О.» (формат расписания).
 */
function tarification_short_fio(string $full): string
{
    $parts = preg_split('/\s+/u', trim($full));
    $parts = array_values(array_filter($parts, static fn($p) => $p !== ''));
    if (count($parts) < 2) {
        return $parts[0] ?? '';
    }
    $surname = array_shift($parts);
    $initials = '';
    foreach ($parts as $p) {
        $initials .= mb_substr($p, 0, 1) . '.';
    }
    return $surname . ' ' . $initials;
}

/** Класс без пробелов: «10 Б» → «10Б» (для сопоставления с расписанием). */
function tarification_class_key(string $class): string
{
    return preg_replace('/\s+/u', '', trim($class));
}

/**
 * Ключ предмета для сопоставления: без регистра, лишних пробелов и различия
 * «ё/е». В файлах один и тот же предмет пишется по-разному
 * («Труд (технология)» в расписании и «Труд (Технология)» в тарификации).
 */
function tarification_subject_key(string $subject): string
{
    $s = str_replace(["\xC2\xA0", "\xE2\x80\xAF"], ' ', $subject); // NBSP, узкий NBSP
    $s = preg_replace('/\s+/u', ' ', trim($s));
    $s = mb_strtolower($s, 'UTF-8');
    return str_replace('ё', 'е', $s);
}

/**
 * Совпадает ли класс расписания с тарификацией: точное совпадение ключа либо
 * тарификационный класс с числовым суффиксом подгруппы
 * («7Г» в расписании ↔ «7Г1»/«7Г2» в тарификации).
 */
function tarification_class_matches(array $byClassForSubject, string $scheduleClass): bool
{
    $key = tarification_class_key($scheduleClass);
    if ($key === '') {
        return false;
    }
    if (isset($byClassForSubject[$key])) {
        return true;
    }
    $keyLen = strlen($key);
    foreach ($byClassForSubject as $tarifClass => $_) {
        $tarifClass = (string)$tarifClass;
        if ($tarifClass !== ''
            && strncmp($tarifClass, $key, $keyLen) === 0
            && preg_match('/^\d+$/', substr($tarifClass, $keyLen))) {
            return true;
        }
    }
    return false;
}

/**
 * Читает лист «Тарификация» из файла. Возвращает список записей.
 * Бросает RuntimeException, если файл/лист не читаются.
 */
function parse_tarification_file(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException('Файл тарификации не найден');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Не удалось открыть файл тарификации');
    }

    try {
        $strings = read_shared_strings($zip);
        $target = null;
        foreach (read_sheet_map($zip) as $info) {
            if ($info['name'] === 'Тарификация') {
                $target = $info;
                break;
            }
        }
        if ($target === null) {
            throw new RuntimeException('В файле нет листа «Тарификация»');
        }
        $xml = $zip->getFromName('xl/' . $target['file']);
        if ($xml === false) {
            throw new RuntimeException('Не удалось прочитать лист «Тарификация»');
        }
    } finally {
        $zip->close();
    }

    $doc = new DOMDocument();
    if (!$doc->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT)) {
        throw new RuntimeException('Некорректный XML листа «Тарификация»');
    }
    $xpath = new DOMXPath($doc);
    $xpath->registerNamespace('s', ML_NS);

    $records = [];
    foreach ($xpath->query('//s:row') as $row) {
        $cells = [];
        foreach ($xpath->query('.//s:c', $row) as $c) {
            $colIdx = col_to_index((string)preg_replace('/\d+/', '', $c->getAttribute('r')));
            $type = $c->getAttribute('t');
            $value = '';
            $vNode = xpath_first($xpath, './/s:v', $c);
            if ($vNode) {
                $value = $vNode->textContent;
                if ($type === 's' && is_numeric($value)) {
                    $value = $strings[(int)$value] ?? '';
                }
            } elseif ($type === 'inlineStr') {
                $value = get_text($xpath, $c);
            }
            $value = trim($value);
            if ($value !== '') {
                $cells[$colIdx] = $value;
            }
        }
        if (empty($cells)) {
            continue;
        }

        $teacherFull = $cells[0] ?? '';
        if ($teacherFull === '' || $teacherFull === 'ФИО учителя') {
            continue; // заголовок или пустая строка
        }
        $subject = $cells[1] ?? '';
        if ($subject === '') {
            continue;
        }
        $classRaw = $cells[2] ?? '';
        $hoursRaw = str_replace([' ', ','], ['', '.'], $cells[3] ?? '');
        $hours = is_numeric($hoursRaw) ? (float)$hoursRaw : 0.0;

        $records[] = [
            'teacher'      => tarification_short_fio($teacherFull),
            'teacher_full' => $teacherFull,
            'subject'      => $subject,
            'class_name'   => tarification_class_key($classRaw),
            'class_raw'    => $classRaw,
            'hours'        => $hours,
            'department'   => $cells[4] ?? '',
            'funding'      => $cells[5] ?? '',
        ];
    }

    return $records;
}

/**
 * Полностью заменяет таблицу `tarification` данными из файла.
 * Возвращает: rows, teachers, subjects.
 */
function import_tarification_from_file(string $path, ?PDO $existing_pdo = null): array
{
    $records = parse_tarification_file($path);
    if (empty($records)) {
        throw new RuntimeException('В листе «Тарификация» не найдено строк (проверьте формат)');
    }

    $pdo = $existing_pdo ?? get_db();
    if ($pdo === null) {
        throw new RuntimeException('БД недоступна');
    }
    init_db($pdo);

    $imported_at = gmdate('Y-m-d H:i:s');
    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM tarification');
        $stmt = $pdo->prepare("
            INSERT INTO tarification
                (teacher, teacher_full, subject, class_name, class_raw, hours, department, funding, imported_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($records as $r) {
            $stmt->execute([
                $r['teacher'], $r['teacher_full'], $r['subject'], $r['class_name'],
                $r['class_raw'], $r['hours'], $r['department'], $r['funding'], $imported_at,
            ]);
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

    $teachers = [];
    $subjects = [];
    foreach ($records as $r) {
        $teachers[$r['teacher']] = true;
        $subjects[$r['subject']] = true;
    }

    return [
        'rows'     => count($records),
        'teachers' => count($teachers),
        'subjects' => count($subjects),
    ];
}

/**
 * Тарификация из БД для страницы статистики.
 *
 * Возвращает:
 *   by_subject       — педагог → предмет → часы (сумма по классам)
 *   by_subject_class — педагог → предмет → класс → часы
 *   total            — педагог → всего часов
 *
 * Ключи предметов нормализованы (без регистра/пробелов, ё=е) — см.
 * tarification_subject_key(); сопоставлять тоже нужно через неё.
 */
function collect_tarification(?PDO $existing_pdo = null): array
{
    $bySubject = [];
    $bySubjectClass = [];
    $total = [];

    $pdo = $existing_pdo ?? get_db();
    if ($pdo === null) {
        return ['by_subject' => $bySubject, 'by_subject_class' => $bySubjectClass, 'total' => $total];
    }
    init_db($pdo);

    $rows = $pdo->query("SELECT teacher, subject, class_name, hours FROM tarification");
    foreach ($rows as $r) {
        $teacher = trim((string)$r['teacher']);
        if ($teacher === '') {
            continue;
        }
        $subjectKey = tarification_subject_key((string)$r['subject']);
        $class = trim((string)$r['class_name']);
        $hours = (float)$r['hours'];

        $bySubject[$teacher][$subjectKey] = ($bySubject[$teacher][$subjectKey] ?? 0.0) + $hours;
        $total[$teacher] = ($total[$teacher] ?? 0.0) + $hours;
        if ($class !== '') {
            $bySubjectClass[$teacher][$subjectKey][$class] =
                ($bySubjectClass[$teacher][$subjectKey][$class] ?? 0.0) + $hours;
        }
    }

    return [
        'by_subject'       => $bySubject,
        'by_subject_class' => $bySubjectClass,
        'total'            => $total,
    ];
}
