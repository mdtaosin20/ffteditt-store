<?php
// The "Request access" page of the private website: send a request / ask whether it was approved.
require __DIR__ . '/../_boot.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) {
        http_response_code(400);
        exit(json_encode(['error' => 'bad request']));
    }
    $in = json_decode((string) file_get_contents('php://input', false, null, 0, 4000), true);
    [$st, $code, $err] = dev_request(is_array($in) ? (string) ($in['name'] ?? '') : '');
    echo json_encode(['status' => $st, 'code' => $code, 'error' => $err]);
    exit;
}
$r = dev_row();
echo json_encode(['status' => $r ? $r['status'] : '', 'code' => $r ? $r['code'] : '']);
