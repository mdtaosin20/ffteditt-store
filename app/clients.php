<?php
/**
 * Client Links: one link for a client or friend. He opens it on his own phone, gives his channel, stream key and the
 * game he wants, then (in the same link) connects his Google account. A stream is made for him here; the owner
 * starts and stops it from the panel (Admin → Client Links). The client never sees the admin panel.
 */
declare(strict_types=1);

const CLI_DAYS = 7;   // an unused link stops working after this many days

/** All links: [{id, label, t (token), made, exp, sid (stream id, 0 = not filled), off}] */
function cli_links(): array { return json_decode((string) (setting_get('cli_links') ?? ''), true) ?: []; }
function cli_save(array $l): void { setting_set('cli_links', json_encode(array_values($l), JSON_UNESCAPED_UNICODE)); }

function cli_url(string $tok): string
{
    $host = preg_replace('/[^a-z0-9.\-:]/i', '', (string) ($_SERVER['HTTP_HOST'] ?? 'ffteditt.store'));
    return 'https://' . $host . '/join.php?t=' . $tok;
}

function cli_create(string $label): array
{
    $l = cli_links();
    $v = ['id' => bin2hex(random_bytes(4)), 'label' => mb_substr(trim($label), 0, 40), 't' => rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '='),
          'made' => time(), 'exp' => time() + CLI_DAYS * 86400, 'sid' => 0, 'off' => false];
    $l[] = $v; cli_save($l);
    return $v;
}

/** The link for a token (still usable), or null. A filled link keeps working, so the client can come back and look. */
function cli_find(string $tok): ?array
{
    if (!preg_match('/^[A-Za-z0-9_-]{20,40}$/', $tok)) return null;
    foreach (cli_links() as $v) {
        if (!hash_equals((string) $v['t'], $tok) || !empty($v['off'])) continue;
        if ((int) $v['sid'] === 0 && (int) $v['exp'] < time()) return null;
        if ((int) $v['sid'] > 0 && !cli_stream((int) $v['sid'])) return null;   // the stream was deleted
        return $v;
    }
    return null;
}

function cli_update(string $id, array $set): void
{
    $l = cli_links();
    foreach ($l as &$v) if ($v['id'] === $id) $v = array_merge($v, $set);
    unset($v);
    cli_save($l);
}

function cli_stream(int $sid): ?array
{
    $st = db()->prepare('SELECT * FROM streams WHERE id = ?');
    $st->execute([$sid]);
    return $st->fetch() ?: null;
}

/** The games a client may choose (24/7 lives only — not the short-video games). */
function cli_games(): array
{
    return array_filter(games_list(), fn ($g) => !in_array($g['key'], ['cupkick', 'actionkick'], true));
}

/** The VPS with the least work (online first). */
function cli_pick_server(): ?int
{
    $now = time(); $best = null;
    $load = [];
    foreach (db()->query("SELECT server_id, COUNT(*) n FROM streams WHERE desired = 'on' AND server_id IS NOT NULL GROUP BY server_id") as $r) $load[(int) $r['server_id']] = (int) $r['n'];
    foreach (db()->query('SELECT id, last_seen, info_json FROM servers ORDER BY id') as $s) {
        $cpus = (int) ((json_decode($s['info_json'] ?: '{}', true) ?: [])['cpus'] ?? 1);
        $score = ($now - (int) $s['last_seen'] <= SERVER_OFFLINE_AFTER ? 0 : 1000) + ($load[(int) $s['id']] ?? 0) / max(1, $cpus);
        if ($best === null || $score < $best[1]) $best = [(int) $s['id'], $score];
    }
    return $best ? $best[0] : null;
}

/** Step 1 of the link: makes the stream. Returns '' or an error to show. */
function cli_submit(array $link, array $in): string
{
    $name = trim(mb_substr((string) ($in['name'] ?? ''), 0, 40));
    $chan = trim(mb_substr((string) ($in['channel'] ?? ''), 0, 200));
    $key  = trim((string) ($in['key'] ?? ''));
    $game = (string) ($in['game'] ?? '');
    $games = cli_games();
    if ($name === '') return 'Please write your name or your channel name.';
    if ($chan !== '' && !preg_match('#^(https?://)?(www\.|m\.)?(youtube\.com|youtu\.be)/#i', $chan) && !preg_match('/^@[\w.\-]{2,60}$/u', $chan)) return 'The channel link should look like youtube.com/@YourChannel (or just @YourChannel).';
    if ($key !== '' && !preg_match('/^[A-Za-z0-9_\-]{8,80}$/', $key)) return 'This does not look like a YouTube stream key. Copy it again from YouTube Studio.';
    if (!isset($games[$game])) return 'Please choose which live you want.';
    if ($key !== '') {
        $d = db()->prepare('SELECT COUNT(*) FROM streams WHERE stream_key = ?'); $d->execute([$key]);
        if ((int) $d->fetchColumn() > 0) return 'This stream key is already used here. Ask the owner, or copy a new key from YouTube Studio.';
    }
    if ($chan !== '' && $chan[0] === '@') $chan = 'https://www.youtube.com/' . $chan;
    elseif ($chan !== '' && !preg_match('#^https?://#i', $chan)) $chan = 'https://' . $chan;
    $host = preg_replace('/[^a-z0-9.\-:]/i', '', (string) ($_SERVER['HTTP_HOST'] ?? 'ffteditt.store'));
    $now = time();
    db()->prepare('INSERT INTO streams (name, stream_key, page_url, server_id, channel_name, channel_url, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$name . ' (client)', $key, 'https://' . $host . $games[$game]['url'], cli_pick_server(), $name, $chan, $now, $now]);
    $sid = (int) db()->lastInsertId();
    cli_update($link['id'], ['sid' => $sid, 'filled' => $now]);
    log_activity("👥 Client “{$name}” filled his link (" . $games[$game]['name'] . ($key !== '' ? ', stream key ✓' : ', no key yet') . ') — next: Google', 'ok');
    return '';
}

/* ---- the Google step (the client has no admin login, so the trip is remembered here) ---- */
function cli_state_new(string $tok): string
{
    $state = 'cli' . bin2hex(random_bytes(16));
    setting_set('cli_state_' . $state, json_encode(['t' => $tok, 'ts' => time()]));
    return $state;
}
function cli_state_take(string $state): ?array
{
    if (!preg_match('/^cli[a-f0-9]{32}$/', $state)) return null;
    $v = json_decode((string) (setting_get('cli_state_' . $state) ?? ''), true);
    setting_set('cli_state_' . $state, '');
    return is_array($v) && time() - (int) $v['ts'] <= 1800 ? $v : null;
}

/** With Google connected and no key given: take the stream key from his YouTube (or make one). Returns the key or ''. */
function cli_fetch_key(array $s): string
{
    if ($s['stream_key'] !== '' || $s['yt_refresh'] === '') return (string) $s['stream_key'];
    try {
        $tok = yt_token($s);
        $r = yt_get($tok, 'liveStreams', ['part' => 'id,snippet,cdn', 'mine' => 'true', 'maxResults' => 50]);
        $pick = null;
        foreach ($r[1]['items'] ?? [] as $x) {
            if (($x['cdn']['ingestionType'] ?? '') !== 'rtmp' || empty($x['cdn']['ingestionInfo']['streamName'])) continue;
            if ($pick === null || stripos((string) ($x['snippet']['title'] ?? ''), 'default') !== false) $pick = $x;
        }
        if ($pick === null) {   // no key on his channel yet: make one
            $body = json_encode(['snippet' => ['title' => 'Game live key'], 'cdn' => ['frameRate' => 'variable', 'ingestionType' => 'rtmp', 'resolution' => 'variable'],
                                 'contentDetails' => ['isReusable' => true]]);
            $c = yt_http('POST', YT_API . 'liveStreams?part=snippet,cdn,contentDetails', ['Authorization: Bearer ' . $tok, 'Content-Type: application/json'], $body);
            if ($c[0] === 200) $pick = $c[1];
        }
        $key = (string) ($pick['cdn']['ingestionInfo']['streamName'] ?? '');
        if ($key === '') return '';
        $d = db()->prepare('SELECT COUNT(*) FROM streams WHERE stream_key = ? AND id != ?'); $d->execute([$key, (int) $s['id']]);
        if ((int) $d->fetchColumn() > 0) return '';
        $addr = (string) ($pick['cdn']['ingestionInfo']['ingestionAddress'] ?? '');
        db()->prepare('UPDATE streams SET stream_key = ?, rtmp_url = CASE WHEN ? <> \'\' THEN ? ELSE rtmp_url END, updated_at = ? WHERE id = ?')
            ->execute([$key, $addr, $addr, time(), (int) $s['id']]);
        return $key;
    } catch (Throwable $e) {
        return '';
    }
}
