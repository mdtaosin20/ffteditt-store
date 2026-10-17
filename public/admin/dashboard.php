<?php
require __DIR__ . '/../_boot.php';
require APP_DIR . '/dash.php';
$admin = require_login();

schedule_tick();
$srv     = dash_server();
$servers = all_servers();
$opts    = panel_opts();
$bw      = bandwidth_month();
$live    = dash_live_data($srv);

page_start('Dashboard', 'dashboard', $admin);
?>
<div class="dash" id="dash" data-server="<?= $srv ? (int) $srv['id'] : 0 ?>">

  <?php if (count($servers) > 1): ?>
  <form class="srv-pick" method="get"><label><span class="muted small">Showing server</span>
    <select name="server" data-autosubmit>
      <?php foreach ($servers as $sid => $s): ?><option value="<?= (int) $sid ?>" <?= $srv && (int) $srv['id'] === $sid ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
    </select></label></form>
  <?php endif; ?>

  <section class="kcards" data-live="cards"><?= dash_cards($srv) ?></section>

  <div class="row r-2">
    <section class="panel">
      <div class="ph"><h2><?= icon('gamepad') ?>Live Stream Overview</h2><a class="ph-link" href="streams.php">View All Streams <?= icon('arrow') ?></a></div>
      <div class="tbl-wrap"><table class="tbl">
        <thead><tr><th>#</th><th>Game</th><th>YouTube Channel</th><th>Status</th><th>Uptime</th><th>Actions</th></tr></thead>
        <tbody data-live="overview"><?= dash_overview() ?></tbody>
      </table></div>
    </section>

    <?= dash_monitor($srv, $live) ?>
  </div>

  <div class="row r-3">
    <section class="panel">
      <div class="ph"><h2><?= icon('gamepad') ?>Game Manager</h2><button class="btn btn-sm btn-blue" type="button" data-open="add-game"><?= icon('plus') ?>Add Game</button></div>
      <?= dash_games() ?>
    </section>

    <section class="panel">
      <div class="ph"><h2><?= icon('calendar') ?>Schedule Manager</h2><a class="btn btn-sm btn-blue" href="schedule.php#add"><?= icon('plus') ?>Add Schedule</a></div>
      <?= dash_schedules() ?>
    </section>

    <section class="panel">
      <div class="ph"><h2><span class="yt yt-lg"><?= icon('play') ?></span>YouTube Manager</h2><a class="btn btn-sm btn-blue" href="stream_edit.php"><?= icon('plus') ?>Add Channel</a></div>
      <?= dash_youtube() ?>
    </section>
  </div>

  <div class="row r-3b">
    <section class="panel">
      <div class="ph"><h2><?= icon('logs') ?>Recent Logs</h2><a class="ph-link" href="activity.php">View All Logs <?= icon('external') ?></a></div>
      <ul class="logs" data-live="logs"><?= dash_logs() ?></ul>
    </section>

    <section class="panel">
      <div class="ph"><h2><?= icon('bolt') ?>Stream Control (Quick Actions)</h2></div>
      <div class="qa">
        <form method="post" action="stream_action.php"><?= csrf_field() ?><input type="hidden" name="do" value="start_all"><input type="hidden" name="back" value="dashboard.php">
          <button class="btn btn-lg btn-green" type="submit"><?= icon('play') ?>Start All</button></form>
        <form method="post" action="stream_action.php" onsubmit="return confirm('Stop ALL streams?')"><?= csrf_field() ?><input type="hidden" name="do" value="stop_all"><input type="hidden" name="back" value="dashboard.php">
          <button class="btn btn-lg btn-red" type="submit"><?= icon('stop') ?>Stop All</button></form>
        <form method="post" action="stream_action.php" onsubmit="return confirm('Restart all running streams?')"><?= csrf_field() ?><input type="hidden" name="do" value="restart_all"><input type="hidden" name="back" value="dashboard.php">
          <button class="btn btn-lg btn-blue" type="submit"><?= icon('refresh') ?>Restart All</button></form>
      </div>
      <div class="qa qa-t">
        <?php foreach ([['auto_start', 'Auto Start', 'Start streams when VPS boots'], ['auto_stop', 'Auto Stop', 'Stop after scheduled time'], ['notify', 'Notification', 'Get alerts on issues']] as [$k, $t, $d]): ?>
        <div class="qt"><div class="qt-h"><b><?= e($t) ?></b><?= toggle_form('panel_action.php', ['key' => $k, 'back' => 'dashboard.php'], (bool) $opts[$k], $t) ?></div><p><?= e($d) ?></p></div>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="panel">
      <div class="ph"><h2><?= icon('pie') ?>Bandwidth Usage</h2><span class="muted small">This Month</span></div>
      <div class="bw">
        <div class="donut" style="--p: <?= (int) $bw['pct'] ?>"><div><small>Used</small><b><?= (int) $bw['pct'] ?>%</b><small><?= e($bw['used']) ?> GB</small></div></div>
        <ul class="bw-l">
          <li><span class="ldot l-ok"></span>Used: <?= e($bw['used']) ?> GB</li>
          <li><span class="ldot l-info"></span>Remaining: <?= e(number_format($bw['left'])) ?> GB</li>
          <li><span class="ldot l-gray"></span>Total: <?= e(number_format($bw['limit'])) ?> GB</li>
        </ul>
      </div>
      <div class="meter meter-lg"><i class="f-blue" style="width: <?= (int) $bw['pct'] ?>%"></i></div>
      <p class="muted small bw-est"><?= $bw['days'] === null ? 'Counting starts after the worker update.' : 'Estimated to last: ' . (int) $bw['days'] . ' days' ?></p>
    </section>
  </div>
</div>

<div class="modal" id="add-game" hidden role="dialog" aria-modal="true" aria-labelledby="ag-t">
  <div class="modal-in">
    <h2 id="ag-t">Add a new game</h2>
    <p class="muted">Each game is a page built for live streaming (like Ball Drop and Flag Battle). Tell Claude what game you want — once it is built it shows up here automatically, with Auto Play and Speed controls.</p>
    <button class="btn btn-blue" type="button" data-close>OK</button>
  </div>
</div>
<script type="application/json" id="dash-data"><?= json_encode($live) ?></script>
<?php page_end();
