<?php
/**
 * Smoke-тест правок расписания: миграция v7 (+перенос room_corrections),
 * наложение edit/add/delete, статус «осиротела» и сохранение при импорте.
 */
$db = tempnam(sys_get_temp_dir(), 'editsdb');
putenv("SCHEDULE_DB_PATH=$db");
require __DIR__ . '/../src/db.php';
require __DIR__ . '/../src/edits.php';

$pdo = get_db();
if (!$pdo) { fwrite(STDERR, "no db\n"); exit(1); }

// Легаси-таблица корректировок до init_db — проверим перенос в schedule_edits
$pdo->exec("CREATE TABLE room_corrections (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    date TEXT NOT NULL, class_name TEXT NOT NULL, lesson_num INTEGER NOT NULL,
    time_start TEXT NOT NULL, time_end TEXT DEFAULT '', subject TEXT DEFAULT '',
    teacher TEXT DEFAULT '', room_old TEXT DEFAULT '', room_new TEXT NOT NULL, created_at TEXT NOT NULL)");
$pdo->exec("INSERT INTO room_corrections (date, class_name, lesson_num, time_start, time_end, subject, teacher, room_old, room_new, created_at)
            VALUES ('2026-09-15','7А',2,'10:20','11:05','Математика','Иванов И.И.','203','305','2026-09-15 10:00:00')");

init_db($pdo);
echo "user_version: " . $pdo->query('PRAGMA user_version')->fetchColumn() . "\n";

$migrated = $pdo->query("SELECT COUNT(*) FROM schedule_edits")->fetchColumn();
if ((int)$migrated !== 1) {
    fwrite(STDERR, "FAIL migration: expected 1 edit, got $migrated\n");
    exit(1);
}
echo "migration: 1 edit from room_corrections — OK\n";

// Урок 15.09.2026, 7А, 2-й урок 10:20-11:05, кабинет 203
$pdo->prepare("INSERT INTO schedule (sheet_name, date, day_of_week, class_name, lesson_num,
    time_start, time_end, subject, teacher, room, parallel_group, imported_at)
    VALUES ('ПН 15.09', '2026-09-15', 'понедельник', '7А', 2, '10:20', '11:05', 'Математика', 'Иванов И.И.', '203', NULL, '2026-09-14 08:00:00')")->execute();

// Перенесённая правка применяется: кабинет 305, остальное из источника
$edits = get_edits($pdo, '2026-09-15', '7А');
$rows = apply_edits(
    $pdo->query("SELECT * FROM schedule WHERE date='2026-09-15' AND class_name='7А'")->fetchAll(PDO::FETCH_ASSOC),
    $edits,
    ['date' => '2026-09-15', 'class' => '7А']
);
if (count($rows) !== 1 || $rows[0]['room'] !== '305' || !$rows[0]['room_corrected'] || $rows[0]['subject'] !== 'Математика') {
    fwrite(STDERR, "FAIL edit apply: " . json_encode($rows, JSON_UNESCAPED_UNICODE) . "\n");
    exit(1);
}
echo "edit apply: room 305, subject from source — OK\n";

// Добавляем и меняем поля через save_edit
save_edit($pdo, [
    'date' => '2026-09-15', 'class_name' => '7А', 'change_type' => 'edit',
    'base_lesson_num' => 2, 'base_time_start' => '10:20',
    'lesson_num' => 2, 'time_start' => '10:30', 'time_end' => '11:15',
    'subject' => 'Алгебра', 'teacher' => 'Петров П.П.', 'room' => '306', 'parallel_group' => '',
]);
save_edit($pdo, [
    'date' => '2026-09-15', 'class_name' => '7А', 'change_type' => 'add',
    'lesson_num' => 3, 'time_start' => '11:25', 'time_end' => '12:10',
    'subject' => 'Физика', 'teacher' => 'Сидоров С.С.', 'room' => '307',
]);
$edits = get_edits($pdo, '2026-09-15', '7А');
$rows = apply_edits(
    $pdo->query("SELECT * FROM schedule WHERE date='2026-09-15' AND class_name='7А'")->fetchAll(PDO::FETCH_ASSOC),
    $edits,
    ['date' => '2026-09-15', 'class' => '7А']
);
$byLesson = [];
foreach ($rows as $r) { $byLesson[(int)$r['lesson_num']] = $r; }
if (!isset($byLesson[2]) || $byLesson[2]['time_start'] !== '10:30' || $byLesson[2]['subject'] !== 'Алгебра' || $byLesson[3]['subject'] !== 'Физика') {
    fwrite(STDERR, "FAIL save/apply: " . json_encode($rows, JSON_UNESCAPED_UNICODE) . "\n");
    exit(1);
}
echo "save + add: оба урока на месте — OK\n";

// Удаление урока (delete) скрывает его из эффективного списка
save_edit($pdo, [
    'date' => '2026-09-15', 'class_name' => '7А', 'change_type' => 'delete',
    'base_lesson_num' => 2, 'base_time_start' => '10:20', 'lesson_num' => 2,
]);
$edits = get_edits($pdo, '2026-09-15', '7А');
$rows = apply_edits(
    $pdo->query("SELECT * FROM schedule WHERE date='2026-09-15' AND class_name='7А'")->fetchAll(PDO::FETCH_ASSOC),
    $edits,
    ['date' => '2026-09-15', 'class' => '7А']
);
if (count($rows) !== 1 || (int)$rows[0]['lesson_num'] !== 3) {
    fwrite(STDERR, "FAIL delete: " . json_encode($rows, JSON_UNESCAPED_UNICODE) . "\n");
    exit(1);
}
echo "delete: исходный урок скрыт — OK\n";

// Осиротевшая правка: урока нет в schedule
$pdo->exec("DELETE FROM schedule WHERE lesson_num = 2");
$byStatus = [];
foreach (list_edits_with_status($pdo) as $e) {
    $byStatus[$e['change_type'] . ':' . $e['lesson_num']] = $e['applied'];
}
if (!isset($byStatus['delete:2']) || $byStatus['delete:2'] !== false
    || !isset($byStatus['add:3']) || $byStatus['add:3'] !== true) {
    fwrite(STDERR, "FAIL orphan status: " . json_encode($byStatus) . "\n");
    exit(1);
}
echo "orphan status: delete без урока помечена «не применена», add активна — OK\n";

// Параллельные группы: правки групп независимы (ключ включает parallel_group)
$pdo->prepare("INSERT INTO schedule (sheet_name, date, day_of_week, class_name, lesson_num,
    time_start, time_end, subject, teacher, room, parallel_group, imported_at)
    VALUES ('ПН 15.09','2026-09-15','понедельник','8А',6,'12:30','13:10','Информатика','Безуглов С.В.','114','1','2026-09-14 08:00:00')")->execute();
$pdo->prepare("INSERT INTO schedule (sheet_name, date, day_of_week, class_name, lesson_num,
    time_start, time_end, subject, teacher, room, parallel_group, imported_at)
    VALUES ('ПН 15.09','2026-09-15','понедельник','8А',6,'12:30','13:10','Информатика','Безуглов С.В.','114','2','2026-09-14 08:00:00')")->execute();
save_edit($pdo, [
    'date' => '2026-09-15', 'class_name' => '8А', 'change_type' => 'edit',
    'base_lesson_num' => 6, 'base_time_start' => '12:30', 'base_parallel_group' => '2',
    'lesson_num' => 6, 'room' => '115',
]);
$rows = apply_edits(
    $pdo->query("SELECT * FROM schedule WHERE date='2026-09-15' AND class_name='8А'")->fetchAll(PDO::FETCH_ASSOC),
    get_edits($pdo, '2026-09-15', '8А'),
    ['date' => '2026-09-15', 'class' => '8А']
);
$byGroup = [];
foreach ($rows as $r) { $byGroup[(string)$r['parallel_group']] = $r; }
if (count($rows) !== 2
    || $byGroup['1']['room'] !== '114' || $byGroup['1']['room_corrected']
    || $byGroup['2']['room'] !== '115' || !$byGroup['2']['room_corrected']) {
    fwrite(STDERR, "FAIL parallel edit: " . json_encode($rows, JSON_UNESCAPED_UNICODE) . "\n");
    exit(1);
}

// Независимая правка второй группы
save_edit($pdo, [
    'date' => '2026-09-15', 'class_name' => '8А', 'change_type' => 'edit',
    'base_lesson_num' => 6, 'base_time_start' => '12:30', 'base_parallel_group' => '1',
    'lesson_num' => 6, 'room' => '116',
]);
$cntParallel = 0;
foreach (list_edits_with_status($pdo) as $e) {
    if ($e['class_name'] === '8А' && (int)$e['base_lesson_num'] === 6) {
        $cntParallel++;
    }
}
if ($cntParallel !== 2) {
    fwrite(STDERR, "FAIL parallel list: expected 2 edit rows, got $cntParallel\n");
    exit(1);
}
echo "parallel groups: независимые правки, без дублей — OK\n";

// Миграция v7→v8: правка без группы размножается по группам слота
$pdo->prepare("INSERT INTO schedule (sheet_name, date, day_of_week, class_name, lesson_num,
    time_start, time_end, subject, teacher, room, parallel_group, imported_at)
    VALUES ('ПН 15.09','2026-09-15','понедельник','9В',3,'09:00','09:45','География','Егоров Е.Е.','301','A','2026-09-14 08:00:00')")->execute();
$pdo->prepare("INSERT INTO schedule (sheet_name, date, day_of_week, class_name, lesson_num,
    time_start, time_end, subject, teacher, room, parallel_group, imported_at)
    VALUES ('ПН 15.09','2026-09-15','понедельник','9В',3,'09:00','09:45','География','Егоров Е.Е.','301','B','2026-09-14 08:00:00')")->execute();
$pdo->prepare("INSERT INTO schedule_edits
    (date,class_name,base_lesson_num,base_time_start,base_parallel_group,change_type,
     lesson_num,time_start,time_end,subject,teacher,room,parallel_group,created_at)
    VALUES ('2026-09-15','9В',3,'09:00','','edit',3,'','','','','302','','2026-09-15 10:00:00')")->execute();
migrate_edits_parallel_group($pdo);
$cnt = (int)$pdo->query("SELECT COUNT(*) FROM schedule_edits WHERE class_name='9В'")->fetchColumn();
if ($cnt !== 2) {
    fwrite(STDERR, "FAIL parallel migration: expected 2 edits, got $cnt\n");
    exit(1);
}
$rows = apply_edits(
    $pdo->query("SELECT * FROM schedule WHERE date='2026-09-15' AND class_name='9В'")->fetchAll(PDO::FETCH_ASSOC),
    get_edits($pdo, '2026-09-15', '9В'),
    ['date' => '2026-09-15', 'class' => '9В']
);
foreach ($rows as $r) {
    if ($r['room'] !== '302') {
        fwrite(STDERR, "FAIL parallel migration apply: " . json_encode($rows, JSON_UNESCAPED_UNICODE) . "\n");
        exit(1);
    }
}
echo "parallel migration: правка размножена на группы A/B — OK\n";

// Правка подгруппы при уроке, показанном «группой целиком» (группа '')
$pdo->prepare("INSERT INTO schedule (sheet_name, date, day_of_week, class_name, lesson_num,
    time_start, time_end, subject, teacher, room, parallel_group, imported_at)
    VALUES ('ВТ 16.09','2026-09-16','вторник','6В',2,'10:00','10:45','Труд','Мастер М.М.','100',NULL,'2026-09-15 08:00:00')")->execute();
save_edit($pdo, [
    'date' => '2026-09-16', 'class_name' => '6В', 'change_type' => 'edit',
    'base_lesson_num' => 2, 'base_time_start' => '10:00', 'base_parallel_group' => '1.2',
    'lesson_num' => 2, 'room' => '101',
]);
$rows = apply_edits(
    $pdo->query("SELECT * FROM schedule WHERE date='2026-09-16' AND class_name='6В'")->fetchAll(PDO::FETCH_ASSOC),
    get_edits($pdo, '2026-09-16', '6В'),
    ['date' => '2026-09-16', 'class' => '6В']
);
$stApplied = null;
foreach (list_edits_with_status($pdo) as $e) {
    if ($e['class_name'] === '6В') { $stApplied = $e['applied']; }
}
if (count($rows) !== 1 || $rows[0]['room'] !== '101' || !$rows[0]['room_corrected'] || $stApplied !== true) {
    fwrite(STDERR, "FAIL subgroup-on-whole: " . json_encode([$rows, $stApplied], JSON_UNESCAPED_UNICODE) . "\n");
    exit(1);
}
echo "подгруппа → урок целиком: применена, не осиротела — OK\n";

// Правка всего класса (group '') применяется ко всем подгруппам слота
$pdo->prepare("INSERT INTO schedule (sheet_name, date, day_of_week, class_name, lesson_num,
    time_start, time_end, subject, teacher, room, parallel_group, imported_at)
    VALUES ('ВТ 16.09','2026-09-16','вторник','6Г',3,'11:00','11:45','Англ','Т.','100','1','2026-09-15 08:00:00')")->execute();
$pdo->prepare("INSERT INTO schedule (sheet_name, date, day_of_week, class_name, lesson_num,
    time_start, time_end, subject, teacher, room, parallel_group, imported_at)
    VALUES ('ВТ 16.09','2026-09-16','вторник','6Г',3,'11:00','11:45','Англ','Т.','100','2','2026-09-15 08:00:00')")->execute();
save_edit($pdo, [
    'date' => '2026-09-16', 'class_name' => '6Г', 'change_type' => 'edit',
    'base_lesson_num' => 3, 'base_time_start' => '11:00', 'base_parallel_group' => '',
    'lesson_num' => 3, 'room' => '102',
]);
$rooms = [];
foreach (apply_edits(
    $pdo->query("SELECT * FROM schedule WHERE date='2026-09-16' AND class_name='6Г'")->fetchAll(PDO::FETCH_ASSOC),
    get_edits($pdo, '2026-09-16', '6Г'),
    ['date' => '2026-09-16', 'class' => '6Г']
) as $r) {
    $rooms[] = $r['room'];
}
sort($rooms);
if ($rooms !== ['102', '102']) {
    fwrite(STDERR, "FAIL whole-on-subgroups: " . json_encode($rooms) . "\n");
    exit(1);
}
echo "весь класс → подгруппы: применена к обеим — OK\n";

// Правка одной подгруппы не затрагивает другую (когда группы различаются)
$pdo->prepare("INSERT INTO schedule (sheet_name, date, day_of_week, class_name, lesson_num,
    time_start, time_end, subject, teacher, room, parallel_group, imported_at)
    VALUES ('ВТ 16.09','2026-09-16','вторник','6Д',2,'09:00','09:45','Лаба','Л.','100','1','2026-09-15 08:00:00')")->execute();
$pdo->prepare("INSERT INTO schedule (sheet_name, date, day_of_week, class_name, lesson_num,
    time_start, time_end, subject, teacher, room, parallel_group, imported_at)
    VALUES ('ВТ 16.09','2026-09-16','вторник','6Д',2,'09:00','09:45','Лаба','Л.','100','2','2026-09-15 08:00:00')")->execute();
save_edit($pdo, [
    'date' => '2026-09-16', 'class_name' => '6Д', 'change_type' => 'edit',
    'base_lesson_num' => 2, 'base_time_start' => '09:00', 'base_parallel_group' => '2',
    'lesson_num' => 2, 'room' => '103',
]);
$byG = [];
foreach (apply_edits(
    $pdo->query("SELECT * FROM schedule WHERE date='2026-09-16' AND class_name='6Д'")->fetchAll(PDO::FETCH_ASSOC),
    get_edits($pdo, '2026-09-16', '6Д'),
    ['date' => '2026-09-16', 'class' => '6Д']
) as $r) {
    $byG[(string)$r['parallel_group']] = $r['room'];
}
if ($byG['1'] !== '100' || $byG['2'] !== '103') {
    fwrite(STDERR, "FAIL subgroup independence: " . json_encode($byG) . "\n");
    exit(1);
}
echo "разные подгруппы: правка одной не трогает другую — OK\n";

// Валидация
try {
    save_edit($pdo, ['date' => '2026-09-15', 'class_name' => '7А', 'change_type' => 'add', 'lesson_num' => 0, 'subject' => 'X']);
    fwrite(STDERR, "FAIL validation: ожидалась ошибка\n");
    exit(1);
} catch (InvalidArgumentException $e) {
    echo "validation: пустой номер урока отклонён — OK\n";
}

$pdo = null;
@unlink($db);
@unlink($db . '-wal');
@unlink($db . '-shm');
echo "ALL OK\n";
