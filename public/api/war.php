<?php
// Public settings for the Territory War page (/war/). No secrets here.
require __DIR__ . '/../_boot.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

echo json_encode(war_config());
