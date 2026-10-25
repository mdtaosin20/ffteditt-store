<?php
// Public settings for the Flag Race page (/race/). No secrets here.
require __DIR__ . '/../_boot.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$c = race_config();
// countries whose people chat in our lives (Flag Race and Flag Arena), most people first: [code, people]
$seen = [];
foreach (array_merge(fans_top('race', 7, 60), fans_top('flags', 7, 60)) as $r) $seen[$r[0]] = ($seen[$r[0]] ?? 0) + $r[2];
arsort($seen);
$c['popular'] = array_map(fn ($k, $v) => [$k, $v], array_keys($seen), $seen);
echo json_encode($c);
