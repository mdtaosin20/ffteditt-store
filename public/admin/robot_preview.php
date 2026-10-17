<?php
// Voice preview for the live host: the VPS makes a sample line in the chosen voice.
//   POST {voice, csrf} -> {h, url}     GET ?h=… -> {h, url, failed}
require __DIR__ . '/../_boot.php';
require_login();

header('Content-Type: application/json');
header('Cache-Control: no-store');

const SAMPLE = 'Hi Rahim! Welcome to the live stream. Your flag is shining now! Please like and subscribe, and I will say your name on screen!';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_decode((string) file_get_contents('php://input', false, null, 0, 2000), true) ?: [];
    start_session();
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string) ($in['csrf'] ?? ''))) {
        http_response_code(400);
        echo json_encode(['error' => 'refresh the page']);
        exit;
    }
    $v = (string) ($in['voice'] ?? '');
    if (!isset(robot_voices()[$v])) {
        http_response_code(400);
        echo json_encode(['error' => 'unknown voice']);
        exit;
    }
    echo json_encode(robot_request(SAMPLE, $v));
    exit;
}
echo json_encode(robot_status((string) ($_GET['h'] ?? '')));
