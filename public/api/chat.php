<?php
// Live chat for the game page running in the stream browser (signed: only the VPS's page may read it).
require __DIR__ . '/../_boot.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$id = (int) ($_GET['stream'] ?? 0);
if ($id <= 0 || !hash_equals(stream_sig($id), (string) ($_GET['sig'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['error' => 'not allowed']);
    exit;
}
echo json_encode(chat_feed($id, max(-1, (int) ($_GET['after'] ?? 0))), JSON_UNESCAPED_UNICODE);
