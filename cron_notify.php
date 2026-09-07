<?php
/**
 * Отправка email-уведомлений об ошибках импорта.
 * Вызывается из cron_import.php.
 */

// .env читаем через единый парсер из db.php (cron_import.php уже подключил db.php,
// но на всякий случай require_once — он идемпотентен)
require_once __DIR__ . '/src/db.php';

function load_env(string $key, string $default = ''): string
{
    return (string)(load_env_value($key) ?? $default);
}

function send_error_email(array $errors): bool
{
    $adminEmail = load_env('ADMIN_EMAIL');
    if ($adminEmail === '') {
        return false;
    }

    // Дедупликация: при устойчивой ошибке cron (каждые 5 минут) не спамим —
    // то же самое письмо не чаще раза в 6 часов.
    $fingerprint = md5(serialize($errors));
    $stateFile = sys_get_temp_dir() . '/r-web-notify-state.json';
    $state = [];
    if (file_exists($stateFile)) {
        $state = json_decode((string)file_get_contents($stateFile), true) ?: [];
    }
    if (($state['fp'] ?? '') === $fingerprint && (time() - (int)($state['ts'] ?? 0)) < 6 * 3600) {
        return false; // уже отправляли недавно
    }

    $siteName = 'r.nayanovaacademy.ru';
    // Кириллица в Subject без MIME-кодирования у многих серверов отображается
    // кракозябрами — кодируем по RFC 2047
    $subject = mb_encode_mimeheader("[$siteName] Ошибка импорта расписания", 'UTF-8', 'B');
    $date = date('Y-m-d H:i:s');

    $body = "Ошибки импорта расписания на $siteName\n";
    $body .= "Дата: $date\n";
    $body .= str_repeat('-', 50) . "\n\n";

    foreach ($errors as $i => $err) {
        $body .= ($i + 1) . ". URL: {$err['url']}\n";
        $body .= "   Ошибка: {$err['error']}\n\n";
    }

    $body .= str_repeat('-', 50) . "\n";
    $body .= "Автоматическое уведомление от cron_import.php\n";

    $headers = [
        'From' => 'r-web@nayanovaacademy.ru',
        'Content-Type' => 'text/plain; charset=UTF-8',
        'MIME-Version' => '1.0',
    ];
    $headerStr = '';
    foreach ($headers as $k => $v) {
        $headerStr .= $k . ': ' . $v . "\r\n";
    }

    // 5-й параметр задаёт envelope-from (-f): без него письма часто попадают
    // в спам (несовпадение From и envelope-from, отсутствие Return-Path)
    $extra = '-f r-web@nayanovaacademy.ru';
    $sent = mail($adminEmail, $subject, $body, $headerStr, $extra);
    if ($sent) {
        // Атомарная запись: сначала во временный файл, затем rename —
        // исключает частично записанное состояние при сбое/параллельном запуске
        $tmp = $stateFile . '.' . uniqid('', true) . '.tmp';
        if (@file_put_contents($tmp, json_encode(['fp' => $fingerprint, 'ts' => time()])) !== false) {
            @rename($tmp, $stateFile);
        } else {
            @unlink($tmp);
        }
    }
    return $sent;
}
