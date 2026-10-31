<?php
/**
 * Moving streams between VPS:
 *  - "This VPS replaces …" (VPS Monitor → Add server): when the new VPS checks in for the first time,
 *    ALL streams of the old VPS move to it (live ones start again there, stopped ones stay stopped).
 *  - "⚖️ Share the load": spreads the streams over the online VPS (by their CPUs), moving as few as possible.
 */
declare(strict_types=1);

/** Move every stream of one VPS to another. Returns [moved, live]. */
function vps_move_all(int $from, int $to): array
{
    $now = time();
    $live = (int) db()->query("SELECT COUNT(*) FROM streams WHERE server_id = {$from} AND desired = 'on'")->fetchColumn();
    $st = db()->prepare('UPDATE streams SET server_id = ?, rev = rev + 1, error = NULL, updated_at = ? WHERE server_id = ?');
    $st->execute([$to, $now, $from]);
    return [$st->rowCount(), $live];
}

/** Called when a VPS checks in: a waiting "replaces" happens now. */
function vps_replace_tick(int $sid): void
{
    $from = (int) (setting_get('srv_replace_' . $sid) ?? 0);
    if ($from <= 0) return;
    setting_set('srv_replace_' . $sid, '');
    $names = [];
    foreach (db()->query('SELECT id, name FROM servers') as $r) $names[(int) $r['id']] = $r['name'];
    if (!isset($names[$from])) return;
    [$n, $live] = vps_move_all($from, $sid);
    setting_set('srv_replaced_' . $from, json_encode(['by' => $sid, 'ts' => time()]));
    log_activity("🖥 {$names[$sid]} is online and replaced {$names[$from]}: moved {$n} stream(s), {$live} live again. You can remove {$names[$from]} now.", 'ok');
}

/** ⚖️ Spread the streams over the online VPS (live ones first), moving as few as possible. Returns [moved, liveMoved]. */
function vps_share_load(): array
{
    $now = time();
    $srv = [];
    foreach (db()->query('SELECT id, last_seen, info_json FROM servers') as $r) {
        if ($now - (int) $r['last_seen'] > SERVER_OFFLINE_AFTER) continue;
        $srv[(int) $r['id']] = max(1, (int) ((json_decode($r['info_json'] ?: '{}', true) ?: [])['cpus'] ?? 1));
    }
    if (count($srv) < 2) return [0, 0];
    $moved = 0; $liveMoved = 0;
    foreach (["desired = 'on'", "desired != 'on'"] as $where) {   // live streams first (they matter for the load), then the stopped ones
        $rows = db()->query("SELECT id, server_id FROM streams WHERE {$where} ORDER BY id")->fetchAll();
        if (!$rows) continue;
        $total = count($rows); $cpus = array_sum($srv);
        $want = []; foreach ($srv as $id => $c) $want[$id] = (int) floor($total * $c / $cpus);
        $left = $total - array_sum($want);
        arsort($srv); foreach ($srv as $id => $c) { if ($left-- <= 0) break; $want[$id]++; }
        $have = array_fill_keys(array_keys($srv), 0); $extra = [];
        foreach ($rows as $r) {
            $sid = (int) $r['server_id'];
            if (isset($have[$sid]) && $have[$sid] < $want[$sid]) $have[$sid]++;
            else $extra[] = (int) $r['id'];   // on a full, offline or no VPS: needs a place
        }
        $st = db()->prepare('UPDATE streams SET server_id = ?, rev = rev + 1, error = NULL, updated_at = ? WHERE id = ?');
        foreach ($extra as $id) {
            foreach ($have as $sid => $n) {
                if ($n < $want[$sid]) { $st->execute([$sid, $now, $id]); $have[$sid]++; $moved++; if ($where === "desired = 'on'") $liveMoved++; break; }
            }
        }
    }
    return [$moved, $liveMoved];
}

/**
 * 🔁 Automatic share: about every minute (with Auto Fix). When one VPS stays busy for 5 minutes (CPU 85 %+ or its
 * lives run slower than real time) and another online VPS has room (CPU under 55 %), ONE live stream moves over
 * (the youngest one that has run 10+ minutes, so long-running lives keep their viewers). Then 15 minutes of calm.
 */
const BAL_HOT = 75;      // % CPU: a VPS is busy from here
const BAL_CHECKS = 3;   // … for this many checks in a row (one a minute)
const BAL_ROOM = 55;    // % CPU: a VPS has room under this
const BAL_GAP = 600;    // seconds between two moves
const BAL_YOUNG = 300;  // a live that started less than this ago stays where it is

function vps_balance_tick(): void
{
    if (empty(panel_opts()['auto_balance'])) return;
    $now = time();
    if ($now - (int) (setting_get('bal_tick_at') ?? 0) < 60) return;
    setting_set('bal_tick_at', (string) $now);
    $note = fn (string $t) => setting_set('bal_note', json_encode(['t' => $now, 'msg' => $t], JSON_UNESCAPED_UNICODE));
    $srv = [];
    foreach (db()->query('SELECT id, name, last_seen, info_json FROM servers') as $r) {
        if ($now - (int) $r['last_seen'] > SERVER_OFFLINE_AFTER) continue;
        $i = json_decode($r['info_json'] ?: '{}', true) ?: [];
        $cpus = max(1, (int) ($i['cpus'] ?? 1));
        $cpu = isset($i['cpu_pct']) ? (float) $i['cpu_pct'] : 100 * (float) ($i['load1'] ?? 0) / $cpus;
        $srv[(int) $r['id']] = ['name' => $r['name'], 'cpu' => $cpu, 'cpus' => $cpus, 'slow' => false, 'live' => []];
    }
    $st = json_decode((string) (setting_get('bal_state') ?? ''), true) ?: [];
    if (count($srv) < 2) { setting_set('bal_state', '{}'); $note('Only one VPS is online — nothing to share with.'); return; }
    foreach (db()->query("SELECT id, name, server_id, started_at, speed_now, status FROM streams WHERE desired = 'on' AND stream_key <> ''") as $r) {
        $sid = (int) $r['server_id'];
        if (!isset($srv[$sid])) continue;
        $srv[$sid]['live'][] = $r;
        $sp = (float) $r['speed_now'];
        if ($r['status'] === 'running' && $sp > 0 && $sp < 0.95) $srv[$sid]['slow'] = true;
    }
    // a VPS is busy when its CPU is 75 %+ (or a live is slow) for 3 checks in a row (about 3 minutes)
    $busy = null;
    foreach ($srv as $id => $s) {
        $hot = $s['cpu'] >= BAL_HOT || $s['slow'];
        $st[$id] = $hot ? ($st[$id] ?? 0) + 1 : 0;
        if ($hot && $st[$id] >= BAL_CHECKS && $s['live'] && ($busy === null || $s['cpu'] > $srv[$busy]['cpu'])) $busy = $id;
    }
    setting_set('bal_state', json_encode(array_intersect_key($st, $srv)));
    if ($busy === null) {
        $hotNow = array_filter($srv, fn ($s, $id) => ($st[$id] ?? 0) > 0, ARRAY_FILTER_USE_BOTH);
        $note($hotNow ? 'Watching: ' . implode(', ', array_map(fn ($id) => $srv[$id]['name'] . ' busy ' . $st[$id] . '/' . BAL_CHECKS . ' min', array_keys($hotNow)))
                      : 'All VPS are fine (CPU under ' . BAL_HOT . ' %).');
        return;
    }
    $B = $srv[$busy];
    $wait = BAL_GAP - ($now - (int) (setting_get('bal_last') ?? 0));
    if ($wait > 0) { $note("{$B['name']} is busy (CPU " . (int) $B['cpu'] . ' %) — the last move was a short time ago; next move possible in ' . ceil($wait / 60) . ' min.'); return; }
    // where to: the VPS with the most free room (free CPU × number of CPUs)
    $to = null;
    foreach ($srv as $id => $s) {
        if ($id === $busy || $s['cpu'] >= BAL_ROOM || $s['slow']) continue;
        if (count($B['live']) < 2 && $s['cpus'] <= $B['cpus']) continue;   // only one live: moving it helps only on a bigger VPS (else it just bounces)
        $room = (100 - $s['cpu']) * $s['cpus'];
        if ($to === null || $room > $to[1]) $to = [$id, $room];
    }
    if ($to === null) {
        $why = count($B['live']) < 2 ? "{$B['name']} has only one live and no other VPS is bigger — moving it would not help. Lower its quality / fps, or use a bigger VPS."
                                     : "{$B['name']} is busy, but no other VPS has room (all " . BAL_ROOM . ' %+ CPU).';
        $note($why);
        if ($now - (int) (setting_get('bal_full_said') ?? 0) > 3600) { setting_set('bal_full_said', (string) $now); log_activity('🔁 ' . $why, 'warn'); }
        return;
    }
    $to = $to[0];
    $pick = null;
    foreach ($B['live'] as $r) {
        if ($now - (int) $r['started_at'] < BAL_YOUNG) continue;   // just started: leave it
        if ($pick === null || (int) $r['started_at'] > (int) $pick['started_at']) $pick = $r;
    }
    if ($pick === null) { $note("{$B['name']} is busy — its lives started less than " . (BAL_YOUNG / 60) . ' min ago; waiting a little.'); return; }
    db()->prepare('UPDATE streams SET server_id = ?, rev = rev + 1, error = NULL, updated_at = ? WHERE id = ?')->execute([$to, $now, (int) $pick['id']]);
    setting_set('bal_last', (string) $now);
    $st[$busy] = 0; setting_set('bal_state', json_encode($st));
    $msg = sprintf('🔁 Auto share: %s was busy (CPU %d%%%s) — moved "%s" to %s (CPU %d%%). It is back in about 20 seconds.',
        $B['name'], (int) $B['cpu'], $B['slow'] ? ', lives slow' : '', $pick['name'], $srv[$to]['name'], (int) $srv[$to]['cpu']);
    $note($msg);
    log_activity($msg, 'info');
}
