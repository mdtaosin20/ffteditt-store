<?php
// Camera Live Links: a link with an end time. He puts his own stream key, allows camera + mic, and goes live on his
// own channel from his phone (through your VPS). You see who is live and can stop him or give more time.
require __DIR__ . '/../_boot.php';
$admin = require_login();
cam_tick();
const CAM_TIMES = [3600 => '1 hour', 10800 => '3 hours', 86400 => '1 day', 604800 => '7 days', 2592000 => '30 days'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $do = (string) ($_POST['do'] ?? '');
    $id = (string) ($_POST['lid'] ?? '');
    $link = null; foreach (cam_links() as $v) if ($v['id'] === $id) $link = $v;
    if ($do === 'make') {
        $secs = (int) ($_POST['secs'] ?? 10800); if (!isset(CAM_TIMES[$secs])) $secs = 10800;
        $v = cam_create((string) ($_POST['label'] ?? ''), $secs);
        log_activity("📱 Camera live link made for {$v['label']} (" . CAM_TIMES[$secs] . ')', 'info');
        flash('Link ready ✓ — press Copy and send it.');
        redirect('camlinks.php#c' . $v['id']);
    }
    if ($link && $do === 'stop') { cam_stop($link, 'stopped by you'); flash('Stopped. His link still works until its end time.'); }
    if ($link && $do === 'more') { cam_update($id, ['exp' => max(time(), (int) $link['exp']) + 3600]); flash('+1 hour ✓'); }
    if ($link && $do === 'cancel') { cam_stop($link, 'link cancelled'); cam_update($id, ['off' => true]); flash('Link cancelled — it does not work any more.'); }
    if ($link && $do === 'remove') {
        cam_stop($link, 'removed');
        db()->prepare('DELETE FROM streams WHERE id = ?')->execute([(int) $link['sid']]);
        cam_save(array_filter(cam_links(), fn ($v) => $v['id'] !== $id));
        flash('Removed (with its camera stream).');
    }
    redirect('camlinks.php');
}

$links = array_reverse(cam_links());
$servers = [];
foreach (db()->query('SELECT id, name, last_seen FROM servers') as $r) $servers[(int) $r['id']] = $r;
page_start('Camera Live Links', 'camlinks', $admin);
?>
<div class="dv-head" style="background:linear-gradient(120deg,#3a0d1c,#10304a)">
  <div class="dv-hic">📱</div>
  <div><h1>Camera Live Links</h1><p>A link with an end time. He opens it on his phone, puts <b>his own stream key</b>, allows the camera and microphone, and goes live on his channel himself — through your VPS. When the time is over, his live stops and the link stops working.</p></div>
</div>

<form method="post" class="card" style="max-width:none">
  <?= csrf_field() ?><input type="hidden" name="do" value="make">
  <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <input name="label" maxlength="30" placeholder="Who is it for? (e.g. Rahim)" style="flex:1;min-width:180px">
    <select name="secs" style="width:auto"><?php foreach (CAM_TIMES as $k => $t): ?><option value="<?= $k ?>" <?= $k === 10800 ? 'selected' : '' ?>>Works for <?= e($t) ?></option><?php endforeach; ?></select>
    <button class="btn btn-blue">🔗 Make camera link</button>
  </div>
</form>

<?php foreach ($links as $l):
    $s = cli_stream((int) $l['sid']);
    $now = time(); $over = !empty($l['off']) || (int) $l['exp'] <= $now || !$s;
    $on = $s && $s['desired'] === 'on'; $run = $on && $s['status'] === 'running';
    $phone = $now - (int) ($l['seen'] ?? 0) < 30;
    $url = cam_url((string) $l['t']);
?>
  <div class="dv-row ow-row" id="c<?= e($l['id']) ?>">
    <div class="dv-ic" style="background:<?= $over ? '#3a2a0a' : ($run ? '#7f1d1d' : '#14532d') ?>"><?= $over ? '⌛' : ($run ? '🔴' : ($on ? '⏳' : '✅')) ?></div>
    <div class="dv-txt">
      <div class="dv-nm"><?= e($l['label']) ?></div>
      <?php if ($over): ?>
        <div class="dv-sm"><?= !empty($l['off']) ? 'Cancelled' : 'Ended ' . e(date('j M, g:i A', (int) $l['exp'])) ?> — the link does not work</div>
      <?php else: ?>
        <div class="dv-sm"><?= $run ? '<b style="color:#f87171">LIVE</b>' . ($phone ? '' : ' <span style="color:#fbbf24">(phone not sending)</span>') : ($on ? '⏳ Starting…' : 'Not live') ?>
          · ends in <b><?= e(cam_left((int) $l['exp'] - $now)) ?></b> (<?= e(date('j M, g:i A', (int) $l['exp'])) ?>)
          · <?= $s['stream_key'] !== '' ? 'key ✓ ••••' . e(substr($s['stream_key'], -4)) : '<span style="color:#fbbf24">no key yet</span>' ?>
          · VPS: <?= isset($servers[(int) $s['server_id']]) ? e($servers[(int) $s['server_id']]['name']) : 'auto' ?></div>
        <div class="ow-link"><input readonly value="<?= e($url) ?>" onclick="this.select()" aria-label="Link">
          <button class="btn btn-green btn-sm" type="button" data-copy="<?= e($url) ?>">Copy</button>
          <a class="btn btn-ghost btn-sm" href="https://wa.me/?text=<?= rawurlencode('Go live with your camera on your YouTube channel: ' . $url) ?>" target="_blank" rel="noopener">WhatsApp</a></div>
      <?php endif; ?>
    </div>
    <div class="dv-act">
      <?php if (!$over): ?>
        <?php if ($on): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="lid" value="<?= e($l['id']) ?>"><button class="btn btn-danger btn-sm" name="do" value="stop">■ Stop now</button></form><?php endif; ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="lid" value="<?= e($l['id']) ?>"><button class="btn btn-ghost btn-sm" name="do" value="more">+1 hour</button></form>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="lid" value="<?= e($l['id']) ?>"><button class="btn btn-ghost btn-sm dv-no" name="do" value="cancel" onclick="return confirm('Cancel this link? His live stops.')">Cancel</button></form>
      <?php else: ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="lid" value="<?= e($l['id']) ?>"><button class="btn btn-ghost btn-sm" name="do" value="remove">Remove</button></form>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
<?php if (!$links): ?><p class="muted">No camera links yet. Write a name above and press <b>Make camera link</b>.</p><?php endif; ?>

<div class="card" style="max-width:none;margin-top:18px">
  <h3 style="margin-top:0">Good to know</h3>
  <p class="muted small" style="margin:0">Each camera live uses your VPS like one game live. It goes out at 720p (standing 9:16 or lying 16:9). If he closes the page for more than 2 minutes, his live stops by itself. He should keep the page open and use earphones if he watches his own live.</p>
</div>
<script>
document.querySelectorAll('[data-copy]').forEach((b) => b.addEventListener('click', async () => {
  try { await navigator.clipboard.writeText(b.dataset.copy); } catch (e) { const i = b.parentNode.querySelector('input'); i.select(); document.execCommand('copy'); }
  b.textContent = 'Copied ✓'; setTimeout(() => { b.textContent = 'Copy'; }, 1800);
}));
setTimeout(() => location.reload(), 30000);   // see live / ended without pressing anything
</script>
<?php page_end();
