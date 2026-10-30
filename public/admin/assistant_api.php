<?php
// Ask Claude: send a message, answer Yes/No on an action card, or start a new chat. JSON in / JSON out.
require __DIR__ . '/../_boot.php';
require APP_DIR . '/assistant.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$out = function (array $j, int $code = 200): void { http_response_code($code); echo json_encode($j, JSON_UNESCAPED_UNICODE); exit; };
if (!current_admin()) $out(['error' => 'Please log in again.'], 401);
if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) ($_SERVER['HTTP_X_CSRF'] ?? ''))) $out(['error' => 'Security check failed — refresh the page.'], 400);
session_write_close();   // Claude may think for a while: the other admin pages must not wait for this one
@set_time_limit(240);
ignore_user_abort(true);

$in = json_decode((string) file_get_contents('php://input'), true) ?: [];
$do = (string) ($in['do'] ?? 'view');
$st = as_state();

// one at a time (a second tab or a double press must not talk over the first)
$lk = fopen(DATA_DIR . '/assistant.lock', 'c');
if ($do !== 'view' && !flock($lk, LOCK_EX | LOCK_NB)) $out(['error' => 'Claude is still answering — wait a moment.'] + as_view($st), 409);
$st = as_state();

if ($do === 'send') {
    $text = trim(mb_substr((string) ($in['text'] ?? ''), 0, 2000));
    if ($text === '') $out(as_view($st));
    if (!as_cfg()['on'] || as_key() === '') $out(['error' => 'Add your Anthropic API key in Settings first.'] + as_view($st));
    if ($st['pending']) $out(['error' => 'First press Yes or No on the card.'] + as_view($st));
    // the last question got no answer (error): put the new words with it instead of two questions in a row
    $last = end($st['msgs']);
    if ($last && $last['role'] === 'user' && is_string($last['content'])) $st['msgs'][count($st['msgs']) - 1]['content'] .= "\n" . $text;
    else $st['msgs'][] = ['role' => 'user', 'content' => $text];
    as_run($st);
    as_store($st);
} elseif ($do === 'answer') {
    as_answer($st, !empty($in['yes']));
    as_store($st);
} elseif ($do === 'reset') {
    $st = ['msgs' => [], 'pending' => null, 'error' => ''];
    as_store($st);
}
$out(as_view($st));
