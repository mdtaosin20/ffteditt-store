<?php
// Serves pictures uploaded in Admin → Game.
require __DIR__ . '/../_boot.php';

$f = (string) ($_GET['f'] ?? '');
if (!preg_match('/^[a-f0-9]{24}\.(jpg|png|webp)$/', $f, $m)) {
    http_response_code(404);
    exit;
}
$path = upload_dir() . '/' . $f;
if (!is_file($path)) {
    http_response_code(404);
    exit;
}
$types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
header('Content-Type: ' . $types[$m[1]]);
header('Content-Length: ' . filesize($path));
header('Cache-Control: public, max-age=31536000, immutable');
header('X-Content-Type-Options: nosniff');
readfile($path);
