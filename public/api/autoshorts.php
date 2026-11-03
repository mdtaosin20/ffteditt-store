<?php
// 🏭 Auto Shorts: the game page on the VPS reports here (signed per job): progress, the video in pieces, and at the
// end its titles, description and thumbnail. app/autoshorts.php then uploads it to YouTube on its schedule.
require __DIR__ . '/../_boot.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$out = function (array $j, int $code = 200): void { http_response_code($code); echo json_encode($j, JSON_UNESCAPED_UNICODE); exit; };
$id = (string) ($_GET['job'] ?? '');
$do = (string) ($_GET['do'] ?? '');
if (($_SERVER['REQUEST_METHOD'] !== 'POST' && $do !== 'song') || !preg_match('/^v[a-f0-9]{10}$/', $id) || !hash_equals(aus_sig($id), (string) ($_GET['sig'] ?? ''))) $out(['error' => 'not allowed'], 403);
$job = aus_job($id);
if (!$job || !in_array($job['st'], ['making', 'wait'], true)) $out(['error' => 'job not open', 'stop' => true]);

if ($do === 'setup') { $out(aus_setup($job)); }   // 🎛 the look of this video (place, text, voice, music …)

if ($do === 'song') {   // 🎵 a background song for this video
    foreach (aus_songs() as $s) if ($s['id'] === (string) ($_GET['s'] ?? '') && is_file($f = aus_song_file($s))) {
        $mime = ['mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'aac' => 'audio/aac', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg'][$s['ext']] ?? 'application/octet-stream';
        header('Content-Type: ' . $mime); header('Content-Length: ' . filesize($f)); header_remove('Cache-Control'); readfile($f); exit;
    }
    $out(['error' => 'no song'], 404);
}

if ($do === 'progress') { aus_update($id, ['prog' => max(0, min(100, (int) ($_GET['p'] ?? 0)))]); $out(['ok' => true]); }

if ($do === 'chunk') {   // the video, about 2 MB at a time, in order
    $i = (int) ($_GET['i'] ?? -1);
    $part = aus_file($id, 'part');
    if ($i === 0) @unlink($part);
    $have = (int) ($job['chunks'] ?? 0);
    if ($i !== ($i === 0 ? 0 : $have)) $out(['error' => 'wrong piece', 'want' => $have], 409);
    $data = (string) file_get_contents('php://input');
    if ($data === '' || strlen($data) > 6 * 1048576) $out(['error' => 'bad piece'], 400);
    if (@filesize($part) > 300 * 1048576) $out(['error' => 'video too big'], 400);
    file_put_contents($part, $data, FILE_APPEND | LOCK_EX);
    aus_update($id, ['chunks' => $i + 1]);
    $out(['ok' => true]);
}

if ($do === 'finish') {
    $in = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $part = aus_file($id, 'part');
    if (!is_file($part) || filesize($part) < 50000) $out(['error' => 'no video'], 400);
    rename($part, aus_file($id, 'mp4'));
    $thumb = (string) ($in['thumb'] ?? '');
    if (preg_match('#^data:image/jpeg;base64,([A-Za-z0-9+/=]+)$#', $thumb, $m)) { $bin = base64_decode($m[1], true); if ($bin !== false && strlen($bin) < 3 * 1048576) file_put_contents(aus_file($id, 'jpg'), $bin); }
    $titles = array_slice(array_values(array_filter(array_map(fn ($t) => mb_substr(trim((string) $t), 0, 100), (array) ($in['titles'] ?? [])))), 0, 6);
    $info = (array) ($in['info'] ?? []);
    $clean = ['kind' => (string) ($info['kind'] ?? 'country'), 'names' => array_slice(array_map(fn ($x) => mb_substr((string) $x, 0, 30), (array) ($info['names'] ?? [])), 0, 6),
              'winner' => mb_substr((string) ($info['winner'] ?? ''), 0, 30), 'secs' => round((float) ($info['secs'] ?? 0), 1)];
    aus_update($id, ['st' => 'ready', 'prog' => 100, 'titles' => $titles, 'title' => $titles[0] ?? '', 'desc' => mb_substr((string) ($in['desc'] ?? ''), 0, 4900),
                     'info' => $clean, 'size' => filesize(aus_file($id, 'mp4')), 'done_at' => time(), 'err' => '']);
    log_activity("🏭 Auto Shorts: video {$id} is ready (" . round(filesize(aus_file($id, 'mp4')) / 1048576, 1) . ' MB)', 'ok');
    $out(['ok' => true]);
}

if ($do === 'fail') {
    $msg = mb_substr((string) (json_decode((string) file_get_contents('php://input'), true)['msg'] ?? 'error'), 0, 200);
    $tries = (int) ($job['tries'] ?? 0) + 1;
    aus_update($id, ['st' => $tries >= 2 ? 'failed' : 'wait', 'tries' => $tries, 'err' => $msg]);
    $out(['ok' => true]);
}
$out(['error' => 'unknown'], 400);
