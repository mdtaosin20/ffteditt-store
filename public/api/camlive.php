<?php
// Camera Live link (cam.php): his phone talks to the panel here — save his key, start / stop his live,
// and pass the WebRTC connection notes to the camera page on the VPS (like Join Live).
require __DIR__ . '/../_boot.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$out = function (array $j): void { echo json_encode($j, JSON_UNESCAPED_UNICODE); exit; };
if ($_SERVER['REQUEST_METHOD'] !== 'POST') $out(['error' => 'POST only']);
$in = json_decode((string) file_get_contents('php://input'), true) ?: [];
$link = cam_find((string) ($in['t'] ?? ''));
if (!$link) $out(['error' => 'over', 'msg' => 'This link does not work any more (time over or cancelled).']);
cam_tick();
$sid = (int) $link['sid'];
$s = cli_stream($sid);
$do = (string) ($in['do'] ?? '');
$now = time();
$state = fn (array $s) => ['left' => (int) $link['exp'] - $now, 'has_key' => $s['stream_key'] !== '', 'orient' => str_ends_with($s['quality'], '-h') ? 'h' : 'v',
                   'on' => $s['desired'] === 'on', 'running' => $s['desired'] === 'on' && $s['status'] === 'running', 'view' => cam_view($link)];

if ($do === 'info') $out(['ok' => true, 'name' => $link['label']] + $state($s));

if ($do === 'save') {
    $key = trim((string) ($in['key'] ?? ''));
    $orient = ($in['orient'] ?? 'v') === 'h' ? 'h' : 'v';
    if ($key === '' && $s['stream_key'] !== '') $key = $s['stream_key'];   // empty = keep the saved one
    if (!preg_match('/^[A-Za-z0-9_\-]{8,80}$/', $key)) $out(['error' => 'key', 'msg' => 'This does not look like a YouTube stream key. Copy it again from YouTube Studio.']);
    $d = db()->prepare("SELECT COUNT(*) FROM streams WHERE stream_key = ? AND id != ? AND desired = 'on'"); $d->execute([$key, $sid]);
    if ((int) $d->fetchColumn() > 0) $out(['error' => 'key', 'msg' => 'This stream key is live somewhere else right now. Stop that live first, or use another key.']);
    $q = $orient === 'h' ? '720p-h' : '720p-v';
    if ($key !== $s['stream_key'] || $q !== $s['quality']) {
        db()->prepare('UPDATE streams SET stream_key = ?, quality = ?, rev = rev + 1, updated_at = ? WHERE id = ?')->execute([$key, $q, $now, $sid]);
        if ($s['stream_key'] === '') log_activity("📱 {$link['label']} put his stream key in his camera link", 'info');
    }
    $s = cli_stream($sid);
    $out(['ok' => true] + $state($s));
}

if ($do === 'start') {
    if ($s['stream_key'] === '') $out(['error' => 'key', 'msg' => 'Put your stream key first.']);
    if (!$s['server_id']) {
        $srv = cli_pick_server();
        if (!$srv) $out(['error' => 'server', 'msg' => 'No server is ready. Tell the owner.']);
        db()->prepare('UPDATE streams SET server_id = ? WHERE id = ?')->execute([$srv, $sid]);
    }
    if ($s['desired'] !== 'on') {
        db()->prepare("UPDATE streams SET desired = 'on', error = NULL, run_id = ?, updated_at = ? WHERE id = ?")->execute([$now, $now, $sid]);
        log_activity("📱 {$link['label']} started his camera live", 'ok');
    }
    cam_update($link['id'], ['seen' => $now, 'live_at' => $now]);
    $s = cli_stream($sid);
    $out(['ok' => true] + $state($s));
}

if ($do === 'offer') {
    $offer = (string) ($in['offer'] ?? '');
    if ($offer === '' || strlen($offer) > 20000) $out(['error' => 'offer']);
    $g = ['on' => true, 'sess' => bin2hex(random_bytes(8)), 'mode' => 'cam', 'offer' => $offer, 'answer' => '', 'seen' => $now, 'view' => cam_view($link),
          'name' => mb_substr(preg_replace('/[^\p{L}\p{N} ]/u', '', $link['label']) ?? '', 0, 16) ?: 'LIVE', 'state' => ''];
    golive_put($sid, $g);
    cam_update($link['id'], ['seen' => $now]);
    $out(['ok' => true, 'sess' => $g['sess']] + $state($s));
}

if ($do === 'poll') {
    $g = golive_get($sid);
    cam_update($link['id'], ['seen' => $now]);
    if (!$g['on'] || !hash_equals($g['sess'], (string) ($in['sess'] ?? ''))) $out(['error' => 'ended'] + $state($s));
    $g['seen'] = $now; golive_put($sid, $g);
    $out(['ok' => true, 'answer' => $g['answer'], 'state' => $g['state']] + $state($s));
}

// 📐 turn / straighten / mirror: kept with the link and sent to the live page at once (no restart)
if ($do === 'view') {
    $v = is_array($in['view'] ?? null) ? $in['view'] : [];
    $view = ['r' => max(-45, min(45, round((float) ($v['r'] ?? 0), 1))), 'q' => ((int) ($v['q'] ?? 0) % 4 + 4) % 4, 'm' => !empty($v['m'])];
    cam_update($link['id'], ['view' => $view]);
    $g = golive_get($sid);
    if ($g['on']) { $g['view'] = $view; golive_put($sid, $g); }
    $out(['ok' => true, 'view' => $view]);
}

if ($do === 'stop') {
    cam_stop($link, 'he pressed End live');
    $s = cli_stream($sid);
    $out(['ok' => true] + $state($s));
}
$out(['error' => 'unknown']);
