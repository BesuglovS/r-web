<?php
/**
 * Админ-страница: тройки «педагог — предмет — класс» из архива расписания,
 * которых нет в тарификации.
 *
 * Помогает найти расхождения: класс, в котором педагог реально ведёт предмет,
 * но этого нет в файле «Тарификация» (или названия/ФИО/класс не совпали).
 * Данные — `schedule_archive` (src/stats.php) и `tarification` (src/tarification.php).
 */
require_once __DIR__ . '/src/auth.php';
require_admin();

// Динамическая страница с сессией — без кэша
header('Cache-Control: no-store');

require_once __DIR__ . '/src/stats.php';
require_once __DIR__ . '/src/tarification.php';

$schedule = collect_teacher_stats();
$tarification = collect_tarification();

// педагог → предмет → класс → уроки/недели, где нет совпадения в тарификации
$unmatched = [];
$teachersMissing = []; // педагоги, которых вообще нет в тарификации
$scheduleTeachers = [];

foreach ($schedule['matrix'] as $teacher => $_) {
    $tarifSubjects = $tarification['by_subject'][$teacher] ?? null;
    $teacherInTarif = $tarifSubjects !== null;
    if (!$teacherInTarif) {
        $teachersMissing[$teacher] = true;
    }
    $scheduleTeachers[$teacher] = true;
    $tarifByClass = $tarification['by_subject_class'][$teacher] ?? [];

    foreach (($schedule['details'][$teacher] ?? []) as $weekKey => $subjects) {
        foreach ($subjects as $subject => $classes) {
            $subjectKey = tarification_subject_key($subject);
            // Есть ли предмет у педагога в тарификации? Если да — сверяем класс.
            $classMap = ($teacherInTarif && isset($tarifSubjects[$subjectKey]))
                ? ($tarifByClass[$subjectKey] ?? [])
                : null;

            foreach ($classes as $class => $groups) {
                if ($classMap !== null && tarification_class_matches($classMap, $class)) {
                    continue; // тройка найдена в тарификации
                }
                $unmatched[$teacher][$subject][$class]['lessons'] =
                    ($unmatched[$teacher][$subject][$class]['lessons'] ?? 0) + array_sum($groups);
                $unmatched[$teacher][$subject][$class]['weeks'][$weekKey] = true;
            }
        }
    }
}

// Плоский список для вывода и CSV
$rows = [];
foreach ($unmatched as $teacher => $subjects) {
    $subjectNames = array_keys($subjects);
    usort($subjectNames, static fn($a, $b) => strcmp(mb_strtolower($a), mb_strtolower($b)));
    foreach ($subjectNames as $subject) {
        $classNames = array_keys($subjects[$subject]);
        sort($classNames, SORT_NATURAL);
        foreach ($classNames as $class) {
            $rows[] = [
                'teacher'  => $teacher,
                'subject'  => $subject,
                'class'    => $class,
                'lessons'  => (int)$subjects[$subject][$class]['lessons'],
                'weeks'    => count($subjects[$subject][$class]['weeks']),
                'in_tarif' => !isset($teachersMissing[$teacher]),
            ];
        }
    }
}

$triplesCount = count($rows);
$teachersAffected = count($unmatched);

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $csvCell = static function ($v): string {
        $v = (string)$v;
        if ($v !== '' && strpbrk($v[0], '=+-@') !== false) {
            $v = "'" . $v;
        }
        return $v;
    };
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="unmatched-tarification.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM для Excel
    fputcsv($out, ['Педагог', 'Предмет', 'Класс', 'Уроков', 'Недель', 'Педагог есть в тарификации'], ';', '"', '\\');
    foreach ($rows as $r) {
        fputcsv($out, array_map($csvCell, [
            $r['teacher'], $r['subject'], $r['class'], $r['lessons'], $r['weeks'],
            $r['in_tarif'] ? 'да' : 'нет',
        ]), ';', '"', '\\');
    }
    fclose($out);
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Админ — Расхождения с тарификацией</title>
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
        .container { max-width: 1100px; margin: 0 auto; }
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
            flex: 1 1 160px;
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: .5rem;
            padding: 1rem 1.25rem;
        }
        .stat .val { font-size: 1.5rem; font-weight: 700; color: #e2e8f0; }
        .stat .lbl { font-size: .8rem; color: #64748b; margin-top: .25rem; }

        .tablewrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: .82rem; }
        th, td { padding: .5rem .6rem; text-align: left; border-bottom: 1px solid #334155; white-space: nowrap; }
        th.num, td.num { text-align: right; }
        thead th { color: #94a3b8; font-weight: 600; position: sticky; top: 0; background: #1e293b; z-index: 2; }
        tbody tr:hover td { background: #243449; }

        .badge { display: inline-block; padding: .1rem .5rem; border-radius: .75rem; font-size: .72rem; }
        .badge-no { background: #78350f; color: #fde68a; }
        .badge-yes { background: #1e3a5f; color: #bfdbfe; }

        .filters { display: flex; gap: .75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
        .filters input[type="text"] {
            padding: .55rem .8rem;
            border: 1px solid #334155;
            border-radius: .5rem;
            background: #0f172a;
            color: #e2e8f0;
            font-size: .9rem;
            min-width: 220px;
        }
        .filters input:focus { outline: none; border-color: #3b82f6; }
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
        .hint { color: #64748b; font-size: .8rem; margin-top: .75rem; }

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
            <h1>Расхождения с тарификацией</h1>
            <div class="links">
                <a href="admin_stats.php">&larr; Статистика педагогов</a>
                <a href="admin.php">Импорт</a>
                <a href="admin.php?logout">Выйти</a>
            </div>
        </div>

        <div class="card">
            <div class="summary">
                <div class="stat"><div class="val"><?= (int)$triplesCount ?></div><div class="lbl">троек «педагог — предмет — класс» без тарификации</div></div>
                <div class="stat"><div class="val"><?= (int)$teachersAffected ?></div><div class="lbl">педагогов затронуто</div></div>
                <div class="stat"><div class="val"><?= count($scheduleTeachers) ?></div><div class="lbl">педагогов в расписании</div></div>
                <div class="stat"><div class="val"><?= count($teachersMissing) ?></div><div class="lbl">педагогов нет в тарификации</div></div>
            </div>
            <p class="hint">
                Классы, в которых педагог ведёт предмет по расписанию, но этой тройки нет
                в файле «Тарификация». Предмет сравнивается без учёта регистра, пробелов и
                «ё/е»; класс — без пробелов (подгруппы «7Г1»/«7Г2» засчитываются как «7Г»).
                Если педагога нет в тарификации вовсе — все его тройки попадают сюда (пометка «нет»).
            </p>
        </div>

        <div class="card">
            <h2>Не найдено в тарификации</h2>
            <?php if (empty($rows)): ?>
                <p class="hint">Расхождений нет — все тройки «педагог — предмет — класс» найдены в тарификации.</p>
            <?php else: ?>
                <div class="filters">
                    <input type="text" id="teacherFilter" placeholder="Фильтр по педагогу, предмету или классу…" autocomplete="off">
                    <a class="link-button" href="admin_stats_unmatched.php?export=csv">Скачать CSV</a>
                </div>
                <div class="tablewrap">
                    <table id="teacherTable">
                        <thead>
                            <tr>
                                <th>Педагог</th>
                                <th>Предмет</th>
                                <th>Класс</th>
                                <th class="num">Уроков</th>
                                <th class="num">Недель</th>
                                <th>Педагог в тарификации</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r): ?>
                                <tr data-teacher="<?= htmlspecialchars(mb_strtolower($r['teacher'] . ' ' . $r['subject'] . ' ' . $r['class'])) ?>">
                                    <td><?= htmlspecialchars($r['teacher']) ?></td>
                                    <td><?= htmlspecialchars($r['subject']) ?></td>
                                    <td><?= htmlspecialchars($r['class']) ?></td>
                                    <td class="num"><?= (int)$r['lessons'] ?></td>
                                    <td class="num"><?= (int)$r['weeks'] ?></td>
                                    <td>
                                        <?php if ($r['in_tarif']): ?>
                                            <span class="badge badge-yes">да</span>
                                        <?php else: ?>
                                            <span class="badge badge-no">нет</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="admin_stats.js?v=1"></script>
</body>
</html>
