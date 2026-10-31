<?php
// Public settings for the Snake Live page (/snake/). No secrets here.
require __DIR__ . '/../_boot.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
echo json_encode(snake_config(), JSON_UNESCAPED_UNICODE);
