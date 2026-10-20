<?php
// The game page asks for an AI answer to one chat message (signed: only the stream's own page may ask).
require __DIR__ . '/../_boot.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$in = json_decode((string) file_get_contents('php://input'), true) ?: [];
$id = (int) ($in['stream'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $id <= 0 || !hash_equals(stream_sig($id), (string) ($in['sig'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['error' => 'not allowed']);
    exit;
}
$text = ai_reply($id, preg_replace('/[^a-z]/', '', (string) ($in['game'] ?? '')), (string) ($in['name'] ?? ''),
    (string) ($in['text'] ?? ''), (string) ($in['info'] ?? ''));
echo json_encode(['text' => $text], JSON_UNESCAPED_UNICODE);
