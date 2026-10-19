<?php
// Public settings for the Beat The Keeper page (/keeper/). No secrets here.
require __DIR__ . '/../_boot.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

echo json_encode(keeper_config());
