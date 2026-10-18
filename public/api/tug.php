<?php
// Public settings for the Tug of War page (/tug/). No secrets here.
require __DIR__ . '/../_boot.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

echo json_encode(tug_config());
