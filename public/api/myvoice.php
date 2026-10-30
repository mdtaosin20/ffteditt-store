<?php
// The owner's own game pages (Cup Kick, Action Kick on his phone) make a line in his voice (Admin → My Voice).
// Only from the owner's devices (owner cookie or admin login) and only from this website.
require __DIR__ . '/../_boot.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$out = function (array $j, int $code = 200): void { http_response_code($code); echo json_encode($j, JSON_UNESCAPED_UNICODE); exit; };
$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$from = strtolower((string) parse_url((string) ($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_HOST) ?: '');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $from === '' || $from !== preg_replace('/:\d+$/', '', $host)) $out(['error' => 'not allowed'], 403);
if (!owner_cookie_ok() && !current_admin()) $out(['error' => 'Open the admin panel once on this phone (log in), then try again.'], 403);
if (!mv_ready()) $out(['error' => 'Set up Admin → 🗣 My Voice → Settings first.']);
$in = json_decode((string) file_get_contents('php://input'), true) ?: [];
$text = trim(mb_substr((string) ($in['text'] ?? ''), 0, 400));
if ($text === '') $out(['error' => 'empty']);
@set_time_limit(90);
$r = mv_make($text, '', (string) ($in['lang'] ?? 'en'));
$out(isset($r['item']) ? ['url' => $r['item']['url'], 'en' => $r['item']['en']] : ['error' => $r['error']]);
