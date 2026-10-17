<?php
/**
 * Worker API — called every few seconds by the agent running on each VPS.
 * The VPS sends what it is doing; the panel answers with what it should be doing.
 * Auth: secret server token in the JSON body (some hosts drop Authorization headers).
 */
require __DIR__ . '/../_boot.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

function fail(int $code, string $msg): void
{
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, 'POST only');
}

$raw = file_get_contents('php://input', false, null, 0, 8 * 1024 * 1024);
$in  = json_decode((string) $raw, true);
if (!is_array($in)) {
    fail(400, 'bad json');
}

$token = (string) ($in['token'] ?? '');
if (!preg_match('/^fft_[a-f0-9]{48}$/', $token)) {
    fail(401, 'bad token');
}
$st = db()->prepare('SELECT * FROM servers WHERE token_hash = ?');
$st->execute([hash('sha256', $token)]);
$server = $st->fetch();
if (!$server) {
    fail(401, 'unknown token');
}
$sid = (int) $server['id'];
$now = time();

// 1) Remember that this server is alive, plus basic machine info.
$info  = is_array($in['info'] ?? null) ? $in['info'] : [];
$clean = [
    'hostname'      => substr((string) ($info['hostname'] ?? ''), 0, 64),
    'cpus'          => (int) ($info['cpus'] ?? 0),
    'load1'         => substr((string) ($info['load1'] ?? ''), 0, 10),
    'mem_total_mb'  => (int) ($info['mem_total_mb'] ?? 0),
    'mem_used_mb'   => (int) ($info['mem_used_mb'] ?? 0),
    'agent_version' => substr((string) ($info['agent_version'] ?? ''), 0, 16),
];
// Extra numbers for the dashboard (worker 1.4.0 and newer).
$num = fn ($k) => isset($info[$k]) && is_numeric($info[$k]) ? (float) $info[$k] : null;
$clean += [
    'cpu_pct'       => $num('cpu_pct') !== null ? max(0, min(100, $num('cpu_pct'))) : null,
    'disk_total_gb' => $num('disk_total_gb'),
    'disk_used_gb'  => $num('disk_used_gb'),
    'up_kbps'       => $num('up_kbps'),
    'down_kbps'     => $num('down_kbps'),
    'rx_bytes'      => (int) ($info['rx_bytes'] ?? 0),
    'tx_bytes'      => (int) ($info['tx_bytes'] ?? 0),
    'uptime_s'      => (int) ($info['uptime_s'] ?? 0),
    'temp_c'        => $num('temp_c'),
    'procs'         => [],
];
foreach (array_slice((array) ($info['procs'] ?? []), 0, 6) as $p) {
    if (is_array($p)) {
        $clean['procs'][] = ['name' => substr((string) ($p['name'] ?? ''), 0, 24), 'cpu' => (float) ($p['cpu'] ?? 0), 'mem_mb' => (int) ($p['mem_mb'] ?? 0)];
    }
}
$prevInfo = json_decode($server['info_json'] ?: '{}', true) ?: [];
if ((int) $server['last_seen'] < $now - SERVER_OFFLINE_AFTER) {
    log_activity("Server online: {$server['name']}", 'ok');
    // Auto Start off: after a reboot, streams wait for the admin instead of starting by themselves.
    if ($clean['uptime_s'] > 0 && $clean['uptime_s'] < 600 && !panel_opts()['auto_start']) {
        db()->prepare("UPDATE streams SET desired = 'off' WHERE server_id = ? AND desired = 'on'")->execute([$sid]);
        log_activity("{$server['name']} restarted: streams left off (Auto Start is off)", 'warn');
    }
}
db()->prepare('UPDATE servers SET last_seen = ?, info_json = ? WHERE id = ?')
    ->execute([$now, json_encode($clean), $sid]);
if ($clean['cpu_pct'] !== null) {
    record_metrics($sid, $prevInfo, $clean);
}
schedule_tick();

// 2) Save what each stream on this server is doing right now.
$mine = [];
$st   = db()->prepare('SELECT * FROM streams WHERE server_id = ?');
$st->execute([$sid]);
foreach ($st->fetchAll() as $row) {
    $mine[(int) $row['id']] = $row;
}

$allowed = ['stopped', 'starting', 'live', 'error'];
$upd = db()->prepare('UPDATE streams SET status = ?, fps_now = ?, speed_now = ?, started_at = ?, error = ? WHERE id = ?');
$reported = [];
foreach ((array) ($in['streams'] ?? []) as $r) {
    $id = (int) ($r['id'] ?? 0);
    if (!isset($mine[$id])) {
        continue;
    }
    $reported[$id] = true;
    $status = in_array($r['status'] ?? '', $allowed, true) ? $r['status'] : 'stopped';
    $error  = $status === 'error' ? substr((string) ($r['error'] ?? 'Unknown error'), 0, 300) : null;
    $upd->execute([
        $status,
        isset($r['fps']) && is_numeric($r['fps']) ? (float) $r['fps'] : null,
        isset($r['speed']) ? substr((string) $r['speed'], 0, 12) : null,
        !empty($r['started_at']) ? (int) $r['started_at'] : null,
        $error,
        $id,
    ]);
    $old = $mine[$id]['status'];
    if ($old !== $status) {
        $name = $mine[$id]['name'];
        if ($status === 'live')    log_activity("Stream '{$name}' is now LIVE", 'ok');
        if ($status === 'error')   log_activity("Problem with {$name}: {$error}", 'error');
        if ($status === 'stopped' && $old === 'live') log_activity("Stream '{$name}' ended", 'warn');
    }
    $mine[$id]['status'] = $status;
}
// Streams the worker no longer runs count as stopped.
foreach ($mine as $id => $row) {
    if (!isset($reported[$id]) && $row['status'] !== 'stopped') {
        db()->prepare("UPDATE streams SET status = 'stopped', fps_now = NULL, speed_now = NULL, started_at = NULL WHERE id = ?")
            ->execute([$id]);
        if ($row['status'] === 'live') {
            log_activity("Stream '{$row['name']}' ended", 'warn');
        }
    }
}

// 2b) Save terminal command results.
$fin = db()->prepare("UPDATE commands SET status = 'done', output = ?, exit_code = ?, duration_ms = ?, finished_at = ?
                      WHERE id = ? AND server_id = ? AND status IN ('queued', 'running')");
foreach ((array) ($in['command_results'] ?? []) as $r) {
    if (!is_array($r)) {
        continue;
    }
    $fin->execute([
        substr((string) ($r['output'] ?? ''), 0, 70000),
        isset($r['exit_code']) ? (int) $r['exit_code'] : null,
        isset($r['duration_ms']) ? (int) $r['duration_ms'] : null,
        $now,
        (int) ($r['id'] ?? 0),
        $sid,
    ]);
}

// 2c) Automatic commentator voices made on the VPS.
foreach ((array) ($in['voice_results'] ?? []) as $r) {
    if (!is_array($r)) continue;
    $mp3 = base64_decode((string) ($r['mp3'] ?? ''), true);
    if ($mp3 !== false) {
        save_auto_voice((string) ($r['id'] ?? ''), (string) ($r['hash'] ?? ''), $mp3);
    }
}
if (!empty($in['voice_error'])) {
    $vs = voice_state();
    $err = substr((string) $in['voice_error'], 0, 300);
    if ($vs['error'] !== $err) {
        $vs['error'] = $err;
        voice_save($vs);
        log_activity("Voice maker on {$server['name']}: {$err}", 'warn');
    }
}

// 3) Tell the worker what should be running.
$q   = qualities();
$out = [];
foreach ($mine as $id => $row) {
    if ($row['desired'] !== 'on' || $row['stream_key'] === '') {
        continue;
    }
    $preset = $q[$row['quality']] ?? $q['480p-v'];
    $out[] = [
        'id'           => $id,
        'name'         => $row['name'],
        'rev'          => (int) $row['rev'],
        'rtmp'         => rtrim($row['rtmp_url'], '/') . '/' . $row['stream_key'],
        'page_url'     => signed_page_url($row['page_url'], $id),
        'width'        => $preset['w'],
        'height'       => $preset['h'],
        'kbps'         => $preset['kbps'],
        'fps'          => (int) $row['fps'],
        'auto_restart' => (bool) $row['auto_restart'],
    ];
}

// 4) Terminal commands waiting for this server.
$cmds = [];
if ((int) ($server['terminal_enabled'] ?? 1) === 1) {
    $st = db()->prepare("SELECT id, cmd FROM commands WHERE server_id = ? AND status = 'queued' ORDER BY id LIMIT 5");
    $st->execute([$sid]);
    $mark = db()->prepare("UPDATE commands SET status = 'running', started_at = ? WHERE id = ?");
    foreach ($st->fetchAll() as $c) {
        $mark->execute([$now, $c['id']]);
        $cmds[] = ['id' => (int) $c['id'], 'cmd' => $c['cmd']];
    }
}

// Check in faster while the terminal is being used.
$st = db()->prepare('SELECT MAX(created_at) FROM commands WHERE server_id = ?');
$st->execute([$sid]);
$poll = ((int) $st->fetchColumn() > $now - 600) ? 2 : 5;

$voiceJobs = array_slice(voice_missing(voice_state(), game_config()), 0, 4);
if ($voiceJobs) {
    $poll = min($poll, 3);
}

echo json_encode(['ok' => true, 'poll_seconds' => $poll, 'run' => $out, 'commands' => $cmds, 'voice_jobs' => $voiceJobs]);

// 5) After answering the worker: set the YouTube title/description/thumbnail on new live videos.
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} elseif (function_exists('litespeed_finish_request')) {
    litespeed_finish_request();
}
ignore_user_abort(true);
try {
    yt_tick($sid);
} catch (Throwable $e) {
    // never break the worker check-in
}
