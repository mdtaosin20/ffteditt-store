<?php
// JSON for the Terminal page: run a command / read recent results.
require __DIR__ . '/../_boot.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

function out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

$admin = current_admin();
if (!$admin) {
    out(['error' => 'login', 'message' => 'Please log in again.'], 401);
}
if (!terminal_unlocked()) {
    out(['error' => 'locked', 'message' => 'Terminal locked. Enter your password again.'], 403);
}

$sid = (int) ($_REQUEST['server_id'] ?? 0);
$st  = db()->prepare('SELECT * FROM servers WHERE id = ?');
$st->execute([$sid]);
$server = $st->fetch();
if (!$server) {
    out(['error' => 'server', 'message' => 'Server not found.'], 404);
}

$now = time();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = (string) ($_POST['action'] ?? 'run');

    if ($action === 'clear') {
        db()->prepare("DELETE FROM commands WHERE server_id = ? AND status NOT IN ('queued', 'running')")->execute([$sid]);
        terminal_touch();
        out(['ok' => true]);
    }

    $cmd = trim(str_replace("\r", '', (string) ($_POST['cmd'] ?? '')));
    if ($cmd === '') {
        out(['error' => 'empty', 'message' => 'Type a command first.'], 400);
    }
    if (strlen($cmd) > 2000) {
        out(['error' => 'long', 'message' => 'Command is too long (max 2000 characters).'], 400);
    }
    if ((int) $server['terminal_enabled'] !== 1) {
        out(['error' => 'disabled', 'message' => 'Terminal is turned off for this server (Servers page).'], 400);
    }
    $st = db()->prepare("SELECT COUNT(*) FROM commands WHERE server_id = ? AND status IN ('queued', 'running')");
    $st->execute([$sid]);
    if ((int) $st->fetchColumn() >= 5) {
        out(['error' => 'busy', 'message' => 'Wait for the running commands to finish.'], 429);
    }

    db()->prepare('INSERT INTO commands (server_id, cmd, created_at) VALUES (?, ?, ?)')->execute([$sid, $cmd, $now]);
    // Never keep stream keys or tokens in the Activity list.
    $safe  = preg_replace(['#(rtmps?://[^\s/]+/[^\s/]+/)\S+#i', '/fft_[a-f0-9]{48}/'], ['$1****', 'fft_****'], $cmd);
    $short = mb_strlen($safe) > 120 ? mb_substr($safe, 0, 120) . '…' : $safe;
    log_activity("Terminal on {$server['name']}: {$short}");
    terminal_touch();
    out(['ok' => true, 'id' => (int) db()->lastInsertId()]);
}

// GET: recent commands for this server.
// Tidy up: commands that never got an answer.
db()->prepare("UPDATE commands SET status = 'lost', output = 'No answer from the server (it may have restarted).', finished_at = ?
               WHERE server_id = ? AND status = 'running' AND started_at < ?")
    ->execute([$now, $sid, $now - 300]);
if (!server_online($server)) {
    db()->prepare("UPDATE commands SET status = 'expired', output = 'Server is offline, the command was not run.', finished_at = ?
                   WHERE server_id = ? AND status = 'queued' AND created_at < ?")
        ->execute([$now, $sid, $now - 120]);
}
// Keep only the last 100 per server.
db()->prepare('DELETE FROM commands WHERE server_id = ? AND id NOT IN
               (SELECT id FROM commands WHERE server_id = ? ORDER BY id DESC LIMIT 100)')->execute([$sid, $sid]);

$st = db()->prepare('SELECT id, cmd, status, output, exit_code, duration_ms, created_at FROM commands
                     WHERE server_id = ? ORDER BY id DESC LIMIT 15');
$st->execute([$sid]);
$rows = array_reverse($st->fetchAll());
foreach ($rows as &$r) {
    $r['id']          = (int) $r['id'];
    $r['exit_code']   = $r['exit_code'] === null ? null : (int) $r['exit_code'];
    $r['duration_ms'] = $r['duration_ms'] === null ? null : (int) $r['duration_ms'];
    $r['time']        = date('H:i:s', (int) $r['created_at']);
    unset($r['created_at']);
}
unset($r);

$info = json_decode($server['info_json'] ?: '{}', true) ?: [];
out([
    'online'   => server_online($server),
    'enabled'  => (int) $server['terminal_enabled'] === 1,
    'hostname' => (string) ($info['hostname'] ?? 'vps'),
    'agent'    => (string) ($info['agent_version'] ?? ''),
    'commands' => $rows,
]);
