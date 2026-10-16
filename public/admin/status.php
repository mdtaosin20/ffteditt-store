<?php
// JSON used by the dashboard to refresh stream badges without reloading.
require __DIR__ . '/../_boot.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!current_admin()) {
    http_response_code(401);
    echo json_encode(['error' => 'login']);
    exit;
}

$servers = all_servers();
$out     = ['streams' => [], 'live' => 0, 'total' => 0];
foreach (db()->query('SELECT * FROM streams') as $s) {
    [$code, $label] = stream_view_status($s, $servers[(int) $s['server_id']] ?? null);
    $live = $code === 'live';
    $out['total']++;
    if ($live) {
        $out['live']++;
    }
    $out['streams'][] = [
        'id'      => (int) $s['id'],
        'code'    => $code,
        'label'   => $label,
        'desired' => $s['desired'],
        'uptime'  => $live && $s['started_at'] ? duration((int) $s['started_at']) : '—',
        'fps'     => $live && $s['fps_now'] !== null ? (string) round((float) $s['fps_now']) : '—',
        'speed'   => $live && $s['speed_now'] ? $s['speed_now'] : '—',
        'error'   => $code === 'error' ? (string) $s['error'] : '',
    ];
}
echo json_encode($out);
