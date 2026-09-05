<?php
/**
 * Админ-страница: импорт расписания с Яндекс-Диска.
 */
require_once __DIR__ . '/src/auth.php';
require_admin();

$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_url'])) {
    $url = trim($_POST['import_url']);
    if (empty($url)) {
        $message = 'Введите ссылку на файл';
        $message_type = 'error';
    } else {
        try {
            require_once __DIR__ . '/src/import.php';
            $result = do_import($url);
            $message = "Импорт завершён: {$result['lessons']} уроков ({$result['date']})";
            $message_type = 'success';
        } catch (Exception $e) {
            $message = 'Ошибка: ' . $e->getMessage();
            $message_type = 'error';
        }
    }
}

if (isset($_GET['logout'])) {
    admin_logout();
    header('Location: admin.php');
    exit;
}

// Load import log
$log = [];
try {
    $pdo = new PDO('sqlite:' . dirname(__DIR__, 2) . '/db/schedule.db');
    $log = $pdo->query("SELECT * FROM import_log ORDER BY id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // DB might not exist yet
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Админ — Импорт расписания</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            min-height: 100vh;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0f172a;
            color: #e2e8f0;
            padding: 2rem;
        }
        .container { max-width: 800px; margin: 0 auto; }
        h1 { font-size: 1.6rem; margin-bottom: 1.5rem; }
        .top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; }
        .top-bar a { color: #94a3b8; text-decoration: none; font-size: .9rem; }
        .top-bar a:hover { color: #e2e8f0; }

        .card {
            background: #1e293b;
            border-radius: .75rem;
            padding: 1.5rem 2rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 12px rgba(0,0,0,.2);
        }
        .card h2 { font-size: 1.1rem; margin-bottom: 1rem; color: #94a3b8; }

        .import-form { display: flex; gap: .75rem; align-items: stretch; }
        .import-form input[type="url"] {
            flex: 1;
            padding: .65rem 1rem;
            border: 1px solid #334155;
            border-radius: .5rem;
            background: #0f172a;
            color: #e2e8f0;
            font-size: .95rem;
        }
        .import-form input:focus { outline: none; border-color: #3b82f6; }
        .import-form button {
            padding: .65rem 1.5rem;
            border: none;
            border-radius: .5rem;
            background: #3b82f6;
            color: #fff;
            font-size: .95rem;
            cursor: pointer;
            white-space: nowrap;
        }
        .import-form button:hover { background: #2563eb; }
        .import-form button:disabled { background: #475569; cursor: wait; }

        .msg {
            padding: .75rem 1rem;
            border-radius: .5rem;
            margin-bottom: 1rem;
            font-size: .9rem;
        }
        .msg.success { background: #065f46; color: #a7f3d0; }
        .msg.error { background: #7f1d1d; color: #fca5a5; }

        table { width: 100%; border-collapse: collapse; font-size: .85rem; }
        th, td { padding: .5rem .75rem; text-align: left; border-bottom: 1px solid #334155; }
        th { color: #64748b; font-weight: 600; }
        td { color: #cbd5e1; }
        .status-ok { color: #34d399; }
        .status-error { color: #f87171; }

        .hint { color: #64748b; font-size: .8rem; margin-top: .5rem; }
    </style>
</head>
<body>
    <div class="container">
        <div class="top-bar">
            <h1>Импорт расписания</h1>
            <a href="?logout">Выйти</a>
        </div>

        <?php if ($message): ?>
            <div class="msg <?= $message_type ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <div class="card">
            <h2>Загрузка с Яндекс-Диска</h2>
            <form class="import-form" method="POST" id="importForm">
                <input type="url" name="import_url" placeholder="https://disk.yandex.ru/i/..."
                       value="<?= htmlspecialchars($_POST['import_url'] ?? '') ?>" required>
                <button type="submit" id="importBtn">Импортировать</button>
            </form>
            <p class="hint">Вставьте публичную ссылку на XLSX-файл с расписанием</p>
        </div>

        <div class="card">
            <h2>История импортов</h2>
            <?php if (empty($log)): ?>
                <p style="color: #64748b;">Импортов ещё не было</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Дата</th>
                            <th>Уроков</th>
                            <th>Статус</th>
                            <th>Источник</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($log as $entry): ?>
                            <tr>
                                <td><?= htmlspecialchars($entry['imported_at']) ?></td>
                                <td><?= (int)$entry['lessons_count'] ?></td>
                                <td class="status-<?= $entry['status'] ?>">
                                    <?= $entry['status'] === 'ok' ? 'OK' : 'Ошибка' ?>
                                </td>
                                <td style="max-width:300px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                    <?= htmlspecialchars($entry['url']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <script>
    document.getElementById('importForm').addEventListener('submit', function() {
        var btn = document.getElementById('importBtn');
        btn.disabled = true;
        btn.textContent = 'Импорт...';
    });
    </script>
</body>
</html>
