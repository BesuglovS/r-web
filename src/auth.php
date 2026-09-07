<?php
/**
 * PHP-авторизация для admin.php.
 * Пароль читается из переменной окружения ADMIN_PASS или .env файла.
 */

// load_env_value() — единый парсер .env живёт в db.php
require_once __DIR__ . '/db.php';

/**
 * Стартует сессию с правильными cookie-параметрами.
 * Сессия нужна ТОЛЬКО для админ-функций (логин, CSRF) — публичные страницы
 * и API её не создают (не плодим файлы сессий и Set-Cookie для анонимов).
 */
function ensure_session(): void
{
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
}

function get_admin_password(): string
{
    // 1. Try env variable
    $pass = getenv('ADMIN_PASS');
    if ($pass !== false && $pass !== '') return $pass;

    // 2. .env рядом с проектом (на сервере это public/.env),
    //    затем на уровень выше (для схем, где .env вне docroot).
    //    Парсинг — через единый load_env_value() из db.php.
    // 3. Пароль не настроен — логин невозможен (никаких дефолтов в коде)
    return (string)(load_env_value('ADMIN_PASS') ?? '');
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
    // Plaintext-пароль в .env — работаем, но напоминаем в лог, что нужно
    // заменить на password_hash('...', PASSWORD_BCRYPT)
    error_log('[auth] ВНИМАНИЕ: ADMIN_PASS хранится как plaintext — замените на bcrypt-хеш (см. AGENTS.MD)');
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
 * Чтение и запись под flock — без гонок при параллельных запросах.
 */
function ip_login_state_file(): string
{
    return sys_get_temp_dir() . '/r-web-login-throttle.json';
}

/**
 * Читает и изменяет JSON-состояние под эксклюзивной блокировкой файла.
 * Общий примитив для throttle логина и rate-limit API.
 */
function with_locked_state(string $file, callable $fn): void
{
    // 'c+' = открыть для чтения И записи, создать если не существует, указатель в начало
    $fp = @fopen($file, 'c+');
    if ($fp === false) {
        return;
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return;
    }
    $raw = stream_get_contents($fp);
    $state = json_decode((string)$raw, true);
    if (!is_array($state)) {
        $state = [];
    }
    $fn($state);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($state));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

function ip_login_with_lock(callable $fn): void
{
    with_locked_state(ip_login_state_file(), $fn);
}

function ip_login_throttle(): bool
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $allowed = true;
    $now = time();
    ip_login_with_lock(function (array &$state) use ($ip, $now, &$allowed) {
        foreach ($state as $k => $v) {
            if (($v['ts'] ?? 0) < $now - 900) unset($state[$k]);
        }
        $entry = $state[$ip] ?? ['ts' => $now, 'fails' => 0];
        if (($now - $entry['ts']) > 900) {
            $entry = ['ts' => $now, 'fails' => 0];
        }
        if ($entry['fails'] >= 10) {
            $allowed = false; // превышен лимит — логин заблокирован на 15 минут
        }
    });
    return $allowed;
}

function ip_login_record_failure(): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $now = time();
    ip_login_with_lock(function (array &$state) use ($ip, $now) {
        $entry = $state[$ip] ?? ['ts' => $now, 'fails' => 0];
        if (($now - $entry['ts']) > 900) {
            $entry = ['ts' => $now, 'fails' => 0];
        }
        $entry['fails']++;
        $entry['ts'] = $now;
        $state[$ip] = $entry;
    });
}

function ip_login_clear(): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    ip_login_with_lock(function (array &$state) use ($ip) {
        unset($state[$ip]);
    });
}

/**
 * Лёгкий rate-limit публичных API-эндпоинтов по IP.
 * Файловый счётчик под flock (тот же примитив, что throttle логина).
 * Скользящее окно $window секунд, максимум $limit запросов.
 * При превышении — 429 и завершение запроса.
 */
function api_rate_limit(int $limit = 240, int $window = 60): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $file = sys_get_temp_dir() . '/r-web-api-rate.json';
    $now = time();
    $allowed = true;
    with_locked_state($file, function (array &$state) use ($ip, $now, $limit, $window, &$allowed) {
        // Чистим давно протухшие записи, чтобы файл не рос бесконечно
        foreach ($state as $k => $v) {
            if (($now - (int)($v['window'] ?? 0)) >= $window * 10) {
                unset($state[$k]);
            }
        }
        $entry = $state[$ip] ?? ['window' => $now, 'count' => 0];
        if (($now - (int)$entry['window']) >= $window) {
            $entry = ['window' => $now, 'count' => 0];
        }
        $entry['count']++;
        $state[$ip] = $entry;
        $allowed = $entry['count'] <= $limit;
    });
    if (!$allowed) {
        http_response_code(429);
        header('Retry-After: ' . $window);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Слишком много запросов, попробуйте позже']);
        exit;
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
        // Ротация CSRF-токена вместе с ID сессии: токен, известный до логина,
        // не должен быть валиден после него
        unset($_SESSION['csrf_token']);
        ip_login_clear();
        return true;
    }
    $_SESSION['login_fails'] = $fails + 1;
    ip_login_record_failure();
    return false;
}

function admin_logout(): void
{
    $_SESSION = [];
    // Удаляем сессионную cookie, чтобы браузер не отправлял её повторно
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function require_admin(): void
{
    ensure_session();
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
