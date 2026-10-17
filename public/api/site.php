<?php
// Live parts of the home page (refreshed every 30 s). No secrets here.
require __DIR__ . '/../_boot.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
$n = site_live_count();
echo json_encode([
    'count'   => $n ? '<span class="dot"></span>' . $n . ' LIVE now' : '',
    'score'   => site_score_html(),
    'results' => site_results_html(),
    'live'    => site_live_html(),
]);
