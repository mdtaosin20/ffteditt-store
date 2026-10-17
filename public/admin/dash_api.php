<?php
// Live numbers for the dashboard (refreshed every 5 seconds by admin.js).
require __DIR__ . '/../_boot.php';
require APP_DIR . '/dash.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
if (!current_admin()) {
    http_response_code(401);
    echo json_encode(['error' => 'login']);
    exit;
}
schedule_tick();
check_offline_servers();
$srv = dash_server();
$data = dash_live_data($srv);
$data['html'] = [
    'cards'    => dash_cards($srv),
    'overview' => dash_overview(),
    'procs'    => dash_procs($srv),
    'uptime'   => dash_uptime($srv),
    'logs'     => dash_logs(),
];
$data['alerts'] = count(alerts_unseen());
echo json_encode($data);
