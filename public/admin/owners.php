<?php
// Channel Owner Links: send a link to a friend whose YouTube channel runs one of your streams.
require __DIR__ . '/../_boot.php';
$admin = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $sid = (int) ($_POST['id'] ?? 0);
    $st = db()->prepare('SELECT id, name FROM streams WHERE id = ?');
    $st->execute([$sid]);
    $s = $st->fetch();
    if ($s) {
        if (($_POST['do'] ?? '') === 'make') {
            inv_create($sid);
            log_activity("Channel owner link made for {$s['name']}", 'info');
            flash('Link ready ✓ — press Copy and send it to the channel owner.');
        } elseif (($_POST['do'] ?? '') === 'cancel') {
            inv_cancel($sid);
            flash('Link cancelled — it does not work any more.');
        }
    }
    redirect('owners.php#s' . $sid);
}

$streams = db()->query('SELECT id, name, channel_name, yt_channel, yt_refresh, status FROM streams ORDER BY id')->fetchAll();
page_start('Channel Owner Links', 'owners', $admin);
?>
<div class="dv-head" style="background:linear-gradient(120deg,#2a0d1c,#4a1030)">
  <div class="dv-hic">🔗</div>
  <div><h1>Channel Owner Links</h1><p>A friend runs your game live on his own YouTube channel? Send him a link — he logs in with Google, and his channel is connected here (title, thumbnail, pinned message). He never sees your admin, and you never see his password.</p></div>
</div>

<?php if (!yt_ready()): ?><div class="flash flash-error">First finish step 1 in <a href="youtube.php">YouTube Manager</a> (Google Client ID and Secret).</div><?php endif; ?>

<?php foreach ($streams as $s): $inv = inv_get((int) $s['id']); $on = $s['yt_refresh'] !== ''; ?>
  <div class="dv-row ow-row" id="s<?= (int) $s['id'] ?>">
    <div class="dv-ic" style="background:<?= $on ? '#14532d' : '#3a1020' ?>"><?= $on ? '✅' : '▶️' ?></div>
    <div class="dv-txt">
      <div class="dv-nm"><?= e($s['name']) ?></div>
      <div class="dv-sm"><?= $on ? 'Connected: <b style="color:var(--green-t)">' . e($s['yt_channel'] ?: 'YouTube channel') . '</b>' : 'Not connected to YouTube' ?>
        <?= $s['channel_name'] !== '' ? ' · ' . e($s['channel_name']) : '' ?></div>
      <?php if ($inv): $url = inv_url((string) $inv['t']); ?>
        <div class="ow-link">
          <input readonly value="<?= e($url) ?>" onclick="this.select()" aria-label="Link">
          <button class="btn btn-green btn-sm" type="button" data-copy="<?= e($url) ?>">Copy</button>
          <a class="btn btn-ghost btn-sm" href="https://wa.me/?text=<?= rawurlencode('Connect your YouTube channel for the 24/7 game live: ' . $url) ?>" target="_blank" rel="noopener">WhatsApp</a>
        </div>
        <div class="dv-sm">Works once · ends <?= e(date('j M, g:i A', (int) $inv['exp'])) ?></div>
      <?php endif; ?>
    </div>
    <div class="dv-act">
      <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
        <button class="btn btn-blue btn-sm" name="do" value="make" <?= yt_ready() ? '' : 'disabled' ?>>🔗 <?= $inv ? 'New link' : ($on ? 'Link to reconnect' : 'Make link') ?></button></form>
      <?php if ($inv): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $s['id'] ?>"><button class="btn btn-ghost btn-sm dv-no" name="do" value="cancel">Cancel link</button></form><?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
<?php if (!$streams): ?><p class="muted">No streams yet. Add one in Live Stream Manager first.</p><?php endif; ?>

<div class="card" style="max-width:none;margin-top:18px">
  <h3 style="margin-top:0">Your friend gets “Access blocked”?</h3>
  <p class="muted small" style="margin:0">While your Google app is in <b>Testing</b>, only Gmail addresses on its list may log in: Google Cloud → <b>APIs &amp; Services → OAuth consent screen</b> (Audience) → <b>Test users → Add users</b> → his Gmail → Save. Then he opens a new link.</p>
</div>
<script>
document.querySelectorAll('[data-copy]').forEach((b) => b.addEventListener('click', async () => {
  try { await navigator.clipboard.writeText(b.dataset.copy); } catch (e) { const i = b.parentNode.querySelector('input'); i.select(); document.execCommand('copy'); }
  b.textContent = 'Copied ✓'; setTimeout(() => { b.textContent = 'Copy'; }, 1800);
}));
</script>
<?php page_end();
