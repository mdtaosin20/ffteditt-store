<?php
/**
 * 🏭 Auto Shorts: the VPS makes Shorts by itself and they go to YouTube on a schedule.
 *  1) Admin → Auto Shorts: "Make N videos" adds N jobs (wait).
 *  2) The chosen VPS (only when it has no live running) opens the game page in a hidden Chrome (worker "render" job).
 *     The page plays a random hard round, records it (Auto record), writes titles / description / thumbnail and
 *     sends the video here in pieces (public/api/autoshorts.php) → ready.
 *  3) After a worker check-in the panel uploads one ready video to YouTube with the official API (videos.insert),
 *     with its publish time (publishAt) → scheduled / uploaded. Until Google has approved (audited) our API project,
 *     YouTube keeps API uploads private — the owner then only switches them to Public in YouTube Studio.
 */
declare(strict_types=1);

function aus_defaults(): array
{
    return [
        'games'    => ['cupkick', 'playerkick', 'actionkick'],
        'count'    => 10,
        'level'    => 4,            // how messy: 3 Very hard, 4 Extreme
        'q'        => 1080,         // video size
        'fps'      => 30,
        'loop'     => true,
        'server'   => 0,            // the VPS that makes the videos
        'channel'  => 0,            // a stream whose Google connection is used to upload
        'first_at' => 0,            // first publish time (unix)
        'every_h'  => 4,            // hours between two videos
        'ai'       => true,         // ✨ AI titles when an AI key is saved
        'paused'   => false,
    ];
}
function aus_cfg(): array { $c = json_decode((string) (setting_get('aus_cfg') ?? ''), true); return array_merge(aus_defaults(), is_array($c) ? $c : []); }
function aus_cfg_save(array $c): void { setting_set('aus_cfg', json_encode(array_intersect_key(array_merge(aus_defaults(), $c), aus_defaults()))); }
function aus_games(): array { return ['cupkick' => 'Cup Kick', 'playerkick' => 'Player Kick', 'actionkick' => 'Action Kick']; }

/** [{id, game, st (wait|making|ready|uploading|scheduled|uploaded|failed), made, start, prog, title, titles, desc, file, thumb, at (publish), vid, err, tries, info}] */
function aus_jobs(): array { return json_decode((string) (setting_get('aus_jobs') ?? ''), true) ?: []; }
function aus_jobs_save(array $j): void { setting_set('aus_jobs', json_encode(array_values($j), JSON_UNESCAPED_UNICODE)); }
function aus_job(string $id): ?array { foreach (aus_jobs() as $j) if ($j['id'] === $id) return $j; return null; }
function aus_update(string $id, array $set): void
{
    $lk = @fopen(DATA_DIR . '/aus.lock', 'c'); if ($lk) flock($lk, LOCK_EX);   // the page, the worker and the panel change jobs at the same time
    $l = aus_jobs();
    foreach ($l as &$j) if ($j['id'] === $id) $j = array_merge($j, $set);
    unset($j);
    aus_jobs_save($l);
    if ($lk) { flock($lk, LOCK_UN); fclose($lk); }
}
function aus_sig(string $id): string { return substr(hash_hmac('sha256', 'autoshort:' . $id, app_secret()), 0, 32); }
function aus_dir(): string { $d = DATA_DIR . '/autoshorts'; if (!is_dir($d)) @mkdir($d, 0700, true); return $d; }
function aus_file(string $id, string $ext): string { return aus_dir() . '/' . preg_replace('/[^a-z0-9]/', '', $id) . '.' . $ext; }

/** "Make N videos": new jobs, games taken in turn, each with its own publish time after the last one planned. */
function aus_make(int $n): int
{
    $c = aus_cfg(); $games = array_values(array_intersect($c['games'], array_keys(aus_games()))) ?: ['cupkick'];
    $l = aus_jobs(); $last = 0;
    foreach ($l as $j) if (in_array($j['st'], ['wait', 'making', 'ready', 'uploading', 'scheduled'], true)) $last = max($last, (int) $j['at']);
    $gap = max(1, (int) $c['every_h']) * 3600;
    $at = max(time() + 1800, (int) $c['first_at'], $last ? $last + $gap : 0);
    for ($i = 0; $i < $n; $i++) {
        $l[] = ['id' => 'v' . bin2hex(random_bytes(5)), 'game' => $games[(count($l) + $i) % count($games)], 'st' => 'wait', 'made' => time(),
                'start' => 0, 'prog' => 0, 'title' => '', 'titles' => [], 'desc' => '', 'at' => $at + $i * $gap, 'vid' => '', 'err' => '', 'tries' => 0, 'info' => []];
    }
    aus_jobs_save($l);
    return $n;
}

/** The page address for one job (opened by the VPS). */
function aus_url(array $j): string
{
    $c = aus_cfg();
    $host = preg_replace('/[^a-z0-9.\-:]/i', '', (string) ($_SERVER['HTTP_HOST'] ?? 'ffteditt.store'));
    return 'https://' . $host . '/' . $j['game'] . '/?' . http_build_query(['auto' => $j['id'], 'asig' => aus_sig($j['id']),
        'lv' => (int) $c['level'], 'q' => (int) $c['q'], 'f' => (int) $c['fps'], 'loop' => $c['loop'] ? 1 : 0]);
}

/** Worker check-in: the job this VPS should make now (or null). Only when it runs no live. */
function aus_render_job(int $serverId): ?array
{
    $c = aus_cfg();
    $l = aus_jobs(); $now = time(); $changed = false; $mine = null;
    foreach ($l as &$j) {   // a job that takes too long (the page crashed?) is tried again once, then failed
        if ($j['st'] === 'making' && $now - (int) $j['start'] > 2400) {
            $j['tries'] = (int) $j['tries'] + 1; $j['st'] = $j['tries'] >= 2 ? 'failed' : 'wait'; $j['err'] = 'Making the video took too long'; $changed = true;
        }
        if ($j['st'] === 'making' && (int) ($j['server'] ?? 0) === $serverId) $mine = $j;
    }
    unset($j);
    if ($changed) aus_jobs_save($l);
    if ($mine) return ['id' => $mine['id'], 'url' => aus_url($mine)];
    if ($c['paused'] || (int) $c['server'] !== $serverId) return null;
    $st = db()->prepare("SELECT COUNT(*) FROM streams WHERE server_id = ? AND desired = 'on'");
    $st->execute([$serverId]);
    if ((int) $st->fetchColumn() > 0) return null;   // a live runs here: never slow it down
    foreach ($l as $j) {
        if ($j['st'] !== 'wait') continue;
        aus_update($j['id'], ['st' => 'making', 'start' => $now, 'prog' => 0, 'server' => $serverId, 'err' => '']);
        log_activity("🏭 Auto Shorts: VPS started making video {$j['id']} (" . (aus_games()[$j['game']] ?? $j['game']) . ')', 'info');
        return ['id' => $j['id'], 'url' => aus_url($j)];
    }
    return null;
}

/* ---------------------------------------------------------------- titles */
function aus_used(): array { return json_decode((string) (setting_get('aus_used') ?? ''), true) ?: []; }
function aus_norm(string $t): string { return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($t))); }
function aus_mark_used(string $t): void { $u = aus_used(); $u[] = aus_norm($t); setting_set('aus_used', json_encode(array_slice(array_values(array_unique($u)), -3000), JSON_UNESCAPED_UNICODE)); }

/** A title never used before: ✨ AI first (when a key is saved), else the page's ready-made ones. */
function aus_pick_title(array $j): string
{
    $used = array_flip(aus_used());
    $c = aus_cfg(); $info = (array) ($j['info'] ?? []);
    if ($c['ai'] && ai_key() !== '') {
        $kind = in_array($info['kind'] ?? '', ['country', 'sign', 'player'], true) ? $info['kind'] : 'country';
        $what = ['country' => 'country flag balls', 'sign' => 'players holding SUBSCRIBE / LIKE / COMMENT / SHARE signs', 'player' => 'famous football players'][$kind];
        $names = implode(', ', array_slice(array_values(array_filter(array_map('strval', (array) ($info['names'] ?? [])), fn ($n) => $n !== (string) ($info['winner'] ?? ''))), 0, 6));   // never the winner (it would give the answer away)
        $txt = ai_call('You write YouTube Shorts titles for a football puzzle video for viewers in the USA and Europe: ' . $what
            . ' kick balls at a golden trophy, only ONE touches it, viewers guess which. Strong curiosity hook, max 70 characters, 1-2 emojis, family-friendly, NEVER reveal the answer. Reply ONLY with JSON {"titles":["…","…","…"]}',
            "Names: {$names}.\nDo not reuse: " . implode(' | ', array_slice(array_keys($used), -30)), 300, 25);
        if ($txt && preg_match('/\{.*\}/s', $txt, $m) && is_array($r = json_decode($m[0], true))) {
            foreach ((array) ($r['titles'] ?? []) as $t) { $t = mb_substr(trim((string) $t), 0, 100); if ($t !== '' && !isset($used[aus_norm($t)])) return $t; }
        }
    }
    foreach ((array) ($j['titles'] ?? []) as $t) { $t = mb_substr(trim((string) $t), 0, 100); if ($t !== '' && !isset($used[aus_norm($t)])) return $t; }
    return mb_substr(trim((string) (($j['titles'] ?? [])[0] ?? 'Which Ball Hit The Golden Cup? 👀🏆')), 0, 100);
}

/* ---------------------------------------------------------------- upload to YouTube */
function aus_channel(): ?array
{
    $id = (int) aus_cfg()['channel'];
    $st = db()->prepare("SELECT * FROM streams WHERE id = ? AND yt_refresh != ''");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** One ready video → YouTube (resumable upload). Called after worker check-ins, at most one at a time. */
function aus_upload_tick(): void
{
    // The video goes up in 8 MB pieces, one piece per worker check-in (a few seconds each), so the web server never
    // has to keep one long request open (cPanel stops long PHP requests — that cut the first try).
    $now = time();
    if ($now - (int) (setting_get('aus_up_at') ?? 0) < 4) return;
    setting_set('aus_up_at', (string) $now);
    $job = null;
    foreach (aus_jobs() as $j) {
        if ($j['st'] === 'uploading') { $job = $j; break; }
        if ($j['st'] === 'ready' && $now >= (int) ($j['retry_at'] ?? 0) && !$job) $job = $j;
    }
    if (!$job || yt_units_out()) return;
    $ch = aus_channel();
    if (!$ch) { aus_update($job['id'], ['st' => 'ready', 'err' => 'Choose the YouTube channel in Auto Shorts (a connected channel)', 'retry_at' => $now + 600]); return; }
    $file = aus_file($job['id'], 'mp4');
    if (!is_file($file)) { aus_update($job['id'], ['st' => 'failed', 'err' => 'The video file is missing']); return; }
    $size = filesize($file);
    $base = getenv('YT_TEST_BASE') ?: 'https://www.googleapis.com';   // (local tests: a fake Google)
    @set_time_limit(120);
    try {
        $tok = yt_token($ch);
        // 1) start a resumable upload (once): title, description, publish time
        if (empty($job['up_url'])) {
            $title = aus_pick_title($job);
            $publish = (int) $job['at'] > $now + 900 ? (int) $job['at'] : 0;
            $meta = ['snippet' => ['title' => $title, 'description' => mb_substr((string) $job['desc'], 0, 4900), 'categoryId' => '17',
                                   'tags' => ['shorts', 'football', 'challenge', 'puzzle', 'soccer']],
                     'status'  => ['privacyStatus' => $publish ? 'private' : 'public', 'selfDeclaredMadeForKids' => false, 'embeddable' => true]
                                  + ($publish ? ['publishAt' => gmdate('Y-m-d\TH:i:s\Z', $publish)] : [])];
            $loc = '';
            $c1 = curl_init($base . '/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status');
            curl_setopt_array($c1, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
                CURLOPT_POSTFIELDS => json_encode($meta, JSON_UNESCAPED_UNICODE),
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok, 'Content-Type: application/json; charset=UTF-8', 'X-Upload-Content-Type: video/mp4', 'X-Upload-Content-Length: ' . $size],
                CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$loc) { if (stripos($h, 'location:') === 0) $loc = trim(substr($h, 9)); return strlen($h); }]);
            $raw = curl_exec($c1); $code = (int) curl_getinfo($c1, CURLINFO_RESPONSE_CODE); curl_close($c1);
            yt_units_count('POST', 'https://www.googleapis.com/upload/youtube/v3/videos', $code, json_decode((string) $raw, true) ?: null);
            if ($code !== 200 || $loc === '') throw new RuntimeException(yt_err([$code, json_decode((string) $raw, true), (string) $raw]));
            aus_update($job['id'], ['st' => 'uploading', 'up_url' => $loc, 'up_off' => 0, 'prog' => 0, 'up_at' => $now, 'title' => $title, 'publish' => $publish, 'err' => '']);
            return;
        }
        // 2) the next piece
        $off = (int) ($job['up_off'] ?? 0);
        $len = min(8 * 1048576, $size - $off);
        $fh = fopen($file, 'rb'); fseek($fh, $off); $data = (string) fread($fh, $len); fclose($fh);
        $range = 0;
        $c2 = curl_init((string) $job['up_url']);
        curl_setopt_array($c2, [CURLOPT_CUSTOMREQUEST => 'PUT', CURLOPT_POSTFIELDS => $data, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 100,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok, 'Content-Type: video/mp4', 'Content-Length: ' . strlen($data), 'Content-Range: bytes ' . $off . '-' . ($off + strlen($data) - 1) . '/' . $size],
            CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$range) { if (preg_match('/^range:\s*bytes=0-(\d+)/i', $h, $m)) $range = (int) $m[1] + 1; return strlen($h); }]);
        $raw = curl_exec($c2); $code = (int) curl_getinfo($c2, CURLINFO_RESPONSE_CODE); curl_close($c2);
        if ($code === 308) {   // more to send
            aus_update($job['id'], ['up_off' => $range ?: $off, 'up_at' => $now, 'prog' => (int) round(($range ?: $off) / $size * 100), 'err' => '']);
            return;
        }
        if ($code === 404 || $code === 410) { aus_update($job['id'], ['st' => 'ready', 'up_url' => '', 'up_off' => 0, 'err' => 'YouTube closed the upload — starting it again']); return; }
        $r = json_decode((string) $raw, true) ?: [];
        if (!in_array($code, [200, 201], true) || empty($r['id'])) throw new RuntimeException(yt_err([$code, $r, (string) $raw]));
        $vid = (string) $r['id']; $title = (string) $job['title']; $publish = (int) ($job['publish'] ?? 0);
        aus_mark_used($title);
        // 3) the thumbnail (YouTube may not take it for Shorts — then the video just keeps its own picture)
        $thumb = aus_file($job['id'], 'jpg');
        if (is_file($thumb)) { try { yt_set_thumb($tok, $vid, [$thumb, 'image/jpeg']); } catch (Throwable $e) { /* not important */ } }
        $priv = (string) ($r['status']['privacyStatus'] ?? '');
        aus_update($job['id'], ['st' => $publish ? 'scheduled' : 'uploaded', 'vid' => $vid, 'up_url' => '', 'prog' => 100,
            'err' => $priv === 'private' && !$publish ? 'YouTube kept it Private (API not approved yet) — set it to Public in YouTube Studio' : '']);
        @unlink($file);   // YouTube has it now: free the space
        log_activity("🏭 Auto Shorts: uploaded “{$title}”" . ($publish ? ' — goes public ' . date('d M h:i A', $publish) : ''), 'ok');
    } catch (Throwable $e) {
        $tries = (int) ($job['tries'] ?? 0) + 1;
        aus_update($job['id'], ['st' => $tries >= 4 ? 'failed' : 'ready', 'tries' => $tries, 'retry_at' => $now + 300, 'up_url' => '', 'up_off' => 0, 'err' => mb_substr($e->getMessage(), 0, 300)]);
        log_activity('🏭 Auto Shorts: upload failed — ' . mb_substr($e->getMessage(), 0, 200), 'warn');
    }
}
