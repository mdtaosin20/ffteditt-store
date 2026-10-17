<?php
require __DIR__ . '/../_boot.php';
require APP_DIR . '/dash.php';
$admin = require_login();

page_start('YouTube Manager', 'youtube', $admin);
?>
<div class="page-head">
  <div>
    <h1>YouTube Manager</h1>
    <p class="muted">Your YouTube channels and their stream keys. Keys are kept secret and never shown on the website. Each channel is one live stream.</p>
  </div>
  <div class="head-actions"><a class="btn btn-blue" href="stream_edit.php"><?= icon('plus') ?>Add Channel</a></div>
</div>

<section class="panel">
  <div class="ph"><h2><span class="yt yt-lg"><?= icon('play') ?></span>Channels</h2></div>
  <?= dash_youtube('youtube.php', 200) ?>
</section>

<section class="panel">
  <div class="ph"><h2><?= icon('youtube') ?>Where to find the stream key</h2></div>
  <ol class="steps">
    <li>Open <a href="https://studio.youtube.com" target="_blank" rel="noopener">YouTube Studio</a> → <strong>Create</strong> → <strong>Go live</strong>.</li>
    <li>Choose <strong>Stream</strong>, then press the copy button next to <strong>Stream key</strong>.</li>
    <li>Here: <strong>Add Channel</strong> (or the gear of a channel) → paste it into “YouTube stream key” → Save.</li>
    <li>Add your channel link (e.g. <code>https://www.youtube.com/@yourname</code>) so the <strong>Watch</strong> button opens your live.</li>
  </ol>
</section>
<?php page_end();
