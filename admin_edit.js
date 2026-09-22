/* admin_edit.js — логика страницы правок расписания (admin_edit.php).
 * Все данные создаются через DOM API (textContent/value), без inline-HTML —
 * защита от XSS. CSRF-токен берётся из <meta name="csrf-token">.
 */
(function () {
    'use strict';

    var CSRF = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var classSel = document.getElementById('classSel');
    var dateInp = document.getElementById('dateInp');
    var addBtn = document.getElementById('addBtn');
    var editorEmpty = document.getElementById('editorEmpty');
    var editorTable = document.getElementById('editorTable');
    var editorBody = document.getElementById('editorBody');
    var roomsList = document.getElementById('roomsList');
    var allEmpty = document.getElementById('allEmpty');
    var allTable = document.getElementById('allTable');
    var allBody = document.getElementById('allBody');
    var msgBox = document.getElementById('msg');

    var state = { cls: '', date: '', base: [], edits: [], rooms: [] };

    function showMsg(text, type) {
        msgBox.hidden = false;
        msgBox.className = 'msg ' + (type || 'success');
        msgBox.textContent = text;
        if (type !== 'error') {
            window.setTimeout(function () { msgBox.hidden = true; }, 4000);
        }
    }

    function post(action, params) {
        var body = new URLSearchParams();
        body.set('action', action);
        body.set('csrf_token', CSRF);
        Object.keys(params).forEach(function (k) {
            if (params[k] !== undefined && params[k] !== null) body.set(k, params[k]);
        });
        return fetch('api.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': CSRF
            },
            body: body.toString()
        }).then(function (r) {
            return r.json().then(function (data) { return { ok: r.ok, data: data }; });
        });
    }

    function mkInput(type, cls, value, attrs) {
        var inp = document.createElement('input');
        inp.type = type;
        if (cls) inp.className = cls;
        inp.value = (value === null || value === undefined) ? '' : value;
        if (attrs) Object.keys(attrs).forEach(function (k) { inp.setAttribute(k, attrs[k]); });
        return inp;
    }

    function mkCell(child) {
        var td = document.createElement('td');
        td.appendChild(child);
        return td;
    }

    function mkBadge(text, cls) {
        var b = document.createElement('span');
        b.className = 'badge ' + cls;
        b.textContent = text;
        return b;
    }

    function mkButton(text, cls, handler) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = cls;
        b.textContent = text;
        b.addEventListener('click', handler);
        return b;
    }

    function rowKey(date, cls, lesson, time, group) {
        return [date, cls, lesson, time, group || ''].join('\u001F');
    }

    function slotKey(date, cls, lesson, time) {
        return [date, cls, lesson, time].join('\u001F');
    }

    // Совместимый подбор правки для урока — как в src/edits.php:
    // точная группа → правка всего класса ('') → для урока «группой целиком»
    // подойдёт правка подгруппы (первая по названию).
    function findEdit(exact, bySlot, date, cls, lesson, time, group) {
        var e = exact[rowKey(date, cls, lesson, time, group)];
        if (e) return e;
        var cands = bySlot[slotKey(date, cls, lesson, time)] || {};
        if (cands['']) return cands[''];
        if (!group) {
            var keys = Object.keys(cands).sort();
            if (keys.length) return cands[keys[0]];
        }
        return null;
    }

    // === Справочники ===

    function loadClasses() {
        fetch('api.php?action=classes').then(function (r) { return r.json(); }).then(function (d) {
            (d.classes || []).forEach(function (c) {
                var o = document.createElement('option');
                o.value = c;
                o.textContent = c;
                classSel.appendChild(o);
            });
        }).catch(function () {});
    }

    function renderRooms() {
        roomsList.innerHTML = '';
        state.rooms.forEach(function (name) {
            var o = document.createElement('option');
            o.value = name;
            roomsList.appendChild(o);
        });
    }

    // === Редактор класса/даты ===

    function loadEditor() {
        if (!state.cls || !state.date) {
            editorEmpty.hidden = false;
            editorEmpty.textContent = 'Выберите класс и дату';
            editorTable.hidden = true;
            return;
        }
        editorEmpty.hidden = false;
        editorEmpty.textContent = 'Загрузка…';
        editorTable.hidden = true;

        fetch('api.php?action=edit_data&class=' + encodeURIComponent(state.cls) +
              '&date=' + encodeURIComponent(state.date))
            .then(function (r) { return r.json(); })
            .then(function (d) {
                state.base = d.base || [];
                state.edits = d.edits || [];
                state.rooms = d.rooms || [];
                renderRooms();
                renderEditor();
            })
            .catch(function () {
                editorEmpty.textContent = 'Ошибка загрузки расписания';
            });
    }

    function renderEditor() {
        editorBody.innerHTML = '';
        var adds = state.edits.filter(function (e) { return e.change_type === 'add'; });
        if (!state.base.length && !adds.length) {
            editorEmpty.hidden = false;
            editorEmpty.textContent = 'На эту дату уроков нет';
            editorTable.hidden = true;
            return;
        }
        editorEmpty.hidden = true;
        editorTable.hidden = false;

        var exact = {};
        var bySlot = {};
        state.edits.forEach(function (e) {
            if (e.change_type !== 'edit' && e.change_type !== 'delete') return;
            exact[rowKey(e.date, e.class_name, e.base_lesson_num, e.base_time_start, e.base_parallel_group)] = e;
            var s = slotKey(e.date, e.class_name, e.base_lesson_num, e.base_time_start);
            if (!bySlot[s]) bySlot[s] = {};
            bySlot[s][e.base_parallel_group || ''] = e;
        });
        state.base.forEach(function (b) {
            var edit = findEdit(exact, bySlot, state.date, state.cls, b.lesson_num, b.time_start, b.parallel_group || '');
            editorBody.appendChild(buildBaseRow(b, edit));
        });
        adds.forEach(function (e) { editorBody.appendChild(buildAddRow(e)); });
    }

    function pick(edit, field, fallback) {
        if (edit && edit[field] !== '' && edit[field] !== null && edit[field] !== undefined) {
            return edit[field];
        }
        return fallback;
    }

    function buildBaseRow(base, edit) {
        var hidden = edit && edit.change_type === 'delete';
        var tr = document.createElement('tr');
        tr.className = hidden ? 'row-hidden' : (edit ? 'row-edited' : '');
        tr.dataset.kind = 'base';
        tr.dataset.date = state.date;
        tr.dataset.className = state.cls;
        tr.dataset.baseLesson = base.lesson_num;
        tr.dataset.baseTime = base.time_start ||
            (edit && edit.base_time_start) || '';
        // Идентичность урока — из источника (не из правки), чтобы сохранение
        // не смещало ключ правки на другую группу
        tr.dataset.baseGroup = base.parallel_group || '';
        tr.dataset.editId = edit ? edit.id : '';

        var num = mkInput('number', 'inp-num', pick(edit, 'lesson_num', base.lesson_num), { min: '1', max: '20' });
        var ts = mkInput('time', 'inp-time', pick(edit, 'time_start', base.time_start));
        var te = mkInput('time', 'inp-time', pick(edit, 'time_end', base.time_end));
        var subject = mkInput('text', 'inp-subject', pick(edit, 'subject', base.subject));
        var teacher = mkInput('text', 'inp-teacher', pick(edit, 'teacher', base.teacher));
        var room = mkInput('text', 'inp-room', pick(edit, 'room', base.room), { list: 'roomsList' });
        var group = mkInput('text', 'inp-group', pick(edit, 'parallel_group', base.parallel_group || ''));

        if (hidden) {
            [num, ts, te, subject, teacher, room, group].forEach(function (i) { i.disabled = true; });
        }

        var statusCell = document.createElement('td');
        statusCell.appendChild(mkBadge(hidden ? 'Скрыт' : (edit ? 'Изменён' : 'Источник'),
            hidden ? 'badge-hidden' : (edit ? 'badge-edited' : 'badge-source')));

        var actions = document.createElement('td');
        actions.className = 'actions';
        if (hidden) {
            actions.appendChild(mkButton('Вернуть', 'btn-sm btn-reset', function () { resetEdit(edit.id); }));
        } else {
            actions.appendChild(mkButton('Сохранить', 'btn-sm btn-save', function () { saveRow(tr, 'edit'); }));
            actions.appendChild(mkButton('Скрыть', 'btn-sm btn-hide', function () { hideRow(tr); }));
            if (edit) {
                actions.appendChild(mkButton('Сбросить', 'btn-sm btn-reset', function () { resetEdit(edit.id); }));
            }
        }

        tr.appendChild(mkCell(num));
        tr.appendChild(mkCell(ts));
        tr.appendChild(mkCell(te));
        tr.appendChild(mkCell(subject));
        tr.appendChild(mkCell(teacher));
        tr.appendChild(mkCell(room));
        tr.appendChild(mkCell(group));
        tr.appendChild(statusCell);
        tr.appendChild(actions);
        return tr;
    }

    function buildAddRow(edit) {
        var tr = document.createElement('tr');
        tr.className = 'row-added';
        tr.dataset.kind = 'add';
        tr.dataset.editId = edit.id;
        tr.dataset.date = state.date;
        tr.dataset.className = state.cls;

        var num = mkInput('number', 'inp-num', edit.lesson_num, { min: '1', max: '20' });
        var ts = mkInput('time', 'inp-time', edit.time_start);
        var te = mkInput('time', 'inp-time', edit.time_end);
        var subject = mkInput('text', 'inp-subject', edit.subject);
        var teacher = mkInput('text', 'inp-teacher', edit.teacher);
        var room = mkInput('text', 'inp-room', edit.room, { list: 'roomsList' });
        var group = mkInput('text', 'inp-group', edit.parallel_group || '');

        var statusCell = document.createElement('td');
        statusCell.appendChild(mkBadge('Добавлен', 'badge-added'));

        var actions = document.createElement('td');
        actions.className = 'actions';
        actions.appendChild(mkButton('Сохранить', 'btn-sm btn-save', function () { saveAddRow(tr, true); }));
        actions.appendChild(mkButton('Удалить', 'btn-sm btn-delete', function () { resetEdit(edit.id); }));

        tr.appendChild(mkCell(num));
        tr.appendChild(mkCell(ts));
        tr.appendChild(mkCell(te));
        tr.appendChild(mkCell(subject));
        tr.appendChild(mkCell(teacher));
        tr.appendChild(mkCell(room));
        tr.appendChild(mkCell(group));
        tr.appendChild(statusCell);
        tr.appendChild(actions);
        return tr;
    }

    function buildNewAddRow() {
        var maxLesson = 0;
        state.base.forEach(function (b) { maxLesson = Math.max(maxLesson, parseInt(b.lesson_num, 10) || 0); });
        var tr = document.createElement('tr');
        tr.className = 'row-added';
        tr.dataset.kind = 'new';
        tr.dataset.date = state.date;
        tr.dataset.className = state.cls;

        var num = mkInput('number', 'inp-num', Math.min(maxLesson + 1, 20), { min: '1', max: '20' });
        var ts = mkInput('time', 'inp-time', '');
        var te = mkInput('time', 'inp-time', '');
        var subject = mkInput('text', 'inp-subject', '');
        var teacher = mkInput('text', 'inp-teacher', '');
        var room = mkInput('text', 'inp-room', '', { list: 'roomsList' });
        var group = mkInput('text', 'inp-group', '');

        var statusCell = document.createElement('td');
        statusCell.appendChild(mkBadge('Новый', 'badge-added'));

        var actions = document.createElement('td');
        actions.className = 'actions';
        actions.appendChild(mkButton('Сохранить', 'btn-sm btn-save', function () { saveAddRow(tr, false); }));
        actions.appendChild(mkButton('Отмена', 'btn-sm btn-reset', function () { tr.remove(); }));

        tr.appendChild(mkCell(num));
        tr.appendChild(mkCell(ts));
        tr.appendChild(mkCell(te));
        tr.appendChild(mkCell(subject));
        tr.appendChild(mkCell(teacher));
        tr.appendChild(mkCell(room));
        tr.appendChild(mkCell(group));
        tr.appendChild(statusCell);
        tr.appendChild(actions);
        return tr;
    }

    function collect(tr) {
        return {
            lesson_num: tr.querySelector('.inp-num').value.trim(),
            time_start: tr.querySelectorAll('.inp-time')[0].value.trim(),
            time_end: tr.querySelectorAll('.inp-time')[1].value.trim(),
            subject: tr.querySelector('.inp-subject').value.trim(),
            teacher: tr.querySelector('.inp-teacher').value.trim(),
            room: tr.querySelector('.inp-room').value.trim(),
            parallel_group: tr.querySelector('.inp-group').value.trim()
        };
    }

    function afterSave(message) {
        showMsg(message, 'success');
        loadEditor();
        loadAll();
    }

    function saveRow(tr, type) {
        var data = collect(tr);
        data.date = tr.dataset.date;
        data.class_name = tr.dataset.className;
        data.change_type = type;
        data.base_lesson_num = tr.dataset.baseLesson;
        data.base_time_start = tr.dataset.baseTime;
        data.base_parallel_group = tr.dataset.baseGroup;
        if (tr.dataset.editId) data.id = tr.dataset.editId;
        post('edit_save', data).then(function (res) {
            if (!res.ok) { showMsg(res.data.error || 'Ошибка сохранения', 'error'); return; }
            afterSave('Правка сохранена');
        }).catch(function () { showMsg('Ошибка сети', 'error'); });
    }

    function saveAddRow(tr, isExisting) {
        var data = collect(tr);
        data.date = tr.dataset.date;
        data.class_name = tr.dataset.className;
        data.change_type = 'add';
        if (isExisting && tr.dataset.editId) data.id = tr.dataset.editId;
        post('edit_save', data).then(function (res) {
            if (!res.ok) { showMsg(res.data.error || 'Ошибка сохранения', 'error'); return; }
            afterSave(isExisting ? 'Урок обновлён' : 'Урок добавлен');
        }).catch(function () { showMsg('Ошибка сети', 'error'); });
    }

    function hideRow(tr) {
        if (!window.confirm('Скрыть этот урок из расписания?')) return;
        var data = {
            date: tr.dataset.date,
            class_name: tr.dataset.className,
            change_type: 'delete',
            base_lesson_num: tr.dataset.baseLesson,
            base_time_start: tr.dataset.baseTime,
            base_parallel_group: tr.dataset.baseGroup,
            lesson_num: tr.querySelector('.inp-num').value.trim()
        };
        if (tr.dataset.editId) data.id = tr.dataset.editId;
        post('edit_save', data).then(function (res) {
            if (!res.ok) { showMsg(res.data.error || 'Ошибка', 'error'); return; }
            afterSave('Урок скрыт');
        }).catch(function () { showMsg('Ошибка сети', 'error'); });
    }

    function resetEdit(id) {
        if (!id) return;
        if (!window.confirm('Убрать правку (вернуть как в источнике)?')) return;
        post('edit_delete', { id: id }).then(function (res) {
            if (!res.ok) { showMsg(res.data.error || 'Ошибка', 'error'); return; }
            afterSave('Правка убрана');
        }).catch(function () { showMsg('Ошибка сети', 'error'); });
    }

    // === Список всех правок ===

    function typeLabel(t) {
        return t === 'add' ? 'Добавлен' : (t === 'delete' ? 'Скрыт' : 'Изменён');
    }

    function loadAll() {
        fetch('api.php?action=edits').then(function (r) { return r.json(); }).then(function (d) {
            renderAll(d.edits || []);
        }).catch(function () {
            allEmpty.textContent = 'Ошибка загрузки';
        });
    }

    function renderAll(edits) {
        allBody.innerHTML = '';
        if (!edits.length) {
            allEmpty.hidden = false;
            allEmpty.textContent = 'Правок нет';
            allTable.hidden = true;
            return;
        }
        allEmpty.hidden = true;
        allTable.hidden = false;

        edits.forEach(function (e) {
            var tr = document.createElement('tr');
            tr.appendChild(mkText(e.date));
            tr.appendChild(mkText(e.class_name));
            tr.appendChild(mkText(typeLabel(e.change_type)));
            tr.appendChild(mkText('№' + e.lesson_num));

            var time = (e.time_start || e.cur_time_start || '—') +
                '–' + (e.time_end || e.cur_time_end || '?');
            tr.appendChild(mkText(time));

            var st = document.createElement('td');
            st.appendChild(document.createTextNode(e.subject || e.cur_subject || ''));
            var teacher = e.teacher || e.cur_teacher || '';
            if (teacher) {
                var span = document.createElement('span');
                span.textContent = ' / ' + teacher;
                st.appendChild(span);
            }
            tr.appendChild(st);

            var room = e.room || e.cur_room || '';
            tr.appendChild(mkText(room));

            var status = document.createElement('td');
            var ok = e.applied;
            var s = document.createElement('span');
            s.className = ok ? 'status-ok' : 'status-bad';
            s.textContent = ok ? 'применена' : 'урока больше нет';
            status.appendChild(s);
            tr.appendChild(status);

            var actions = document.createElement('td');
            actions.className = 'actions';
            actions.appendChild(mkButton('Удалить', 'btn-sm btn-delete', function () { resetEdit(e.id); }));
            tr.appendChild(actions);

            allBody.appendChild(tr);
        });
    }

    function mkText(text) {
        var td = document.createElement('td');
        td.textContent = (text === null || text === undefined) ? '' : String(text);
        return td;
    }

    // === Инициализация ===

    classSel.addEventListener('change', function () {
        state.cls = classSel.value;
        loadEditor();
    });
    dateInp.addEventListener('change', function () {
        state.date = dateInp.value;
        loadEditor();
    });
    addBtn.addEventListener('click', function () {
        if (!state.cls || !state.date) {
            showMsg('Сначала выберите класс и дату', 'error');
            return;
        }
        if (editorTable.hidden) editorTable.hidden = false;
        editorEmpty.hidden = true;
        editorBody.appendChild(buildNewAddRow());
    });

    // Дата по умолчанию — сегодня
    var now = new Date();
    var pad = function (n) { return (n < 10 ? '0' : '') + n; };
    state.date = now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate());
    dateInp.value = state.date;

    loadClasses();
    loadAll();
})();
