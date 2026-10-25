<?php
// The game page in the stream tells the panel which countries were named in the chat (chats, different people).
// Only pages opened by the VPS carry a valid signature, so visitors cannot send fake numbers.
require __DIR__ . '/../_boot.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$in = json_decode((string) file_get_contents('php://input', false, null, 0, 20000), true);
$id = (int) ($in['stream'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !is_array($in) || $id <= 0
    || !hash_equals(stream_sig($id), (string) ($in['sig'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['error' => 'not allowed']);
    exit;
}
$game = (string) ($in['game'] ?? '');
if (!in_array($game, ['flags', 'war', 'tug', 'keeper', 'ball', 'race'], true) || !is_array($in['c'] ?? null)) {
    http_response_code(400);
    echo json_encode(['error' => 'bad data']);
    exit;
}
fans_add($game, $in['c']);
echo json_encode(['ok' => true]);
