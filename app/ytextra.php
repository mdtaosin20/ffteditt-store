<?php
/**
 * More choice for each channel's lives (YouTube Manager → Title & thumbnail):
 *  - a list of titles: a different one, at random, on every live (never the same twice in a row);
 *    until the owner chooses, a game with its own list of titles (Last Flag Standing) uses that
 *  - several thumbnails: a different one, at random, on every live
 *  - a message for pinning: sent in the live chat by itself when the live starts, and again every N minutes
 *    (YouTube does not let a program pin it: the owner taps it on the phone → Pin)
 * Kept in settings: ytx_<stream id> (choices), ytx_pick_<id> (what this live uses), ytx_pin_<id> (when sent).
 */
declare(strict_types=1);

const YTX_MAX_TITLES = 20, YTX_MAX_THUMBS = 5;   // own extra pictures (+ the main / ready-made one)

function ytx_get(int $id): array
{
    $d = ['tmode' => '', 'titles' => [], 'thmode' => '', 'thumbs' => [], 'pin_on' => false, 'pin_text' => '', 'pin_every' => 60];
    $c = json_decode((string) (setting_get('ytx_' . $id) ?? ''), true);
    return is_array($c) ? array_merge($d, array_intersect_key($c, $d)) : $d;
}

function ytx_save(int $id, array $c): void
{
    setting_set('ytx_' . $id, json_encode($c, JSON_UNESCAPED_UNICODE));
}

/** The pinned-message text a game starts with. */
function ytx_pin_default(string $game): string
{
    $name = ['lastflag' => 'LAST FLAG STANDING', 'flags' => 'FLAG ARENA'][$game] ?? '';
    if ($name === '') return "🌍 Type your country in the chat!\n🔔 Subscribe & 👍 Like\n🔁 Share & bring your team!";
    return "🌍 {$name}\n➊ Type your country 🌍\n➋ 10 chats = 🛡 Shield\n➌ Out? 20 chats = ♻ Revive\n➍ Subscribe 🔔 / Share 📢 = 🛡 or ♻\n➎ Super Chat ⭐ = Both\n🔁 Share & bring your team!";
}

function ytx_thumb_path(int $id, string $name): string
{
    return yt_thumb_dir() . '/x_' . $id . '_' . preg_replace('/[^a-z0-9]/', '', $name) . '.jpg';
}

/** The game's own list of titles (used on every live when the stream has no title of its own). */
function ytx_game_titles(array $s): array
{
    if ((string) ($s['yt_title'] ?? '') !== '') return [];
    $d = yt_defaults(game_of_url((string) $s['page_url']));
    return array_values(array_filter((array) ($d['titles'] ?? []), fn ($t) => mb_strlen($t) <= 100));
}

/** The game's ready-made thumbnails (app/yt/<game>.jpg, <game>_2.jpg …) — used in turn when the stream uses the ready-made one. */
function ytx_game_thumbs(array $s): array
{
    if ((string) ($s['yt_thumb'] ?? 'default') !== 'default') return [];
    $g = game_of_url((string) $s['page_url']);
    $out = [];
    foreach (['', '_2', '_3', '_4', '_5'] as $k) { $f = APP_DIR . '/yt/' . $g . $k . '.jpg'; if (is_file($f)) $out[] = $f; }
    return count($out) > 1 ? $out : [];
}

/** Pick at random, but not the one used last time (when there is a choice). */
function ytx_random(int $n, int $last): int
{
    if ($n <= 1) return 0;
    do { $k = random_int(0, $n - 1); } while ($k === $last);
    return $k;
}

/**
 * Title and thumbnail for this live (chosen once per live, so retries use the same).
 * @return array{title: ?string, thumb: ?array} title null = keep; thumb null = keep the normal one
 */
function ytx_pick(array $s): array
{
    $id = (int) $s['id'];
    $c = ytx_get($id);
    $start = (int) ($s['started_at'] ?? 0);
    $p = json_decode((string) (setting_get('ytx_pick_' . $id) ?? ''), true) ?: [];
    $game = game_of_url((string) $s['page_url']);
    if (($p['start'] ?? -1) !== $start || ($p['g'] ?? '') !== $game || !array_key_exists('ti', $p)) {
        $titles = $c['tmode'] === 'list' ? $c['titles'] : ($c['tmode'] === '' ? ytx_game_titles($s) : []);
        $thumbs = $c['thmode'] === 'list' ? array_merge(['main'], $c['thumbs']) : ($c['thmode'] === '' ? ytx_game_thumbs($s) : []);
        $p = ['start' => $start, 'g' => $game,
              'ti' => $titles ? ytx_random(count($titles), (int) ($p['ti'] ?? -1)) : -1,
              'th' => count($thumbs) > 1 ? ytx_random(count($thumbs), (int) ($p['th'] ?? -1)) : -1];
        setting_set('ytx_pick_' . $id, json_encode($p));
    }
    $list = $c['tmode'] === 'list' ? $c['titles'] : ($c['tmode'] === '' ? ytx_game_titles($s) : []);
    $title = isset($list[$p['ti']]) && $p['ti'] >= 0 ? (string) $list[$p['ti']] : null;
    $thumb = null;
    if ($c['thmode'] === 'list' && $p['th'] > 0 && isset($c['thumbs'][$p['th'] - 1])) {
        $f = ytx_thumb_path($id, (string) $c['thumbs'][$p['th'] - 1]);
        if (is_file($f)) $thumb = [$f, 'image/jpeg'];
    } elseif ($c['thmode'] === '' && $p['th'] >= 0) {
        $g = ytx_game_thumbs($s);
        if (isset($g[$p['th']]) && is_file($g[$p['th']])) $thumb = [$g[$p['th']], 'image/jpeg'];
    }
    return ['title' => $title, 'thumb' => $thumb];
}

/**
 * After each worker check-in: send the message for pinning in each live chat that wants it —
 * when the live has run for a minute, then again every pin_every minutes.
 */
function ytx_pin_tick(int $serverId): void
{
    if (!yt_ready() || yt_units_out()) return;
    $now = time();
    $st = db()->prepare("SELECT * FROM streams WHERE server_id = ? AND status = 'live' AND yt_refresh != '' AND yt_video != ''
                         AND yt_video_start = COALESCE(started_at, 0) AND started_at < ?");
    $st->execute([$serverId, $now - 75]);
    foreach ($st->fetchAll() as $s) {
        $id = (int) $s['id'];
        $c = ytx_get($id);
        if (!$c['pin_on'] || trim($c['pin_text']) === '') continue;
        $k = json_decode((string) (setting_get('ytx_pin_' . $id) ?? ''), true) ?: [];
        if (($k['start'] ?? 0) !== (int) $s['started_at']) $k = ['start' => (int) $s['started_at'], 'at' => 0, 'n' => 0, 'fails' => 0, 'msg' => ''];
        $every = max(10, (int) $c['pin_every']) * 60;
        if ($k['at'] && $now - $k['at'] < $every) continue;
        if ($k['fails'] >= 3 && $now - $k['at'] < 1800) continue;   // something is wrong (chat off?): try again in half an hour
        $k['at'] = $now;
        try {
            ytp_say($s, $c['pin_text']);
            $k['n']++; $k['fails'] = 0; $k['msg'] = 'Sent ' . date('d M, h:i A') . ' (' . $k['n'] . ' on this live)';
            log_activity("YouTube: message for pinning sent on {$s['name']}", 'ok');
        } catch (Throwable $e) {
            $k['fails']++; $k['msg'] = 'Could not send (' . date('h:i A') . '): ' . $e->getMessage();
        }
        setting_set('ytx_pin_' . $id, json_encode($k, JSON_UNESCAPED_UNICODE));
    }
}
