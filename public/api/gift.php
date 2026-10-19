<?php
// The game page tells the panel what a "share" / "subscribed" / chat reward really did (for Admin → Audience Insights).
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
$clip = fn ($k, $n) => mb_substr(preg_replace('/[\x00-\x1F]/u', '', (string) ($in[$k] ?? '')), 0, $n);
db()->prepare('INSERT INTO gifts (stream_id, ts, kind, author, country, result) VALUES (?,?,?,?,?,?)')
    ->execute([$id, time(), $clip('kind', 16), $clip('author', 60), $clip('country', 8), $clip('result', 16)]);
if (random_int(1, 50) === 1) db()->exec('DELETE FROM gifts WHERE ts < ' . (time() - 7 * 86400));
echo json_encode(['ok' => true]);
