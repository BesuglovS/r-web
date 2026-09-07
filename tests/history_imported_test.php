<?php
/**
 * Тест: «Изменено» для показанного расписания должно браться из schedule_history.changed_at
 * (а не из перезатираемого при каждом импорте schedule.imported_at).
 */
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE schedule_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    date TEXT, class_name TEXT, teacher TEXT, changed_at TEXT
)");
// История: последнее реальное изменение учителя Куцева на 08.09 было в  ../../в 18:50
$pdo->exec("INSERT INTO schedule_history (date,class_name,teacher,changed_at)
    VALUES ('2026-09-08','5А','Куцева И.К.','2026-09-07 18:50:03'),
           ('2026-09-08','5А','Куцева И.К.','2026-09-09 09:00:00'),
           ('2026-09-08','5Б','Иванов','2026-09-09 09:00:00')"); // другой класс/учитель на ту же дату — не должен влиять

// Имитируем «расписание, где imported_at перезатёрт всем файлом» (все строки 20:45)
$select = "SELECT MAX(changed_at) FROM schedule_history
    WHERE date = :date AND teacher = :teacher";
$st = $pdo->prepare($select);
$st->execute([':date' => '2026-09-08', ':teacher' => 'Куцева И.К.']);
$got = $st->fetchColumn();

// Ожидаем максимум по этому учителю+дате = 09.09 09:00 (строки для этого учителя), а не Иванова
$expected = '2026-09-09 09:00:00';
if ($got === $expected) {
    echo "OK: MAX(changed_at по учителю+дата) = $got\n";
} else {
    echo "FAIL: получено $got, ожидалось $expected\n";
    exit(1);
}

// Проверка: запрос по классу тоже изолирован
$st = $pdo->prepare("SELECT MAX(changed_at) FROM schedule_history
    WHERE date = :date AND class_name = :class_name");
$st->execute([':date' => '2026-09-08', ':class_name' => '5А']);
$gotClass = $st->fetchColumn();
echo "MAX(changed_at по классу 5А+дата) = $gotClass\n";
if ($gotClass === '2026-09-09 09:00:00') {
    echo "OK class\n";
} else {
    echo "FAIL class\n";
    exit(1);
}
echo "TEST OK\n";