<?php
// Public game settings for /game/ (names, colours, photo links). No secrets here.
require __DIR__ . '/../_boot.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$c = game_config();
foreach ($c['players'] as &$p) {
    $p['photo'] = upload_url($p['photo']);
    $p['ball']  = upload_url($p['ball']);
}
unset($p);
echo json_encode($c);
