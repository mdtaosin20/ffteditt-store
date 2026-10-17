<?php
require __DIR__ . '/../_boot.php';
$admin = require_login();

$filter = (string) ($_GET['show'] ?? 'all');
$where  = $filter === 'alerts' ? "WHERE level IN ('warn','error')" : '';
$rows   = db()->query("SELECT id, ts, message, level FROM activity {$where} ORDER BY id DESC LIMIT 300")->fetchAll();
// opening this page counts as reading the alerts
panel_save(['alerts_seen' => (int) db()->query('SELECT COALESCE(MAX(id), 0) FROM activity')->fetchColumn()]);
$labels = ['ok' => 'OK', 'info' => 'Info', 'warn' => 'Warning', 'error' => 'Problem'];

page_start('Logs & Alerts', 'activity', $admin);
?>
<div class="page-head">
  <div>
    <h1>Logs &amp; Alerts</h1>
    <p class="muted">Everything that happened, newest first (Bangladesh time). Orange and red lines are alerts.</p>
  </div>
  <div class="head-actions seg">
    <a class="btn btn-sm <?= $filter !== 'alerts' ? 'btn-blue' : 'btn-ghost' ?>" href="activity.php">All logs</a>
    <a class="btn btn-sm <?= $filter === 'alerts' ? 'btn-blue' : 'btn-ghost' ?>" href="activity.php?show=alerts">Alerts only</a>
  </div>
</div>
<section class="panel">
  <?php if (!$rows): ?>
    <p class="muted">Nothing here yet.</p>
  <?php else: ?>
  <ul class="logs logs-full">
    <?php foreach ($rows as $r): ?>
      <li><span class="ldot l-<?= e($r['level']) ?>"></span><time><?= e(date('M j, H:i:s', (int) $r['ts'])) ?></time>
        <span class="lt l-<?= e($r['level']) ?>"><?= e($r['message']) ?></span><span class="tag tag-sm lv-<?= e($r['level']) ?>"><?= e($labels[$r['level']] ?? 'Info') ?></span></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
<?php page_end();
