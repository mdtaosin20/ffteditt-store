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
    yt_units_count($method, $url, $code, is_array($j) ? $j : null);
    return [$code, is_array($j) ? $j : null, (string) $raw];
}

/* ---------------------------------------------------------------- daily limit meter
 * YouTube gives 10,000 "units" a day for the whole panel (all channels), new each day at midnight
 * in California (1–2 PM in Bangladesh). Every call is counted here so the panel can show what used them. */
function yt_units_day(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('America/Los_Angeles')))->format('Y-m-d');
}

function yt_units_count(string $method, string $url, int $code, ?array $j): void
{
    if (!preg_match('#googleapis\.com/(upload/)?youtube/v3/([^?]+)|^https?://127\.0\.0\.1[^/]*/(upload/)?youtube/v3/([^?]+)#', $url, $m)) return;
    $path = $m[2] !== '' ? $m[2] : ($m[4] ?? '');
    $cost = 1;
    if ($method === 'PUT' || ($method === 'POST' && preg_match('#^(liveBroadcasts|thumbnails/set|videos)#', $path))) $cost = 50;
    if ($method === 'POST' && str_starts_with($path, 'liveChat/')) $cost = 20;
    if ($path === 'search') $cost = 100;
    $names = ['liveBroadcasts' => 'Find live video', 'liveStreams' => 'Find live video', 'liveBroadcasts/transition' => 'Press "Go live"',
        'liveBroadcasts/bind' => 'Open new live video', 'videos' => $method === 'PUT' ? 'Set title & description' : 'Read video / likes & viewers',
        'thumbnails/set' => 'Set thumbnail', 'subscriptions' => 'Check new subscribers', 'channels' => 'Channel name',
        'liveChat/messages' => 'Poll / chat message', 'liveChat/messages/transition' => 'End poll'];
    $name = $method === 'POST' && $path === 'liveBroadcasts' ? 'Open new live video' : ($names[$path] ?? $path);
    $reason = (string) ($j['error']['errors'][0]['reason'] ?? '');
    try {
        $day = yt_units_day();
        $u = json_decode((string) (setting_get('yt_units_' . $day) ?? ''), true) ?: ['total' => 0, 'by' => [], 'out_at' => 0];
        if ($reason === 'quotaExceeded' || $reason === 'dailyLimitExceeded') {
            if (empty($u['out_at'])) $u['out_at'] = time();
        } else {
            $u['total'] += $cost;
            $u['by'][$name] = (int) ($u['by'][$name] ?? 0) + $cost;
        }
        setting_set('yt_units_' . $day, json_encode($u));
    } catch (Throwable $e) {
        // never break a YouTube call because of the meter
    }
}

/** Today's use: ['total', 'by' => [name => units] (biggest first), 'out_at' (0 = not used up)]. */
function yt_units_today(): array
{
    $u = json_decode((string) (setting_get('yt_units_' . yt_units_day()) ?? ''), true) ?: [];
    $u = array_merge(['total' => 0, 'by' => [], 'out_at' => 0], $u);
    arsort($u['by']);
    return $u;
}

/** True when YouTube said today's limit is used up (then the panel stops asking until the new day). */
function yt_units_out(): bool
{
    return !empty(yt_units_today()['out_at']);
}

/** A small box: how much of today's YouTube limit is used, and by what. */
function yt_units_box(): string
{
    $u = yt_units_today();
    $pc = min(100, (int) round($u['total'] / 100));
    $col = $u['out_at'] || $pc >= 90 ? '#ef4444' : ($pc >= 70 ? '#f59e0b' : '#22c55e');
    $h = '<div class="ytu" style="margin:6px 0 4px"><div style="display:flex;justify-content:space-between;gap:10px;font-weight:700"><span>YouTube daily limit used today</span><span>'
        . number_format($u['total']) . ' / 10,000</span></div>'
        . '<div style="height:10px;border-radius:6px;background:rgba(255,255,255,.1);overflow:hidden;margin:6px 0"><i style="display:block;height:100%;width:' . $pc . '%;background:' . $col . '"></i></div>';
    if ($u['out_at']) $h .= '<p class="small" style="color:#f87171;margin:4px 0">Used up at ' . e(date('h:i A', (int) $u['out_at'])) . ' (Bangladesh time). It starts again at 1–2 PM Bangladesh time.</p>';
    if ($u['by']) {
        $h .= '<div class="small muted" style="display:grid;gap:2px">';
        foreach (array_slice($u['by'], 0, 8, true) as $n => $v) $h .= '<div style="display:flex;justify-content:space-between"><span>' . e($n) . '</span><span>' . number_format((int) $v) . '</span></div>';
        $h .= '</div>';
    } else {
        $h .= '<p class="small muted" style="margin:4px 0">Counting starts now — check again later to see what uses the limit.</p>';
    }
    return $h . '</div>';
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
    if ($game === 'keeper') {
        $k = keeper_config();
        return [
            'title' => '🔴 LIVE Beat The Keeper ⚽ ' . ucwords(strtolower($k['cup'])) . ' 🌍 36 Countries! Type Your Country for More Shots 🏆',
            'desc'  => "⚽ BEAT THE KEEPER — {$k['cup']} LIVE 24/7!\n"
                . "36 country flag balls roll down the track, a spinning paddle kicks them at the goal… and the KEEPER tries to stop them!\n\n"
                . "🏆 Group stage → Quarterfinals → Semifinals → THE FINAL. First countries to {$k['goals']} goals go through!\n"
                . "👇 TYPE YOUR COUNTRY in chat — its ball shoots faster! Every {$k['bonus_chats']} chats = a BONUS BALL!\n"
                . "🔔 Subscribe = 2 bonus shots for your country · 🎙️ LUNA, our live host, answers your comments.\n\n"
                . "🇵🇭 Philippines 🇲🇾 Malaysia 🇺🇸 USA 🇮🇳 India 🇧🇩 Bangladesh 🇧🇷 Brazil 🇵🇰 Pakistan 🇮🇩 Indonesia 🇬🇧 UK 🇯🇵 Japan 🇦🇷 Argentina 🇫🇷 France …\n\n"
                . "📣 Share with friends from your country · 👍 Like if you are cheering!\n\n"
                . "#beatthekeeper #worldcup #marblerace #countryballs #football #soccer #live #countries #flags #chatgame #philippines #malaysia #usa #india #bangladesh",
        ];
    }
    if ($game === 'tug') {
        return [
            'title' => '🔴 LIVE Country Tug of War 🪢 Bangladesh vs India, India vs Pakistan! Comment Your Country to Pull 💪',
            'desc'  => "🪢 COUNTRY TUG OF WAR — two countries, one rope, and YOU are the pulling power!\n"
                . "Every comment with your country's name = ONE PULL. The country with more fans in chat wins!\n\n"
                . "👇 COMMENT YOUR COUNTRY NOW to pull the rope to your side!\n"
                . "🏅 The strongest pullers get their names on screen.\n"
                . "🎙️ LUNA, our live host, reads every comment and answers you live.\n"
                . "🔁 A new round every few minutes: 🇧🇩 Bangladesh vs 🇮🇳 India · 🇮🇳 India vs 🇵🇰 Pakistan · 🇧🇷 Brazil vs 🇦🇷 Argentina · 🇺🇸 USA vs 🇷🇺 Russia · 🇮🇩 Indonesia vs 🇲🇾 Malaysia …\n\n"
                . "📣 Share this live with friends from your country — more people = more power!\n"
                . "🔔 Subscribe and turn on notifications · 👍 Like if you are pulling with us!\n\n"
                . "#tugofwar #countryballs #bangladeshvsindia #indiavspakistan #countries #flags #live #interactive #chatgame #bangladesh #india #pakistan #brazil #argentina",
        ];
    }
    if ($game === 'war') {
        $n = count(war_config()['teams']);
        $list = $n <= 16
            ? "🇧🇩 Bangladesh 🇮🇳 India 🇵🇰 Pakistan 🇺🇸 USA 🇧🇷 Brazil 🇦🇷 Argentina 🇸🇦 Saudi Arabia 🇹🇷 Türkiye\n"
              . "🇮🇩 Indonesia 🇳🇬 Nigeria 🇷🇺 Russia 🇨🇳 China 🇯🇵 Japan 🇩🇪 Germany 🇫🇷 France 🇬🇧 UK"
            : "🇧🇩 Bangladesh 🇮🇳 India 🇵🇰 Pakistan 🇺🇸 USA 🇧🇷 Brazil 🇦🇷 Argentina 🇸🇦 Saudi Arabia 🇹🇷 Türkiye 🇮🇩 Indonesia 🇳🇬 Nigeria\n"
              . "🇷🇺 Russia 🇨🇳 China 🇯🇵 Japan 🇩🇪 Germany 🇫🇷 France 🇬🇧 UK 🇪🇬 Egypt 🇲🇦 Morocco 🇲🇾 Malaysia 🇳🇵 Nepal … and " . ($n - 20) . " more!";
        return [
            'title' => "🔴 LIVE Territory War 🌍 {$n} Countries, Only 1 Conquers the World! Comment Your Country 🏆",
            'desc'  => "🌍 TERRITORY WAR — {$n} countries fight for the whole world, square by square!\n"
                . "Every country has a flag ball. Bigger countries get bigger armies… but one comment can change everything!\n\n"
                . "👇 COMMENT YOUR COUNTRY to give its ball a SPEED BOOST and see your name on the map!\n"
                . "🎙️ LUNA, our live host, reads every comment and answers you live.\n"
                . "🏆 The last country standing becomes CHAMPION OF THE WORLD — then a new war begins!\n\n"
                . "⏱️ One war ≈ " . ($n > 40 ? '2 hours' : '1 hour') . " · Live 24/7\n"
                . "🔔 Subscribe and turn on notifications so you never miss a war\n"
                . "👍 Like and share with friends from your country!\n\n"
                . "Countries in this war:\n{$list}\n\n"
                . "#territorywar #countryballs #worldwar #countries #flags #marblerace #satisfying #live #gaming #geography #bangladesh #india #pakistan #usa #brazil",
        ];
    }
    if ($game === 'flags') {
        $f = flags_config();
        return [
            'title' => "🔴 LIVE Flag Arena 🌍 {$f['count']} Countries vs the Black Hole! Type Your Country to SAVE It 🛡️",
            'desc'  => "🌍 FLAG ARENA — {$f['count']} country flags bounce in the arena… and the BLACK HOLE takes them one by one!\n"
                . "Only ONE country survives to become the ROUND CHAMPION 🏆\n\n"
                . "👇 YOU CAN SAVE YOUR COUNTRY:\n"
                . "💬 {$f['shield']} chats with your country's name = 🛡️ SHIELD (survives the black hole)\n"
                . "💬 {$f['revive']} chats when it is out = ♻️ REVIVE (it comes back!)\n"
                . "🔔 Subscribe = FREE SHIELD · ⭐ Super Chat = SHIELD + REVIVE\n"
                . "👍 Like goals close the black hole · 👀 Viewer goals give free shields\n\n"
                . "🎙️ LUNA, our live host, reads your comments and answers you live.\n"
                . "🎁 Mystery boxes: Double Draw, Lucky Shields, Speed Storm, Lucky Comeback!\n"
                . "⏱️ New round every ~30 minutes · Live 24/7\n\n"
                . (!empty($f['music']) && ($f['music_src'] ?? '') === 'songs' && music_tracks() ? music_credit_text() . "\n" : '')
                . "👍 LIKE IF YOU SEE YOUR COUNTRY FLAG and share with friends from your country!\n\n"
                . "#flagarena #countryballs #flags #countries #marblerace #live #interactive #chatgame #satisfying #geography #bangladesh #india #pakistan #brazil #usa",
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

/** Turn a big picture into a 1280 × 720 JPG under 2 MB (crops to 16:9 from the middle). Needs PHP GD. */
function yt_shrink_thumb(string $src, int $type, string $dest): bool
{
    if (!function_exists('imagecreatetruecolor')) return false;
    $img = match ($type) {
        IMAGETYPE_PNG  => @imagecreatefrompng($src),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false,
        default        => @imagecreatefromjpeg($src),
    };
    if (!$img) return false;
    $w = imagesx($img); $h = imagesy($img);
    $cw = $w; $ch = (int) round($w * 9 / 16);
    if ($ch > $h) { $ch = $h; $cw = (int) round($h * 16 / 9); }
    $out = imagecreatetruecolor(1280, 720);
    imagecopyresampled($out, $img, 0, 0, (int) (($w - $cw) / 2), (int) (($h - $ch) / 2), 1280, 720, $cw, $ch);
    imagedestroy($img);
    foreach ([90, 82, 74, 65] as $q) {
        imagejpeg($out, $dest, $q);
        clearstatcache(true, $dest);
        if (filesize($dest) <= 2 * 1024 * 1024 - 20000) break;
    }
    imagedestroy($out);
    return is_file($dest) && filesize($dest) <= 2 * 1024 * 1024;
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
        // NCS asks for this credit when its songs play in a livestream: always add it (once)
        if (game_of_url($s['page_url']) === 'flags' && music_tracks() && !str_contains($t['desc'], 'spoti.fi/NCS')) {
            $f = flags_config();
            if (!empty($f['music']) && ($f['music_src'] ?? '') === 'songs') $t['desc'] = rtrim($t['desc']) . "\n\n" . music_credit_text();
        }
        // a little different on every live (to learn which title / thumbnail works best)
        $thumb = yt_thumb_file($s);
        $vary = null;
        if (!empty($s['yt_vary'])) {
            try { $vary = ytv_for_video($s, $t, $video, $thumb); } catch (Throwable $e) { $vary = null; }
            if ($vary) { $t['title'] = $vary['title']; $t['desc'] = $vary['desc']; $thumb = $vary['thumb']; }
        }
        preg_match_all('/#([\p{L}\p{N}_]+)/u', $t['desc'], $m);
        $snippet = [
            'title'       => $t['title'],
            'description' => $t['desc'],
            'categoryId'  => (string) ($sn['categoryId'] ?? '20'),
        ];
        $tags = $vary ? array_slice($vary['tags'], 0, 15) : array_slice(array_values(array_unique($m[1])), 0, 15);
        if ($tags) $snippet['tags'] = $tags;
        if (!empty($sn['defaultLanguage'])) $snippet['defaultLanguage'] = $sn['defaultLanguage'];

        $r = yt_http('PUT', YT_API . 'videos?part=snippet', ['Authorization: Bearer ' . $tok, 'Content-Type: application/json'],
            json_encode(['id' => $video, 'snippet' => $snippet], JSON_UNESCAPED_UNICODE));
        if ($r[0] !== 200) throw new RuntimeException(yt_err($r));

        $note = 'Title and description set' . ($vary ? " (version {$vary['letter']})" : '');
        if ($thumb) {
            $r = yt_http('POST', 'https://www.googleapis.com/upload/youtube/v3/thumbnails/set?videoId=' . rawurlencode($video),
                ['Authorization: Bearer ' . $tok, 'Content-Type: ' . $thumb[1]], (string) file_get_contents($thumb[0]), 30);
            $note .= $r[0] === 200 ? ', thumbnail set' : '. Thumbnail failed: ' . yt_err($r);
        }

        db()->prepare('UPDATE streams SET yt_done_rev = yt_rev, yt_done_start = COALESCE(started_at, 0) WHERE id = ?')
            ->execute([$s['id']]);
        setting_set('yt_done_video_' . $s['id'], $video);
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
        // first 10 minutes: look every minute; after that every 5 minutes (saves the daily YouTube limit)
        if ($now - (int) $s['started_at'] > 600 && (int) $s['yt_find_at'] > $now - 300) continue;
        if (yt_units_out()) continue;
        db()->prepare('UPDATE streams SET yt_find_at = ? WHERE id = ?')->execute([$now, $s['id']]);
        try {
            $tok = yt_token($s);
            $video = yt_find_video($s, $tok, $now - (int) $s['started_at'] > 50);
            // found or opened it: make sure it really goes live right away (not a minute later)
            if ($video !== '') { $s['yt_video'] = $video; if (yt_kick($s, $tok)) setting_set('yt_kick_' . $s['id'], json_encode(['start' => (int) $s['started_at'], 'done' => true, 'tries' => 1, 'at' => $now])); }
        } catch (Throwable $e) {
            // tried again in a minute
        }
    }
    // The live video was found / opened, but YouTube may leave it waiting ("Upcoming") even though our
    // video arrives (it happens when the next live starts right after the last one on the same key): start it.
    $st = db()->prepare("SELECT * FROM streams WHERE server_id = ? AND status = 'live' AND yt_refresh != '' AND yt_video != ''
                         AND yt_video_start = COALESCE(started_at, 0) AND started_at < ?");
    $st->execute([$serverId, $now - 40]);
    foreach ($st->fetchAll() as $s) {
        $k = json_decode((string) (setting_get('yt_kick_' . $s['id']) ?? ''), true) ?: [];
        if (($k['start'] ?? 0) !== (int) $s['started_at']) $k = ['start' => (int) $s['started_at'], 'done' => false, 'tries' => 0, 'at' => 0];
        if ($k['done'] || $k['tries'] >= 16 || $k['at'] > $now - 30 || yt_units_out()) continue;   // every 30 s for the first ~8 minutes
        $k['tries']++; $k['at'] = $now;
        try { $k['done'] = yt_kick($s, yt_token($s)); } catch (Throwable $e) { /* again in a minute */ }
        setting_set('yt_kick_' . $s['id'], json_encode($k));
    }

    $st = db()->prepare("SELECT * FROM streams WHERE server_id = ? AND status = 'live' AND yt_auto = 1 AND yt_refresh != ''
                         AND (yt_done_rev != yt_rev OR yt_done_start != COALESCE(started_at, 0))
                         AND NOT (yt_fail_start = COALESCE(started_at, 0) AND yt_fails >= 5)
                         AND COALESCE(started_at, 0) > 0 AND started_at < ? AND yt_try_at < ?");
    $st->execute([$serverId, $now - 60, $now - 180]);
    foreach ($st->fetchAll() as $s) {
        // the stream only reconnected (e.g. after a short drop) and is still on the same live video, which already has
        // this title and thumbnail: nothing to send again (saves ~100 units for every reconnect)
        if ($s['yt_video'] !== '' && (int) $s['yt_video_start'] === (int) $s['started_at'] && (int) $s['yt_done_rev'] === (int) $s['yt_rev']
            && (string) (setting_get('yt_done_video_' . $s['id']) ?? '') === $s['yt_video']) {
            db()->prepare('UPDATE streams SET yt_done_start = COALESCE(started_at, 0) WHERE id = ?')->execute([$s['id']]);
            continue;
        }
        if (yt_units_out()) continue;
        // claim it first so two check-ins never do the same job
        $c = db()->prepare('UPDATE streams SET yt_try_at = ? WHERE id = ? AND yt_try_at = ?');
        $c->execute([$now, $s['id'], $s['yt_try_at']]);
        if ($c->rowCount() === 1) {
            yt_apply($s);
        }
    }

    if (chat_cfg()['enabled']) {
        yt_subscribers_tick($serverId);
        yt_stats_tick($serverId);
    }
}

/**
 * Likes and people watching now, for the games' LIKE / VIEWER goals. 1 unit per check,
 * once a minute per live video. Also kept as history for Admin → Audience Insights.
 */
function yt_stats_tick(int $serverId): void
{
    $now = time();
    // One shared check for ALL live videos (1 unit for up to 50 videos), not one per video.
    $last = (int) (setting_get('yt_stats_all_at') ?? 0);
    if ($last > $now - 120 || yt_units_out()) return;   // every 2 minutes (720 units a day)
    $st = db()->query("SELECT * FROM streams WHERE status = 'live' AND yt_refresh != '' AND yt_video != ''
                       AND yt_video_start = COALESCE(started_at, 0) ORDER BY id");
    $list = $st->fetchAll();
    if (!$list) return;
    setting_set('yt_stats_all_at', (string) $now);
    $byVideo = [];
    foreach ($list as $s) $byVideo[$s['yt_video']][] = $s;
    $tok = '';
    foreach ($list as $s) {
        try { $tok = yt_token($s); break; } catch (Throwable $e) { /* try the next channel */ }
    }
    if ($tok === '') return;
    $r = yt_get($tok, 'videos', ['part' => 'statistics,liveStreamingDetails', 'id' => implode(',', array_slice(array_keys($byVideo), 0, 50))]);
    if ($r[0] !== 200) return;
    foreach ($r[1]['items'] ?? [] as $it) {
        $likes = (int) ($it['statistics']['likeCount'] ?? 0);
        $viewers = (int) ($it['liveStreamingDetails']['concurrentViewers'] ?? 0);
        foreach ($byVideo[(string) ($it['id'] ?? '')] ?? [] as $s) {
            setting_set('yt_stats_' . $s['id'], json_encode(['t' => $now, 'video' => $s['yt_video'], 'likes' => $likes, 'viewers' => $viewers]));
            db()->prepare('INSERT INTO audience (stream_id, ts, video, viewers, likes) VALUES (?,?,?,?,?)')
                ->execute([(int) $s['id'], $now, $s['yt_video'], $viewers, $likes]);
        }
    }
    if (random_int(1, 60) === 1) db()->exec('DELETE FROM audience WHERE ts < ' . ($now - 14 * 86400));
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
        if ($last > $now - 180 || yt_units_out()) continue;   // every 3 minutes per channel (480 units a day)
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

/**
 * Makes sure the live video really is live. Returns true when nothing more is needed
 * (live, ended, or not ours to start). About 2 units, + 50 when it has to press "Go live".
 */
function yt_kick(array $s, string $tok): bool
{
    $video = (string) $s['yt_video'];
    $r = yt_get($tok, 'liveBroadcasts', ['part' => 'id,status,contentDetails', 'id' => $video]);
    if ($r[0] !== 200) return false;
    $b = $r[1]['items'][0] ?? null;
    if (!$b) return true;                                     // not a live broadcast we can see
    $life = (string) ($b['status']['lifeCycleStatus'] ?? '');
    if (in_array($life, ['live', 'liveStarting', 'complete', 'revoked'], true)) return true;
    if (!in_array($life, ['ready', 'testing', 'created', 'testStarting'], true)) return false;
    // only when our video is really arriving on the bound stream key
    $sid = (string) ($b['contentDetails']['boundStreamId'] ?? '');
    if ($sid === '') return false;
    $r = yt_get($tok, 'liveStreams', ['part' => 'id,status', 'id' => $sid]);
    if ($r[0] !== 200 || (($r[1]['items'][0]['status']['streamStatus'] ?? '') !== 'active')) return false;
    // "Go live" costs 50 units: at most 4 presses per live session (a stuck live would otherwise use the whole day's limit)
    $gk = 'yt_go_' . (int) $s['id'];
    $g = json_decode((string) (setting_get($gk) ?? ''), true) ?: [];
    if (($g['start'] ?? 0) !== (int) $s['started_at']) $g = ['start' => (int) $s['started_at'], 'n' => 0];
    if ($g['n'] >= 4) return true;
    $g['n'] += $life === 'ready' ? 2 : 1;
    setting_set($gk, json_encode($g));
    $go = fn (string $to) => yt_http('POST', YT_API . 'liveBroadcasts/transition?' . http_build_query(['broadcastStatus' => $to, 'id' => $video, 'part' => 'id,status']),
        ['Authorization: Bearer ' . $tok, 'Content-Length: 0'], '');
    $r = $go('live');
    if ($r[0] !== 200 && $life === 'ready') { $go('testing'); return false; }   // some lives must pass "testing" first: live next minute
    if ($r[0] === 200) {
        log_activity("YouTube: {$s['name']} — the live video was waiting, pressed Go live", 'ok');
        return true;
    }
    return false;
}
