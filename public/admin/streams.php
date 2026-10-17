<?php
require __DIR__ . '/../_boot.php';
$admin = require_login();

$servers = all_servers();
$streams = db()->query('SELECT * FROM streams ORDER BY name')->fetchAll();
$q       = qualities();

$liveCount = 0;
foreach ($streams as $s) {
    if (stream_view_status($s, $servers[(int) $s['server_id']] ?? null)[0] === 'live') {
        $liveCount++;
    }
}
$onlineServers = count(array_filter($servers, 'server_online'));

page_start('Live Stream Manager', 'streams', $admin);
?>
<div class="page-head">
  <div>
    <h1>Live Stream Manager</h1>
    <p class="muted">Add as many streams as you like. Each one runs on the server you choose.</p>
  </div>
  <div class="head-actions">
    <a class="btn btn-blue" href="stream_edit.php"><?= icon('plus') ?>Add stream</a>
  </div>
</div>

<div class="stats">
  <div class="stat"><div class="stat-label">Live now</div><div class="stat-value" id="live-count"><?= $liveCount ?> / <?= count($streams) ?></div></div>
  <div class="stat"><div class="stat-label">Servers online</div><div class="stat-value"><?= $onlineServers ?> / <?= count($servers) ?></div></div>
  <div class="stat stat-actions">
    <form method="post" action="stream_action.php"><?= csrf_field() ?><input type="hidden" name="do" value="start_all">
      <button class="btn btn-green" type="submit" <?= $streams ? '' : 'disabled' ?>><?= icon('play') ?>Start all</button></form>
    <form method="post" action="stream_action.php" onsubmit="return confirm('Stop ALL streams?')"><?= csrf_field() ?><input type="hidden" name="do" value="stop_all">
      <button class="btn btn-red" type="submit" <?= $streams ? '' : 'disabled' ?>><?= icon('stop') ?>Stop all</button></form>
    <form method="post" action="stream_action.php" onsubmit="return confirm('Restart all running streams?')"><?= csrf_field() ?><input type="hidden" name="do" value="restart_all">
      <button class="btn btn-blue" type="submit" <?= $streams ? '' : 'disabled' ?>><?= icon('refresh') ?>Restart all</button></form>
  </div>
</div>

<?php if (!$servers): ?>
  <div class="callout">
    <strong>Step 1: add a server.</strong> A server (VPS) is the computer that actually sends video to YouTube.
    <a href="servers.php">Go to Servers →</a>
  </div>
<?php endif; ?>

<?php if (!$streams): ?>
  <div class="card empty">
    <h2>No streams yet</h2>
    <p class="muted">Click <strong>+ Add stream</strong>, paste a YouTube stream key and choose a server.</p>
  </div>
<?php else: ?>
<div class="grid" id="streams">
  <?php foreach ($streams as $s):
      $srv = $servers[(int) $s['server_id']] ?? null;
      [$code, $label] = stream_view_status($s, $srv);
      $isLive = $code === 'live';
  ?>
  <section class="card stream" data-id="<?= (int) $s['id'] ?>">
    <div class="stream-top">
      <h2><?= e($s['name']) ?></h2>
      <span class="badge badge-<?= e($code) ?>" data-field="badge"><?= e($label) ?></span>
    </div>
    <dl class="meta">
      <div><dt>Server</dt><dd><?= $srv ? e($srv['name']) : '<span class="warn-text">None chosen</span>' ?></dd></div>
      <div><dt>Quality</dt><dd><?= e($q[$s['quality']]['label'] ?? $s['quality']) ?> · <?= (int) $s['fps'] ?> fps</dd></div>
      <div><dt>Channel</dt><dd><?= e($s['channel_name'] ?: '—') ?></dd></div>
      <div><dt>Game</dt><dd><?= e(games_list()[game_of_url($s['page_url'])]['name']) ?></dd></div>
      <div><dt>Key</dt><dd><?= e(mask_key($s['stream_key'])) ?></dd></div>
    </dl>
    <div class="mini-stats">
      <div><span>Running for</span><strong data-field="uptime"><?= $isLive && $s['started_at'] ? e(duration((int) $s['started_at'])) : '—' ?></strong></div>
      <div><span>FPS</span><strong data-field="fps"><?= $isLive && $s['fps_now'] !== null ? e(round((float) $s['fps_now'])) : '—' ?></strong></div>
      <div><span>Speed</span><strong data-field="speed"><?= $isLive && $s['speed_now'] ? e($s['speed_now']) : '—' ?></strong></div>
    </div>
    <p class="error-text" data-field="error" <?= ($code === 'error' && $s['error']) ? '' : 'hidden' ?>><?= e($s['error'] ?? '') ?></p>
    <div class="stream-actions">
      <form method="post" action="stream_action.php"><?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
        <?php if ($s['desired'] === 'on'): ?>
          <input type="hidden" name="do" value="stop">
          <button class="btn btn-red" type="submit"><?= icon('stop') ?>Stop</button>
        <?php else: ?>
          <input type="hidden" name="do" value="start">
          <button class="btn btn-green" type="submit" <?= ($srv && $s['stream_key'] !== '') ? '' : 'disabled' ?>><?= icon('play') ?>Start</button>
        <?php endif; ?>
      </form>
      <?php if ($s['desired'] === 'on' && watch_url($s['channel_url'])): ?><a class="btn btn-blue" href="<?= e(watch_url($s['channel_url'])) ?>" target="_blank" rel="noopener"><?= icon('eye') ?>Watch</a><?php endif; ?>
      <a class="btn btn-ghost" href="stream_edit.php?id=<?= (int) $s['id'] ?>"><?= icon('gear') ?>Edit</a>
    </div>
  </section>
  <?php endforeach; ?>
</div>
<p class="muted small">Status refreshes automatically. After Start/Stop the server reacts within about 10 seconds.</p>
<?php endif; ?>
<?php page_end();
