<?php
/**
 * Страница изменений конкретного импорта.
 * Открывается кликом по строке журнала в admin.php (вкладки «Импорты»/«Проверки»).
 * Параметр at — время импорта в UTC (Y-m-d H:i:s). Изменения выбираются по
 * ВЕРСИИ (уникальна на импорт), а не по точному совпадению changed_at:
 * несколько источников, импортированных в одну секунду, делят одну метку
 * времени — по точному совпадению их изменения смешались бы / терялись.
 */
require_once __DIR__ . '/src/auth.php';
require_admin();
require_once __DIR__ . '/src/db.php';

// Динамическая страница — без кэша
header('Cache-Control: no-store');

$at = (string)($_GET['at'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $at)) {
    header('Location: admin.php');
    exit;
}

$changes = [];
$pdo = get_db();
if ($pdo !== null) {
    try {
        init_db($pdo);
        // 1) находим все версии, у которых changed_at = at (обычно одна),
        // 2) берём ВСЕ изменения этих версий — даже если часть из них
        //    записана с чуть отличным changed_at (cleanup_stale_dates и т.п.)
        $vstmt = $pdo->prepare("SELECT DISTINCT version FROM schedule_history WHERE changed_at = :at");
        $vstmt->execute([':at' => $at]);
        $versions = $vstmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($versions)) {
            $placeholders = implode(',', array_fill(0, count($versions), '?'));
            $stmt = $pdo->prepare(
                "SELECT * FROM schedule_history WHERE version IN ($placeholders) ORDER BY date, class_name, lesson_num"
            );
            $stmt->execute($versions);
            $changes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        // показываем страницу без данных, но причину оставляем в логе сервера
        error_log('[history] ' . $e->getMessage());
    }
}

$counts = ['added' => 0, 'removed' => 0, 'room_changed' => 0];
foreach ($changes as $c) {
    if (array_key_exists($c['change_type'], $counts)) {
        $counts[$c['change_type']]++;
    }
}

$grouped = [];
foreach ($changes as $c) {
    $grouped[$c['date']][] = $c;
}

function change_label(string $t): string
{
    $map = ['added' => 'Добавлен', 'removed' => 'Удалён', 'room_changed' => 'Кабинет'];
    return $map[$t] ?? $t;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Изменения расписания — <?= htmlspecialchars(utc_to_samara($at)) ?></title>
    <link rel="icon" href="favicon.ico?v=2" type="image/x-icon">
    <link rel="icon" href="favicon.svg?v=2" type="image/svg+xml">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            min-height: 100vh;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0f172a;
            color: #e2e8f0;
            padding: 2rem;
        }
        .container { max-width: 1000px; margin: 0 auto; }
        .top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; flex-wrap: wrap; gap: .5rem; }
        h1 { font-size: 1.3rem; }
        .top-bar a { color: #94a3b8; text-decoration: none; font-size: .9rem; white-space: nowrap; }
        .top-bar a:hover { color: #e2e8f0; }

        .card {
            background: #1e293b;
            border-radius: .75rem;
            padding: 1.25rem 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 12px rgba(0,0,0,.2);
        }

        .summary-row { display: flex; gap: 1.25rem; flex-wrap: wrap; font-size: .95rem; }
        .stat { color: #94a3b8; }
        .stat b { color: #e2e8f0; margin-left: .35rem; }
        .stat-added b { color: #34d399; }
        .stat-removed b { color: #f87171; }
        .stat-room b { color: #fbbf24; }
        .version { color: #64748b; font-size: .8rem; margin-top: .5rem; }

        table { width: 100%; border-collapse: collapse; font-size: .78rem; table-layout: fixed; }
        th, td { padding: .4rem .5rem; text-align: left; border-bottom: 1px solid #334155; vertical-align: top; }
        th { color: #64748b; font-weight: 600; }
        td { color: #cbd5e1; word-break: break-word; }
        th:nth-child(1), td:nth-child(1) { width: 14%; }
        th:nth-child(2), td:nth-child(2) { width: 11%; }
        th:nth-child(3), td:nth-child(3) { width: 7%; text-align: center; }
        th:nth-child(4), td:nth-child(4) { width: 13%; }
        th:nth-child(5), td:nth-child(5) { width: 27%; }
        th:nth-child(7), td:nth-child(7) { width: 14%; }
        th { color: #64748b; font-weight: 600; }
        td { color: #cbd5e1; }
        .date-row td {
            color: #e2e8f0;
            background: #26334d;
            font-weight: 600;
            padding: .45rem .75rem;
        }
        .type-added { color: #34d399; font-weight: 600; }
        .type-removed { color: #f87171; font-weight: 600; }
        .type-room_changed { color: #fbbf24; font-weight: 600; }
        .room-arrow { color: #64748b; }
        .subgroup { color: #64748b; font-size: .78rem; }
        .empty { color: #94a3b8; padding: .5rem 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="top-bar">
            <h1>Изменения расписания</h1>
            <a href="admin.php">&larr; Вернуться в админ-панель</a>
        </div>

        <div class="card summary-row">
            <span class="stat">Импорт: <b class="stat-plain" style="color:#e2e8f0"><?= htmlspecialchars(utc_to_samara($at)) ?></b></span>
            <span class="stat stat-added">Добавлено: <b><?= $counts['added'] ?></b></span>
            <span class="stat stat-removed">Удалено: <b><?= $counts['removed'] ?></b></span>
            <span class="stat stat-room">Смен кабинета: <b><?= $counts['room_changed'] ?></b></span>
        </div>

        <?php if (empty($grouped)): ?>
            <div class="card">
                <p class="empty">Для этого импорта не записано ни одного изменения</p>
            </div>
        <?php else: ?>
            <div class="card">
                <table>
                    <thead>
                        <tr>
                            <th>Изменение</th>
                            <th>Класс</th>
                            <th>Урок</th>
                            <th>Время</th>
                            <th>Предмет</th>
                            <th>Учитель</th>
                            <th>Кабинет</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($grouped as $date => $rows): ?>
                            <tr class="date-row">
                                <td colspan="7"><?= htmlspecialchars($date . ' · ' . $rows[0]['day_of_week']) ?></td>
                            </tr>
                            <?php foreach ($rows as $c): ?>
                                <tr>
                                    <td class="type-<?= htmlspecialchars($c['change_type']) ?>">
                                        <?= htmlspecialchars(change_label($c['change_type'])) ?>
                                    </td>
                                    <td><?= htmlspecialchars($c['class_name']) ?></td>
                                    <td><?= (int)$c['lesson_num'] ?></td>
                                    <td><?= htmlspecialchars(substr($c['time_start'], 0, 5) . '–' . substr($c['time_end'], 0, 5)) ?></td>
                                    <td>
                                        <?= htmlspecialchars($c['subject']) ?>
                                        <?php if (!empty($c['parallel_group'])): ?>
                                            <span class="subgroup">(<?= htmlspecialchars($c['parallel_group']) ?>)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($c['teacher']) ?></td>
                                    <td>
                                        <?php if ($c['change_type'] === 'room_changed'): ?>
                                            <?= htmlspecialchars($c['old_room']) ?> <span class="room-arrow">&rarr;</span> <?= htmlspecialchars($c['room']) ?>
                                        <?php else: ?>
                                            <?= htmlspecialchars($c['room']) ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
