<?php
/**
 * Админ-страница: детализация уроков одного педагога по неделям, предметам,
 * классам и группам (параллелям) внутри класса.
 *
 * Открывается кликом по строке педагога на admin_stats.php
 * (admin_stats_teacher.php?teacher=<ФИО>). Данные — из архива XLSX
 * (src/stats.php → collect_teacher_stats()).
 */
require_once __DIR__ . '/src/auth.php';
require_admin();

// Динамическая страница с сессией — без кэша
header('Cache-Control: no-store');

require_once __DIR__ . '/src/stats.php';

$stats = collect_teacher_stats();
$teacher = trim((string)($_GET['teacher'] ?? ''));
$found = $teacher !== '' && isset($stats['matrix'][$teacher]);

$weeks = $stats['weeks'];
$detail = $found ? ($stats['details'][$teacher] ?? []) : [];

/** Подпись группы: пустая = урок для всего класса. */
function stats_group_label(string $group): string
{
    return $group === '' ? 'весь класс' : ('группа ' . $group);
}

// Предмет → класс → группа (суммарно по всем неделям)
$subjectTotals = [];
$subjectClasses = [];   // subject => class => group => всего уроков
foreach ($detail as $weekKey => $subjects) {
    foreach ($subjects as $subject => $classes) {
        foreach ($classes as $class => $groups) {
            foreach ($groups as $group => $n) {
                $subjectTotals[$subject] = ($subjectTotals[$subject] ?? 0) + (int)$n;
                $subjectClasses[$subject][$class][$group] = ($subjectClasses[$subject][$class][$group] ?? 0) + (int)$n;
            }
        }
    }
}

$classTotal = static function (string $subject, string $class) use ($subjectClasses): int {
    return array_sum($subjectClasses[$subject][$class] ?? []);
};
$groupMeaningful = static function (array $groups): bool {
    // Разбивку группы показываем, если это не единственный «весь класс»
    return !(count($groups) === 1 && array_key_first($groups) === '');
};
$hasBreakdown = static function (array $classes) use ($groupMeaningful): bool {
    if (count($classes) > 1) {
        return true;
    }
    foreach ($classes as $groups) {
        if ($groupMeaningful($groups)) {
            return true;
        }
    }
    return false;
};

// Сортировки: предметы и классы — по убыванию уроков, затем по имени; группы так же
$subjects = array_keys($subjectTotals);
usort($subjects, static function (string $a, string $b) use ($subjectTotals): int {
    return ($subjectTotals[$b] <=> $subjectTotals[$a]) ?: strcmp(mb_strtolower($a), mb_strtolower($b));
});
foreach ($subjects as $subject) {
    uksort($subjectClasses[$subject], static function (string $a, string $b) use ($subjectClasses, $subject): int {
        $ta = array_sum($subjectClasses[$subject][$a] ?? []);
        $tb = array_sum($subjectClasses[$subject][$b] ?? []);
        return ($tb <=> $ta) ?: strcmp(mb_strtolower($a), mb_strtolower($b));
    });
    foreach ($subjectClasses[$subject] as $class => $groups) {
        uksort($subjectClasses[$subject][$class], static function (string $a, string $b) use ($subjectClasses, $subject, $class): int {
            return ($subjectClasses[$subject][$class][$b] <=> $subjectClasses[$subject][$class][$a])
                ?: strcmp(mb_strtolower($a), mb_strtolower($b));
        });
    }
}

$grandTotal = array_sum($subjectTotals);
$classSet = [];
$groupSet = [];
foreach ($subjectClasses as $subject => $classes) {
    foreach ($classes as $class => $groups) {
        $classSet[$class] = true;
        foreach ($groups as $group => $_) {
            $groupSet[$class . "\x1F" . $group] = true;
        }
    }
}

// Уроки предмета за неделю и по педагогу за неделю
$subjectWeek = static function (string $subject, string $weekKey) use ($detail): int {
    $sum = 0;
    foreach (($detail[$weekKey][$subject] ?? []) as $groups) {
        $sum += array_sum($groups);
    }
    return $sum;
};
$classWeek = static function (string $subject, string $class, string $weekKey) use ($detail): int {
    return array_sum($detail[$weekKey][$subject][$class] ?? []);
};
$weekTotals = [];
foreach ($weeks as $w) {
    $sum = 0;
    foreach (($detail[$w['key']] ?? []) as $classes) {
        foreach ($classes as $groups) {
            $sum += array_sum($groups);
        }
    }
    $weekTotals[$w['key']] = $sum;
}

if (isset($_GET['export']) && $_GET['export'] === 'csv' && $found) {
    $csvCell = static function ($v): string {
        $v = (string)$v;
        if ($v !== '' && strpbrk($v[0], '=+-@') !== false) {
            $v = "'" . $v;
        }
        return $v;
    };

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="teacher-detail.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM для Excel
    $head = ['Педагог', 'Предмет', 'Класс', 'Группа'];
    foreach ($weeks as $w) {
        $head[] = $w['label'];
    }
    $head[] = 'Итого';
    fputcsv($out, array_map($csvCell, $head), ';', '"', '\\');

    foreach ($subjects as $subject) {
        $classes = $subjectClasses[$subject];
        if (!$hasBreakdown($classes)) {
            $class = (string)array_key_first($classes);
            $group = (string)array_key_first($classes[$class]);
            $row = [$teacher, $subject, $class, stats_group_label($group)];
            foreach ($weeks as $w) {
                $row[] = (string)(int)($detail[$w['key']][$subject][$class][$group] ?? 0);
            }
            $row[] = (string)$subjectTotals[$subject];
            fputcsv($out, array_map($csvCell, $row), ';', '"', '\\');
            continue;
        }

        // итог по предмету
        $row = ['', $subject, '', 'итого по предмету'];
        foreach ($weeks as $w) {
            $row[] = (string)$subjectWeek($subject, $w['key']);
        }
        $row[] = (string)$subjectTotals[$subject];
        fputcsv($out, array_map($csvCell, $row), ';', '"', '\\');

        foreach ($classes as $class => $groups) {
            if (count($classes) > 1) {
                $row = ['', '', $class, 'итого по классу'];
                foreach ($weeks as $w) {
                    $row[] = (string)$classWeek($subject, $class, $w['key']);
                }
                $row[] = (string)$classTotal($subject, $class);
                fputcsv($out, array_map($csvCell, $row), ';', '"', '\\');
            }
            if ($groupMeaningful($groups)) {
                foreach ($groups as $group => $groupTotal) {
                    $row = ['', '', $class, stats_group_label($group)];
                    foreach ($weeks as $w) {
                        $row[] = (string)(int)($detail[$w['key']][$subject][$class][$group] ?? 0);
                    }
                    $row[] = (string)$groupTotal;
                    fputcsv($out, array_map($csvCell, $row), ';', '"', '\\');
                }
            }
        }
    }

    $totalRow = ['Итого', '', '', ''];
    foreach ($weeks as $w) {
        $totalRow[] = (string)$weekTotals[$w['key']];
    }
    $totalRow[] = (string)$grandTotal;
    fputcsv($out, array_map($csvCell, $totalRow), ';', '"', '\\');
    fclose($out);
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Админ — Детализация педагога</title>
    <link rel="icon" href="favicon.ico?v=2" type="image/x-icon">
    <link rel="icon" href="favicon.svg?v=2" type="image/svg+xml">
    <link rel="apple-touch-icon" href="apple-touch-icon.png?v=2">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            min-height: 100vh;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0f172a;
            color: #e2e8f0;
            padding: 2rem;
        }
        .container { max-width: 1200px; margin: 0 auto; }
        h1 { font-size: 1.5rem; margin-bottom: 1.5rem; }
        .top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; flex-wrap: wrap; gap: .5rem; }
        .top-bar .links { display: flex; gap: 1rem; align-items: center; }
        .top-bar a { color: #94a3b8; text-decoration: none; font-size: .9rem; white-space: nowrap; }
        .top-bar a:hover { color: #e2e8f0; }

        .card {
            background: #1e293b;
            border-radius: .75rem;
            padding: 1.5rem 2rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 12px rgba(0,0,0,.2);
        }
        .card h2 { font-size: 1.05rem; margin-bottom: 1rem; color: #94a3b8; }

        .summary { display: flex; gap: 1rem; flex-wrap: wrap; }
        .stat {
            flex: 1 1 140px;
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: .5rem;
            padding: 1rem 1.25rem;
        }
        .stat .val { font-size: 1.5rem; font-weight: 700; color: #e2e8f0; }
        .stat .lbl { font-size: .8rem; color: #64748b; margin-top: .25rem; }

        .tablewrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: .82rem; }
        th, td { padding: .5rem .6rem; text-align: right; border-bottom: 1px solid #334155; white-space: nowrap; }
        th:first-child, td:first-child { text-align: left; }
        thead th {
            color: #94a3b8;
            font-weight: 600;
            position: sticky;
            top: 0;
            background: #1e293b;
            z-index: 2;
        }
        tbody th.subject {
            text-align: left;
            font-weight: 600;
            color: #e2e8f0;
            position: sticky;
            left: 0;
            background: #1e293b;
            z-index: 1;
            white-space: normal;
            min-width: 220px;
        }
        tbody tr.class-row th.subject { font-weight: 500; color: #cbd5e1; padding-left: 1.4rem; }
        tbody tr.class-row td { color: #cbd5e1; }
        tbody tr.group-row th.subject { font-weight: 400; color: #94a3b8; padding-left: 2.8rem; }
        tbody tr.group-row td { color: #94a3b8; }
        tbody tr:hover td, tbody tr:hover th.subject { background: #243449; }
        tfoot td, tfoot th {
            font-weight: 700;
            color: #cbd5e1;
            border-top: 2px solid #475569;
            position: sticky;
            bottom: 0;
            background: #1e293b;
        }
        tfoot th.subject { text-align: left; }
        td.total, th.total { color: #93c5fd; font-weight: 700; }
        .zero { color: #475569; }

        .hint { color: #64748b; font-size: .8rem; margin-top: .75rem; }
        .link-button {
            display: inline-block;
            padding: .5rem 1rem;
            border-radius: .5rem;
            background: #334155;
            color: #e2e8f0;
            text-decoration: none;
            font-size: .85rem;
        }
        .link-button:hover { background: #475569; }

        @media (max-width: 800px) {
            body { padding: 1rem; }
            .card { padding: 1rem; }
            table { font-size: .75rem; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="top-bar">
            <h1><?= $found ? htmlspecialchars($teacher) : 'Педагог не найден' ?></h1>
            <div class="links">
                <a href="admin_stats.php">&larr; Статистика педагогов</a>
                <a href="admin_edit.php">Правки</a>
                <a href="admin.php?logout">Выйти</a>
            </div>
        </div>

        <?php if (!$found): ?>
            <div class="card">
                <p class="hint">Педагог «<?= htmlspecialchars($teacher) ?>» не найден в архиве.</p>
                <p style="margin-top:1rem;"><a class="link-button" href="admin_stats.php">К списку педагогов</a></p>
            </div>
        <?php else: ?>
            <div class="card">
                <div class="summary">
                    <div class="stat"><div class="val"><?= (int)$grandTotal ?></div><div class="lbl">уроков всего</div></div>
                    <div class="stat"><div class="val"><?= count($subjects) ?></div><div class="lbl">предметов</div></div>
                    <div class="stat"><div class="val"><?= count($classSet) ?></div><div class="lbl">классов</div></div>
                    <div class="stat"><div class="val"><?= count($groupSet) ?></div><div class="lbl">групп (класс + подгруппа)</div></div>
                    <div class="stat"><div class="val"><?= count(array_filter($weekTotals, static fn($n) => $n > 0)) ?></div><div class="lbl">недель с уроками</div></div>
                </div>
            </div>

            <div class="card">
                <h2>Уроки по неделям, предметам, классам и группам</h2>
                <?php if (empty($subjects)): ?>
                    <p class="hint">Данных нет.</p>
                <?php else: ?>
                    <div style="margin-bottom:1rem;">
                        <a class="link-button" href="admin_stats_teacher.php?teacher=<?= rawurlencode($teacher) ?>&amp;export=csv">Скачать CSV</a>
                    </div>
                    <div class="tablewrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Предмет / класс / группа</th>
                                    <?php foreach ($weeks as $w): ?>
                                        <th title="<?= htmlspecialchars($w['label']) ?>"><?= htmlspecialchars($w['label']) ?></th>
                                    <?php endforeach; ?>
                                    <th>Итого</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($subjects as $subject): ?>
                                    <?php $classes = $subjectClasses[$subject]; ?>
                                    <tr class="subject-row">
                                        <th class="subject"><?= htmlspecialchars($subject) ?></th>
                                        <?php foreach ($weeks as $w): ?>
                                            <?php $n = $subjectWeek($subject, $w['key']); ?>
                                            <td><?= $n > 0 ? (int)$n : '<span class="zero">—</span>' ?></td>
                                        <?php endforeach; ?>
                                        <td class="total"><?= (int)$subjectTotals[$subject] ?></td>
                                    </tr>
                                    <?php if ($hasBreakdown($classes)): ?>
                                        <?php foreach ($classes as $class => $groups): ?>
                                            <tr class="class-row">
                                                <th class="subject">↳ <?= htmlspecialchars($class) ?></th>
                                                <?php foreach ($weeks as $w): ?>
                                                    <?php $n = $classWeek($subject, $class, $w['key']); ?>
                                                    <td><?= $n > 0 ? (int)$n : '<span class="zero">—</span>' ?></td>
                                                <?php endforeach; ?>
                                                <td><?= (int)$classTotal($subject, $class) ?></td>
                                            </tr>
                                            <?php if ($groupMeaningful($groups)): ?>
                                                <?php foreach ($groups as $group => $groupTotal): ?>
                                                    <tr class="group-row">
                                                        <th class="subject">↳ <?= htmlspecialchars(stats_group_label($group)) ?></th>
                                                        <?php foreach ($weeks as $w): ?>
                                                            <?php $n = (int)($detail[$w['key']][$subject][$class][$group] ?? 0); ?>
                                                            <td><?= $n > 0 ? (int)$n : '<span class="zero">—</span>' ?></td>
                                                        <?php endforeach; ?>
                                                        <td><?= (int)$groupTotal ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th class="subject">Итого по неделе</th>
                                    <?php foreach ($weeks as $w): ?>
                                        <td><?= (int)$weekTotals[$w['key']] ?></td>
                                    <?php endforeach; ?>
                                    <td class="total"><?= (int)$grandTotal ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <p class="hint">Предмет — сумма по классам; класс — сумма по группам; ниже строки групп, если класс делится на подгруппы. «—» — уроков не было.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
