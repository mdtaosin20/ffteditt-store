<?php
declare(strict_types=1);

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('fftadmin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/admin',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function has_admin(): bool
{
    return (int) db()->query('SELECT COUNT(*) FROM admins')->fetchColumn() > 0;
}

function current_admin(): ?array
{
    start_session();
    if (empty($_SESSION['admin_id'])) {
        return null;
    }
    $st = db()->prepare('SELECT id, username FROM admins WHERE id = ?');
    $st->execute([$_SESSION['admin_id']]);
    $row = $st->fetch();
    if ($row) owner_cookie_set((int) $row['id']);
    return $row ?: null;
}

function require_login(): array
{
    if (!has_admin()) {
        redirect('setup.php');
    }
    $admin = current_admin();
    if (!$admin) {
        redirect('index.php');
    }
    return $admin;
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

/** Returns seconds remaining if this IP is locked out, else 0. */
function login_locked_for(): int
{
    $st = db()->prepare('SELECT locked_until FROM login_attempts WHERE ip = ?');
    $st->execute([client_ip()]);
    $until = (int) ($st->fetchColumn() ?: 0);
    return max(0, $until - time());
}

function attempt_login(string $username, string $password): bool
{
    $st = db()->prepare('SELECT id, pass_hash FROM admins WHERE username = ?');
    $st->execute([$username]);
    $row = $st->fetch();

    // Always run password_verify so timing doesn't reveal valid usernames.
    $hash = $row['pass_hash'] ?? '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
    $ok   = password_verify($password, $hash) && $row;

    if ($ok) {
        db()->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([client_ip()]);
        start_session();
        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int) $row['id'];
        log_activity('Admin logged in');
        return true;
    }

    record_login_failure();
    return false;
}

function record_login_failure(): void
{
    $ip = client_ip();
    db()->prepare('INSERT INTO login_attempts (ip, attempts) VALUES (?, 1)
                   ON CONFLICT(ip) DO UPDATE SET attempts = attempts + 1')->execute([$ip]);
    $st = db()->prepare('SELECT attempts FROM login_attempts WHERE ip = ?');
    $st->execute([$ip]);
    if ((int) $st->fetchColumn() >= LOGIN_MAX_TRIES) {
        db()->prepare('UPDATE login_attempts SET attempts = 0, locked_until = ? WHERE ip = ?')
            ->execute([time() + LOGIN_LOCK_SECONDS, $ip]);
        log_activity('Login locked for 15 minutes after too many wrong passwords');
    }
}

/* ---------- terminal lock ---------- */

const TERMINAL_UNLOCK_SECONDS = 900; // 15 minutes without use

function terminal_unlocked(): bool
{
    start_session();
    return (int) ($_SESSION['term_until'] ?? 0) > time();
}

function terminal_touch(): void
{
    start_session();
    $_SESSION['term_until'] = time() + TERMINAL_UNLOCK_SECONDS;
}

function terminal_lock(): void
{
    start_session();
    unset($_SESSION['term_until']);
}

/** Re-check the admin password before opening the terminal. */
function terminal_unlock(int $adminId, string $password): bool
{
    $st = db()->prepare('SELECT pass_hash FROM admins WHERE id = ?');
    $st->execute([$adminId]);
    if (password_verify($password, (string) $st->fetchColumn())) {
        db()->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([client_ip()]);
        terminal_touch();
        log_activity('Terminal unlocked');
        return true;
    }
    record_login_failure();
    log_activity('Wrong password when opening the terminal');
    return false;
}

function logout(): void
{
    start_session();
    $_SESSION = [];
    session_destroy();
}

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function check_csrf(): void
{
    start_session();
    $sent = (string) ($_POST['csrf'] ?? '');
    if ($sent === '' || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent)) {
        http_response_code(400);
        exit('Security check failed. Please go back, refresh the page and try again.');
    }
}
