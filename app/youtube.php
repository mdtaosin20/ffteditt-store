<?php
/**
 * YouTube connection (Google OAuth) — sets the live video's title, description and thumbnail.
 *
 * One Google "OAuth client" (Client ID + Secret, saved in settings) is shared by all channels.
 * Each stream row connects its own YouTube channel and keeps its own refresh token.
 * The secret and tokens never leave the server and are never shown in the panel.
 */
declare(strict_types=1);

const YT_SCOPE = 'https://www.googleapis.com/auth/youtube.force-ssl';
const YT_API   = 'https://www.googleapis.com/youtube/v3/';

/* ---------------------------------------------------------------- app keys */

function yt_client(): array
{
    return [
        'id'     => (string) (setting_get('yt_client_id') ?? ''),
        'secret' => (string) (setting_get('yt_client_secret') ?? ''),
    ];
}

function yt_ready(): bool
{
    $c = yt_client();
    return $c['id'] !== '' && $c['secret'] !== '';
}

function yt_redirect_uri(): string
{
    $host = preg_replace('/[^a-z0-9.\-:]/i', '', (string) ($_SERVER['HTTP_HOST'] ?? 'ffteditt.store'));
    return 'https://' . $host . '/admin/yt_oauth.php';
}

function yt_auth_url(string $state): string
{
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id'              => yt_client()['id'],
        'redirect_uri'           => yt_redirect_uri(),
        'response_type'          => 'code',
        'scope'                  => YT_SCOPE,
        'access_type'            => 'offline',
        'prompt'                 => 'consent select_account',
        'include_granted_scopes' => 'true',
        'state'                  => $state,
    ]);
}

/* ---------------------------------------------------------------- HTTP */

/** @return array{0:int,1:array|null,2:string} [status, json, raw] */
function yt_http(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 12): array
{
    $test = getenv('YT_TEST_BASE'); // local tests only: send Google calls to a fake server
    if ($test) {
        $url = preg_replace('#^https://[^/]+#', $test, $url);
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 6,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        return [0, null, $err];
    }
    $j = json_decode((string) $raw, true);
    return [$code, is_array($j) ? $j : null, (string) $raw];
}

function yt_err(array $r): string
{
    [$code, $j, $raw] = $r;
    if ($code === 0) return 'Could not reach Google (' . $raw . ')';
    $m = $j['error']['message'] ?? $j['error_description'] ?? (is_string($j['error'] ?? null) ? $j['error'] : '') ?: substr(strip_tags($raw), 0, 120);
    $reason = $j['error']['errors'][0]['reason'] ?? '';
    if ($reason === 'quotaExceeded') return 'YouTube daily limit used up. It resets at 1–2 PM Bangladesh time.';
    if ($code === 403 && stripos((string) $m, 'thumbnail') !== false) return 'YouTube does not allow custom thumbnails on this channel yet. Verify the channel with your phone at youtube.com/verify.';
    return "Google said ({$code}): " . strip_tags((string) $m);
}

/** Swap the auth code (after "Allow") for tokens. */
function yt_exchange(string $code): array
{
    $c = yt_client();
    $r = yt_http('POST', 'https://oauth2.googleapis.com/token', ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
        'code'          => $code,
        'client_id'     => $c['id'],
        'client_secret' => $c['secret'],
        'redirect_uri'  => yt_redirect_uri(),
        'grant_type'    => 'authorization_code',
    ]));
    if ($r[0] !== 200 || empty($r[1]['access_token'])) {
        throw new RuntimeException(yt_err($r));
    }
    return $r[1];
}

/** A fresh access token for this stream's channel (cached ~55 minutes). */
function yt_token(array $s): string
{
    if ($s['yt_refresh'] === '') {
        throw new RuntimeException('This channel is not connected to YouTube yet.');
    }
    $key   = 'yt_at_' . (int) $s['id'];
    $cache = json_decode((string) (setting_get($key) ?? ''), true);
    if (is_array($cache) && ($cache['exp'] ?? 0) > time() + 60 && ($cache['rh'] ?? '') === md5($s['yt_refresh'])) {
        return (string) $cache['t'];
    }
    $c = yt_client();
    $r = yt_http('POST', 'https://oauth2.googleapis.com/token', ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
        'client_id'     => $c['id'],
        'client_secret' => $c['secret'],
        'refresh_token' => $s['yt_refresh'],
        'grant_type'    => 'refresh_token',
    ]));
    if ($r[0] !== 200 || empty($r[1]['access_token'])) {
        if (($r[1]['error'] ?? '') === 'invalid_grant') {
            throw new RuntimeException('The YouTube connection has expired. Press "Connect YouTube" again. (Tip: in Google Cloud set the app to "In production" so it stays connected.)');
        }
        throw new RuntimeException(yt_err($r));
    }
    setting_set($key, json_encode(['t' => $r[1]['access_token'], 'exp' => time() + (int) ($r[1]['expires_in'] ?? 3600), 'rh' => md5($s['yt_refresh'])]));
    return (string) $r[1]['access_token'];
}

function yt_get(string $tok, string $path, array $q): array
{
    return yt_http('GET', YT_API . $path . '?' . http_build_query($q), ['Authorization: Bearer ' . $tok]);
}

/** Name of the channel the token belongs to. */
function yt_channel_title(string $tok): string
{
    $r = yt_get($tok, 'channels', ['part' => 'snippet', 'mine' => 'true']);
    if ($r[0] !== 200) throw new RuntimeException(yt_err($r));
    return (string) ($r[1]['items'][0]['snippet']['title'] ?? '(no channel)');
}

function yt_revoke(string $refresh): void
{
    if ($refresh !== '') {
        yt_http('POST', 'https://oauth2.googleapis.com/revoke', ['Content-Type: application/x-www-form-urlencoded'], http_build_query(['token' => $refresh]), 6);
    }
}

/* ---------------------------------------------------------------- texts and thumbnails */

function yt_defaults(string $game): array
{
    if ($game === 'war') {
        return [
            'title' => '🔴 LIVE Territory War 🌍 16 Countries, Only 1 Conquers the World! Comment Your Country 🏆',
            'desc'  => "🌍 TERRITORY WAR — 16 countries fight for the whole world, square by square!\n"
                . "Every country has a flag ball. Bigger countries get bigger armies… but one comment can change everything!\n\n"
                . "👇 COMMENT YOUR COUNTRY to give its ball a SPEED BOOST and see your name on the map!\n"
                . "🎙️ LUNA, our live host, reads every comment and answers you live.\n"
                . "🏆 The last country standing becomes CHAMPION OF THE WORLD — then a new war begins!\n\n"
                . "⏱️ One war ≈ 1 hour · Live 24/7\n"
                . "🔔 Subscribe and turn on notifications so you never miss a war\n"
                . "👍 Like and share with friends from your country!\n\n"
                . "Countries in this war:\n"
                . "🇧🇩 Bangladesh 🇮🇳 India 🇵🇰 Pakistan 🇺🇸 USA 🇧🇷 Brazil 🇦🇷 Argentina 🇸🇦 Saudi Arabia 🇹🇷 Türkiye\n"
                . "🇮🇩 Indonesia 🇳🇬 Nigeria 🇷🇺 Russia 🇨🇳 China 🇯🇵 Japan 🇩🇪 Germany 🇫🇷 France 🇬🇧 UK\n\n"
                . "#territorywar #countryballs #worldwar #countries #flags #marblerace #satisfying #live #gaming #geography #bangladesh #india #pakistan #usa #brazil",
        ];
    }
    if ($game === 'flags') {
        return [
            'title' => '🔴 LIVE Country Flag Battle 🌍 195 Countries, Only 1 Winner! Comment Your Country 🏆',
            'desc'  => "🌍 195 countries fight inside a turning ring. Only one flag survives!\n"
                . "Which country will be the champion today? 🏆\n\n"
                . "👇 COMMENT YOUR COUNTRY and cheer for your flag!\n"
                . "🔔 Subscribe and turn on notifications so you never miss a battle.\n\n"
                . "🇧🇩 Bangladesh 🇮🇳 India 🇵🇰 Pakistan 🇺🇸 USA 🇧🇷 Brazil 🇦🇷 Argentina 🇸🇦 Saudi Arabia 🇹🇷 Turkey 🇮🇩 Indonesia 🇳🇬 Nigeria\n\n"
                . "#countryballs #flagbattle #marblerace #countries #flags #live #satisfying #game #worldflags #geography",
        ];
    }
    return [
        'title' => '🔴 LIVE Messi vs Ronaldo ⚽ Ball Drop Battle — Who Wins? Comment M or R!',
        'desc'  => "⚽ Messi or Ronaldo? Balls fall into the bowl and every goal counts!\n"
            . "Live 24/7 — watch who wins each round. 🏆\n\n"
            . "👇 Comment M for Messi or R for Ronaldo!\n"
            . "🔔 Subscribe and turn on notifications.\n\n"
            . "#messi #ronaldo #messivsronaldo #football #soccer #marblerace #satisfying #live #game #goat",
    ];
}

function yt_thumb_dir(): string
{
    $d = DATA_DIR . '/yt';
    if (!is_dir($d)) @mkdir($d, 0700, true);
    return $d;
}

/** @return array{0:string,1:string}|null [file path, mime] of the thumbnail to use */
function yt_thumb_file(array $s): ?array
{
    $t = (string) $s['yt_thumb'];
    if ($t === 'jpg' || $t === 'png') {
        $f = yt_thumb_dir() . '/thumb_' . (int) $s['id'] . '.' . $t;
        if (is_file($f)) return [$f, $t === 'png' ? 'image/png' : 'image/jpeg'];
        $t = 'default';
    }
    if ($t === 'default') {
        $f = APP_DIR . '/yt/' . game_of_url($s['page_url']) . '.jpg';
        return is_file($f) ? [$f, 'image/jpeg'] : null;
    }
    return null;
}

/** Texts this stream will use (its own, or the game's ready-made ones). */
function yt_texts(array $s): array
{
    $d = yt_defaults(game_of_url($s['page_url']));
    return [
        'title' => $s['yt_title'] !== '' ? $s['yt_title'] : $d['title'],
        'desc'  => $s['yt_desc'] !== '' ? $s['yt_desc'] : $d['desc'],
    ];
}

/* ---------------------------------------------------------------- apply to the live video */

/**
 * The live video this stream is sending to right now ('' if none yet). About 2 units.
 * Remembered per live session (yt_video_start = the stream's started_at).
 */
function yt_find_video(array $s, string $tok, bool $create = false): string
{
    // Which YouTube "stream" uses our stream key? (so the right live video is used when a channel has several)
    $boundId = '';
    $streamActive = false;
    $r = yt_get($tok, 'liveStreams', ['part' => 'id,cdn,status', 'mine' => 'true', 'maxResults' => 50]);
    if ($r[0] === 200) {
        foreach ($r[1]['items'] ?? [] as $ls) {
            if (($ls['cdn']['ingestionInfo']['streamName'] ?? '') === $s['stream_key']) {
                $boundId = (string) $ls['id'];
                $streamActive = ($ls['status']['streamStatus'] ?? '') === 'active';
            }
        }
    }
    $video = '';
    foreach (['active', 'upcoming'] as $status) {
        $r = yt_get($tok, 'liveBroadcasts', ['part' => 'id,contentDetails,status', 'broadcastStatus' => $status, 'broadcastType' => 'all', 'maxResults' => 25]);
        if ($r[0] !== 200) throw new RuntimeException(yt_err($r));
        $items = $r[1]['items'] ?? [];
        foreach ($items as $b) {
            if ($boundId !== '' && ($b['contentDetails']['boundStreamId'] ?? '') === $boundId) {
                $video = (string) $b['id'];
                // waiting broadcast that will not start by itself: start it now
                $life = $b['status']['lifeCycleStatus'] ?? '';
                if ($status === 'upcoming' && $create && $streamActive && in_array($life, ['ready', 'testing'], true)
                    && empty($b['contentDetails']['enableAutoStart'])) {
                    yt_http('POST', YT_API . 'liveBroadcasts/transition?' . http_build_query(['broadcastStatus' => 'live', 'id' => $video, 'part' => 'id,status']),
                        ['Authorization: Bearer ' . $tok, 'Content-Length: 0'], '');
                    log_activity("YouTube: {$s['name']} — started the waiting live video", 'ok');
                }
                break 2;
            }
        }
        if ($status === 'active' && count($items) === 1 && $boundId === '') {
            $video = (string) $items[0]['id'];
            break;
        }
    }
    // Nothing on YouTube for our stream key (e.g. the last live was ended): open a new live video.
    if ($video === '' && $create && $boundId !== '') {
        $video = yt_create_broadcast($s, $tok, $boundId);
    }
    if ($video !== '') {
        db()->prepare('UPDATE streams SET yt_video = ?, yt_video_start = COALESCE(started_at, 0) WHERE id = ?')->execute([$video, $s['id']]);
    }
    return $video;
}

/** Makes a new public live video on the channel and connects it to our stream key. About 100 units. */
function yt_create_broadcast(array $s, string $tok, string $streamId): string
{
    $key = 'yt_created_' . (int) $s['id'];
    $mark = (int) ($s['started_at'] ?? 0);
    if ((string) (setting_get($key) ?? '') === (string) $mark) return '';   // once per live session
    setting_set($key, (string) $mark);

    $t = yt_texts($s);
    $body = [
        'snippet' => ['title' => $t['title'], 'description' => $t['desc'], 'scheduledStartTime' => gmdate('Y-m-d\TH:i:s\Z')],
        'status' => ['privacyStatus' => 'public', 'selfDeclaredMadeForKids' => false],
        'contentDetails' => ['enableAutoStart' => true, 'enableAutoStop' => true, 'enableDvr' => true,
                             'latencyPreference' => 'normal', 'monitorStream' => ['enableMonitorStream' => false]],
    ];
    $r = yt_http('POST', YT_API . 'liveBroadcasts?part=snippet,status,contentDetails',
        ['Authorization: Bearer ' . $tok, 'Content-Type: application/json'], json_encode($body, JSON_UNESCAPED_UNICODE));
    if ($r[0] !== 200 || empty($r[1]['id'])) {
        log_activity("YouTube: could not open a new live for {$s['name']}: " . yt_err($r), 'error');
        return '';
    }
    $id = (string) $r[1]['id'];
    $r = yt_http('POST', YT_API . 'liveBroadcasts/bind?' . http_build_query(['id' => $id, 'part' => 'id,contentDetails', 'streamId' => $streamId]),
        ['Authorization: Bearer ' . $tok, 'Content-Length: 0'], '');
    if ($r[0] !== 200) {
        log_activity("YouTube: new live made but not connected for {$s['name']}: " . yt_err($r), 'error');
        return '';
    }
    log_activity("YouTube: opened a new live video for {$s['name']}", 'ok');
    // it starts by itself once YouTube sees the video coming in (a few seconds)
    return $id;
}

/**
 * Finds the live video this stream is sending to and sets title, description and thumbnail.
 * Uses ~103 of the 10,000 daily YouTube "units" (title 50 + thumbnail 50 + lookups).
 */
function yt_apply(array $s): array
{
    $mark = db()->prepare('UPDATE streams SET yt_msg = ?, yt_msg_ok = ?, yt_try_at = ? WHERE id = ?');
    try {
        $tok = yt_token($s);

        $video = yt_find_video($s, $tok);
        if ($video === '') {
            throw new RuntimeException('No live video found on this channel yet. Start the stream and try again in a minute.');
        }

        $r = yt_get($tok, 'videos', ['part' => 'snippet', 'id' => $video]);
        if ($r[0] !== 200 || empty($r[1]['items'][0])) throw new RuntimeException(yt_err($r));
        $sn = $r[1]['items'][0]['snippet'];

        $t = yt_texts($s);
        preg_match_all('/#([\p{L}\p{N}_]+)/u', $t['desc'], $m);
        $snippet = [
            'title'       => $t['title'],
            'description' => $t['desc'],
            'categoryId'  => (string) ($sn['categoryId'] ?? '20'),
        ];
        $tags = array_slice(array_values(array_unique($m[1])), 0, 15);
        if ($tags) $snippet['tags'] = $tags;
        if (!empty($sn['defaultLanguage'])) $snippet['defaultLanguage'] = $sn['defaultLanguage'];

        $r = yt_http('PUT', YT_API . 'videos?part=snippet', ['Authorization: Bearer ' . $tok, 'Content-Type: application/json'],
            json_encode(['id' => $video, 'snippet' => $snippet], JSON_UNESCAPED_UNICODE));
        if ($r[0] !== 200) throw new RuntimeException(yt_err($r));

        $note = 'Title and description set';
        $thumb = yt_thumb_file($s);
        if ($thumb) {
            $r = yt_http('POST', 'https://www.googleapis.com/upload/youtube/v3/thumbnails/set?videoId=' . rawurlencode($video),
                ['Authorization: Bearer ' . $tok, 'Content-Type: ' . $thumb[1]], (string) file_get_contents($thumb[0]), 30);
            $note .= $r[0] === 200 ? ', thumbnail set' : '. Thumbnail failed: ' . yt_err($r);
        }

        db()->prepare('UPDATE streams SET yt_done_rev = yt_rev, yt_done_start = COALESCE(started_at, 0) WHERE id = ?')
            ->execute([$s['id']]);
        $msg = $note . ' (' . date('d M, h:i A') . ').';
        $mark->execute([$msg, 1, time(), $s['id']]);
        db()->prepare('UPDATE streams SET yt_fails = 0 WHERE id = ?')->execute([$s['id']]);
        log_activity("YouTube: {$s['name']} — {$note}", 'ok');
        return [true, $msg];
    } catch (Throwable $e) {
        $mark->execute([$e->getMessage(), 0, time(), $s['id']]);
        db()->prepare('UPDATE streams SET yt_fails = CASE WHEN yt_fail_start = COALESCE(started_at, 0) THEN yt_fails + 1 ELSE 1 END,
                       yt_fail_start = COALESCE(started_at, 0) WHERE id = ?')->execute([$s['id']]);
        return [false, $e->getMessage()];
    }
}

/**
 * Called after each worker check-in: when a connected stream has been live for a minute and its
 * title/thumbnail are not set on this live video yet, set them. Retries every 3 minutes,
 * at most 5 times per live session (keeps the daily YouTube limit safe).
 */
function yt_tick(int $serverId): void
{
    if (!yt_ready()) return;
    $now = time();

    // Make sure YouTube shows each live stream: find its live video, or open a new one.
    $st = db()->prepare("SELECT * FROM streams WHERE server_id = ? AND status = 'live' AND yt_refresh != ''
                         AND yt_video_start != COALESCE(started_at, 0) AND COALESCE(started_at, 0) > 0
                         AND started_at < ? AND yt_find_at < ?");
    $st->execute([$serverId, $now - 45, $now - 60]);
    foreach ($st->fetchAll() as $s) {
        db()->prepare('UPDATE streams SET yt_find_at = ? WHERE id = ?')->execute([$now, $s['id']]);
        try {
            yt_find_video($s, yt_token($s), $now - (int) $s['started_at'] > 70);
        } catch (Throwable $e) {
            // tried again in a minute
        }
    }
    $st = db()->prepare("SELECT * FROM streams WHERE server_id = ? AND status = 'live' AND yt_auto = 1 AND yt_refresh != ''
                         AND (yt_done_rev != yt_rev OR yt_done_start != COALESCE(started_at, 0))
                         AND NOT (yt_fail_start = COALESCE(started_at, 0) AND yt_fails >= 5)
                         AND COALESCE(started_at, 0) > 0 AND started_at < ? AND yt_try_at < ?");
    $st->execute([$serverId, $now - 60, $now - 180]);
    foreach ($st->fetchAll() as $s) {
        // claim it first so two check-ins never do the same job
        $c = db()->prepare('UPDATE streams SET yt_try_at = ? WHERE id = ? AND yt_try_at = ?');
        $c->execute([$now, $s['id'], $s['yt_try_at']]);
        if ($c->rowCount() === 1) {
            yt_apply($s);
        }
    }

    if (chat_cfg()['enabled']) {
        yt_subscribers_tick($serverId);
    }
}

/**
 * New subscribers (only people whose subscriptions are public). 1 unit per check,
 * every 2 minutes per channel while it is live (~720 units a day).
 */
function yt_subscribers_tick(int $serverId): void
{
    if (!chat_cfg()['subs']) return;
    $now = time();
    $st = db()->prepare("SELECT * FROM streams WHERE server_id = ? AND status = 'live' AND yt_refresh != '' ORDER BY id");
    $st->execute([$serverId]);
    $byChannel = [];
    foreach ($st->fetchAll() as $s) {
        $byChannel[md5($s['yt_refresh'])][] = $s;   // one check per YouTube channel
    }
    foreach ($byChannel as $key => $list) {
        $last = (int) (setting_get('yt_sub_at_' . $key) ?? 0);
        if ($last > $now - 120) continue;
        setting_set('yt_sub_at_' . $key, (string) $now);
        try {
            $r = yt_get(yt_token($list[0]), 'subscriptions', ['part' => 'subscriberSnippet', 'myRecentSubscribers' => 'true', 'maxResults' => 20]);
            if ($r[0] !== 200) continue;
            $seenRaw = setting_get('yt_sub_seen_' . $key);
            $seen = json_decode((string) $seenRaw, true) ?: [];
            $first = $seenRaw === null;
            $new = [];
            foreach (array_reverse($r[1]['items'] ?? []) as $it) {
                $cid = (string) ($it['subscriberSnippet']['channelId'] ?? $it['id'] ?? '');
                if ($cid === '' || in_array($cid, $seen, true)) continue;
                $seen[] = $cid;
                if (!$first) $new[] = (string) ($it['subscriberSnippet']['title'] ?? '');
            }
            setting_set('yt_sub_seen_' . $key, json_encode(array_slice($seen, -200)));
            foreach ($new as $name) {
                if ($name === '') continue;
                foreach ($list as $s) {
                    chat_add((int) $s['id'], ['k' => 'sub', 'a' => $name, 't' => '', 'id' => 'sub:' . md5($name . $now)]);
                }
            }
        } catch (Throwable $e) {
            // try again next time
        }
    }
}
