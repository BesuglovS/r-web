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
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}

function dateLabel(dateStr) {
    const d = new Date(dateStr + 'T00:00:00');
    const m = ['янв','фев','мар','апр','мая','июн','июл','авг','сен','окт','ноя','дек'];
    const w = ['вс','пн','вт','ср','чт','пт','сб'];
    return `${w[d.getDay()]}, ${d.getDate()} ${m[d.getMonth()]}`;
}

function getMonday(dateStr) {
    const d = new Date(dateStr + 'T00:00:00');
    const day = d.getDay();
    const diff = d.getDate() - day + (day === 0 ? -6 : 1);
    d.setDate(diff);
    return d.toISOString().slice(0, 10);
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
    const hdr1 = mode === 'time' ? 'Класс' : 'Предмет';
    const hdr2 = mode === 'time' ? 'Предмет' : 'Класс';
    let h = '<table class="schedule-table"><thead><tr>';
    h += '<th class="col-num">№</th><th class="col-time">Время</th>';
    h += `<th>${hdr1}</th><th class="col-subject">${hdr2}</th>`;
    h += '<th class="col-extra">Учитель</th><th class="col-room">Каб.</th>';
    h += '</tr></thead><tbody>';
    for (let i = 0; i < lessons.length; i++) {
        const l = lessons[i];
        const subj = esc(l.subject) + (l.parallel_group ? ` <span class="group">(${esc(l.parallel_group)})</span>` : '');
        let rowCls = '';
        if (nowStatus && nowStatus.type === 'now' && nowStatus.index === i) rowCls = ' class="row-now"';
        h += `<tr${rowCls}>`;
        h += `<td class="col-num">${esc(String(l.lesson_num))}</td>`;
        h += `<td class="col-time">${esc(l.time_start)}–${esc(l.time_end)}</td>`;
        if (mode === 'time') {
            h += `<td>${esc(l.class_name)}</td><td class="col-subject">${subj}</td>`;
        } else {
            h += `<td class="col-subject">${subj}</td><td>${esc(l.class_name)}</td>`;
        }
        h += `<td class="col-extra">${esc(l.teacher)}</td>`;
        h += `<td class="col-room">${esc(l.room)}</td></tr>`;

        if (nowStatus && nowStatus.type === 'break' && nowStatus.after === i) {
            h += `<tr class="row-break"><td colspan="6"><div class="break-now">сейчас перерыв</div></td></tr>`;
        }
    }
    if (nowStatus && nowStatus.type === 'done') {
        h += `<tr class="row-done"><td colspan="6"><div class="day-done">все уроки прошли</div></td></tr>`;
    }
    return h + '</tbody></table>';
}

/* ── Комбинированный рендер (карточки + таблица) ── */
function renderSchedule(lessons, mode, nowStatus) {
    return renderCards(lessons, mode, nowStatus) + renderTable(lessons, mode, nowStatus);
}

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
