<?php
/**
 * Админ-страница: статистика по педагогам из архива исходных XLSX.
 *
 * Считается количество УРОКОВ каждого педагога, сгруппированное по неделям
 * (каждый файл архива — неделя). Источник данных — каталог архива
 * (get_archive_dir(), см. src/import.php). Доступна из admin.php.
 */
require_once __DIR__ . '/src/auth.php';
require_admin();

// Динамическая страница с сессией — без кэша
header('Cache-Control: no-store');

require_once __DIR__ . '/src/stats.php';

$stats = collect_teacher_stats();

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    /** Нейтрализация CSV-формул (значения, начинающиеся с = + - @). */
    $csvCell = static function ($v): string {
        $v = (string)$v;
        if ($v !== '' && strpbrk($v[0], '=+-@') !== false) {
            $v = "'" . $v;
        }
        return $v;
    };

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="teacher-lessons.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM для Excel
    $head = ['Педагог'];
    foreach ($stats['weeks'] as $w) {
        $head[] = $w['label'];
    }
    $head[] = 'Итого';
    fputcsv($out, array_map($csvCell, $head), ';', '"', '\\');
    foreach ($stats['matrix'] as $teacher => $cells) {
        $row = [$teacher];
        $tl = 0;
        foreach ($stats['weeks'] as $w) {
            $n = (int)($cells[$w['key']] ?? 0);
            $row[] = (string)$n;
            $tl += $n;
        }
        $row[] = (string)$tl;
        fputcsv($out, array_map($csvCell, $row), ';', '"', '\\');
    }
    fclose($out);
    exit;
}

$teachersCount = count($stats['matrix']);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Админ — Статистика педагогов</title>
    <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
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
        .container { max-width: 1400px; margin: 0 auto; }
        h1 { font-size: 1.6rem; margin-bottom: 1.5rem; }
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
        .card h2 { font-size: 1.1rem; margin-bottom: 1rem; color: #94a3b8; }

        .summary { display: flex; gap: 1rem; flex-wrap: wrap; }
        .stat {
            flex: 1 1 160px;
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: .5rem;
            padding: 1rem 1.25rem;
        }
        .stat .val { font-size: 1.6rem; font-weight: 700; color: #e2e8f0; }
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
        tbody th.teacher {
            text-align: left;
            font-weight: 500;
            color: #e2e8f0;
            position: sticky;
            left: 0;
            background: #1e293b;
            z-index: 1;
        }
        tbody tr:hover td, tbody tr:hover th.teacher { background: #243449; }
        tbody tr[data-href] { cursor: pointer; }
        tbody th.teacher a { color: inherit; text-decoration: none; }
        tbody tr:hover th.teacher a { text-decoration: underline; }
        tfoot td, tfoot th {
            font-weight: 700;
            color: #cbd5e1;
            border-top: 2px solid #475569;
            position: sticky;
            bottom: 0;
            background: #1e293b;
        }
        tfoot th.teacher { text-align: left; }
        td.total, th.total { color: #93c5fd; font-weight: 700; }
        .zero { color: #475569; }

        .hint { color: #64748b; font-size: .8rem; margin-top: .75rem; }
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
        .err { color: #fca5a5; font-size: .78rem; }

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
            <h1>Статистика педагогов</h1>
            <div class="links">
                <a href="admin.php">&larr; Импорт расписания</a>
                <a href="admin_edit.php">Правки</a>
                <a href="admin.php?logout">Выйти</a>
            </div>
        </div>

        <div class="card">
            <div class="summary">
                <div class="stat"><div class="val"><?= count($stats['weeks']) ?></div><div class="lbl">недель в архиве</div></div>
                <div class="stat"><div class="val"><?= $teachersCount ?></div><div class="lbl">педагогов</div></div>
                <div class="stat"><div class="val"><?= (int)$stats['total']['lessons'] ?></div><div class="lbl">уроков всего</div></div>
            </div>
            <?php if (!empty($stats['no_teacher']['lessons'])): ?>
                <p class="hint">
                    Уроков без указанного педагога: <?= (int)$stats['no_teacher']['lessons'] ?> — в разбивке не учтены.
                </p>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>Недели в архиве</h2>
            <?php if (empty($stats['weeks'])): ?>
                <p class="hint">В архиве нет файлов XLSX. Воспользуйтесь кнопкой «Скачать все недели в архив» на странице импорта.</p>
            <?php else: ?>
                <div class="tablewrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Неделя</th>
                                <th>Даты</th>
                                <th>Уроков</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stats['weeks'] as $w): ?>
                                <tr>
                                    <th class="teacher"><?= htmlspecialchars($w['label']) ?></th>
                                    <td style="text-align:left;"><?= htmlspecialchars(implode(', ', array_map(static function ($d) {
                                        $p = explode('-', $d);
                                        return $p[2] . '.' . $p[1];
                                    }, $w['dates']))) ?></td>
                                    <td><?= (int)$w['lessons'] ?></td>
                                </tr>
                                <?php if (!empty($w['error'])): ?>
                                    <tr><td colspan="3" class="err">Ошибка чтения: <?= htmlspecialchars($w['error']) ?></td></tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>Уроки педагогов по неделям</h2>
            <?php if (empty($stats['matrix'])): ?>
                <p class="hint">Данных нет.</p>
            <?php else: ?>
                <div class="filters">
                    <input type="text" id="teacherFilter" placeholder="Фильтр по фамилии…" autocomplete="off">
                    <a class="link-button" href="admin_stats.php?export=csv">Скачать CSV</a>
                </div>
                <div class="tablewrap">
                    <table id="teacherTable">
                        <thead>
                            <tr>
                                <th>Педагог</th>
                                <?php foreach ($stats['weeks'] as $w): ?>
                                    <th title="<?= htmlspecialchars($w['label']) ?>"><?= htmlspecialchars($w['label']) ?></th>
                                <?php endforeach; ?>
                                <th>Итого</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stats['matrix'] as $teacher => $cells): ?>
                                <?php
                                    $tl = 0;
                                    $href = 'admin_stats_teacher.php?teacher=' . rawurlencode($teacher);
                                ?>
                                <tr data-teacher="<?= htmlspecialchars(mb_strtolower($teacher)) ?>" data-href="<?= htmlspecialchars($href) ?>">
                                    <th class="teacher"><a href="<?= htmlspecialchars($href) ?>"><?= htmlspecialchars($teacher) ?></a></th>
                                    <?php foreach ($stats['weeks'] as $w): ?>
                                        <?php $n = (int)($cells[$w['key']] ?? 0); $tl += $n; ?>
                                        <td><?= $n > 0 ? (int)$n : '<span class="zero">—</span>' ?></td>
                                    <?php endforeach; ?>
                                    <td class="total"><?= (int)$tl ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <th class="teacher">Итого по неделе</th>
                                <?php foreach ($stats['weeks'] as $w): ?>
                                    <td><?= (int)$w['lessons'] ?></td>
                                <?php endforeach; ?>
                                <td class="total"><?= (int)$stats['total']['lessons'] ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <p class="hint">В ячейке — количество уроков педагога за неделю. «—» — в эту неделю уроков не было. Нажмите на строку педагога — откроется детализация по неделям и предметам.</p>
            <?php endif; ?>
        </div>
    </div>

    <script src="admin_stats.js?v=1"></script>
</body>
</html>
