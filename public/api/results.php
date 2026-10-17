<?php
// Live scores and winners sent by the game pages that run in the stream browser.
// Only pages opened by the VPS carry a valid signature (stream id + secret), so visitors can't post fake results.
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

$now = time();
$r = results_get();
$name = fn ($v) => mb_substr(preg_replace('/[^\p{L}\p{N} .&\'-]/u', '', (string) $v), 0, 40);

if (($in['game'] ?? '') === 'ball') {
    $b = $r['ball'];
    $b['updated'] = $now;
    $b['score'] = [max(0, (int) ($in['score'][0] ?? 0)), max(0, (int) ($in['score'][1] ?? 0))];
    $b['names'] = [$name($in['names'][0] ?? ''), $name($in['names'][1] ?? '')];
    $b['rounds'] = [max(0, (int) ($in['rounds'][0] ?? 0)), max(0, (int) ($in['rounds'][1] ?? 0))];
    $b['round'] = max(0, (int) ($in['round'] ?? 0));
    if (isset($in['winner'])) {
        $w = (int) $in['winner'] === 1 ? 1 : 0;
        $b['count'] = (int) ($b['count'] ?? 0) + 1;
        $b['games'] = (array) ($b['games'] ?? []);
        $fin = isset($in['final']) && is_array($in['final']) ? [(int) ($in['final'][0] ?? 0), (int) ($in['final'][1] ?? 0)] : $b['score'];
        array_unshift($b['games'], [
            'n' => $b['count'], 'w' => $b['names'][$w], 'wi' => $w,
            'score' => $fin[$w] . ' – ' . $fin[1 - $w], 't' => $now,
        ]);
        $b['games'] = array_slice($b['games'], 0, 10);
    }
    $r['ball'] = $b;
} elseif (($in['game'] ?? '') === 'flags') {
    $f = $r['flags'];
    $f['updated'] = $now;
    $f['round'] = max(0, (int) ($in['round'] ?? 0));
    $f['final'] = !empty($in['final']);
    $f['qualified'] = max(0, (int) ($in['qualified'] ?? 0));
    $code = strtolower((string) ($in['code'] ?? ''));
    if (isset($in['event']) && preg_match('/^[a-z]{2}$/', $code)) {
        $item = ['code' => $code, 'name' => $name($in['name'] ?? ''), 'round' => $f['round'], 't' => $now];
        if ($in['event'] === 'champion') {
            $f['champion'] = $item;
        } elseif ($in['event'] === 'qualified') {
            $f['winners'] = array_slice(array_merge([$item], (array) ($f['winners'] ?? [])), 0, 10);
        }
    }
    $r['flags'] = $f;
} else {
    http_response_code(400);
    echo json_encode(['error' => 'bad game']);
    exit;
}
results_save($r);
echo json_encode(['ok' => true]);
