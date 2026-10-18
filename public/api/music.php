<?php
// Background songs for the game page running in the stream browser (signed: only the VPS's page).
//   ?stream=&sig=          -> list of songs
//   ?stream=&sig=&f=name   -> the mp3
require __DIR__ . '/../_boot.php';

$id = (int) ($_GET['stream'] ?? 0);
if ($id <= 0 || !hash_equals(stream_sig($id), (string) ($_GET['sig'] ?? ''))) {
    http_response_code(403);
    exit;
}
$tracks = music_tracks();
$f = (string) ($_GET['f'] ?? '');
if ($f === '') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(array_map(fn ($t) => ['f' => $t['file'], 'title' => $t['title']], $tracks));
    exit;
}
foreach ($tracks as $t) {
    if ($t['file'] === $f) {
        $path = music_dir() . '/' . $t['file'];
        header('Content-Type: audio/mpeg');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, max-age=86400');
        readfile($path);
        exit;
    }
}
http_response_code(404);
