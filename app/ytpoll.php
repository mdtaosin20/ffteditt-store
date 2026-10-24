<?php
/**
 * Live polls and channel messages in the YouTube live chat, sent from Admin → Live Poll.
 * Uses the stream's own YouTube connection (the same one that sets the title). Each poll / message costs
 * 20 units of the daily YouTube limit (10,000), finding the chat costs 1 (once per live video).
 */
declare(strict_types=1);

const YTP_POLLS = [
    ['q' => 'Is your country still in the arena? 🌍', 'o' => ['Yes! Still alive 💪', 'Has a shield 🛡', 'Out… need revive ♻', 'Not in this round 😢']],
    ['q' => 'What helps your country most? 🏆', 'o' => ['Chat 20 times 💬', 'Subscribe 🔔', 'Like 👍', 'Super Chat ⭐']],
    ['q' => 'Which country wins this round? 🏆', 'o' => ['My country! 💪', 'Asia 🌏', 'Africa / Europe 🌍', 'Americas 🌎']],
];
const YTP_MSGS = [
    "🌍 FLAG ARENA\n➊ Type your country 🌍\n➋ 20 chats = 🛡 Shield\n➌ Out? 40 chats = ♻ Revive\n➍ Subscribe 🔔 = Shield\n➎ Super Chat ⭐ = Both\n➏ Like 👍 = Hole stops 30s\n🔁 Share & bring your team!",
];
const YTP_Q_MAX = 100, YTP_O_MAX = 35, YTP_MSG_MAX = 200;

/** Saved polls and messages: ['polls' => [['q','o'=>[]]], 'msgs' => [string]] */
function ytp_saved(): array
{
    $d = json_decode((string) (setting_get('ytp_saved') ?? ''), true);
    if (!is_array($d)) $d = ['polls' => YTP_POLLS, 'msgs' => YTP_MSGS];
    return ['polls' => array_values($d['polls'] ?? []), 'msgs' => array_values($d['msgs'] ?? [])];
}

function ytp_save(array $d): void
{
    setting_set('ytp_saved', json_encode(['polls' => array_values($d['polls']), 'msgs' => array_values($d['msgs'])], JSON_UNESCAPED_UNICODE));
}

/** YouTube counts letters like a phone does (an emoji can be 2). */
function ytp_len(string $t): int
{
    return intdiv(strlen((string) mb_convert_encoding($t, 'UTF-16LE', 'UTF-8')), 2);
}

/** Clean poll from a form; returns [poll, error]. */
function ytp_clean_poll(string $q, array $opts): array
{
    $one = static fn ($t) => trim(preg_replace('/\s+/u', ' ', str_replace(['<', '>'], '', (string) $t)) ?? '');
    $q = $one($q);
    $o = array_values(array_filter(array_map($one, $opts), fn ($x) => $x !== ''));
    if ($q === '') return [null, 'Write the question.'];
    if (ytp_len($q) > YTP_Q_MAX) return [null, 'The question is too long (YouTube allows ' . YTP_Q_MAX . ' letters, now ' . ytp_len($q) . ').'];
    if (count($o) < 2) return [null, 'Write at least 2 answers.'];
    if (count($o) > 4) $o = array_slice($o, 0, 4);
    foreach ($o as $x) if (ytp_len($x) > YTP_O_MAX) return [null, '"' . $x . '" is too long (an answer can have ' . YTP_O_MAX . ' letters).'];
    if (count(array_unique(array_map('mb_strtolower', $o))) !== count($o)) return [null, 'Two answers are the same.'];
    return [['q' => $q, 'o' => $o], ''];
}

function ytp_clean_msg(string $t): array
{
    $t = trim(str_replace(["\r\n", "\r", '<', '>'], ["\n", "\n", '', ''], $t));
    $t = preg_replace("/\n{3,}/", "\n\n", $t) ?? $t;
    if ($t === '') return [null, 'Write the message.'];
    if (ytp_len($t) > YTP_MSG_MAX) return [null, 'The message is too long (YouTube allows ' . YTP_MSG_MAX . ' letters, now ' . ytp_len($t) . ').'];
    return [$t, ''];
}

/** Live streams with their YouTube state: [stream, ready (bool), why-not text]. */
function ytp_streams(): array
{
    $out = [];
    foreach (db()->query("SELECT * FROM streams WHERE status = 'live' ORDER BY id")->fetchAll() as $s) {
        $why = '';
        if ($s['yt_refresh'] === '') $why = 'not connected to YouTube (YouTube Manager → Connect YouTube)';
        elseif ($s['yt_video'] === '' || (int) $s['yt_video_start'] !== (int) $s['started_at']) $why = 'waiting for YouTube to show this live (a few minutes after it starts)';
        $out[] = [$s, $why === '', $why];
    }
    return $out;
}

function ytp_post(string $tok, string $path, array $q, ?array $body): array
{
    return yt_http('POST', YT_API . $path . '?' . http_build_query($q), ['Authorization: Bearer ' . $tok, 'Content-Type: application/json'],
        $body === null ? '' : json_encode($body, JSON_UNESCAPED_UNICODE));
}

/** The live chat of the stream's current live video (asked once per video). */
function ytp_chat_id(array $s, string $tok): string
{
    $key = 'ytp_chat_' . (int) $s['id'];
    $c = json_decode((string) (setting_get($key) ?? ''), true);
    if (is_array($c) && ($c['video'] ?? '') === $s['yt_video'] && ($c['chat'] ?? '') !== '') return (string) $c['chat'];
    $r = yt_get($tok, 'videos', ['part' => 'liveStreamingDetails', 'id' => $s['yt_video']]);
    if ($r[0] !== 200) throw new RuntimeException(yt_err($r));
    $chat = (string) ($r[1]['items'][0]['liveStreamingDetails']['activeLiveChatId'] ?? '');
    if ($chat === '') throw new RuntimeException('This live has no live chat right now (is chat turned on, and is the live still running?).');
    setting_set($key, json_encode(['video' => $s['yt_video'], 'chat' => $chat]));
    return $chat;
}

function ytp_error(array $r): string
{
    $reason = (string) ($r[1]['error']['errors'][0]['reason'] ?? '');
    if ($reason === 'preconditionCheckFailed' || stripos((string) ($r[1]['error']['message'] ?? ''), 'active poll') !== false) {
        return 'A poll is already running in this live. End it first (button "End poll"), or end it in YouTube Studio.';
    }
    if ($reason === 'quotaExceeded') return 'YouTube\'s daily limit is used up. It works again after about 1–2 PM Bangladesh time.';
    if ($reason === 'liveChatEnded' || $reason === 'liveChatNotFound' || $reason === 'liveChatDisabled') return 'The live chat of this live is closed or turned off.';
    if (in_array($reason, ['forbidden', 'insufficientPermissions'], true)) return 'YouTube did not allow it. Press "Connect YouTube" again for this channel in YouTube Manager.';
    return yt_err($r);
}

/** Start a poll; remembers it so it can be ended later. */
function ytp_start_poll(array $s, array $poll): void
{
    $tok = yt_token($s);
    $chat = ytp_chat_id($s, $tok);
    $r = ytp_post($tok, 'liveChat/messages', ['part' => 'snippet'], ['snippet' => [
        'liveChatId' => $chat, 'type' => 'pollEvent',
        'pollDetails' => ['metadata' => ['questionText' => $poll['q'], 'options' => array_map(fn ($o) => ['optionText' => $o], $poll['o'])]],
    ]]);
    if ($r[0] !== 200) throw new RuntimeException(ytp_error($r));
    setting_set('ytp_active_' . (int) $s['id'], json_encode(['id' => (string) ($r[1]['id'] ?? ''), 'q' => $poll['q'], 'o' => $poll['o'], 'at' => time(), 'video' => $s['yt_video']], JSON_UNESCAPED_UNICODE));
}

/** The poll this page started in the stream's current live, or null. */
function ytp_active(array $s): ?array
{
    $a = json_decode((string) (setting_get('ytp_active_' . (int) $s['id']) ?? ''), true);
    return is_array($a) && ($a['id'] ?? '') !== '' && ($a['video'] ?? '') === $s['yt_video'] ? $a : null;
}

/** End the running poll; returns the results [[answer, votes]] (votes may be null if YouTube does not say). */
function ytp_end_poll(array $s): array
{
    $a = ytp_active($s);
    if (!$a) throw new RuntimeException('No poll from this page is running in this live.');
    $tok = yt_token($s);
    $r = ytp_post($tok, 'liveChat/messages/transition', ['id' => $a['id'], 'status' => 'closed', 'part' => 'snippet'], null);
    setting_set('ytp_active_' . (int) $s['id'], '');
    if ($r[0] !== 200 && !in_array((string) ($r[1]['error']['errors'][0]['reason'] ?? ''), ['modificationNotAllowed', 'liveChatMessagesNotFound'], true)) {
        throw new RuntimeException(ytp_error($r));
    }
    $opts = $r[1]['snippet']['pollDetails']['metadata']['options'] ?? [];
    if (isset($opts['optionText'])) $opts = [$opts];
    $res = [];
    foreach ($opts as $o) $res[] = [(string) ($o['optionText'] ?? ''), isset($o['tally']) ? (int) $o['tally'] : null];
    if (!$res) foreach ($a['o'] as $o) $res[] = [$o, null];
    $last = ['q' => $a['q'], 'res' => $res, 'at' => time()];
    setting_set('ytp_last_' . (int) $s['id'], json_encode($last, JSON_UNESCAPED_UNICODE));
    return $last;
}

/** Send a message in the live chat as the channel (pin it from the phone afterwards). */
function ytp_say(array $s, string $text): void
{
    $tok = yt_token($s);
    $chat = ytp_chat_id($s, $tok);
    $r = ytp_post($tok, 'liveChat/messages', ['part' => 'snippet'], ['snippet' => [
        'liveChatId' => $chat, 'type' => 'textMessageEvent', 'textMessageDetails' => ['messageText' => $text],
    ]]);
    if ($r[0] !== 200) throw new RuntimeException(ytp_error($r));
}
