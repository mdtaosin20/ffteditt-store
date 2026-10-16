<?php
require __DIR__ . '/../_boot.php';
$admin = require_login();

$rows = db()->query('SELECT ts, message FROM activity ORDER BY id DESC LIMIT 200')->fetchAll();

page_start('Activity', 'activity', $admin);
?>
<div class="page-head">
  <div>
    <h1>Activity</h1>
    <p class="muted">What happened recently (newest first, Bangladesh time).</p>
  </div>
</div>
<section class="card">
  <?php if (!$rows): ?>
    <p class="muted">Nothing yet.</p>
  <?php else: ?>
  <ul class="log">
    <?php foreach ($rows as $r): ?>
      <li><time><?= e(date('M j, H:i:s', (int) $r['ts'])) ?></time><span><?= e($r['message']) ?></span></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
<?php page_end();
