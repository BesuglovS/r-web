<?php
/**
 * PHP-авторизация для admin.php.
 * Пароль читается из переменной окружения ADMIN_PASS или .env файла.
 */

session_start();

function get_admin_password(): string
{
    // 1. Try env variable
    $pass = getenv('ADMIN_PASS');
    if ($pass !== false && $pass !== '') return $pass;

    // 2. Try .env file (project root, two levels up from src/)
    $env_path = dirname(__DIR__, 2) . '/.env';
    if (file_exists($env_path)) {
        $lines = file($env_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            if (preg_match('/^ADMIN_PASS\s*=\s*(.+)$/', $line, $m)) {
                return trim($m[1]);
            }
        }
    }

    // 3. Default fallback (should be overridden in .env)
    return 'nayanova2026';
}

function is_admin_logged_in(): bool
{
    return !empty($_SESSION['admin_logged_in']);
}

function admin_login(string $password): bool
{
    $correct = get_admin_password();
    if (hash_equals($correct, $password)) {
        $_SESSION['admin_logged_in'] = true;
        return true;
    }
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
            if (admin_login($_POST['password'])) {
                return; // Logged in, continue
            }
            $error = 'Неверный пароль';
        }
        ?>
        <!DOCTYPE html>
        <html lang="ru">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Админ — Вход</title>
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
