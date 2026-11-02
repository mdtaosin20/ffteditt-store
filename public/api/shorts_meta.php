<?php
// The Shorts games (Cup Kick, Action Kick, Player Kick) on the owner's phone: ✨ AI titles and description for a
// recorded video. Only from the owner's devices (owner cookie or admin login) and only from this website.
// Uses the AI key in Admin → Settings (the same as LUNA). The answer is never sent here (the page leaves it out).
require __DIR__ . '/../_boot.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$out = function (array $j, int $code = 200): void { http_response_code($code); echo json_encode($j, JSON_UNESCAPED_UNICODE); exit; };
$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$from = strtolower((string) parse_url((string) ($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_HOST) ?: '');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $from === '' || $from !== preg_replace('/:\d+$/', '', $host)) $out(['error' => 'not allowed'], 403);
if (!owner_cookie_ok() && !current_admin()) $out(['error' => 'Open the admin panel once on this phone (log in), then try again.'], 403);
if (ai_key() === '') $out(['error' => 'No AI key saved yet: YouTube Manager → 🤖 AI Replies → save your OpenAI key.']);   // works even when LUNA's AI answers are off
$in = json_decode((string) file_get_contents('php://input'), true) ?: [];
$i = is_array($in['info'] ?? null) ? $in['info'] : [];
$kind = in_array($i['kind'] ?? '', ['country', 'sign', 'player'], true) ? $i['kind'] : 'country';
$names = array_slice(array_map(fn ($x) => mb_substr(preg_replace('/[^\p{L}\p{N} .\'\-]/u', '', (string) $x), 0, 24), is_array($i['names'] ?? null) ? $i['names'] : []), 0, 6);
$used = array_slice(array_map(fn ($x) => mb_substr((string) $x, 0, 100), is_array($in['used'] ?? null) ? $in['used'] : []), -40);
$what = ['country' => 'country flag balls', 'sign' => 'players holding SUBSCRIBE / LIKE / COMMENT / SHARE signs', 'player' => 'famous football players'][$kind];
$level = (int) ($i['level'] ?? 3);
$system = 'You write YouTube Shorts titles and descriptions for a short football puzzle video. In the video ' . count($names) . " {$what} each kick a ball at a golden trophy; only ONE ball touches it and knocks it over, and viewers must guess which one. "
    . 'Titles: strong curiosity hook (challenge the viewer, e.g. "Only 1% see it"), max 70 characters, 1-2 emojis, English, NEVER reveal or hint the answer, no clickbait lies, no ALL CAPS sentences. '
    . 'Description: 4-6 short lines (what happens, ask viewers to comment their answer, list the names with emojis, invite to subscribe) then one line of 6-9 hashtags starting with #shorts. '
    . 'Reply ONLY with JSON: {"titles":["…","…","…","…"],"description":"…"}';
$user = 'Names: ' . implode(', ', $names) . '. Difficulty: ' . (['calm', 'normal', 'wild', 'very hard', 'extreme'][$level] ?? 'hard') . '. Video length: ' . round((float) ($i['secs'] ?? 10)) . ' s'
    . (!empty($i['loop']) ? ', it loops' : '') . ".\nDo not use these titles again: " . implode(' | ', $used);
@set_time_limit(60);
$txt = ai_call($system, $user, 700, 30);
if ($txt === null) $out(['error' => 'The AI did not answer. Check the key in YouTube Manager → 🤖 AI Replies.']);
if (!preg_match('/\{.*\}/s', $txt, $m) || !is_array($j = json_decode($m[0], true))) $out(['error' => 'The AI answer was not readable — press ✨ AI again.']);
$titles = array_values(array_filter(array_map(fn ($t) => mb_substr(trim((string) $t), 0, 100), is_array($j['titles'] ?? null) ? $j['titles'] : [])));
$out(['titles' => array_slice($titles, 0, 4), 'description' => mb_substr(trim((string) ($j['description'] ?? '')), 0, 4500)]);
