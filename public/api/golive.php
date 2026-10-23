<?php
// The game page in the stream: is the owner joining with camera/voice? (GET) · here is my answer (POST)
require __DIR__ . '/../_boot.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$in = $_SERVER['REQUEST_METHOD'] === 'POST' ? (json_decode((string) file_get_contents('php://input'), true) ?: []) : $_GET;
$id = (int) ($in['stream'] ?? 0);
if ($id <= 0 || !hash_equals(stream_sig($id), (string) ($in['sig'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['error' => 'not allowed']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $g = golive_get($id);
    $sess = (string) ($in['sess'] ?? '');
    if ($g['on'] && $sess !== '' && hash_equals($g['sess'], $sess)) {
        if (isset($in['answer'])) $g['answer'] = mb_substr((string) $in['answer'], 0, 20000);
        if (isset($in['state'])) $g['state'] = mb_substr(preg_replace('/[^a-z\- ]/', '', (string) $in['state']) ?? '', 0, 30);
        golive_put($id, $g);
    }
    echo json_encode(['ok' => true]);
    exit;
}
echo json_encode(golive_public($id));
