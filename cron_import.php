<?php
/**
 * CLI-скрипт для автоматической проверки расписания.
 * Вызывается через cron каждые 5 минут.
 *
 * Логика: лёгкий запрос метаданных файла на Яндекс.Диске (md5/modified/size,
 * без скачивания). Полный импорт запускается только если файл изменился,
 * либо раз в 6 часов в качестве страховки.
 */

// /etc/cron.d/r-web:
// */5 * * * * www-data php /var/www/r.nayanovaacademy.ru/public/cron_import.php >> /var/log/r-web-import.log 2>&1

require_once __DIR__ . '/src/db.php';
require_once __DIR__ . '/src/import.php';
require_once __DIR__ . '/cron_notify.php';

// Защита от исполнения через веб (даже если nginx-конфиг ошибочно пропустит)
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

// Защита от параллельных запусков cron (импорт может длиться дольше 5 минут)
$lock_file = sys_get_temp_dir() . '/r-web-cron.lock';
$lock_fp = fopen($lock_file, 'c');
if ($lock_fp === false || !flock($lock_fp, LOCK_EX | LOCK_NB)) {
    echo "[" . date('Y-m-d H:i:s') . "] Предыдущий запуск ещё выполняется — пропуск.\n";
    exit(0);
}

$date = date('Y-m-d H:i:s');
echo "[$date] Запуск проверки источников...\n";

$pdo = get_db();
if ($pdo === null) {
    echo "ОШИБКА: БД недоступна. Проверка отменена.\n";
    exit(1);
}
init_db($pdo);

$result = do_check_all(6, $pdo);

// Ежедневный бэкап БД (VACUUM INTO, ротация 7 копий в db/backups/)
// — делается после проверки, чтобы в копию попали свежие данные
$backup = backup_db($pdo);
if ($backup !== null) {
    echo "Бэкап БД: $backup\n";
}

$changed = 0;
$unchanged = 0;
foreach ($result['results'] as $r) {
    if (($r['check'] ?? '') === 'changed') {
        $changed++;
    } elseif (($r['check'] ?? '') === 'unchanged') {
        $unchanged++;
    }
}

echo "Источников: {$result['total']}, Импортов: $changed, Без изменений: $unchanged, Ошибок: {$result['errors']}\n";

foreach ($result['results'] as $r) {
    switch ($r['check'] ?? '') {
        case 'unchanged':
            echo "  [БЕЗ ИЗМЕНЕНИЙ] ID={$r['id']} URL={$r['url']}\n";
            break;

        case 'changed':
            $res = $r['result'] ?? [];
            $lessons = $res['lessons'] ?? 0;
            $changes = $res['changes'] ?? [];
            $added = $changes['added'] ?? 0;
            $removed = $changes['removed'] ?? 0;
            $room = $changes['room_changed'] ?? 0;
            $reason = $r['reason'] ?? 'changed';
            echo "  [ИМПОРТ: $reason] ID={$r['id']} URL={$r['url']}: $lessons уроков (+$added -$removed ~$room каб.)\n";
            break;

        default:
            echo "  [ОШИБКА] ID={$r['id']} URL={$r['url']}: " . ($r['error'] ?? 'неизвестная ошибка') . "\n";
    }
}

if (!empty($result['cleanup']['removed_dates'])) {
    $c = $result['cleanup'];
    echo "  [ОЧИСТКА] Удалены устаревшие даты: " . implode(', ', $c['removed_dates']) . " ({$c['lessons']} уроков)\n";
}

if (!empty($result['error_details'])) {
    try {
        $sent = send_error_email($result['error_details']);
        if ($sent) {
            echo "Уведомление об ошибках (" . count($result['error_details']) . ") отправлено на email.\n";
        } else {
            echo "Не удалось отправить email-уведомление (ADMIN_EMAIL не настроен).\n";
        }
    } catch (Throwable $e) {
        echo "Ошибка отправки email-уведомления: " . $e->getMessage() . "\n";
    }
}

echo date('Y-m-d H:i:s') . " Проверка завершена.\n";
