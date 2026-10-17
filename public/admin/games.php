<?php
require __DIR__ . '/../_boot.php';
require APP_DIR . '/dash.php';
$admin = require_login();

$use = [];
foreach (db()->query('SELECT page_url, status FROM streams') as $s) {
    $k = game_of_url($s['page_url']);
    $use[$k]['n'] = ($use[$k]['n'] ?? 0) + 1;
    $use[$k]['live'] = ($use[$k]['live'] ?? 0) + ($s['status'] === 'live' ? 1 : 0);
}

page_start('Game Manager', 'games', $admin);
?>
<div class="page-head">
  <div>
    <h1>Game Manager</h1>
    <p class="muted">Turn a game's auto play on or off, change its speed, or restart it. When auto play is off, streams showing that game show a “be right back” screen.</p>
  </div>
</div>

<section class="panel">
  <div class="ph"><h2><?= icon('gamepad') ?>Games</h2></div>
  <?= dash_games('games.php') ?>
</section>

<div class="cards2">
  <?php foreach (games_list() as $g): ?>
  <section class="panel game-card">
    <div class="ph"><h2><?= game_badge($g['key']) ?><?= e($g['name']) ?></h2>
      <span class="tag <?= $g['autoplay'] ? 'tag-green' : 'tag-gray' ?>"><?= $g['autoplay'] ? 'Active' : 'Stopped' ?></span></div>
    <dl class="meta">
      <div><dt>Page</dt><dd><a href="<?= e($g['url']) ?>" target="_blank" rel="noopener">ffteditt.store<?= e($g['url']) ?></a></dd></div>
      <div><dt>Game</dt><dd><?= e($g['detail']) ?></dd></div>
      <div><dt>Speed</dt><dd><?= e(paces()[$g['pace']] ?? 'Normal') ?></dd></div>
      <div><dt>Streams</dt><dd><?= (int) ($use[$g['key']]['live'] ?? 0) ?> live / <?= (int) ($use[$g['key']]['n'] ?? 0) ?> using this game</dd></div>
    </dl>
    <div class="form-actions">
      <a class="btn btn-blue" href="<?= e($g['settings']) ?>"><?= icon('pencil') ?>Game settings</a>
      <a class="btn btn-ghost" href="<?= e($g['url']) ?>" target="_blank" rel="noopener"><?= icon('external') ?>Open game</a>
    </div>
  </section>
  <?php endforeach; ?>
</div>
<?php page_end();
