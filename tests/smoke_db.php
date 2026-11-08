<?php if (PHP_SAPI !== "cli") { http_response_code(404); exit; } ?>
<?php
// Временный интеграционный смоук: init_db / prune_old_data / backup_db
$db_file = sys_get_temp_dir() . '/rweb_test/schedule.db';
putenv('SCHEDULE_DB_PATH=' . $db_file);
// Тест должен переживать повторный запуск: убираем БД прошлого прогона
@unlink($db_file);
@unlink($db_file . '-wal');
@unlink($db_file . '-shm');
require __DIR__ . '/../src/db.php';

$pdo = get_db();
if ($pdo === null) { fwrite(STDERR, "DB fail\n"); exit(1); }
init_db($pdo);
init_db($pdo); // повторный вызов — кеш static

$pdo->exec("INSERT INTO schedule (sheet_name,date,day_of_week,class_name,lesson_num,time_start,time_end,subject,teacher,imported_at)
    VALUES ('ПН 02.09','2026-09-07','понедельник','5А',1,'9:00','10:45','Мат','Ив',datetime('now'))");
$pdo->exec("INSERT INTO schedule_history (version,change_type,date,day_of_week,class_name,lesson_num,time_start,time_end,subject,teacher,room,old_room,changed_at)
    VALUES (1,'added','2026-09-07','понедельник','5А',1,'9:00','10:45','Мат','Ив','204','',datetime('now','-100 days'))");
$pdo->exec("INSERT INTO schedule_history (version,change_type,date,day_of_week,class_name,lesson_num,time_start,time_end,subject,teacher,room,old_room,changed_at)
    VALUES (2,'added','2026-09-08','вторник','6Б',1,'9:00','10:45','Физ','Пет','205','',datetime('now'))");

prune_old_data($pdo, 10, 90);
$hist = (int)$pdo->query('SELECT COUNT(*) FROM schedule_history')->fetchColumn();
$sched = (int)$pdo->query('SELECT COUNT(*) FROM schedule')->fetchColumn();
echo "history после prune (должна остаться свежая): $hist\n";
echo "schedule (не трогается prune): $sched\n";

$b = backup_db($pdo);
echo "backup #1: " . ($b ?: 'НЕТ') . "\n";
$b2 = backup_db($pdo);
echo "backup #2 (тот же файл за день): " . ($b2 ?: 'НЕТ') . "\n";
echo "user_version: " . $pdo->query('PRAGMA user_version')->fetchColumn() . "\n";
$arch = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='schedule_archive'")->fetchColumn();
echo "schedule_archive table: " . ($arch ? 'есть' : 'НЕТ') . "\n";
$tarif = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='tarification'")->fetchColumn();
echo "tarification table: " . ($tarif ? 'есть' : 'НЕТ') . "\n";

// Проверка целостности бэкапа
$check = new PDO('sqlite:' . $b);
echo "уроков в бэкапе: " . $check->query('SELECT COUNT(*) FROM schedule')->fetchColumn() . "\n";
echo "SMOKE OK\n";