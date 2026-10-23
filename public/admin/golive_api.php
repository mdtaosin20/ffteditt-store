<?php
// The owner's phone (logged in): start / keep alive / switch camera / leave.
require __DIR__ . '/../_boot.php';
require_login();
check_csrf();
header('Content-Type: application/json');
header('Cache-Control: no-store');

$sid = (int) ($_POST['stream'] ?? 0);
$st = db()->prepare('SELECT id, name, status FROM streams WHERE id = ?');
$st->execute([$sid]);
$s = $st->fetch();
if (!$s) { echo json_encode(['error' => 'Channel not found.']); exit; }

$do = (string) ($_POST['do'] ?? '');
$g = golive_get($sid);
if ($do === 'start') {
    $offer = (string) ($_POST['offer'] ?? '');
    if ($offer === '' || strlen($offer) > 20000) { echo json_encode(['error' => 'Bad connection note.']); exit; }
    $g = ['on' => true, 'sess' => bin2hex(random_bytes(8)), 'mode' => ($_POST['mode'] ?? '') === 'voice' ? 'voice' : 'cam',
          'offer' => $offer, 'answer' => '', 'seen' => time(), 'name' => mb_substr(preg_replace('/[^\p{L}\p{N} ]/u', '', (string) ($_POST['name'] ?? '')) ?? '', 0, 16) ?: 'HOST', 'state' => ''];
    golive_put($sid, $g);
    log_activity("Join Live: started on {$s['name']} (" . ($g['mode'] === 'cam' ? 'camera + voice' : 'voice only') . ')', 'ok');
    echo json_encode(['sess' => $g['sess'], 'live' => $s['status'] === 'live']);
    exit;
}
if (!$g['on'] || !hash_equals($g['sess'], (string) ($_POST['sess'] ?? ''))) { echo json_encode(['error' => 'ended']); exit; }
if ($do === 'poll') {
    $g['seen'] = time();
    if (in_array($_POST['mode'] ?? '', ['cam', 'voice'], true)) $g['mode'] = $_POST['mode'];
    golive_put($sid, $g);
    echo json_encode(['answer' => $g['answer'], 'state' => $g['state']]);
    exit;
}
if ($do === 'stop') {
    $g['on'] = false;
    golive_put($sid, $g);
    log_activity("Join Live: left {$s['name']}", 'info');
    echo json_encode(['ok' => true]);
    exit;
}
echo json_encode(['error' => 'unknown']);
