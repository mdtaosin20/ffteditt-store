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
// the game page is alive (it asks for chat twice a second): Auto Fix restarts a live whose page froze
$hb = DATA_DIR . '/page_seen_' . $id;
if (time() - (int) @filemtime($hb) >= 10) @touch($hb);
echo json_encode(chat_feed($id, max(-1, (int) ($_GET['after'] ?? 0))), JSON_UNESCAPED_UNICODE);
