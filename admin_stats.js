/* admin_stats.js — фильтр таблицы статистики педагогов (admin_stats.php).
 * Без inline-скриптов ради CSP. Данные уже отрендерены на сервере,
 * скрипт только скрывает строки, не совпавшие с фильтром. */
(function () {
    'use strict';

    var input = document.getElementById('teacherFilter');
    var table = document.getElementById('teacherTable');
    if (!input || !table || !table.tBodies.length) return;

    var rows = Array.prototype.slice.call(table.tBodies[0].rows);

    function apply() {
        var q = input.value.trim().toLowerCase();
        rows.forEach(function (tr) {
            var name = tr.dataset.teacher || '';
            tr.hidden = q !== '' && name.indexOf(q) === -1;
        });
    }

    input.addEventListener('input', apply);

    // Клик по строке педагога → страница детализации (data-href)
    table.addEventListener('click', function (e) {
        if (e.target.closest && e.target.closest('a')) return;
        var tr = e.target.closest ? e.target.closest('tr[data-href]') : null;
        if (tr && tr.dataset.href) {
            window.location.href = tr.dataset.href;
        }
    });
})();
