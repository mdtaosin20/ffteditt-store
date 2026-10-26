<?php
// Which game page a running stream should show now (signed: only the stream's own page may ask).
// The game page asks every few seconds; when the admin picked another game for the stream,
// the page shows "GAME UPDATE" for 2 seconds and opens the new game — the live itself never stops.
require __DIR__ . '/../_boot.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$id = (int) ($_GET['stream'] ?? 0);
if ($id <= 0 || !hash_equals(stream_sig($id), (string) ($_GET['sig'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['error' => 'not allowed']);
    exit;
}
$st = db()->prepare('SELECT page_url, run_id FROM streams WHERE id = ?');
$st->execute([$id]);
$s = $st->fetch();
if (!$s) { http_response_code(404); echo json_encode(['error' => 'no stream']); exit; }
$key = game_of_url((string) $s['page_url']);
$g = games_list()[$key] ?? null;
$p = parse_url((string) $s['page_url']);
echo json_encode([
    'page'  => ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : ''),
    'url'   => signed_page_url((string) $s['page_url'], $id) . '&run=' . (int) ($s['run_id'] ?? 0),
    'name'  => $g['name'] ?? 'New game',
    'thumb' => $g['thumb'] ?? '',
    'promo' => promo_for_stream($id),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
