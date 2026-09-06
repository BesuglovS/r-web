<?php
/**
 * Админ-страница: импорт расписания с Яндекс-Диска.
 */
require_once __DIR__ . '/src/auth.php';
require_admin();

$message = '';
$message_type = '';

require_once __DIR__ . '/src/import.php';

// utc_to_samara() теперь в src/db.php: хранит UTC, отображает самарское время

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $message = 'Сессия устарела, обновите страницу и попробуйте снова';
        $message_type = 'error';
    } elseif (isset($_POST['import_url'])) {
        $url = trim($_POST['import_url']);
        if (empty($url)) {
            $message = 'Введите ссылку на файл';
            $message_type = 'error';
        } else {
            try {
                $result = do_import($url);
                $message = "Импорт завершён: {$result['lessons']} уроков ({$result['date']})";
                $message_type = 'success';
            } catch (Exception $e) {
                $message = 'Ошибка: ' . $e->getMessage();
                $message_type = 'error';
            }
        }
    } elseif (isset($_POST['add_source'])) {
        $url = trim($_POST['source_url'] ?? '');
        $label = trim($_POST['source_label'] ?? '');
        if (empty($url)) {
            $message = 'URL не может быть пустым';
            $message_type = 'error';
        } else {
            try {
                add_source($url, $label);
                $message = 'Источник добавлен';
                $message_type = 'success';
            } catch (Exception $e) {
                $message = 'Ошибка: ' . $e->getMessage();
                $message_type = 'error';
            }
        }
    } elseif (isset($_POST['delete_source'])) {
        $id = (int)($_POST['source_id'] ?? 0);
        try {
            delete_source($id);
            $message = 'Источник удалён';
            $message_type = 'success';
        } catch (Exception $e) {
            $message = 'Ошибка: ' . $e->getMessage();
            $message_type = 'error';
        }
    } elseif (isset($_POST['toggle_source'])) {
        $id = (int)($_POST['source_id'] ?? 0);
        try {
            toggle_source($id);
            $message = 'Статус источника обновлён';
            $message_type = 'success';
        } catch (Exception $e) {
            $message = 'Ошибка: ' . $e->getMessage();
            $message_type = 'error';
        }
    } elseif (isset($_POST['edit_source'])) {
        $id = (int)($_POST['source_id'] ?? 0);
        $url = trim($_POST['source_url'] ?? '');
        $label = trim($_POST['source_label'] ?? '');
        if ($id <= 0 || empty($url)) {
            $message = 'Неверные данные';
            $message_type = 'error';
        } else {
            try {
                edit_source($id, $url, $label);
                $message = 'Источник обновлён';
                $message_type = 'success';
            } catch (Exception $e) {
                $message = 'Ошибка: ' . $e->getMessage();
                $message_type = 'error';
            }
        }
    } elseif (isset($_POST['import_all'])) {
        try {
            $result = do_import_all();
            $msg = "Импорт завершён: {$result['success']}/{$result['total']} успешно";
            if ($result['errors'] > 0) {
                $msg .= " ({$result['errors']} ошибок)";
            }
            $message = $msg;
            $message_type = $result['errors'] > 0 ? 'error' : 'success';
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

$sources = get_sources();

$log = [];
$check_log = [];
$last_import = null;
$pdo = get_db();
if ($pdo !== null) {
    try {
        init_db($pdo);
        $log = $pdo->query("SELECT * FROM import_log ORDER BY id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
        $last_import = $pdo->query("SELECT imported_at, status, error_message FROM import_log ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $check_log = $pdo->query("SELECT * FROM check_log ORDER BY id DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // ignore
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Админ — Импорт расписания</title>
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

        .source-form { display: flex; gap: .75rem; align-items: stretch; flex-wrap: wrap; }
        .source-form input[type="url"],
        .source-form input[type="text"] {
            flex: 1;
            min-width: 200px;
            padding: .65rem 1rem;
            border: 1px solid #334155;
            border-radius: .5rem;
            background: #0f172a;
            color: #e2e8f0;
            font-size: .95rem;
        }
        .source-form input:focus { outline: none; border-color: #3b82f6; }
        .source-form button {
            padding: .65rem 1.5rem;
            border: none;
            border-radius: .5rem;
            background: #3b82f6;
            color: #fff;
            font-size: .95rem;
            cursor: pointer;
            white-space: nowrap;
        }
        .source-form button:hover { background: #2563eb; }

        .source-actions { display: flex; gap: .5rem; align-items: center; flex-wrap: wrap; }
        .btn-sm {
            padding: .3rem .75rem;
            border: none;
            border-radius: .4rem;
            font-size: .8rem;
            cursor: pointer;
        }
        .btn-toggle { background: #334155; color: #e2e8f0; }
        .btn-toggle.active { background: #065f46; color: #a7f3d0; }
        .btn-toggle:hover { background: #475569; }
        .btn-delete { background: #7f1d1d; color: #fca5a5; }
        .btn-delete:hover { background: #991b1b; }
        .btn-import-all {
            padding: .65rem 1.5rem;
            border: none;
            border-radius: .5rem;
            background: #059669;
            color: #fff;
            font-size: .95rem;
            cursor: pointer;
            margin-top: .75rem;
        }
        .btn-import-all:hover { background: #047857; }
        .btn-import-all:disabled { background: #475569; cursor: wait; }

        .source-active { color: #34d399; }
        .source-inactive { color: #64748b; }
        .source-status-ok { color: #34d399; font-size: .8rem; }
        .source-status-error { color: #f87171; font-size: .8rem; }
        .source-error-text { color: #f87171; font-size: .75rem; max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .btn-edit { background: #1e40af; color: #bfdbfe; }
        .btn-edit:hover { background: #1d4ed8; }
        .edit-input {
            width: 100%;
            padding: .35rem .6rem;
            border: 1px solid #3b82f6;
            border-radius: .4rem;
            background: #0f172a;
            color: #e2e8f0;
            font-size: .85rem;
        }
        .edit-input:focus { outline: none; border-color: #60a5fa; }

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

        .last-import-card { padding: 1rem 1.5rem; }
        .last-import-card.status-ok { border-left: 4px solid #34d399; }
        .last-import-card.status-error { border-left: 4px solid #f87171; }
        .last-import-row { display: flex; align-items: center; gap: .75rem; flex-wrap: wrap; }
        .last-import-label { color: #94a3b8; font-size: .9rem; }
        .last-import-date { color: #e2e8f0; font-size: .95rem; font-weight: 500; }
        .last-import-status { font-size: .85rem; font-weight: 600; }
        .last-import-error { color: #f87171; font-size: .8rem; margin-top: .5rem; }

        .tabs {
            display: flex;
            gap: .25rem;
            margin-bottom: 1rem;
            border-bottom: 1px solid #334155;
            flex-wrap: wrap;
        }
        .tab-btn {
            padding: .55rem 1.1rem;
            border: none;
            background: transparent;
            color: #94a3b8;
            font-size: .95rem;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            margin-bottom: -1px;
            white-space: nowrap;
        }
        .tab-btn:hover { color: #e2e8f0; }
        .tab-btn.active {
            color: #e2e8f0;
            border-bottom-color: #3b82f6;
        }
        .tab-btn .tab-count {
            display: inline-block;
            margin-left: .35rem;
            padding: .1rem .45rem;
            border-radius: .75rem;
            background: #334155;
            color: #cbd5e1;
            font-size: .72rem;
        }
        .tab-btn.active .tab-count { background: #1d4ed8; color: #dbeafe; }
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

        <?php if ($last_import): ?>
            <div class="card last-import-card <?= $last_import['status'] === 'error' ? 'status-error' : 'status-ok' ?>">
                <div class="last-import-row">
                    <span class="last-import-label">Последний импорт:</span>
                    <span class="last-import-date"><?= htmlspecialchars(utc_to_samara($last_import['imported_at'])) ?></span>
                    <span class="last-import-status <?= $last_import['status'] === 'error' ? 'status-error' : 'status-ok' ?>">
                        <?= $last_import['status'] === 'ok' ? 'Успех' : ($last_import['status'] === 'no_changes' ? 'Без изменений' : 'Ошибка') ?>
                    </span>
                </div>
                <?php if ($last_import['status'] === 'error' && $last_import['error_message']): ?>
                    <div class="last-import-error"><?= htmlspecialchars($last_import['error_message']) ?></div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="card last-import-card">
                <div class="last-import-row">
                    <span class="last-import-label">Последний импорт:</span>
                    <span style="color:#64748b;">—</span>
                </div>
            </div>
        <?php endif; ?>

        <div class="card">
            <h2>Загрузка с Яндекс-Диска</h2>
            <form class="import-form" method="POST" id="importForm">
                <?= csrf_field() ?>
                <input type="url" name="import_url" placeholder="https://disk.yandex.ru/i/..."
                       value="<?= htmlspecialchars($_POST['import_url'] ?? '') ?>" required>
                <button type="submit" id="importBtn">Импортировать</button>
            </form>
            <p class="hint">Вставьте публичную ссылку на XLSX-файл с расписанием</p>
        </div>

        <div class="card">
            <h2>Источники расписаний</h2>
            <form class="source-form" method="POST" style="margin-bottom: 1rem;">
                <?= csrf_field() ?>
                <input type="url" name="source_url" placeholder="https://disk.yandex.ru/i/..." required>
                <input type="text" name="source_label" placeholder="Описание (неделя и т.д.)">
                <button type="submit" name="add_source" value="1">Добавить</button>
            </form>

            <?php if (empty($sources)): ?>
                <p style="color: #64748b;">Нет добавленных источников</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Описание</th>
                            <th>URL</th>
                            <th>Статус</th>
                            <th>Последний импорт</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sources as $src): ?>
                            <tr data-id="<?= (int)$src['id'] ?>">
                                <td><?= (int)$src['id'] ?></td>
                                <td class="cell-label"><?= htmlspecialchars($src['label']) ?></td>
                                <td class="cell-url" style="max-width:250px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"
                                    title="<?= htmlspecialchars($src['url']) ?>">
                                    <?= htmlspecialchars($src['url']) ?>
                                </td>
                                <td class="<?= (int)$src['is_active'] ? 'source-active' : 'source-inactive' ?>">
                                    <?= (int)$src['is_active'] ? 'Активен' : 'Выкл' ?>
                                </td>
                                <td>
                                    <?php if ($src['last_imported_at']): ?>
                                        <span class="source-status-<?= $src['last_import_status'] === 'error' ? 'error' : 'ok' ?>">
                                            <?= $src['last_import_status'] === 'ok' ? 'OK' : ($src['last_import_status'] === 'no_changes' ? 'Без изменений' : 'Ошибка') ?>
                                        </span>
                                        <br><span style="font-size:.75rem;color:#64748b;"><?= htmlspecialchars(utc_to_samara($src['last_imported_at'])) ?></span>
                                        <?php if ($src['last_error']): ?>
                                            <br><span class="source-error-text" title="<?= htmlspecialchars($src['last_error']) ?>"><?= htmlspecialchars($src['last_error']) ?></span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color:#64748b;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="source-actions">
                                        <button type="button" class="btn-sm btn-edit"
                                                onclick="startEdit(this)"
                                                data-url="<?= htmlspecialchars($src['url']) ?>"
                                                data-label="<?= htmlspecialchars($src['label']) ?>">Ред.</button>
                                        <form method="POST" style="display:inline;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="source_id" value="<?= (int)$src['id'] ?>">
                                            <button type="submit" name="toggle_source" value="1"
                                                    class="btn-sm btn-toggle <?= (int)$src['is_active'] ? 'active' : '' ?>">
                                                <?= (int)$src['is_active'] ? 'Выкл' : 'Вкл' ?>
                                            </button>
                                        </form>
                                        <form method="POST" style="display:inline;"
                                              onsubmit="return confirm('Удалить источник?')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="source_id" value="<?= (int)$src['id'] ?>">
                                            <button type="submit" name="delete_source" value="1"
                                                    class="btn-sm btn-delete">Удалить</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <form method="POST">
                <?= csrf_field() ?>
                <button type="submit" name="import_all" value="1" class="btn-import-all"
                        onclick="this.disabled=true;this.textContent='Импорт...'">
                    Импортировать последние 2 источника
                </button>
            </form>
        </div>

        <div class="card">
            <div class="tabs" id="logTabs">
                <button type="button" class="tab-btn" data-tab="imports">Импорты <span class="tab-count"><?= count($log) ?></span></button>
                <button type="button" class="tab-btn" data-tab="checks">Проверки <span class="tab-count"><?= count($check_log) ?></span></button>
            </div>

            <div class="tab-panel" id="tab-imports">
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
                                    <td><?= htmlspecialchars(utc_to_samara($entry['imported_at'])) ?></td>
                                    <td><?= (int)$entry['lessons_count'] ?></td>
                                    <td class="status-<?= htmlspecialchars($entry['status']) ?>">
                                        <?= $entry['status'] === 'ok' ? 'OK' : ($entry['status'] === 'no_changes' ? 'Без изменений' : 'Ошибка') ?>
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

            <div class="tab-panel" id="tab-checks" hidden>
                <p class="hint" style="margin-bottom:1rem;">
                    Автопроверка метаданных файла на Яндекс.Диске каждые 5 минут.
                    Импорт запускается только при изменении файла (md5), либо раз в 6 часов для страховки.
                </p>
                <?php if (empty($check_log)): ?>
                    <p style="color: #64748b;">Проверок ещё не было</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Дата</th>
                                <th>Результат</th>
                                <th>Изменение</th>
                                <th>Источник</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($check_log as $entry): ?>
                                <tr>
                                    <td><?= htmlspecialchars(utc_to_samara($entry['checked_at'])) ?></td>
                                    <td class="status-<?= $entry['result'] === 'changed' ? 'ok' : ($entry['result'] === 'unchanged' ? 'no_changes' : 'error') ?>">
                                        <?= $entry['result'] === 'changed' ? 'Импорт' : ($entry['result'] === 'unchanged' ? 'Без изменений' : 'Ошибка') ?>
                                    </td>
                                    <td style="font-family:monospace;font-size:.7rem;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                        <?= $entry['old_md5'] ? htmlspecialchars(substr($entry['old_md5'], 0, 8)) . ' → ' . htmlspecialchars(substr($entry['new_md5'], 0, 8)) : '—' ?>
                                        <?php if ($entry['message']): ?><br><span style="color:#94a3b8;"><?= htmlspecialchars($entry['message']) ?></span><?php endif; ?>
                                    </td>
                                    <td style="max-width:250px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                        <?= htmlspecialchars($entry['url']) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
    document.getElementById('importForm').addEventListener('submit', function() {
        var btn = document.getElementById('importBtn');
        btn.disabled = true;
        btn.textContent = 'Импорт...';
    });

    var CSRF_TOKEN = '<?= csrf_token() ?>';

    function startEdit(btn) {
        var row = btn.closest('tr');
        var id = row.dataset.id;
        var url = btn.dataset.url;
        var label = btn.dataset.label;

        row.dataset.origUrl = url;
        row.dataset.origLabel = label;

        // Инпуты создаём через DOM API — значение не попадает в HTML-строку
        // (защита от XSS даже при ошибке экранирования)
        var labelCell = row.querySelector('.cell-label');
        var urlCell = row.querySelector('.cell-url');
        labelCell.innerHTML = '';
        urlCell.innerHTML = '';
        var labelInput = document.createElement('input');
        labelInput.className = 'edit-input';
        labelInput.name = 'source_label';
        labelInput.value = label;
        labelCell.appendChild(labelInput);
        var urlInput = document.createElement('input');
        urlInput.className = 'edit-input';
        urlInput.name = 'source_url';
        urlInput.value = url;
        urlInput.style.maxWidth = '250px';
        urlCell.appendChild(urlInput);

        var actions = row.querySelector('.source-actions');
        actions.innerHTML = '';
        var form = document.createElement('form');
        form.method = 'POST';
        form.style.display = 'inline';
        var csrf = document.createElement('input');
        csrf.type = 'hidden'; csrf.name = 'csrf_token'; csrf.value = CSRF_TOKEN;
        var hidId = document.createElement('input');
        hidId.type = 'hidden'; hidId.name = 'source_id'; hidId.value = id;
        var hidUrl = document.createElement('input');
        hidUrl.type = 'hidden'; hidUrl.name = 'source_url'; hidUrl.className = 'edit-url-val';
        var hidLabel = document.createElement('input');
        hidLabel.type = 'hidden'; hidLabel.name = 'source_label'; hidLabel.className = 'edit-label-val';
        var saveBtn = document.createElement('button');
        saveBtn.type = 'submit'; saveBtn.name = 'edit_source'; saveBtn.value = '1';
        saveBtn.className = 'btn-sm btn-toggle';
        saveBtn.textContent = 'Сохранить';
        saveBtn.onclick = function() { return submitEdit(saveBtn); };
        var cancelBtn = document.createElement('button');
        cancelBtn.type = 'button'; cancelBtn.className = 'btn-sm btn-delete';
        cancelBtn.textContent = 'Отмена';
        cancelBtn.onclick = cancelEdit;
        form.appendChild(csrf); form.appendChild(hidId); form.appendChild(hidUrl);
        form.appendChild(hidLabel); form.appendChild(saveBtn);
        actions.appendChild(form); actions.appendChild(cancelBtn);
    }

    function submitEdit(btn) {
        var row = btn.closest('tr');
        var urlInput = row.querySelector('.cell-url .edit-input');
        var labelInput = row.querySelector('.cell-label .edit-input');
        var hiddenUrl = row.querySelector('.edit-url-val');
        var hiddenLabel = row.querySelector('.edit-label-val');
        if (urlInput) hiddenUrl.value = urlInput.value;
        if (labelInput) hiddenLabel.value = labelInput.value;
        return true;
    }

    function cancelEdit() {
        location.reload();
    }

    function escHtml(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    // Вкладки журналов: импорты / проверки (запоминаем выбор)
    var tabBtns = document.querySelectorAll('#logTabs .tab-btn');
    function activateTab(name) {
        tabBtns.forEach(function(b) {
            var active = b.dataset.tab === name;
            b.classList.toggle('active', active);
            var panel = document.getElementById('tab-' + b.dataset.tab);
            if (panel) panel.hidden = !active;
        });
        try { localStorage.setItem('adminLogTab', name); } catch (e) {}
    }
    tabBtns.forEach(function(b) {
        b.addEventListener('click', function() { activateTab(b.dataset.tab); });
    });
    (function() {
        var saved = null;
        try { saved = localStorage.getItem('adminLogTab'); } catch (e) {}
        if (saved && document.getElementById('tab-' + saved)) {
            activateTab(saved);
        } else if (tabBtns.length) {
            activateTab(tabBtns[0].dataset.tab);
        }
    })();
    </script>
</body>
</html>
