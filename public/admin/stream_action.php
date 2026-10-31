<?php
require __DIR__ . '/../_boot.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('streams.php');
}
check_csrf();

$do  = (string) ($_POST['do'] ?? '');
$now = time();

// Start gives the stream a new run number, so the games on it begin fresh (a crash restart keeps the old one).
$startSql = "UPDATE streams SET desired = 'on', error = NULL, updated_at = ?,
             run_id = CASE WHEN desired = 'off' THEN ? ELSE run_id END
             WHERE stream_key <> '' AND server_id IS NOT NULL";
$stopSql  = "UPDATE streams SET desired = 'off', updated_at = ?";

switch ($do) {
    case 'start':
    case 'stop':
        $id = (int) ($_POST['id'] ?? 0);
        $st = db()->prepare('SELECT name, stream_key, server_id FROM streams WHERE id = ?');
        $st->execute([$id]);
        $s = $st->fetch();
        if (!$s) {
            flash('Stream not found.', 'error');
            break;
        }
        if ($do === 'start') {
            if ($s['stream_key'] === '' || !$s['server_id']) {
                flash('Add a stream key and choose a server first.', 'error');
                break;
            }
            // one stream key = one live at a time (two lives on one key break each other on YouTube)
            $dup = db()->prepare("SELECT name FROM streams WHERE stream_key = ? AND id != ? AND desired = 'on'");
            $dup->execute([$s['stream_key'], $id]);
            if (($dn = $dup->fetchColumn()) !== false) {
                flash("\"{$dn}\" is already running with the same stream key. Stop it first — or give \"{$s['name']}\" its own key — because two lives cannot share one key.", 'error');
                break;
            }
            db()->prepare($startSql . ' AND id = ?')->execute([$now, $now, $id]);
            log_activity("Start requested: {$s['name']}");
            flash("Starting \"{$s['name']}\"… the server will pick it up in a few seconds.");
        } else {
            db()->prepare($stopSql . ' WHERE id = ?')->execute([$now, $id]);
            log_activity("Stop requested: {$s['name']}");
            flash("Stopping \"{$s['name']}\"…");
        }
        break;

    case 'restart': // one running stream: the game starts fresh (YouTube opens a new live video)
        $id = (int) ($_POST['id'] ?? 0);
        $st = db()->prepare("UPDATE streams SET rev = rev + 1, run_id = ?, updated_at = ? WHERE id = ? AND desired = 'on'");
        $st->execute([$now, $now, $id]);
        if ($st->rowCount()) {
            log_activity("Restart requested: stream #{$id}");
            flash('Restarting… it comes back in about 20 seconds.');
        } else {
            flash('This stream is not running.', 'error');
        }
        break;

    case 'start_all':
        $n = db()->prepare($startSql);
        $n->execute([$now, $now]);
        // streams that share a key: only the first one runs
        db()->exec("UPDATE streams SET desired = 'off' WHERE id IN (SELECT b.id FROM streams a JOIN streams b ON a.stream_key = b.stream_key AND a.id < b.id
                    WHERE a.desired = 'on' AND b.desired = 'on' AND a.stream_key <> '')");
        log_activity('Start requested: all streams');
        flash('Starting all streams that have a key and a server.');
        break;

    case 'restart_all':
        $n = db()->prepare("UPDATE streams SET rev = rev + 1, run_id = ?, updated_at = ? WHERE desired = 'on'");
        $n->execute([$now, $now]);
        log_activity('Restart requested: all live streams');
        flash('Restarting all running streams. They come back in about 20 seconds.');
        break;

    case 'stop_all':
        db()->prepare($stopSql)->execute([$now]);
        log_activity('Stop requested: all streams');
        flash('Stopping all streams.');
        break;
}

$back = (string) ($_POST['back'] ?? '');
redirect(in_array($back, ['dashboard.php', 'streams.php', 'youtube.php', 'clients.php'], true) ? $back : 'streams.php');
