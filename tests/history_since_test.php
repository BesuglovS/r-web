<?php
// Смоук SQL-ветки since_version (action=history&since_version=N):
// группировка по версиям, подсчёт, детали — той же формы, что в api.php.
putenv('SCHEDULE_DB_PATH=' . sys_get_temp_dir() . '/rweb_test_since/schedule.db');
require __DIR__ . '/../src/db.php';

$pdo = get_db();
if ($pdo === null) { fwrite(STDERR, "DB fail\n"); exit(1); }
init_db($pdo);
$pdo->exec("DELETE FROM schedule_history");

// Версии 1..3: в выборку since=1 должны попасть только 2 и 3
$pdo->exec("INSERT INTO schedule_history (version,change_type,date,day_of_week,class_name,lesson_num,time_start,time_end,subject,teacher,room,old_room,changed_at)
    VALUES (1,'added','2026-09-07','понедельник','5А',1,'9:00','9:45','Мат','Ив','204','',datetime('now'))");
$pdo->exec("INSERT INTO schedule_history (version,change_type,date,day_of_week,class_name,lesson_num,time_start,time_end,subject,teacher,room,old_room,changed_at)
    VALUES (2,'room_changed','2026-09-08','вторник','6Б',1,'9:00','9:45','Физ','Пет','205','204',datetime('now'))");
$pdo->exec("INSERT INTO schedule_history (version,change_type,date,day_of_week,class_name,lesson_num,time_start,time_end,subject,teacher,room,old_room,changed_at)
    VALUES (3,'removed','2026-09-08','вторник','6Б',2,'10:00','10:45','Хим','Сид','','',datetime('now'))");

$since = 1;
$stmt = $pdo->prepare(
    "SELECT version, MAX(changed_at) as changed_at, COUNT(*) as cnt
     FROM schedule_history WHERE version > :since
     GROUP BY version ORDER BY version DESC LIMIT 50"
);
$stmt->execute([':since' => $since]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$versions = array_column($rows, 'version');
$ph = implode(',', array_fill(0, count($versions), '?'));
$det = $pdo->prepare("SELECT version, change_type, date, class_name, lesson_num, subject, teacher, room, old_room
    FROM schedule_history WHERE version IN ($ph) ORDER BY date, lesson_num, class_name");
$det->execute($versions);
$details = [];
foreach ($det->fetchAll(PDO::FETCH_ASSOC) as $d) { $details[$d['version']][] = $d; }

$result = [];
foreach ($rows as $row) {
    $result[] = [
        'version' => (int)$row['version'],
        'count'   => (int)$row['cnt'],
        'details' => $details[$row['version']] ?? [],
    ];
}

// Ожидания: версии 3 и 2 (DESC), counts 1/1, детали на месте
if (array_column($result, 'version') !== [3, 2]) { fwrite(STDERR, "FAIL: versions\n"); exit(1); }
if ($result[0]['count'] !== 1 || $result[1]['count'] !== 1) { fwrite(STDERR, "FAIL: counts\n"); exit(1); }
if (count($result[0]['details']) !== 1 || $result[0]['details'][0]['change_type'] !== 'removed') { fwrite(STDERR, "FAIL: details v3\n"); exit(1); }
if ($result[1]['details'][0]['old_room'] !== '204') { fwrite(STDERR, "FAIL: details v2\n"); exit(1); }

// since=0 — все три версии; since больше максимума — пусто
$stmt->execute([':since' => 0]);
if (count($stmt->fetchAll(PDO::FETCH_ASSOC)) !== 3) { fwrite(STDERR, "FAIL: since=0\n"); exit(1); }
$stmt->execute([':since' => 99]);
if ($stmt->fetchAll(PDO::FETCH_ASSOC) !== []) { fwrite(STDERR, "FAIL: since=99\n"); exit(1); }

// before_version — эксклюзивная верхняя граница (пагинация вглубь):
// since=0, before=3 — только версии 2 и 1 (DESC); since=1, before=2 — пусто
$stmt2 = $pdo->prepare(
    "SELECT version, MAX(changed_at) as changed_at, COUNT(*) as cnt
     FROM schedule_history WHERE version > :since AND version < :before
     GROUP BY version ORDER BY version DESC LIMIT 50"
);
$stmt2->execute([':since' => 0, ':before' => 3]);
if (array_column($stmt2->fetchAll(PDO::FETCH_ASSOC), 'version') !== [2, 1]) { fwrite(STDERR, "FAIL: before=3\n"); exit(1); }
$stmt2->execute([':since' => 1, ':before' => 2]);
if ($stmt2->fetchAll(PDO::FETCH_ASSOC) !== []) { fwrite(STDERR, "FAIL: before=2 since=1\n"); exit(1); }

echo "SINCE_VERSION SMOKE OK\n";
