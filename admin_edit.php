<?php
/**
 * Админ-страница: правки расписания.
 *
 * Ручные правки хранятся отдельно (schedule_edits) и накладываются на
 * расписание при чтении, поэтому переживают импорт. Здесь можно изменить
 * любой урок класса на дату, скрыть урок или добавить новый.
 * Доступна из страницы импорта (admin.php).
 */
require_once __DIR__ . '/src/auth.php';
require_admin();

// Динамическая страница с сессией — без кэша
header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Админ — Правки расписания</title>
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
        .container { max-width: 1200px; margin: 0 auto; }
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

        .selector { display: flex; gap: .75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
        select, input[type="date"], input[type="time"], input[type="text"], input[type="number"] {
            padding: .55rem .8rem;
            border: 1px solid #334155;
            border-radius: .5rem;
            background: #0f172a;
            color: #e2e8f0;
            font-size: .9rem;
        }
        select:focus, input:focus { outline: none; border-color: #3b82f6; }
        input.inp-num { width: 56px; }
        input.inp-time { width: 96px; }
        input.inp-subject { width: 100%; min-width: 140px; }
        input.inp-teacher { width: 100%; min-width: 120px; }
        input.inp-room { width: 90px; }
        input.inp-group { width: 90px; }

        button {
            padding: .5rem 1rem;
            border: none;
            border-radius: .5rem;
            font-size: .85rem;
            cursor: pointer;
        }
        .btn-primary { background: #3b82f6; color: #fff; }
        .btn-primary:hover { background: #2563eb; }
        .btn-add { background: #059669; color: #fff; }
        .btn-add:hover { background: #047857; }
        .btn-sm { padding: .3rem .7rem; font-size: .78rem; }
        .btn-save { background: #1e40af; color: #bfdbfe; }
        .btn-save:hover { background: #1d4ed8; }
        .btn-hide { background: #78350f; color: #fde68a; }
        .btn-hide:hover { background: #92400e; }
        .btn-reset { background: #334155; color: #cbd5e1; }
        .btn-reset:hover { background: #475569; }
        .btn-delete { background: #7f1d1d; color: #fca5a5; }
        .btn-delete:hover { background: #991b1b; }
        button:disabled { background: #475569 !important; color: #cbd5e1 !important; cursor: wait; }

        .msg { padding: .75rem 1rem; border-radius: .5rem; margin-bottom: 1rem; font-size: .9rem; }
        .msg.success { background: #065f46; color: #a7f3d0; }
        .msg.error { background: #7f1d1d; color: #fca5a5; }
        .msg[hidden] { display: none; }

        table { width: 100%; border-collapse: collapse; font-size: .82rem; }
        th, td { padding: .45rem .5rem; text-align: left; border-bottom: 1px solid #334155; vertical-align: middle; }
        th { color: #64748b; font-weight: 600; white-space: nowrap; }
        td.actions { white-space: nowrap; }
        tr.row-edited td { background: rgba(59,130,246,.08); }
        tr.row-hidden td { opacity: .55; }
        tr.row-added td { background: rgba(5,150,105,.08); }

        .badge { display: inline-block; padding: .1rem .5rem; border-radius: .75rem; font-size: .72rem; white-space: nowrap; }
        .badge-source { background: #334155; color: #cbd5e1; }
        .badge-edited { background: #1d4ed8; color: #dbeafe; }
        .badge-added { background: #065f46; color: #a7f3d0; }
        .badge-hidden { background: #78350f; color: #fde68a; }

        .hint { color: #64748b; font-size: .8rem; margin-top: .5rem; }
        .empty { color: #64748b; }
        .status-ok { color: #34d399; }
        .status-bad { color: #f87171; }
        .mono { font-family: monospace; font-size: .72rem; color: #94a3b8; }

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
            <h1>Правки расписания</h1>
            <div class="links">
                <a href="admin.php">&larr; Импорт расписания</a>
                <a href="admin.php?logout">Выйти</a>
            </div>
        </div>

        <div class="msg" id="msg" hidden></div>

        <div class="card">
            <h2>Редактор</h2>
            <p class="hint" style="margin:0 0 1rem;">
                Правки применяются автоматически и не затираются при импорте.
                Если урок в источнике исчез или сдвинулся — правка сохраняется и
                применится снова, когда появится совпадающий урок.
            </p>
            <div class="selector">
                <select id="classSel">
                    <option value="">Класс…</option>
                </select>
                <input type="date" id="dateInp">
                <button type="button" class="btn-primary" id="addBtn">+ Добавить урок</button>
            </div>

            <div id="editorWrap">
                <p class="empty" id="editorEmpty">Выберите класс и дату</p>
                <table id="editorTable" hidden>
                    <thead>
                        <tr>
                            <th>№</th>
                            <th>Начало</th>
                            <th>Конец</th>
                            <th>Предмет</th>
                            <th>Учитель</th>
                            <th>Кабинет</th>
                            <th>Группа</th>
                            <th>Статус</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="editorBody"></tbody>
                </table>
            </div>
            <datalist id="roomsList"></datalist>
        </div>

        <div class="card">
            <h2>Все сохранённые правки</h2>
            <div id="allWrap">
                <p class="empty" id="allEmpty">Загрузка…</p>
                <table id="allTable" hidden>
                    <thead>
                        <tr>
                            <th>Дата</th>
                            <th>Класс</th>
                            <th>Тип</th>
                            <th>Урок</th>
                            <th>Время</th>
                            <th>Предмет / учитель</th>
                            <th>Кабинет</th>
                            <th>Статус</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="allBody"></tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="admin_edit.js?v=1"></script>
</body>
</html>
