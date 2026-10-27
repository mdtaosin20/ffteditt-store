<?php
// Fingerprint / face login (passkeys): options and checks for /assets/passkey.js.
require __DIR__ . '/../_boot.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

$do = (string) ($_GET['do'] ?? '');
$out = function (array $a, int $code = 200) { http_response_code($code); echo json_encode($a); exit; };
$in = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_decode((string) file_get_contents('php://input', false, null, 0, 20000), true);
    if (!is_array($in)) $out(['error' => 'bad request'], 400);
}

try {
    if ($do === 'login_opts') {
        if (login_locked_for() > 0) $out(['error' => 'Login is locked for a few minutes.'], 429);
        $out(['challenge' => pk_challenge('login'), 'rpId' => pk_rp_id()]);
    }
    if ($do === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (login_locked_for() > 0) $out(['error' => 'Login is locked for a few minutes.'], 429);
        try {
            $id = pk_login($in);
        } catch (Throwable $e) {
            record_login_failure();
            throw $e;
        }
        db()->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([client_ip()]);
        start_session();
        session_regenerate_id(true);
        $_SESSION['admin_id'] = $id;
        log_activity('Admin logged in with fingerprint');
        flash('Welcome back — logged in with fingerprint ✓');
        $out(['ok' => true, 'go' => 'dashboard.php']);
    }

    // below: only for a logged-in admin
    $admin = current_admin();
    if (!$admin) $out(['error' => 'Please log in first.'], 401);
    start_session();
    if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) ($_SERVER['HTTP_X_CSRF'] ?? ''))) $out(['error' => 'Security check failed — refresh the page.'], 400);

    if ($do === 'reg_opts') {
        pk_table();
        $st = db()->prepare('SELECT cred_id FROM passkeys WHERE admin_id = ?');
        $st->execute([(int) $admin['id']]);
        $out(['challenge' => pk_challenge('reg'), 'rpId' => pk_rp_id(), 'rpName' => 'GameStream Admin',
              'userId' => b64u_enc('fftadmin:' . (int) $admin['id']), 'userName' => $admin['username'],
              'exclude' => $st->fetchAll(PDO::FETCH_COLUMN)]);
    }
    if ($do === 'reg' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $name = pk_register((int) $admin['id'], $in);
        $out(['ok' => true, 'name' => $name]);
    }
    $out(['error' => 'unknown'], 400);
} catch (Throwable $e) {
    $out(['error' => 'Fingerprint login failed: ' . $e->getMessage()], 400);
}
