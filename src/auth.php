<?php
/**
 * PHP-авторизация для admin.php.
 * Пароль читается из переменной окружения ADMIN_PASS или .env файла.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        // Сайт полностью HTTPS (nginx редиректит 80 → 443)
        'secure'   => true,
    ]);
    session_start();
}

function get_admin_password(): string
{
    // 1. Try env variable
    $pass = getenv('ADMIN_PASS');
    if ($pass !== false && $pass !== '') return $pass;

    // 2. Try .env рядом с проектом (на сервере это public/.env),
    //    затем на уровень выше (для схем, где .env вне docroot)
    foreach ([dirname(__DIR__), dirname(__DIR__, 2)] as $env_path) {
        $env_file = $env_path . '/.env';
        if (!file_exists($env_file)) continue;
        $lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            if (preg_match('/^ADMIN_PASS\s*=\s*(.+)$/', $line, $m)) {
                return trim($m[1]);
            }
        }
    }

    // 3. Пароль не настроен — логин невозможен (никаких дефолтов в коде)
    return '';
}

/**
 * Проверяет пароль: поддерживает как plaintext в .env, так и password_hash().
 */
function verify_admin_password(string $password, string $correct): bool
{
    // Современные хеши: $2y$ (bcrypt), $argon2i/$argon2id
    if (preg_match('/^\$(2y|2b|2a|argon2i|argon2id)\$/', $correct)) {
        return password_verify($password, $correct);
    }
    return hash_equals($correct, $password);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

function csrf_verify(): bool
{
    $sent = $_POST['csrf_token'] ?? '';
    return is_string($sent) && $sent !== '' && hash_equals(csrf_token(), $sent);
}

/**
 * Проверяет, что сессия админа ещё действительна.
 * Сессия истекает через ADMIN_SESSION_TTL секунд после логина (по умолчанию 12 часов).
 */
function is_admin_logged_in(): bool
{
    if (empty($_SESSION['admin_logged_in'])) {
        return false;
    }
    $ttl = 12 * 3600;
    $loginTime = $_SESSION['login_time'] ?? 0;
    if ($loginTime > 0 && (time() - $loginTime) > $ttl) {
        admin_logout();
        return false;
    }
    return true;
}

/**
 * Простой файловый throttle неудачных логинов по IP.
 * Сессионного ограничения недостаточно: атакующий сбрасывает cookie на каждый запрос.
 * Хранит счётчик в tmp: последние 15 минут, максимум 10 попыток.
 */
function ip_login_throttle(): bool
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $file = sys_get_temp_dir() . '/r-web-login-throttle.json';
    $state = [];
    if (file_exists($file)) {
        $state = json_decode((string)file_get_contents($file), true) ?: [];
    }
    $now = time();
    foreach ($state as $k => $v) {
        if (($v['ts'] ?? 0) < $now - 900) unset($state[$k]);
    }
    $entry = $state[$ip] ?? ['ts' => $now, 'fails' => 0];
    if (($now - $entry['ts']) > 900) {
        $entry = ['ts' => $now, 'fails' => 0];
    }
    if ($entry['fails'] >= 10) {
        return false; // превышен лимит — логин заблокирован на 15 минут
    }
    return true;
}

function ip_login_record_failure(): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $file = sys_get_temp_dir() . '/r-web-login-throttle.json';
    $state = [];
    if (file_exists($file)) {
        $state = json_decode((string)file_get_contents($file), true) ?: [];
    }
    $now = time();
    $entry = $state[$ip] ?? ['ts' => $now, 'fails' => 0];
    if (($now - $entry['ts']) > 900) {
        $entry = ['ts' => $now, 'fails' => 0];
    }
    $entry['fails']++;
    $entry['ts'] = $now;
    $state[$ip] = $entry;
    @file_put_contents($file, json_encode($state), LOCK_EX);
}

function ip_login_clear(): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $file = sys_get_temp_dir() . '/r-web-login-throttle.json';
    if (file_exists($file)) {
        $state = json_decode((string)file_get_contents($file), true) ?: [];
        unset($state[$ip]);
        @file_put_contents($file, json_encode($state), LOCK_EX);
    }
}

function admin_login(string $password): bool
{
    // Throttling: по IP (защита от брутфорса с новой сессией) + по сессии
    if (!ip_login_throttle()) {
        return false;
    }
    $fails = (int)($_SESSION['login_fails'] ?? 0);
    if ($fails > 0) {
        sleep(min($fails, 5));
    }

    $correct = get_admin_password();
    if ($correct === '' || $password === '') {
        $_SESSION['login_fails'] = $fails + 1;
        ip_login_record_failure();
        return false;
    }
    if (verify_admin_password($password, $correct)) {
        session_regenerate_id(true);
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['login_time'] = time();
        unset($_SESSION['login_fails']);
        ip_login_clear();
        return true;
    }
    $_SESSION['login_fails'] = $fails + 1;
    ip_login_record_failure();
    return false;
}

function admin_logout(): void
{
    $_SESSION['admin_logged_in'] = false;
    session_destroy();
}

function require_admin(): void
{
    if (!is_admin_logged_in()) {
        // Show login form
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
            if (csrf_verify() && admin_login($_POST['password'])) {
                return; // Logged in, continue
            }
            $error = csrf_verify() ? 'Неверный пароль' : 'Сессия устарела, обновите страницу';
        } else {
            $error = '';
        }
        ?>
        <!DOCTYPE html>
        <html lang="ru">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Админ — Вход</title>
            <link rel="icon" href="favicon.ico" type="image/x-icon">
            <link rel="icon" href="favicon.svg" type="image/svg+xml">
            <link rel="apple-touch-icon" href="apple-touch-icon.png">
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body {
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                    background: #0f172a;
                    color: #e2e8f0;
                }
                .login-box {
                    background: #1e293b;
                    padding: 2.5rem 3rem;
                    border-radius: 1rem;
                    box-shadow: 0 4px 24px rgba(0,0,0,.3);
                    width: 100%;
                    max-width: 380px;
                }
                h1 { font-size: 1.4rem; margin-bottom: 1.5rem; text-align: center; }
                input[type="password"] {
                    width: 100%;
                    padding: .75rem 1rem;
                    border: 1px solid #334155;
                    border-radius: .5rem;
                    background: #0f172a;
                    color: #e2e8f0;
                    font-size: 1rem;
                    margin-bottom: 1rem;
                }
                input[type="password"]:focus { outline: none; border-color: #3b82f6; }
                button {
                    width: 100%;
                    padding: .75rem;
                    border: none;
                    border-radius: .5rem;
                    background: #3b82f6;
                    color: #fff;
                    font-size: 1rem;
                    cursor: pointer;
                }
                button:hover { background: #2563eb; }
                .error { color: #ef4444; font-size: .9rem; margin-bottom: 1rem; text-align: center; }
            </style>
        </head>
        <body>
            <form class="login-box" method="POST">
                <h1>Админ — Вход</h1>
                <?= csrf_field() ?>
                <?php if (!empty($error)): ?>
                    <div class="error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                <input type="password" name="password" placeholder="Пароль" autofocus>
                <button type="submit">Войти</button>
            </form>
        </body>
        </html>
        <?php
        exit;
    }
}
