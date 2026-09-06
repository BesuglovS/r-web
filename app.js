/* app.js — фронтенд-логика расписания (mobile-first) */

/* ── Theme ── */
(function initTheme() {
    const saved = localStorage.getItem('theme');
    if (saved) {
        document.documentElement.setAttribute('data-theme', saved);
    } else if (window.matchMedia('(prefers-color-scheme: light)').matches) {
        document.documentElement.setAttribute('data-theme', 'light');
    }
    document.addEventListener('DOMContentLoaded', function() {
        var btn = document.getElementById('themeToggle');
        if (btn) btn.addEventListener('click', function() {
            var cur = document.documentElement.getAttribute('data-theme');
            var next = cur === 'light' ? 'dark' : 'light';
            document.documentElement.setAttribute('data-theme', next);
            localStorage.setItem('theme', next);
        });
    });
})();

const API = 'api.php';

async function apiFetch(action, params = {}) {
    const url = new URL(API, location.href);
    url.searchParams.set('action', action);
    for (const [k, v] of Object.entries(params)) {
        if (v != null && v !== '') url.searchParams.set(k, v);
    }
    const r = await fetch(url);
    if (!r.ok) throw new Error(`HTTP ${r.status}`);
    return r.json();
}

function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, (ch) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[ch]));
}

function dateLabel(dateStr) {
    const d = new Date(dateStr + 'T00:00:00');
    const m = ['янв','фев','мар','апр','мая','июн','июл','авг','сен','окт','ноя','дек'];
    const w = ['вс','пн','вт','ср','чт','пт','сб'];
    return `${w[d.getDay()]}, ${d.getDate()} ${m[d.getMonth()]}`;
}

/* Локальная дата «сегодня» (не UTC — UTC давал «вчера» до 04:00 по Самаре) */
function todayLocal() {
    const d = new Date();
    return d.getFullYear() + '-'
        + String(d.getMonth() + 1).padStart(2, '0') + '-'
        + String(d.getDate()).padStart(2, '0');
}

/* ── Рендер карточек (мобилка/планшет) ── */
function renderCards(lessons, mode, nowStatus) {
    if (!lessons.length) return '<div class="empty-state">Нет уроков</div>';
    let h = '<div class="lessons">';
    for (let i = 0; i < lessons.length; i++) {
        const l = lessons[i];
        const extra = mode === 'time'
            ? esc(l.teacher)
            : esc(l.class_name);

        let cls = 'lesson';
        if (nowStatus && nowStatus.type === 'now' && nowStatus.index === i) cls += ' lesson-now';

        h += `<div class="${cls}">
            <div class="lesson-num">${esc(String(l.lesson_num))}</div>
            <div class="lesson-subject">${esc(l.subject)}${l.parallel_group ? ` <span class="group">(${esc(l.parallel_group)})</span>` : ''}</div>
            <div class="lesson-meta">${extra}</div>
            ${l.room ? `<div class="lesson-room">${esc(l.room)}</div>` : ''}
            <div class="lesson-time">${esc(l.time_start)}–${esc(l.time_end)}</div>
        </div>`;

        if (nowStatus && nowStatus.type === 'break' && nowStatus.after === i) {
            h += '<div class="break-now">сейчас перерыв</div>';
        }
    }
    if (nowStatus && nowStatus.type === 'done') {
        h += '<div class="day-done">все уроки прошли</div>';
    }
    return h + '</div>';
}
function renderTable(lessons, mode, nowStatus) {
    if (!lessons.length) return '';
    let h = '<table class="schedule-table"><thead><tr>';
    h += '<th class="col-num">№</th><th class="col-time">Время</th>';
    h += '<th class="col-subject">Предмет</th>';
    if (mode === 'time') {
        h += '<th class="col-extra">Учитель</th>';
    } else {
        h += '<th>Класс</th>';
    }
    h += '<th class="col-room">Каб.</th>';
    h += '</tr></thead><tbody>';
    for (let i = 0; i < lessons.length; i++) {
        const l = lessons[i];
        const subj = esc(l.subject) + (l.parallel_group ? ` <span class="group">(${esc(l.parallel_group)})</span>` : '');
        let rowCls = '';
        if (nowStatus && nowStatus.type === 'now' && nowStatus.index === i) rowCls = ' class="row-now"';
        h += `<tr${rowCls}>`;
        h += `<td class="col-num">${esc(String(l.lesson_num))}</td>`;
        h += `<td class="col-time">${esc(l.time_start)}–${esc(l.time_end)}</td>`;
        h += `<td class="col-subject">${subj}</td>`;
        if (mode === 'time') {
            h += `<td class="col-extra">${esc(l.teacher)}</td>`;
        } else {
            h += `<td>${esc(l.class_name)}</td>`;
        }
        h += `<td class="col-room">${esc(l.room)}</td></tr>`;

        if (nowStatus && nowStatus.type === 'break' && nowStatus.after === i) {
            h += `<tr class="row-break"><td colspan="5"><div class="break-now">сейчас перерыв</div></td></tr>`;
        }
    }
    if (nowStatus && nowStatus.type === 'done') {
        h += `<tr class="row-done"><td colspan="5"><div class="day-done">все уроки прошли</div></td></tr>`;
    }
    return h + '</tbody></table>';
}

/* ── Комбинированный рендер: одна версия по ширине экрана ── */
function isDesktop() {
    return window.matchMedia('(min-width: 900px)').matches;
}

let _lastRender = null;
function renderSchedule(lessons, mode, nowStatus) {
    _lastRender = { lessons, mode, nowStatus };
    return isDesktop()
        ? renderTable(lessons, mode, nowStatus)
        : renderCards(lessons, mode, nowStatus);
}

/* Перерисовка при пересечении брейкпоинта 900px (debounce) */
(function () {
    let timer = null;
    let lastDesktop = isDesktop();
    window.addEventListener('resize', () => {
        clearTimeout(timer);
        timer = setTimeout(() => {
            if (isDesktop() !== lastDesktop && _lastRender) {
                lastDesktop = isDesktop();
                const box = document.getElementById('scheduleContent');
                if (box) {
                    box.innerHTML = renderSchedule(
                        _lastRender.lessons, _lastRender.mode, _lastRender.nowStatus
                    );
                }
            }
        }, 150);
    });
})();

/* ── Текущее время: определение статуса ── */
function getCurrentStatus(lessons) {
    if (!lessons.length) return null;
    const now = new Date();
    const hm = now.getHours().toString().padStart(2,'0') + ':' + now.getMinutes().toString().padStart(2,'0');

    for (let i = 0; i < lessons.length; i++) {
        if (hm >= lessons[i].time_start && hm < lessons[i].time_end) {
            return { type: 'now', index: i };
        }
    }
    for (let i = 0; i < lessons.length - 1; i++) {
        if (hm >= lessons[i].time_end && hm < lessons[i + 1].time_start) {
            return { type: 'break', after: i };
        }
    }
    if (hm >= lessons[lessons.length - 1].time_end) {
        return { type: 'done' };
    }
    return null;
}

/* ── Время последней проверки и изменения отображаемого расписания ── */
let lastImportInfo = { checked: '', imported: '' };

function fmtDateTime(ts) {
    const parts = String(ts || '').split(' ');
    const dm = (parts[0] || '').split('-');
    if (dm.length !== 3) return String(ts || '');
    return dm[2] + '.' + dm[1] + '.' + dm[0] + ' в ' + (parts[1] || '');
}

function renderLastImport() {
    const el = document.getElementById('lastImport');
    if (!el) return;
    const parts = [];
    if (lastImportInfo.checked) parts.push('Проверено ' + fmtDateTime(lastImportInfo.checked));
    if (lastImportInfo.imported) parts.push('Изменено ' + fmtDateTime(lastImportInfo.imported));
    el.textContent = parts.join(' · ');
}

async function fetchLastCheck() {
    try {
        const d = await apiFetch('last_import');
        lastImportInfo.checked = d.checked_at || d.imported_at || '';
    } catch (e) {
        lastImportInfo.checked = '';
    }
    renderLastImport();
}

/* ── История изменений ── */
function changeTypeLabel(t) {
    return { added: 'добавлен', removed: 'удалён', room_changed: 'кабинет изменён' }[t] || t;
}

function renderHistory(data) {
    if (!data.history.length) return '<div class="empty-state">История пуста</div>';
    let h = '';
    for (const v of data.history) {
        h += `<div class="history-version">`;
        h += `<div class="history-head">Версия ${v.version} — ${esc(v.changed_at || '')} (${v.count} изм.)</div>`;
        for (const d of v.details) {
            h += `<div class="history-row">`;
            h += `${esc(dateLabel(d.date))}, ${esc(d.class_name)}, ${esc(String(d.lesson_num))} урок: `;
            h += `${esc(d.subject)} (${esc(d.teacher)})`;
            if (d.change_type === 'room_changed') {
                h += ` — каб. ${esc(d.old_room)} → ${esc(d.room)}`;
            }
            h += ` <span class="group">${esc(changeTypeLabel(d.change_type))}</span>`;
            h += `</div>`;
        }
        h += `</div>`;
    }
    return h;
}

/* Текущая отображаемая дата (для фильтра истории изменений) */
let currentPageDate = '';

/* Время изменения «06.09 15:56» из «2026-09-06 15:56:09» */
function shortWhen(ts) {
    const p = String(ts || '').split(' ');
    if (p.length !== 2) return ts || '';
    const d = p[0].split('-');
    return (d[2] || '') + '.' + (d[1] || '') + ' ' + p[1].slice(0, 5);
}

/* Плоский список изменений (с фильтром по классу/учителю/дате) — таблица.
 * Первый столбец: предмет (расписание ученика) или класс (расписание учителя).
 * Последний столбец: характер изменения значком (+ добавлен, − удалён, ⇄ кабинет).
 */
function renderHistoryFlat(changes, emptyMsg, mode) {
    if (!changes.length) return `<div class="empty-state">${esc(emptyMsg)}</div>`;
    const isTeacher = mode === 'teacher';
    let h = '<div class="history-scroll"><table class="history-table"><thead><tr>';
    h += `<th>${isTeacher ? 'Класс' : 'Предмет'}</th>`;
    h += '<th class="ht-time">Время</th>';
    h += `<th>${isTeacher ? 'Предмет' : 'Учитель'}</th><th class="ht-room">Каб.</th>`;
    h += '<th class="ht-change">Изм.</th>';
    h += '</tr></thead><tbody>';
    for (const c of changes) {
        const first = isTeacher ? esc(c.class_name) : esc(c.subject);
        const second = isTeacher ? esc(c.subject) : esc(c.teacher);
        const room = c.change_type === 'room_changed'
            ? esc(c.old_room) + ' → ' + esc(c.room)
            : esc(c.room || '—');
        const change = c.change_type === 'added'
            ? '<span class="ch-add" title="добавлен">+</span>'
            : c.change_type === 'removed'
                ? '<span class="ch-del" title="удалён">−</span>'
                : '<span class="ch-room" title="кабинет изменён">⇄</span>';
        h += '<tr>';
        h += `<td class="ht-first">${first}</td>`;
        h += `<td class="ht-time">${esc(c.time_start)}–${esc(c.time_end)}</td>`;
        h += `<td>${second}</td>`;
        h += `<td class="ht-room">${room}</td>`;
        h += `<td class="ht-change">${change}<span class="ht-when">${esc(shortWhen(c.changed_at))}</span></td>`;
        h += '</tr>';
    }
    h += '</tbody></table></div>';
    return h;
}

async function showHistory(containerId, filter) {
    const el = document.getElementById(containerId);
    if (!el) return;
    el.innerHTML = '<div class="empty-state">Загрузка...</div>';
    try {
        const params = {};
        let filtered = false;
        if (filter) {
            const item = localStorage.getItem(filter.storageKey);
            if (item) {
                params[filter.param] = item;
                filtered = true;
            }
        }
        if (currentPageDate) {
            params.date = currentPageDate;
            filtered = true;
        }
        const d = await apiFetch('history', params);
        if (d.changes) {
            const what = filter?.param === 'teacher' ? 'учителя' : 'класса';
            const when = currentPageDate ? ' на выбранную дату' : '';
            el.innerHTML = renderHistoryFlat(
                d.changes,
                filtered ? `Изменений для выбранного ${what}${when} пока нет` : 'История пуста',
                filter?.mode
            );
        } else {
            el.innerHTML = renderHistory(d);
        }
    } catch (e) {
        el.innerHTML = '<div class="empty-state">Ошибка загрузки истории</div>';
    }
}

function initHistoryToggle(btnId, containerId, filter) {
    const btn = document.getElementById(btnId);
    const box = document.getElementById(containerId);
    if (!btn || !box) return;
    box.style.display = 'none';
    btn.addEventListener('click', () => {
        const open = box.style.display !== 'none';
        if (open) {
            box.style.display = 'none';
        } else {
            box.style.display = '';
            // Загружаем при каждом открытии — фильтр зависит от текущего выбора
            showHistory(containerId, filter);
        }
    });
}

/* ── Общая инициализация страницы расписания (ученики / учителя) ──
 * cfg = {
 *   mode: 'class' | 'teacher',
 *   action: 'classes' | 'teachers',
 *   selectId, itemStorageKey,
 * }
 */
async function initSchedulePage(cfg) {
    const sel = document.getElementById(cfg.selectId);
    const weekSelect = document.getElementById('weekSelect');
    const weekSelectWrap = weekSelect ? weekSelect.closest('.select-wrap') : null;
    const dayTabs = document.getElementById('dayTabs');
    const box = document.getElementById('scheduleContent');
    let dates = [], weeks = [], item = '', week = '', date = '';

    try {
        const d = await apiFetch(cfg.action);
        for (const v of (cfg.mode === 'class' ? d.classes : d.teachers)) {
            const o = document.createElement('option');
            o.value = v; o.textContent = v;
            sel.appendChild(o);
        }
    } catch (e) { box.innerHTML = '<div class="empty-state">Ошибка загрузки</div>'; return; }

    try { const d = await apiFetch('weeks'); weeks = d.weeks; } catch (e) { weeks = []; }

    function shiftDate(dateStr, days) {
        const d = new Date(dateStr + 'T00:00:00');
        d.setDate(d.getDate() + days);
        return d.getFullYear() + '-'
            + String(d.getMonth() + 1).padStart(2, '0') + '-'
            + String(d.getDate()).padStart(2, '0');
    }

    function weekOf(dateStr) {
        return weeks.find(w => dateStr >= w.week_start && dateStr <= w.week_end)?.week_start || '';
    }

    function drawWeekSelect() {
        if (!weekSelect) return;
        weekSelect.innerHTML = '';
        for (const w of weeks) {
            const o = document.createElement('option');
            o.value = w.week_start;
            o.textContent = w.label;
            weekSelect.appendChild(o);
        }
        weekSelect.value = week;
        if (weekSelectWrap) weekSelectWrap.style.display = weeks.length > 1 ? '' : 'none';
    }

    async function loadDates() {
        if (!week) { dates = []; date = ''; currentPageDate = ''; drawDayTabs(); return; }
        try {
            const d = await apiFetch('dates', { week });
            dates = d.dates;
        } catch (e) { dates = []; }
        if (!date || !dates.some(x => x.date === date)) {
            const today = todayLocal();
            const dow = new Date(today + 'T00:00:00').getDay(); // 0 — воскресенье
            const tomorrow = shiftDate(today, 1);
            const yesterday = shiftDate(today, -1);
            const inNextWeek = week === weekOf(tomorrow);

            if (dow === 0 && inNextWeek && !dates.some(x => x.date === tomorrow)) {
                // Воскресенье, но «завтра» в следующей неделе недоступно —
                // откатываемся к текущей неделе и вчерашнему дню (суббота)
                week = weekOf(today) || (weeks.length ? weeks[weeks.length - 1].week_start : '');
                date = '';
                drawWeekSelect();
                await loadDates();
                return;
            }

            // Дефолтный день недели: сегодня; в воскресенье — завтра (если оно
            // в открытой следующей неделе), иначе вчера (суббота)
            const preferred = dow === 0 ? (inNextWeek ? tomorrow : yesterday) : today;
            date = dates.some(x => x.date === preferred) ? preferred : (dates.length ? dates[0].date : '');
        }
        currentPageDate = date;
        drawDayTabs();
        if (item && date) load();
    }

    function drawDayTabs() {
        dayTabs.innerHTML = '';
        for (const d of dates) {
            const b = document.createElement('button');
            b.className = 'day-tab' + (d.date === date ? ' active' : '');
            b.textContent = dateLabel(d.date);
            b.onclick = () => { date = d.date; currentPageDate = date; drawDayTabs(); load(); };
            dayTabs.appendChild(b);
        }
    }

    async function load() {
        if (!item || !date) {
            box.innerHTML = `<div class="empty-state">Выберите ${cfg.mode === 'class' ? 'класс' : 'учителя'} и день</div>`;
            return;
        }
        box.innerHTML = '<div class="empty-state">Загрузка...</div>';
        try {
            const params = { date };
            if (cfg.mode === 'class') params.class = item; else params.teacher = item;
            const d = await apiFetch('schedule', params);
            const di = dates.find(x => x.date === date);
            const today = todayLocal();
            const nowStatus = date === today ? getCurrentStatus(d.schedule) : null;
            let h = di ? `<div class="date-header">${esc(dateLabel(di.date))}</div>` : '';
            h += renderSchedule(d.schedule, cfg.mode === 'class' ? 'time' : 'class', nowStatus);
            box.innerHTML = h;
            // Время последнего изменения отображаемого расписания
            lastImportInfo.imported = d.imported_at || '';
            renderLastImport();
        } catch (e) { box.innerHTML = '<div class="empty-state">Ошибка загрузки</div>'; }
    }

    sel.onchange = () => { item = sel.value; localStorage.setItem(cfg.itemStorageKey, item); load(); };

    if (weekSelect) {
        weekSelect.onchange = () => { week = weekSelect.value; date = ''; loadDates(); };
    }

    if (sel.options.length > 1) {
        const saved = localStorage.getItem(cfg.itemStorageKey);
        if (saved && [...sel.options].some(o => o.value === saved)) {
            sel.value = saved; item = saved;
        } else {
            sel.selectedIndex = 1; item = sel.value;
        }
    }

    // Дефолтная неделя: текущая; в воскресенье — неделя завтрашнего дня
    // (если она импортирована), иначе текущая
    const today = todayLocal();
    const tomorrow = shiftDate(today, 1);
    if (new Date(today + 'T00:00:00').getDay() === 0 && weekOf(tomorrow)) {
        week = weekOf(tomorrow);
    } else {
        week = weekOf(today) || (weeks.length ? weeks[weeks.length - 1].week_start : '');
    }
    drawWeekSelect();
    loadDates();
    fetchLastCheck();
}
