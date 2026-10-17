<?php
require __DIR__ . '/../_boot.php';
require APP_DIR . '/dash.php';
$admin = require_login();

$streams = db()->query('SELECT id, name, page_url FROM streams ORDER BY name')->fetchAll();
$byId    = array_column($streams, null, 'id');
$errors  = [];
$editId  = (int) ($_GET['edit'] ?? 0);
$v = ['stream_id' => $streams[0]['id'] ?? 0, 'start_time' => '20:00', 'end_time' => '23:59', 'days' => '1234567', 'enabled' => 1];
if ($editId) {
    $st = db()->prepare('SELECT * FROM schedules WHERE id = ?');
    $st->execute([$editId]);
    $row = $st->fetch();
    if ($row) {
        $v = $row;
    } else {
        $editId = 0;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $do   = (string) ($_POST['do'] ?? 'save');
    $id   = (int) ($_POST['id'] ?? 0);
    $back = ($_POST['back'] ?? '') === 'dashboard.php' ? 'dashboard.php' : 'schedule.php';

    if ($do === 'delete' && $id) {
        db()->prepare('DELETE FROM schedules WHERE id = ?')->execute([$id]);
        log_activity('Schedule deleted');
        flash('Schedule deleted.');
        redirect($back);
    }
    if ($do === 'toggle' && $id) {
        $on = ($_POST['to'] ?? '') === 'on' ? 1 : 0;
        // switching on: treat as "not started yet" so a stream inside its window starts now
        db()->prepare("UPDATE schedules SET enabled = ?, last_state = CASE WHEN ? = 1 THEN 'out' ELSE last_state END WHERE id = ?")->execute([$on, $on, $id]);
        log_activity('Schedule ' . ($on ? 'turned on' : 'paused'));
        if ($on) schedule_tick();
        redirect($back);
    }

    $v = [
        'stream_id'  => (int) ($_POST['stream_id'] ?? 0),
        'start_time' => (string) ($_POST['start_time'] ?? ''),
        'end_time'   => (string) ($_POST['end_time'] ?? ''),
        'days'       => implode('', array_values(array_intersect(['1', '2', '3', '4', '5', '6', '7'], (array) ($_POST['days'] ?? [])))),
        'enabled'    => 1,
    ];
    if (!isset($byId[$v['stream_id']])) $errors[] = 'Choose a stream.';
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v['start_time'])) $errors[] = 'Choose a start time.';
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v['end_time'])) $errors[] = 'Choose an end time.';
    if ($v['days'] === '') $errors[] = 'Pick at least one day.';

    if (!$errors) {
        $name = $byId[$v['stream_id']]['name'];
        if ($id) {
            db()->prepare("UPDATE schedules SET stream_id = ?, start_time = ?, end_time = ?, days = ?, last_state = 'out' WHERE id = ?")
                ->execute([$v['stream_id'], $v['start_time'], $v['end_time'], $v['days'], $id]);
            log_activity("Schedule updated: {$name} {$v['start_time']}–{$v['end_time']} " . days_label($v['days']));
        } else {
            db()->prepare('INSERT INTO schedules (stream_id, start_time, end_time, days, created_at) VALUES (?, ?, ?, ?, ?)')
                ->execute([$v['stream_id'], $v['start_time'], $v['end_time'], $v['days'], time()]);
            log_activity("Schedule added: {$name} {$v['start_time']}–{$v['end_time']} " . days_label($v['days']));
        }
        schedule_tick();
        flash('Schedule saved. The stream starts and stops by itself at these times (Bangladesh time).');
        redirect('schedule.php');
    }
    $editId = $id;
}

$rows = db()->query('SELECT sc.*, s.name, s.page_url, s.status FROM schedules sc JOIN streams s ON s.id = sc.stream_id ORDER BY sc.start_time')->fetchAll();
$opts = panel_opts();
$dayNames = [1 => 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

page_start('Schedule Manager', 'schedule', $admin);
?>
<div class="page-head">
  <div>
    <h1>Schedule Manager</h1>
    <p class="muted">Start a live stream at a set time and stop it later, every day or on chosen days. Times are Bangladesh time (now <?= e(date('h:i A, D')) ?>).</p>
  </div>
</div>

<?php foreach ($errors as $err): ?><div class="flash flash-error" role="alert"><?= e($err) ?></div><?php endforeach; ?>
<?php if (!$opts['auto_stop']): ?><div class="callout">Auto Stop is off on the Dashboard, so schedules only <strong>start</strong> streams — they don't stop them at the end time.</div><?php endif; ?>

<section class="panel">
  <div class="ph"><h2><?= icon('calendar') ?>All schedules</h2><a class="btn btn-sm btn-blue" href="#add"><?= icon('plus') ?>Add Schedule</a></div>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>Stream / Game</th><th>Start Time</th><th>End Time</th><th>Repeat</th><th>Now</th><th>On / Off</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="7" class="td-empty">No schedules yet. Add one below.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): $in = schedule_in_window($r, time()); ?>
      <tr>
        <td><span class="cell-game"><?= game_badge(game_of_url($r['page_url'])) ?><span><?= e($r['name']) ?><small><?= e(games_list()[game_of_url($r['page_url'])]['name']) ?></small></span></span></td>
        <td><?= e(date('h:i A', strtotime($r['start_time']))) ?></td>
        <td><?= e(date('h:i A', strtotime($r['end_time']))) ?></td>
        <td><?= e(days_label($r['days'])) ?></td>
        <td><?= !$r['enabled'] ? '<span class="tag tag-gray">Paused</span>' : ($in ? '<span class="tag tag-green">In time window</span>' : '<span class="tag tag-blue">Waiting</span>') ?></td>
        <td><form method="post" class="tgl-form"><?= csrf_field() ?><input type="hidden" name="do" value="toggle"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
          <input type="hidden" name="to" value="<?= $r['enabled'] ? 'off' : 'on' ?>">
          <button type="submit" class="tgl <?= $r['enabled'] ? 'is-on' : '' ?>" aria-pressed="<?= $r['enabled'] ? 'true' : 'false' ?>" aria-label="Schedule on or off"><span><?= $r['enabled'] ? 'ON' : 'OFF' ?></span><i></i></button></form></td>
        <td><div class="icons">
          <a class="ia ia-blue" href="schedule.php?edit=<?= (int) $r['id'] ?>#add" aria-label="Edit"><?= icon('pencil') ?></a>
          <form method="post" onsubmit="return confirm('Delete this schedule?')"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <button class="ia ia-red" type="submit" aria-label="Delete"><?= icon('trash') ?></button></form>
        </div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>

<section class="panel" id="add">
  <div class="ph"><h2><?= icon($editId ? 'pencil' : 'plus') ?><?= $editId ? 'Edit schedule' : 'Add schedule' ?></h2></div>
  <?php if (!$streams): ?>
    <p class="muted">First add a stream in <a href="streams.php">Live Stream Manager</a>, then come back here.</p>
  <?php else: ?>
  <form method="post" class="form">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $editId ?>">
    <div class="row2">
      <label class="field"><span>Stream (channel + game)</span>
        <select name="stream_id">
          <?php foreach ($streams as $s): ?><option value="<?= (int) $s['id'] ?>" <?= (int) $v['stream_id'] === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?> — <?= e(games_list()[game_of_url($s['page_url'])]['name']) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label class="field"><span>Start time</span><input type="time" name="start_time" required value="<?= e($v['start_time']) ?>"></label>
      <label class="field"><span>End time</span><input type="time" name="end_time" required value="<?= e($v['end_time']) ?>">
        <small class="muted">If the end is earlier than the start (e.g. 9 PM → 9 AM), it runs overnight.</small></label>
    </div>
    <fieldset class="days"><legend>Repeat on</legend>
      <div class="day-presets">
        <button type="button" class="btn btn-sm btn-ghost" data-days="1234567">Daily</button>
        <button type="button" class="btn btn-sm btn-ghost" data-days="123456">Mon-Sat</button>
        <button type="button" class="btn btn-sm btn-ghost" data-days="12345">Mon-Fri</button>
        <button type="button" class="btn btn-sm btn-ghost" data-days="67">Weekend</button>
      </div>
      <div class="day-boxes">
        <?php foreach ($dayNames as $n => $dn): ?>
          <label class="day"><input type="checkbox" name="days[]" value="<?= $n ?>" <?= str_contains((string) $v['days'], (string) $n) ? 'checked' : '' ?>><span><?= $dn ?></span></label>
        <?php endforeach; ?>
      </div>
    </fieldset>
    <div class="form-actions">
      <button class="btn btn-blue" type="submit"><?= $editId ? 'Save schedule' : 'Add schedule' ?></button>
      <?php if ($editId): ?><a class="btn btn-ghost" href="schedule.php">Cancel</a><?php endif; ?>
    </div>
  </form>
  <?php endif; ?>
</section>
<?php page_end();
