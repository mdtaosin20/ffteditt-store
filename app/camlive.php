<?php
/**
 * Camera Live Links: a link with an end time. The person opens it on his phone, puts HIS stream key, allows the
 * camera and microphone, and starts the live himself. His phone sends the picture and voice to the camera page
 * (/camlive/) that the VPS opens and sends to YouTube with his key — the same way as "Join Live".
 * When the time is over (or the owner stops it) the live stops and the link stops working.
 */
declare(strict_types=1);

const CAM_AWAY = 120;   // seconds without his phone (page closed) = the live is stopped

/** [{id, label, t, made, exp, sid, off, seen, live_at}] */
function cam_links(): array { return json_decode((string) (setting_get('cam_links') ?? ''), true) ?: []; }
function cam_save(array $l): void { setting_set('cam_links', json_encode(array_values($l), JSON_UNESCAPED_UNICODE)); }
function cam_update(string $id, array $set): void
{
    $l = cam_links();
    foreach ($l as &$v) if ($v['id'] === $id) $v = array_merge($v, $set);
    unset($v);
    cam_save($l);
}
function cam_url(string $tok): string
{
    $host = preg_replace('/[^a-z0-9.\-:]/i', '', (string) ($_SERVER['HTTP_HOST'] ?? 'ffteditt.store'));
    return 'https://' . $host . '/cam.php?t=' . $tok;
}

/** A new link: also makes his camera stream (no key yet). */
function cam_create(string $label, int $secs): array
{
    $label = mb_substr(trim($label), 0, 30) ?: 'Guest';
    $host = preg_replace('/[^a-z0-9.\-:]/i', '', (string) ($_SERVER['HTTP_HOST'] ?? 'ffteditt.store'));
    $now = time();
    db()->prepare("INSERT INTO streams (name, stream_key, page_url, server_id, quality, fps, channel_name, created_at, updated_at) VALUES (?, '', ?, ?, '720p-v', 24, ?, ?, ?)")
        ->execute([$label . ' (camera)', 'https://' . $host . '/camlive/', cli_pick_server(), $label, $now, $now]);
    $v = ['id' => bin2hex(random_bytes(4)), 'label' => $label, 't' => rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '='),
          'made' => $now, 'exp' => $now + $secs, 'sid' => (int) db()->lastInsertId(), 'off' => false, 'seen' => 0];
    $l = cam_links(); $l[] = $v; cam_save($l);
    return $v;
}

/** The link for a token, if it still works (not cancelled, not over, stream still there). */
function cam_find(string $tok): ?array
{
    if (!preg_match('/^[A-Za-z0-9_-]{20,40}$/', $tok)) return null;
    foreach (cam_links() as $v) {
        if (!hash_equals((string) $v['t'], $tok)) continue;
        if (!empty($v['off']) || (int) $v['exp'] <= time() || !cli_stream((int) $v['sid'])) return null;
        return $v;
    }
    return null;
}

/** Stop his live (and the phone connection). */
function cam_stop(array $link, string $why): void
{
    $sid = (int) $link['sid'];
    $s = cli_stream($sid);
    if ($s && $s['desired'] === 'on') {
        db()->prepare("UPDATE streams SET desired = 'off', updated_at = ? WHERE id = ?")->execute([time(), $sid]);
        log_activity("📱 Camera live of {$link['label']} stopped — {$why}", 'info');
    }
    $g = golive_get($sid);
    if ($g['on']) { $g['on'] = false; golive_put($sid, $g); }
}

/** Every few seconds (with Auto Fix): stop lives whose time is over, or whose phone went away. */
function cam_tick(): void
{
    $now = time();
    if ($now - (int) (setting_get('cam_tick_at') ?? 0) < 10) return;
    setting_set('cam_tick_at', (string) $now);
    foreach (cam_links() as $v) {
        $s = cli_stream((int) $v['sid']);
        if (!$s || $s['desired'] !== 'on') continue;
        if (!empty($v['off'])) cam_stop($v, 'link cancelled');
        elseif ((int) $v['exp'] <= $now) cam_stop($v, 'the link time is over');
        elseif ($now - (int) ($v['seen'] ?? 0) > CAM_AWAY) cam_stop($v, 'his phone left the page');
    }
}

/** How his picture is turned: r = small turn in degrees, q = quarter turns (0–3), m = mirror. */
function cam_view(array $link): array
{
    $v = is_array($link['view'] ?? null) ? $link['view'] : [];
    return ['r' => (float) ($v['r'] ?? 0), 'q' => (int) ($v['q'] ?? 0), 'm' => !empty($v['m'])];
}

/** Seconds as "2h 18m" / "12m" / "40s". */
function cam_left(int $s): string
{
    if ($s <= 0) return 'over';
    if ($s >= 86400) return intdiv($s, 86400) . 'd ' . intdiv($s % 86400, 3600) . 'h';
    if ($s >= 3600) return intdiv($s, 3600) . 'h ' . intdiv($s % 3600, 60) . 'm';
    return $s >= 60 ? intdiv($s, 60) . 'm' : $s . 's';
}
