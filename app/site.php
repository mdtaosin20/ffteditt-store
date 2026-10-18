<?php
/**
 * The public games website (home page): its texts, and live results reported by the stream browsers.
 */
declare(strict_types=1);

function site_defaults(): array
{
    return [
        'name'      => 'FFTEdit Games',
        'tagline'   => 'Play · Watch Live · Comment',
        'hero_sub'  => 'Play free · Watch live 24/7',
        'hero_text' => 'Free games you can play right here — and watch them live on YouTube all day. Pick your side and comment!',
        'youtube'   => '',   // main channel link for "Watch on YouTube"
        'about'     => 'We make fun, simple games and stream them live on YouTube 24/7. Join the live chat, pick your side and cheer!',
        'contact'   => '',   // e-mail or link shown under Contact
    ];
}

function site_config(): array
{
    $d = site_defaults();
    $c = json_decode(setting_get('site') ?? '', true);
    return is_array($c) ? array_merge($d, array_intersect_key($c, $d)) : $d;
}

function site_save(array $c): void
{
    setting_set('site', json_encode(array_merge(site_config(), $c)));
}

/** Splits "FFTEdit Games" into ["FFTEdit", "Games"] so the last word can be coloured. */
function site_name_parts(string $name): array
{
    $name = trim($name);
    $p = strrpos($name, ' ');
    return $p === false ? [$name, ''] : [substr($name, 0, $p), substr($name, $p + 1)];
}

/* ---------------------------------------------------------------- stream signing */

function app_secret(): string
{
    $s = setting_get('app_secret');
    if (!$s) {
        $s = bin2hex(random_bytes(32));
        setting_set('app_secret', $s);
    }
    return $s;
}

function stream_sig(int $id): string
{
    return substr(hash_hmac('sha256', 'stream:' . $id, app_secret()), 0, 32);
}

/** Page address given to the VPS: the game page plus a signature so it may report results. */
function signed_page_url(string $url, int $id): string
{
    return $url . (str_contains($url, '?') ? '&' : '?') . 'stream=' . $id . '&sig=' . stream_sig($id);
}

/* ---------------------------------------------------------------- results */

function results_get(): array
{
    $r = json_decode(setting_get('results') ?? '', true);
    $r = is_array($r) ? $r : [];
    return [
        'ball'  => (array) ($r['ball'] ?? []),
        'flags' => (array) ($r['flags'] ?? []),
    ];
}

function results_save(array $r): void
{
    setting_set('results', json_encode($r));
}

/** Small public summary for the home page. */
function results_public(): array
{
    $r = results_get();
    $b = $r['ball'];
    $f = $r['flags'];
    $fresh = fn ($t) => (int) $t > time() - 900; // only show live numbers reported in the last 15 minutes
    return [
        'ball' => [
            'live'  => $fresh($b['updated'] ?? 0),
            'score' => array_map('intval', (array) ($b['score'] ?? [0, 0])),
            'games' => array_slice((array) ($b['games'] ?? []), 0, 4),
        ],
        'flags' => [
            'live'      => $fresh($f['updated'] ?? 0),
            'round'     => (int) ($f['round'] ?? 0),
            'final'     => (bool) ($f['final'] ?? false),
            'qualified' => (int) ($f['qualified'] ?? 0),
            'winners'   => array_slice((array) ($f['winners'] ?? []), 0, 4),
            'champion'  => $f['champion'] ?? null,
        ],
    ];
}

/** Streams that are live right now, for the "Live on YouTube" box. */
function live_streams(): array
{
    $out = [];
    $servers = all_servers();
    foreach (db()->query('SELECT id, name, page_url, status, server_id, channel_name, channel_url FROM streams ORDER BY id') as $s) {
        $srv = $servers[(int) $s['server_id']] ?? null;
        $out[] = [
            'game'    => game_of_url($s['page_url']),
            'name'    => $s['name'],
            'channel' => $s['channel_name'] !== '' ? $s['channel_name'] : $s['name'],
            'url'     => watch_url($s['channel_url']),
            'live'    => $s['status'] === 'live' && server_online($srv),
        ];
    }
    return $out;
}

/* ---------------------------------------------------------------- small HTML pieces (page + 30 s refresh) */

function flag_html(string $code, string $name = ''): string
{
    static $idx = null;
    if ($idx === null) {
        $idx = [];
        $f = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/') . '/flags/flags.json';
        $j = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
        foreach ((array) ($j['countries'] ?? []) as $i => $c) {
            $idx[$c['code']] = $i;
        }
    }
    if (!isset($idx[$code])) {
        return '';
    }
    $i = $idx[$code];
    $w = 24; $h = 18; $cols = 16; $rows = 13;
    return '<span class="flg" role="img" aria-label="' . e($name) . '" style="background-size:' . ($cols * $w) . 'px ' . ($rows * $h)
        . 'px;background-position:-' . (($i % $cols) * $w) . 'px -' . (intdiv($i, $cols) * $h) . 'px"></span>';
}

function site_live_count(): int
{
    return count(array_filter(live_streams(), fn ($s) => $s['live']));
}

function site_live_html(): string
{
    $games = games_list();
    $rows = live_streams();
    if (!$rows) {
        return '<p class="muted">Live streams appear here.</p>';
    }
    usort($rows, fn ($a, $b) => (int) $b['live'] - (int) $a['live']);
    $h = '';
    foreach (array_slice($rows, 0, 5) as $s) {
        $g = $games[$s['game']];
        $tag = $s['live'] ? '<span class="tag tl">LIVE</span>' : '<span class="tag to">OFFLINE</span>';
        $inner = '<img src="' . e($g['thumb']) . '" alt=""><div>' . e($g['title']) . '<small>' . e($s['channel']) . '</small></div>' . $tag;
        $h .= $s['url'] !== ''
            ? '<a class="lv" href="' . e($s['url']) . '" target="_blank" rel="noopener">' . $inner . '</a>'
            : '<div class="lv">' . $inner . '</div>';
    }
    return $h;
}

function site_score_html(): string
{
    $r = results_public();
    $ball = game_config();
    $h = '';
    $p = $ball['players'];
    $h .= '<div class="kv"><span>' . e(ucwords(strtolower($p[0]['name']))) . '</span><b style="color:' . e($p[0]['color']) . '">' . ($r['ball']['live'] ? (int) $r['ball']['score'][0] : '–') . '</b></div>';
    $h .= '<div class="kv"><span>' . e(ucwords(strtolower($p[1]['name']))) . '</span><b style="color:' . e($p[1]['color']) . '">' . ($r['ball']['live'] ? (int) $r['ball']['score'][1] : '–') . '</b></div>';
    $f = $r['flags'];
    $h .= '<div class="kv"><span>Flag Arena</span><b>' . ($f['live'] ? 'Round ' . (int) $f['round'] : '–') . '</b></div>';
    $c = $f['champion'];
    $h .= '<div class="kv"><span>Last champion</span><b>' . ($c ? flag_html($c['code'], $c['name']) . ' ' . e($c['name']) : '–') . '</b></div>';
    return $h;
}

function site_results_html(): string
{
    $r = results_public();
    $ball = game_config();
    $left = '';
    foreach ($r['ball']['games'] as $g) {
        $color = $ball['players'][(int) ($g['wi'] ?? 0)]['color'] ?? '#fff';
        $left .= '<div class="kv"><span>⚽ ' . e(ucwords(strtolower($ball['players'][0]['name'] . ' vs ' . $ball['players'][1]['name']))) . ' · Game #' . (int) $g['n'] . '</span><b style="color:' . e($color) . '">'
            . e(ucwords(strtolower((string) $g['w']))) . ' ' . e($g['score']) . '</b></div>';
    }
    $right = '';
    foreach ($r['flags']['winners'] as $w) {
        $right .= '<div class="kv"><span>🏳 Flag Arena · Round ' . (int) $w['round'] . ' champion</span><b>' . flag_html($w['code'], $w['name']) . ' ' . e($w['name']) . '</b></div>';
    }
    $empty = '<p class="muted small">Results show here while the game is live.</p>';
    return '<div class="box">' . ($left ?: $empty) . '</div><div class="box">' . ($right ?: $empty) . '</div>';
}

function site_watch_url(): string
{
    $s = site_config();
    if ($s['youtube'] !== '') {
        return $s['youtube'];
    }
    foreach (live_streams() as $l) {
        if ($l['live'] && $l['url'] !== '') return $l['url'];
    }
    foreach (live_streams() as $l) {
        if ($l['url'] !== '') return $l['url'];
    }
    return '';
}
