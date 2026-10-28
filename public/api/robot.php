<?php
// Robot host voice lines for the game page in the stream browser (signed like /api/chat.php).
//   POST {stream, sig, text}  -> {h, url}   (url is empty while the VPS makes it)
//   GET  ?stream&sig&h=…      -> {h, url, failed}
require __DIR__ . '/../_boot.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$in = $_SERVER['REQUEST_METHOD'] === 'POST' ? (json_decode((string) file_get_contents('php://input', false, null, 0, 4000), true) ?: []) : $_GET;
$id = (int) ($in['stream'] ?? 0);
if ($id <= 0 || !hash_equals(stream_sig($id), (string) ($in['sig'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['error' => 'not allowed']);
    exit;
}
// ann = the stadium announcer ("Spain is out!"): always Ryan's voice, made once and kept for every round
$ann = !empty($in['ann']) && preg_match("/^[A-Za-z .'-]{2,40} is out!$/", (string) ($in['text'] ?? ''));
echo json_encode($_SERVER['REQUEST_METHOD'] === 'POST'
    ? ($ann ? robot_request((string) $in['text'], 'ryan', true) : robot_request((string) ($in['text'] ?? '')))
    : robot_status((string) ($in['h'] ?? '')));
