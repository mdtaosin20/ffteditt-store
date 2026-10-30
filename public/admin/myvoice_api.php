<?php
// My Voice: make a line in the owner's voice, or play one on the live. JSON in / JSON out.
require __DIR__ . '/../_boot.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$out = function (array $j, int $code = 200): void { http_response_code($code); echo json_encode($j, JSON_UNESCAPED_UNICODE); exit; };
if (!current_admin()) $out(['error' => 'Please log in again.'], 401);
if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) ($_SERVER['HTTP_X_CSRF'] ?? ''))) $out(['error' => 'Security check failed — refresh the page.'], 400);
session_write_close();
@set_time_limit(120);
$in = json_decode((string) file_get_contents('php://input'), true) ?: [];
$do = (string) ($in['do'] ?? '');
if ($do === 'make') {
    if (!mv_ready()) $out(['error' => 'Open ⚙️ Settings and add your ElevenLabs key and Voice ID first.']);
    $text = trim((string) ($in['text'] ?? ''));
    $en = trim(mb_substr((string) ($in['en'] ?? ''), 0, 1000));
    if ($text === '' && $en === '') $out(['error' => 'Write something first.']);
    $out(mv_make($text !== '' ? $text : $en, $en));
}
if ($do === 'live') $out(mv_play_live((string) ($in['id'] ?? '')) ? ['ok' => true] : ['error' => 'Not found — make it again.']);
$out(['error' => 'Unknown request.'], 400);
