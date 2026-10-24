<?php
// Public settings for the Country Flag Battle page (/flags/). No secrets here.
require __DIR__ . '/../_boot.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$c = flags_config();
$c['voice'] = voice_public(game_config(), 'flags');
$c['popular'] = array_map(fn ($r) => [$r[0], $r[2]], fans_top('flags', 7, 100));   // countries whose people chat here (code, people)
echo json_encode($c);
