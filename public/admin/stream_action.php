<?php
require __DIR__ . '/../_boot.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('dashboard.php');
}
check_csrf();

$do  = (string) ($_POST['do'] ?? '');
$now = time();

$startSql = "UPDATE streams SET desired = 'on', error = NULL, updated_at = ?
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
            db()->prepare($startSql . ' AND id = ?')->execute([$now, $id]);
            log_activity("Start requested: {$s['name']}");
            flash("Starting \"{$s['name']}\"… the server will pick it up in a few seconds.");
        } else {
            db()->prepare($stopSql . ' WHERE id = ?')->execute([$now, $id]);
            log_activity("Stop requested: {$s['name']}");
            flash("Stopping \"{$s['name']}\"…");
        }
        break;

    case 'start_all':
        $n = db()->prepare($startSql);
        $n->execute([$now]);
        log_activity('Start requested: all streams');
        flash('Starting all streams that have a key and a server.');
        break;

    case 'stop_all':
        db()->prepare($stopSql)->execute([$now]);
        log_activity('Stop requested: all streams');
        flash('Stopping all streams.');
        break;
}

redirect('dashboard.php');
