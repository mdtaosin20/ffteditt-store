<?php
// Public settings for the Country Flag Battle page (/flags/). No secrets here.
require __DIR__ . '/../_boot.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$c = flags_config();
$c['voice'] = voice_public(game_config(), 'flags');
echo json_encode($c);
