<?php
/**
 * Отправка email-уведомлений об ошибках импорта.
 * Вызывается из cron_import.php.
 */

function load_env(string $key, string $default = ''): string
{
    // .env теперь лежит вне docroot (на уровень выше public/);
    // проверяем оба варианта для совместимости
    $candidates = [dirname(__DIR__, 2) . '/.env', dirname(__DIR__) . '/.env'];

    foreach ($candidates as $envFile) {
        if (!file_exists($envFile)) {
            continue;
        }
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (strpos($line, '=') === false) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            if (trim($k) === $key) {
                return trim($v);
            }
        }
    }
    return $default;
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
    $subject = "[$siteName] Ошибка импорта расписания";
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
    ];

    $sent = mail($adminEmail, $subject, $body, $headers);
    if ($sent) {
        @file_put_contents($stateFile, json_encode(['fp' => $fingerprint, 'ts' => time()]), LOCK_EX);
    }
    return $sent;
}
