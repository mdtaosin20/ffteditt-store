<?php
// Public settings for the Last Flag Standing page (/lastflag/). No secrets here.
require __DIR__ . '/../_boot.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$c = lastflag_config();
$c['voice'] = voice_public(game_config(), 'flags');   // LUNA's recorded lines are the same as in Flag Arena
$seen = [];   // countries whose people chat here and in Flag Arena (code, people)
foreach (array_merge(fans_top('lastflag', 7, 100), fans_top('flags', 7, 100)) as $r) $seen[$r[0]] = ($seen[$r[0]] ?? 0) + $r[2];
arsort($seen);
$c['popular'] = array_map(fn ($k, $v) => [$k, $v], array_keys($seen), array_values($seen));
echo json_encode($c);
