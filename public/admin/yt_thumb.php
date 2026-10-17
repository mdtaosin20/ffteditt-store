<?php
// Shows a channel's YouTube thumbnail in the panel (admins only).
require __DIR__ . '/../_boot.php';
require_login();

$st = db()->prepare('SELECT * FROM streams WHERE id = ?');
$st->execute([(int) ($_GET['id'] ?? 0)]);
$s = $st->fetch();
if ($s && isset($_GET['def'])) {
    $s['yt_thumb'] = 'default';
}
$f = $s ? yt_thumb_file($s) : null;
if (!$f) {
    http_response_code(404);
    exit;
}
header('Content-Type: ' . $f[1]);
header('Cache-Control: private, max-age=60');
header('X-Content-Type-Options: nosniff');
readfile($f[0]);
