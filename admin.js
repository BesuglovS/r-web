/* admin.js — клиентская логика админ-панели.
 * Вынесен из admin.php, чтобы CSP мог запретить inline-скрипты (script-src 'self').
 * CSRF-токен передаётся через <meta name="csrf-token"> в head.
 */
document.getElementById('importForm').addEventListener('submit', function() {
    var btn = document.getElementById('importBtn');
    btn.disabled = true;
    btn.textContent = 'Импорт...';
});

var CSRF_TOKEN = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

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

/* Inline-обработчики (onclick=/onsubmit=) запрещены CSP (script-src 'self'),
 * поэтому клики/сабмиты вешаем делегированием. */
document.addEventListener('click', function(e) {
    var btn = e.target.closest ? e.target.closest('.btn-edit') : null;
    if (btn) startEdit(btn);
    // Кликабельные строки журналов (импорты/проверки) → страница изменений
    var row = e.target.closest ? e.target.closest('tr.clickable[data-href]') : null;
    if (row) location.href = row.dataset.href;
});

document.addEventListener('submit', function(e) {
    var f = e.target;
    if (f && f.classList && f.classList.contains('form-delete-source')) {
        if (!confirm('Удалить источник?')) e.preventDefault();
        return;
    }
    // Кнопка «Импортировать все»: блокируем повторный сабмит (был inline onclick)
    var btn = f.querySelector && f.querySelector('button[name="import_all"]');
    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Импорт...';
    }
});

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
