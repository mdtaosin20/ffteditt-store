<?php
// Shows a channel's YouTube thumbnail in the panel (admins only).
require __DIR__ . '/../_boot.php';
require_login();

$st = db()->prepare('SELECT * FROM streams WHERE id = ?');
$st->execute([(int) ($_GET['id'] ?? 0)]);
$s = $st->fetch();
if ($s && isset($_GET['x'])) {   // one of the extra thumbnails (YouTube Manager → More thumbnails)
    $xf = ytx_thumb_path((int) $s['id'], (string) $_GET['x']);
    if (!in_array(preg_replace('/[^a-z0-9]/', '', (string) $_GET['x']), ytx_get((int) $s['id'])['thumbs'], true) || !is_file($xf)) { http_response_code(404); exit; }
    header('Content-Type: image/jpeg'); header('Cache-Control: private, max-age=300'); header('X-Content-Type-Options: nosniff');
    readfile($xf); exit;
}
if ($s && isset($_GET['g'])) {   // the game's ready-made picture number g (2, 3 …)
    $gf = APP_DIR . '/yt/' . game_of_url((string) $s['page_url']) . '_' . max(2, min(9, (int) $_GET['g'])) . '.jpg';
    if (!is_file($gf)) { http_response_code(404); exit; }
    header('Content-Type: image/jpeg'); header('Cache-Control: private, max-age=300'); header('X-Content-Type-Options: nosniff');
    readfile($gf); exit;
}
if ($s && isset($_GET['def'])) {
    $s['yt_thumb'] = 'default';
}
$f = $s ? yt_thumb_file($s) : null;
if ($f && isset($_GET['var'])) { // preview of the thumbnail version number var
    $tmp = tempnam(sys_get_temp_dir(), 'ytv');
    if (ytv_thumb($f[0], max(0, (int) $_GET['var']), ytv_badges($s), $tmp)) {
        header('Content-Type: image/jpeg'); header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
        readfile($tmp); @unlink($tmp); exit;
    }
    @unlink($tmp);
}
if (!$f) {
    http_response_code(404);
    exit;
}
header('Content-Type: ' . $f[1]);
header('Cache-Control: private, max-age=60');
header('X-Content-Type-Options: nosniff');
readfile($f[0]);
