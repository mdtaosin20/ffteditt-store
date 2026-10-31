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
function vps_balance_tick(): void
{
    if (empty(panel_opts()['auto_balance'])) return;
    $now = time();
    if ($now - (int) (setting_get('bal_tick_at') ?? 0) < 60) return;
    setting_set('bal_tick_at', (string) $now);
    $srv = [];
    foreach (db()->query('SELECT id, name, last_seen, info_json FROM servers') as $r) {
        if ($now - (int) $r['last_seen'] > SERVER_OFFLINE_AFTER) continue;
        $i = json_decode($r['info_json'] ?: '{}', true) ?: [];
        $cpu = isset($i['cpu_pct']) ? (float) $i['cpu_pct'] : 100 * (float) ($i['load1'] ?? 0) / max(1, (int) ($i['cpus'] ?? 1));
        $srv[(int) $r['id']] = ['name' => $r['name'], 'cpu' => $cpu, 'slow' => false, 'live' => []];
    }
    $st = json_decode((string) (setting_get('bal_state') ?? ''), true) ?: [];
    if (count($srv) < 2) { setting_set('bal_state', '{}'); return; }
    foreach (db()->query("SELECT id, name, server_id, started_at, speed_now, status FROM streams WHERE desired = 'on' AND stream_key <> ''") as $r) {
        $sid = (int) $r['server_id'];
        if (!isset($srv[$sid])) continue;
        $srv[$sid]['live'][] = $r;
        $sp = (float) $r['speed_now'];
        if ($r['status'] === 'running' && $sp > 0 && $sp < 0.95) $srv[$sid]['slow'] = true;
    }
    $busy = null;
    foreach ($srv as $id => $s) {
        $hot = $s['cpu'] >= 85 || $s['slow'];
        $st[$id] = $hot ? ($st[$id] ?? 0) + 1 : 0;
        if ($hot && $st[$id] >= 5 && count($s['live']) >= 2 && ($busy === null || $s['cpu'] > $srv[$busy]['cpu'])) $busy = $id;
    }
    setting_set('bal_state', json_encode(array_intersect_key($st, $srv)));
    if ($busy === null || $now - (int) (setting_get('bal_last') ?? 0) < 900) return;
    $to = null;
    foreach ($srv as $id => $s) if ($id !== $busy && $s['cpu'] < 55 && !$s['slow'] && ($to === null || $s['cpu'] < $srv[$to]['cpu'])) $to = $id;
    if ($to === null) {
        if ($now - (int) (setting_get('bal_full_said') ?? 0) > 3600) {
            setting_set('bal_full_said', (string) $now);
            log_activity("🔁 {$srv[$busy]['name']} is busy, but no other VPS has room — think about a bigger VPS or one more.", 'warn');
        }
        return;
    }
    $pick = null;
    foreach ($srv[$busy]['live'] as $r) {
        if ($now - (int) $r['started_at'] < 600) continue;   // just started: leave it
        if ($pick === null || (int) $r['started_at'] > (int) $pick['started_at']) $pick = $r;
    }
    if ($pick === null) return;
    db()->prepare('UPDATE streams SET server_id = ?, rev = rev + 1, error = NULL, updated_at = ? WHERE id = ?')->execute([$to, $now, (int) $pick['id']]);
    setting_set('bal_last', (string) $now);
    $st[$busy] = 0; setting_set('bal_state', json_encode($st));
    log_activity(sprintf('🔁 Auto share: %s was busy (CPU %d%%%s) — moved "%s" to %s (CPU %d%%). It is back in about 20 seconds.',
        $srv[$busy]['name'], (int) $srv[$busy]['cpu'], $srv[$busy]['slow'] ? ', lives slow' : '', $pick['name'], $srv[$to]['name'], (int) $srv[$to]['cpu']), 'info');
}
