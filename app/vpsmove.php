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
