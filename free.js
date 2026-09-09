/* free.js — страница «Аудитории» (свободные аудитории по корпусам).
 * Переиспользует из app.js: apiFetch, esc, todayLocal, fetchLastCheck.
 * Время выбирается из выпадающего списка времён начала уроков дня;
 * авто-выбор следует за «сейчас»: внутри урока — его начало,
 * в перемене или до первого — начало следующего.
 */
(function () {
    if (!document.getElementById('buildingSelect')) return;

    const sel = document.getElementById('buildingSelect');
    const dateInput = document.getElementById('dateInput');
    const timeSelect = document.getElementById('timeSelect');
    const modeTabs = document.getElementById('modeTabs');
    const box = document.getElementById('freeContent');

    let mode = localStorage.getItem('freeMode') === 'all' ? 'all' : 'free';
    let periods = [];        // интервалы уроков текущей даты
    let starts = [];         // времена начала уроков — значения выпадающего списка
    let optionsKey = '';     // чтобы не перестраивать список без изменений
    let selectedTime = '';   // текущее выбранное время
    let timeIsManual = false; // после ручного выбора авто-переключение отключается
    let reqParams = null;    // параметры запроса в полёте — защита от устаревших ответов

    const savedBuilding = localStorage.getItem('freeBuilding');
    if (savedBuilding === '2' || savedBuilding === '3') sel.value = savedBuilding;

    dateInput.value = todayLocal();

    sel.addEventListener('change', () => {
        localStorage.setItem('freeBuilding', sel.value);
        load();
    });
    dateInput.addEventListener('change', () => {
        // Список времён от новой даты придёт с ответом; выбор — заново авто
        timeIsManual = false;
        selectedTime = '';
        load();
    });
    timeSelect.addEventListener('change', () => {
        timeIsManual = true;
        selectedTime = timeSelect.value;
        load();
    });
    for (const b of modeTabs.querySelectorAll('.day-tab')) {
        b.addEventListener('click', () => {
            mode = b.dataset.mode;
            localStorage.setItem('freeMode', mode);
            drawMode();
            render();
        });
    }

    drawMode();
    load(nowHM()); // первый запрос — по текущему времени, список придёт с ответом
    fetchLastCheck();
    // Автообновление раз в минуту: пока время не выбрано вручную и дата —
    // сегодня, выбор сам переходит на текущий/следующий урок
    setInterval(tick, 60000);

    function nowHM() {
        const d = new Date();
        return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
    }

    /* Времена в БД могут быть '9:00' (без паддинга) — сравнивать строки
     * и подставлять в select можно только 'HH:MM' */
    function padHM(hm) {
        const m = /^(\d{1,2}):(\d{2})$/.exec(String(hm || ''));
        if (!m || +m[1] > 23 || +m[2] > 59) return null;
        return String(+m[1]).padStart(2, '0') + ':' + m[2];
    }

    /* Автовыбор урока для момента hm:
     * — внутри урока → его начало; в перемене или до первого урока →
     *   начало ближайшего следующего; после последнего → последний. */
    function autoPick(hm) {
        if (!starts.length) return '';
        for (const p of periods) {
            if (hm >= p.time_start && hm < p.time_end && starts.includes(p.time_start)) {
                return p.time_start;
            }
        }
        for (const s of starts) {
            if (hm < s) return s;
        }
        return starts[starts.length - 1];
    }

    /* Перестройка списка времён (только если набор изменился или прежний
     * выбор пропал — например, при смене даты) */
    function rebuildTimeOptions() {
        const key = starts.join(',');
        if (key === optionsKey && selectedTime && starts.includes(selectedTime)) return;
        optionsKey = key;
        timeSelect.innerHTML = '';
        if (!starts.length) {
            const o = document.createElement('option');
            o.value = '';
            o.textContent = '--:--';
            timeSelect.appendChild(o);
        }
        for (const s of starts) {
            const o = document.createElement('option');
            o.value = s;
            o.textContent = s;
            timeSelect.appendChild(o);
        }
        if (selectedTime && starts.includes(selectedTime)) {
            timeSelect.value = selectedTime;
        } else if (starts.length) {
            selectedTime = autoPick(nowHM());
            timeSelect.value = selectedTime;
        }
    }

    /* После ответа: авто-выбор мог сместиться (начался следующий урок) —
     * перезагружаем данные с новым временем */
    function autoReloadIfNeeded() {
        if (timeIsManual) return;
        const v = autoPick(nowHM());
        if (v && v !== selectedTime) {
            selectedTime = v;
            timeSelect.value = v;
            load();
        }
    }

    function tick() {
        if (dateInput.value === todayLocal() && !timeIsManual && starts.length) {
            const v = autoPick(nowHM());
            if (v && v !== selectedTime) {
                selectedTime = v;
                timeSelect.value = v;
            }
        }
        load();
    }

    async function load(timeOverride) {
        const building = sel.value;
        const date = dateInput.value;
        const time = timeOverride || selectedTime || nowHM();
        if (!building || !date || !time) return;
        reqParams = { building, date, time };
        box.innerHTML = '<div class="empty-state">Загрузка...</div>';
        try {
            const d = await apiFetch('free_rooms', reqParams);
            // Параметры успели смениться — ответ устарел
            if (!reqParams
                || reqParams.building !== building
                || reqParams.date !== date
                || reqParams.time !== time) return;
            periods = (d.periods || [])
                .map(p => ({ time_start: padHM(p.time_start), time_end: padHM(p.time_end) }))
                .filter(p => p.time_start !== null && p.time_end !== null)
                .sort((a, b) => a.time_start < b.time_start ? -1 : 1);
            starts = [...new Set((d.lesson_starts || [])
                .map(x => padHM(x.time_start))
                .filter(v => v !== null))];
            rebuildTimeOptions();
            // Выбор сместился при перестройке списка (первая загрузка/смена
            // даты) — данные под старое время не показываем, перезагружаем
            if (selectedTime && selectedTime !== time) {
                load();
                return;
            }
            lastData = d;
            render();
            autoReloadIfNeeded();
        } catch (e) {
            if (!reqParams
                || reqParams.building !== building
                || reqParams.date !== date
                || reqParams.time !== time) return;
            box.innerHTML = '<div class="empty-state">Ошибка загрузки</div>';
        }
    }

    function drawMode() {
        for (const b of modeTabs.querySelectorAll('.day-tab')) {
            b.classList.toggle('active', b.dataset.mode === mode);
        }
    }

    function render() {
        if (!lastData) return;
        const rooms = lastData.rooms || [];
        const free = rooms.filter(r => r.free);
        let h = `<div class="free-summary">Свободно <b>${free.length}</b> из ${rooms.length}</div>`;
        const shown = mode === 'all' ? rooms : free;
        if (shown.length) {
            h += '<div class="free-grid">';
            shown.forEach((r, i) => h += renderTile(r, mode === 'all' ? i : -1));
            h += '</div>';
        } else {
            h += '<div class="empty-state">Свободных аудиторий нет</div>';
        }
        box.innerHTML = h;
    }

    /* Фамилия преподавателя («Безуглов С.В.» → «Безуглов») */
    function shortTeacher(t) {
        return String(t || '').trim().split(/\s+/)[0] || '';
    }

    /* Группа: класс + (при наличии) параллельная подгруппа */
    function groupLabel(r) {
        return r.class_name + (r.parallel_group ? ` (${r.parallel_group})` : '');
    }

    function renderTile(r, idx) {
        const long = r.name.length >= 8 ? ' room-long' : '';
        if (r.free) {
            return `<div class="room-tile room-free${long}">${esc(r.name)}</div>`;
        }
        return `<div class="room-tile room-busy${long}" data-idx="${idx}">
            <div class="rt-name">${esc(r.name)}</div>
            <div class="rt-subject">${esc(r.subject)}</div>
            <div class="rt-teacher">${esc(shortTeacher(r.teacher))}</div>
            <div class="rt-class">${esc(groupLabel(r))}</div>
        </div>`;
    }

    /* ── Модальное окно с полной информацией об уроке ── */
    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.hidden = true;
    document.body.appendChild(overlay);

    box.addEventListener('click', (e) => {
        const tile = e.target.closest('.room-tile[data-idx]');
        if (tile) openModal(+tile.dataset.idx);
    });
    overlay.addEventListener('click', (e) => {
        if (e.target === overlay || e.target.closest('.modal-close')) close();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !overlay.hidden) close();
    });

    function close() {
        overlay.hidden = true;
        overlay.innerHTML = '';
    }

    function openModal(idx) {
        if (!lastData) return;
        const r = (lastData.rooms || [])[idx];
        if (!r || r.free) return;
        const buildingLabel = sel.options[sel.selectedIndex]?.textContent || '';
        const rows = [];
        if (r.lesson_num || r.time_start) {
            const num = r.lesson_num ? `${r.lesson_num} урок, ` : '';
            rows.push(['Время', `${num}${r.time_start}–${r.time_end}`]);
        }
        if (r.subject) rows.push(['Предмет', r.subject]);
        if (r.class_name || r.parallel_group) rows.push(['Группа', groupLabel(r)]);
        if (r.teacher) rows.push(['Учитель', r.teacher]);
        rows.push(['Дата', dateLabel(lastData.date)]);

        let h = `<div class="modal" role="dialog" aria-label="Информация об уроке">
            <button class="modal-close" aria-label="Закрыть">&times;</button>
            <div class="modal-title">Кабинет ${esc(r.name)}</div>
            <div class="modal-sub">${esc(buildingLabel)} · занято</div>
            <div class="modal-list">`;
        for (const [k, v] of rows) {
            h += `<div class="m-row"><span class="m-k">${esc(k)}</span><span class="m-v">${esc(v)}</span></div>`;
        }
        h += '</div></div>';
        overlay.innerHTML = h;
        overlay.hidden = false;
    }
})();
