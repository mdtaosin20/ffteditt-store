<?php
/** Flag Arena settings (the game at /flags/), shared by Admin → Flag Arena and /api/flags.php. */
declare(strict_types=1);

function flags_defaults(): array
{
    return [
        'title'    => 'FLAG ARENA',
        'count'    => 64,      // countries in one round
        'gap'      => 5,       // seconds between the black hole's openings (longer = longer rounds)
        'minutes'  => 55,      // (old setting, not used)
        'shield'   => 10,      // chats for a country -> it gets a shield
        'revive'   => 20,      // chats for a country that is out -> it comes back
        'revives'  => 0,       // revives allowed in one round
        'likes'    => 50,      // every this many new likes: the hole closes for 30 s
        'viewers'  => 100,     // people watching reach this: top 3 chat countries get shields
        'events'   => true,    // mystery boxes (double draw, lucky shields, …)
        'music'    => true,    // background music made in code (copyright free)
        'music_src'   => 'songs',   // 'songs' = NCS songs from the panel (live stream only), 'own' = made in code
        'music_style' => 'upbeat',
        'music_vol'   => 35,   // %
        'autoplay' => true,
        'pace'     => 'normal',
        'rev'      => '1',
        'reset'    => '1',
    ];
}

function flags_config(): array
{
    $d = flags_defaults();
    $c = json_decode(setting_get('flags') ?? '', true);
    $c = is_array($c) ? array_merge($d, array_intersect_key($c, $d)) : $d;
    if (setting_get('mig_flag_arena') === null) {   // once: Flag Battle became Flag Arena
        setting_set('mig_flag_arena', '1');
        $c['title'] = $d['title'];
        $c['reset'] = bin2hex(random_bytes(4));
        $c['rev'] = bin2hex(random_bytes(4));
        flags_save($c);
    }
    if (setting_get('mig_flag_unlim') === null) {   // once: revives with no limit (the owner asked)
        setting_set('mig_flag_unlim', '1');
        $c['revives'] = 0;
        $c['rev'] = bin2hex(random_bytes(4));
        flags_save($c);
    }
    return $c;
}

function flags_save(array $c): void
{
    setting_set('flags', json_encode(array_intersect_key(array_merge(flags_defaults(), $c), flags_defaults())));
}


/* ---------------------------------------------------------------- which countries chat
 * The game page tells the panel how many chats (and different people) named each country, once a minute.
 * The next rounds then use the countries whose people really come, plus the biggest countries. */
function fans_add(string $game, array $tally): void
{
    $day = date('Y-m-d');
    // (insert-or-ignore + update works on every SQLite version)
    $ins = db()->prepare('INSERT OR IGNORE INTO country_chat (game, day, code, chats, fans) VALUES (?,?,?,0,0)');
    $up = db()->prepare('UPDATE country_chat SET chats = chats + ?, fans = fans + ? WHERE game = ? AND day = ? AND code = ?');
    $n = 0;
    foreach ($tally as $code => $v) {
        if (++$n > 300 || !is_string($code) || !preg_match('/^[a-z]{2}$/', $code) || !is_array($v)) continue;
        $chats = max(0, min(5000, (int) ($v[0] ?? 0)));
        $fans = max(0, min($chats, (int) ($v[1] ?? 0)));
        if ($chats > 0) { $ins->execute([$game, $day, $code]); $up->execute([$chats, $fans, $game, $day, $code]); }
    }
    if (random_int(1, 100) === 1) db()->exec("DELETE FROM country_chat WHERE day < '" . date('Y-m-d', time() - 120 * 86400) . "'");
}

/** Same numbers worked out from the saved live chat (last 3 days) — used until the game has sent its own. */
function fans_from_chat(string $game): array
{
    $cache = json_decode((string) (setting_get('fans_chat_' . $game) ?? ''), true);
    if (is_array($cache) && ($cache['t'] ?? 0) > time() - 600) return $cache['top'];
    $ids = [];
    foreach (db()->query('SELECT id, page_url FROM streams')->fetchAll() as $st) if (game_of_url((string) $st['page_url']) === $game) $ids[] = (int) $st['id'];
    $chats = []; $people = [];
    if ($ids) {
        $q = db()->query("SELECT author, text FROM chat_events WHERE kind = 'chat' AND ts > " . (time() - 3 * 86400) . ' AND stream_id IN (' . implode(',', $ids) . ')');
        foreach ($q as $r) {
            $cc = insight_country_of((string) $r['text']);
            if (!$cc) continue;
            $chats[$cc] = ($chats[$cc] ?? 0) + 1;
            $people[$cc][mb_strtolower((string) $r['author'])] = true;
        }
    }
    $top = [];
    foreach ($chats as $cc => $n) $top[] = [(string) $cc, $n, count($people[$cc])];
    usort($top, fn ($x, $y) => [$y[2], $y[1]] <=> [$x[2], $x[1]]);
    setting_set('fans_chat_' . $game, json_encode(['t' => time(), 'top' => $top]));
    return $top;
}

/** [[code, chats, people], …] most first, over the last $days days (from the saved live chat while the game has sent too little). */
function fans_top(string $game, int $days = 7, int $limit = 120): array
{
    $n = (int) db()->query("SELECT COUNT(*) FROM country_chat WHERE game = " . db()->quote($game))->fetchColumn();
    if ($n < 20) return array_slice(fans_from_chat($game), 0, $limit);
    $q = db()->prepare('SELECT code, SUM(chats) c, SUM(fans) f FROM country_chat WHERE game = ? AND day >= ? GROUP BY code ORDER BY f DESC, c DESC LIMIT ' . $limit);
    $q->execute([$game, date('Y-m-d', time() - ($days - 1) * 86400)]);
    return array_map(fn ($r) => [(string) $r['code'], (int) $r['c'], (int) $r['f']], $q->fetchAll());
}

/** Country name for a code (from the Flag Arena list). */
function fans_name(string $code): string
{
    static $n = null;
    if ($n === null) {
        $n = [];
        $j = json_decode((string) @file_get_contents(dirname(__DIR__) . '/public/flags/flags.json'), true);
        foreach ($j['countries'] ?? [] as $c) $n[$c['code']] = $c['name'];
    }
    return $n[$code] ?? strtoupper($code);
}

function fans_emoji(string $code): string
{
    return preg_match('/^[a-z]{2}$/', $code) ? mb_chr(0x1F1E6 + ord($code[0]) - 97) . mb_chr(0x1F1E6 + ord($code[1]) - 97) : '';
}

