<?php
/**
 * Статистика по педагогам из БД-архива расписания (таблица schedule_archive).
 *
 * Таблица заполняется при импорте источника (do_import()) и бэкфиллом из
 * файлов архива (backfill_archive_from_files()). Считается КОЛИЧЕСТВО УРОКОВ
 * (не реальные часы по времени занятия) в разрезе недель, предметов, классов
 * и групп. XLSX-файлы в каталоге архива — только резерв для восстановления.
 */

require_once __DIR__ . '/db.php';

/**
 * Читаемый заголовок недели по диапазону дат
 * (source_id=1, [2026-09-02, 2026-09-05] → «#1 02.09–05.09»).
 */
function stats_week_label_from_dates(int $source_id, array $dates): string
{
    $dates = array_values(array_filter($dates));
    if (empty($dates)) {
        return $source_id > 0 ? ('#' . $source_id) : '—';
    }
    sort($dates);
    $fmt = static function (string $d): string {
        return substr($d, 8, 2) . '.' . substr($d, 5, 2);
    };
    $range = $fmt($dates[0]);
    $last = $fmt($dates[count($dates) - 1]);
    if ($last !== $range) {
        $range .= '–' . $last;
    }
    return ($source_id > 0 ? '#' . $source_id . ' ' : '') . $range;
}

/**
 * Собирает статистику из schedule_archive.
 *
 * Возвращает:
 *   weeks   — список недель: key, label, dates, lessons, error
 *   matrix  — педагог → [week_key => количество уроков]
 *   details — педагог → [week_key => [предмет => [класс => [группа => кол-во]]]]
 *   total   — ['lessons'=>int]
 *   no_teacher — уроки без указанного педагога
 *   generated_at — UTC
 */
function collect_teacher_stats(?PDO $existing_pdo = null): array
{
    $empty = [
        'weeks'        => [],
        'matrix'       => [],
        'details'      => [],
        'total'        => ['lessons' => 0],
        'no_teacher'   => ['lessons' => 0],
        'generated_at' => gmdate('Y-m-d H:i:s'),
    ];

    $pdo = $existing_pdo ?? get_db();
    if ($pdo === null) {
        return $empty;
    }
    init_db($pdo);

    // Недели (с датами и числом уроков), хронологически
    $weeks = [];
    $weekRows = $pdo->query("
        SELECT week_key, source_id, MIN(date) AS mn, MAX(date) AS mx,
               COUNT(*) AS lessons, GROUP_CONCAT(DISTINCT date) AS dates_csv
        FROM schedule_archive
        GROUP BY week_key
        ORDER BY mn, week_key
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($weekRows as $r) {
        $dates = ($r['dates_csv'] !== null && $r['dates_csv'] !== '')
            ? array_filter(explode(',', (string)$r['dates_csv']))
            : [];
        sort($dates);
        $weeks[] = [
            'key'     => (string)$r['week_key'],
            'label'   => stats_week_label_from_dates((int)$r['source_id'], $dates),
            'dates'   => $dates,
            'lessons' => (int)$r['lessons'],
            'error'   => null,
        ];
    }

    // Педагог × неделя × предмет × класс × группа
    $matrix = [];
    $details = [];
    $totalLessons = 0;
    $noTeacher = 0;

    $rows = $pdo->query("
        SELECT week_key, subject, class_name, parallel_group, teacher, COUNT(*) AS c
        FROM schedule_archive
        GROUP BY week_key, subject, class_name, parallel_group, teacher
    ");

    foreach ($rows as $r) {
        $weekKey = (string)$r['week_key'];
        $count = (int)$r['c'];
        $totalLessons += $count;

        $teacher = trim((string)$r['teacher']);
        if ($teacher === '') {
            $noTeacher += $count;
            continue;
        }

        $matrix[$teacher][$weekKey] = ($matrix[$teacher][$weekKey] ?? 0) + $count;

        $subject = trim((string)$r['subject']);
        if ($subject === '') {
            $subject = '(без предмета)';
        }
        $class = trim((string)$r['class_name']);
        if ($class === '') {
            $class = '(без класса)';
        }
        $group = trim((string)($r['parallel_group'] ?? ''));

        $details[$teacher][$weekKey][$subject][$class][$group] =
            ($details[$teacher][$weekKey][$subject][$class][$group] ?? 0) + $count;
    }

    uksort($matrix, static function (string $a, string $b): int {
        return strcmp(mb_strtolower($a), mb_strtolower($b));
    });

    return [
        'weeks'        => $weeks,
        'matrix'       => $matrix,
        'details'      => $details,
        'total'        => ['lessons' => $totalLessons],
        'no_teacher'   => ['lessons' => $noTeacher],
        'generated_at' => gmdate('Y-m-d H:i:s'),
    ];
}
