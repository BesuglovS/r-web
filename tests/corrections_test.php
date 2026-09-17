<?php
/**
 * Smoke-тест корректировок аудиторий: миграция v6 + применение при чтении.
 */
$db = tempnam(sys_get_temp_dir(), 'corrdb');
putenv("SCHEDULE_DB_PATH=$db");
require __DIR__ . '/../src/db.php';

$pdo = get_db();
if (!$pdo) { fwrite(STDERR, "no db\n"); exit(1); }
init_db($pdo);

echo "user_version: " . $pdo->query('PRAGMA user_version')->fetchColumn() . "\n";

// Урок 15.09.2026, 7А, 2-й урок 10:20-11:05, кабинет 203
$ins = $pdo->prepare("INSERT INTO schedule (sheet_name, date, day_of_week, class_name, lesson_num,
    time_start, time_end, subject, teacher, room, parallel_group, imported_at)
    VALUES ('ПН 15.09', '2026-09-15', 'ПН', '7А', 2, '10:20', '11:05', 'Математика', 'Иванов И.И.', '203', NULL, '2026-09-14 08:00:00')");
$ins->execute();

// Корректировка: перенос из 203 в 306
$cpdo = $pdo;
$ups = $cpdo->prepare(
    "INSERT INTO room_corrections (date, class_name, lesson_num, time_start, time_end, subject, teacher, room_old, room_new, created_at)
     VALUES ('2026-09-15', '7А', 2, '10:20', '11:05', 'Математика', 'Иванов И.И.', '203', :rn, '2026-09-15 10:00:00')
     ON CONFLICT(date, class_name, lesson_num, time_start)
     DO UPDATE SET room_new = excluded.room_new");
$params = ['rn' => '305'];
$ups->execute($params);

// Повторный upsert меняет аудиторию
$ups = $cpdo->prepare(
    "INSERT INTO room_corrections (date, class_name, lesson_num, time_start, time_end, subject, teacher, room_old, room_new, created_at)
     VALUES ('2026-09-15', '7А', 2, '10:20', '11:05', 'Математика', 'Иванов И.И.', '203', :rn, '2026-09-15 10:00:00')
     ON CONFLICT(date, class_name, lesson_num, time_start)
     DO UPDATE SET room_new = excluded.room_new");
$ups->execute(['rn' => '177']);
$cnt = $pdo->query("SELECT COUNT(*) FROM room_corrections")->fetchColumn();
echo "corrections after upsert (expect 1): $cnt\n";

// SQL из api.php?action=schedule — эффекттивная аудитория
$stmt = $pdo->prepare(
    "SELECT COALESCE(rc.room_new, s.room) AS room,
            rc.room_new IS NOT NULL AS room_corrected, s.subject
     FROM schedule s
     LEFT JOIN room_corrections rc ON rc.date = s.date AND rc.class_name = s.class_name
        AND rc.lesson_num = s.lesson_num AND rc.time_start = s.time_start
     WHERE s.date = ? AND s.class_name = ?");
$stmt->execute(['2026-09-15', '7А']);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if ($row['room'] !== '177' || (int)$row['room_corrected'] !== 1) {
    fwrite(STDERR, "FAIL schedule: " . json_encode($row, JSON_UNESCAPED_UNICODE) . "\n");
    exit(1);
}
echo "schedule room: {$row['room']} (corrected)\n";

// SQL из free_rooms: занятость с корректировкой — новый кабинет '177' занят, '203' свободен
$rs = $pdo->query("SELECT name FROM rooms WHERE name IN ('177','203')")->fetchAll(PDO::FETCH_COLUMN);
$names = [];
foreach (['177','203'] as $n) $names[] = $n;
$ph = implode(',', array_fill(0, count($names), '?'));
$fstmt = $pdo->prepare(
    "SELECT COALESCE(rc.room_new, s.room) AS eff_room
     FROM schedule s
     LEFT JOIN room_corrections rc ON rc.date = s.date AND rc.class_name = s.class_name
        AND rc.lesson_num = s.lesson_num AND rc.time_start = s.time_start
     WHERE s.date = ? AND (s.room IN ($ph) OR rc.room_new IN ($ph))
       AND substr('0'||s.time_start,-5) <= ? AND substr('0'||s.time_end,-5) > ?");
$fstmt->execute(array_merge(['2026-09-15'], $names, $names, ['10:30', '10:30']));
$ms_occupied = [];
foreach ($fstmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    if (in_array($r['eff_room'], $names, true)) $ms_occupied[$r['eff_room']] = true;
}
if (isset($ms_occupied['177']) && !isset($ms_occupied['203'])) {
    echo "free_rooms: 177 занята, 203 свободна — OK\n";
} else {
    fwrite(STDERR, "FAIL free_rooms: " . json_encode(array_keys($ms_occupied)) . "\n");
    exit(1);
}

// Удаление корректировки — возвращается старая аудитория
$pdo->prepare("DELETE FROM room_corrections")->execute();
$stmt->execute(['2026-09-15', '7А']);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if ($row['room'] !== '203') {
    fwrite(STDERR, "FAIL revert: {$row['room']}\n");
    exit(1);
}
echo "after delete: room {$row['room']} — OK\n";

unlink($db);
echo "ALL OK\n";
