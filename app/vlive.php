<?php
/**
 * 🎞 Video Live: the owner's own recorded videos sent to YouTube as a live (Admin → Video Live), for times
 * when no game runs. A Video Live is an ordinary stream row with source = 'video'; its videos live in a
 * library on the panel (uploaded in pieces, or a direct link). The VPS downloads each video once, makes it
 * ready for live (the stream's size, frame rate and bitrate, done once and kept), then plays the list with
 * almost no CPU (no new encoding while live).
 *
 * ⏱ Run time (any stream): live for N hours, off for M minutes, then on again by itself — every start is a
 * new YouTube live. A stop by anyone else (Stop button, schedule …) ends the cycle until the next Start.
 */
declare(strict_types=1);

function vl_dir(): string { $d = DATA_DIR . '/vlive'; if (!is_dir($d)) @mkdir($d, 0700, true); return $d; }
function vl_videos(): array { return json_decode((string) (setting_get('vl_videos') ?? ''), true) ?: []; }
function vl_videos_save(array $l): void { setting_set('vl_videos', json_encode(array_values($l), JSON_UNESCAPED_UNICODE)); }
function vl_video(string $id): ?array { foreach (vl_videos() as $v) if ($v['id'] === $id) return $v; return null; }
function vl_file(array $v): string { return vl_dir() . '/' . preg_replace('/[^a-z0-9]/', '', (string) $v['id']) . '.' . preg_replace('/[^a-z0-9]/', '', (string) ($v['ext'] ?? 'mp4')); }
function vl_sig(string $id): string { return substr(hash_hmac('sha256', 'vlive:' . $id, app_secret()), 0, 32); }

/** Where the VPS gets the video: the panel's copy (signed) or the owner's own link. */
function vl_src_url(array $v): string
{
    if (($v['kind'] ?? 'file') === 'link') return (string) $v['url'];
    $host = preg_replace('/[^a-z0-9.\-:]/i', '', (string) ($_SERVER['HTTP_HOST'] ?? 'ffteditt.store'));
    return (getenv('VL_TEST_HTTP') ? 'http://' : 'https://') . $host . '/api/vlive.php?' . http_build_query(['v' => $v['id'], 'sig' => vl_sig($v['id'])]);
}

function vl_cfg(array $row): array
{
    $c = json_decode((string) ($row['vlist'] ?? ''), true);
    $c = is_array($c) ? $c : [];
    return ['ids' => array_values(array_filter((array) ($c['ids'] ?? []), 'is_string')), 'loop' => (bool) ($c['loop'] ?? true), 'shuffle' => (bool) ($c['shuffle'] ?? false)];
}

/** What the worker needs for a Video Live (added to its stream settings). */
function vl_worker_cfg(array $row): array
{
    $c = vl_cfg($row); $vids = [];
    foreach ($c['ids'] as $id) {
        $v = vl_video($id);
        if (!$v || (($v['kind'] ?? 'file') === 'file' && !is_file(vl_file($v)))) continue;
        $vids[] = ['id' => $v['id'], 'name' => mb_substr((string) $v['name'], 0, 60), 'url' => vl_src_url($v), 'size' => (int) ($v['size'] ?? 0)];
    }
    return ['source' => 'video', 'videos' => $vids, 'loop' => $c['loop'], 'shuffle' => $c['shuffle']];
}

/* ---------------------------------------------------------------- ⏱ Run time */
function cycle_tick(): void
{
    $now = time();
    // 1) live long enough → off for a while (remember when, so a stop by anyone else is not undone)
    $st = db()->query("SELECT id, name, started_at, cycle_hours, cycle_off_min FROM streams
                       WHERE cycle_on = 1 AND desired = 'on' AND status = 'live' AND started_at IS NOT NULL")->fetchAll();
    foreach ($st as $r) {
        $hours = max(0.25, (float) $r['cycle_hours']);
        if ($now - (int) $r['started_at'] < $hours * 3600) continue;
        $back = $now + max(1, (int) $r['cycle_off_min']) * 60;
        db()->prepare("UPDATE streams SET desired = 'off', updated_at = ?, cycle_off_at = ?, cycle_resume_at = ? WHERE id = ? AND desired = 'on'")->execute([$now, $now, $back, $r['id']]);
        log_activity("⏱ Run time: '{$r['name']}' paused after " . rtrim(rtrim(number_format($hours, 2), '0'), '.') . " h — back on at " . date('h:i A', $back), 'info');
    }
    // 2) the break is over → on again (only if nobody changed the stream since the break began)
    $st = db()->prepare("SELECT id, name, stream_key, server_id FROM streams WHERE cycle_on = 1 AND desired = 'off' AND cycle_resume_at > 0
                         AND cycle_resume_at <= ? AND updated_at = cycle_off_at");
    $st->execute([$now]);
    foreach ($st->fetchAll() as $r) {
        $dup = db()->prepare("SELECT COUNT(*) FROM streams WHERE stream_key = ? AND id != ? AND desired = 'on'");
        $dup->execute([$r['stream_key'], $r['id']]);
        if ($r['stream_key'] === '' || !$r['server_id'] || (int) $dup->fetchColumn() > 0) {
            db()->prepare('UPDATE streams SET cycle_resume_at = 0 WHERE id = ?')->execute([$r['id']]);
            log_activity("⏱ Run time: could not start '{$r['name']}' again (no key / server, or its key is used by another live)", 'warn');
            continue;
        }
        db()->prepare("UPDATE streams SET desired = 'on', error = NULL, updated_at = ?, run_id = ?, cycle_resume_at = 0 WHERE id = ?")->execute([$now, $now, $r['id']]);
        log_activity("⏱ Run time: '{$r['name']}' started again (a new YouTube live)", 'ok');
    }
}

/** A stream is in a Run-time break right now (the VPS is kept free for it). */
function cycle_breaks_on(int $serverId): bool
{
    $st = db()->prepare("SELECT COUNT(*) FROM streams WHERE server_id = ? AND cycle_on = 1 AND desired = 'off' AND cycle_resume_at > ? AND updated_at = cycle_off_at");
    $st->execute([$serverId, time()]);
    return (int) $st->fetchColumn() > 0;
}
