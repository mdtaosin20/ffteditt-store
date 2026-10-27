<?php
// Device Access: phones and PCs that asked to open the private website, and the ones you allowed.
require __DIR__ . '/../_boot.php';
$admin = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $st = db()->prepare('SELECT * FROM devices WHERE id = ?');
    $st->execute([$id]);
    $d = $st->fetch();
    if ($d) {
        $nm = $d['name'] !== '' ? $d['name'] : 'No name';
        switch ((string) ($_POST['do'] ?? '')) {
            case 'approve':
                db()->prepare("UPDATE devices SET status = 'approved' WHERE id = ?")->execute([$id]);
                log_activity("Device Access: “{$nm}” approved", 'ok');
                flash("Approved ✓ — “{$nm}” opens by itself in a few seconds.");
                break;
            case 'deny':
                db()->prepare("UPDATE devices SET status = 'denied' WHERE id = ?")->execute([$id]);
                log_activity("Device Access: “{$nm}” denied", 'info');
                flash("Denied — “{$nm}” cannot open the website.");
                break;
            case 'remove':
                db()->prepare('DELETE FROM devices WHERE id = ?')->execute([$id]);
                log_activity("Device Access: “{$nm}” removed", 'info');
                flash("Removed — “{$nm}” cannot open the website any more.");
                break;
            case 'rename':
                $new = dev_clean((string) ($_POST['name'] ?? ''), 60);
                db()->prepare('UPDATE devices SET name = ? WHERE id = ?')->execute([$new, $id]);
                flash('Name saved ✓');
                break;
        }
    }
    redirect('devices.php');
}

$pending = db()->query("SELECT * FROM devices WHERE status = 'pending' ORDER BY asked_at DESC")->fetchAll();
$approved = db()->query("SELECT * FROM devices WHERE status = 'approved' ORDER BY last_seen DESC, id DESC")->fetchAll();
$denied = db()->query("SELECT * FROM devices WHERE status = 'denied' ORDER BY asked_at DESC LIMIT 20")->fetchAll();
$ago = function (int $t): string {
    if ($t <= 0) return 'never';
    $d = time() - $t;
    if ($d < 90) return 'now';
    if ($d < 3600) return intdiv($d, 60) . ' min ago';
    if ($d < 86400) return intdiv($d, 3600) . ' h ago';
    if ($d < 172800) return 'yesterday';
    return date('j M', $t);
};
$ipShort = fn (string $ip) => preg_match('/^(\d+\.\d+)\.\d+\.\d+$/', $ip, $m) ? $m[1] . '.x.x' : (strlen($ip) > 14 ? substr($ip, 0, 12) . '…' : $ip);
$row = function (array $d, string $kind) use ($ago, $ipShort) {
    [$ic, $what] = dev_kind($d['ua']);
    $nm = $d['name'] !== '' ? $d['name'] : 'No name';
    $online = $kind === 'approved' && time() - (int) $d['last_seen'] < 300;
    ob_start(); ?>
    <div class="dv-row<?= $kind === 'pending' ? ' dv-new' : '' ?>">
      <div class="dv-ic <?= $ic === '📱' ? 'dv-ph' : 'dv-pc' ?>"><?= $ic ?></div>
      <div class="dv-txt">
        <div class="dv-nm"><?= e($nm) ?><?php if ($online): ?> <span class="dv-on">● online</span><?php endif; ?></div>
        <div class="dv-sm"><?= e($what) ?> · <span class="dv-ip" title="<?= e($d['ip']) ?>"><?= e($ipShort($d['ip'])) ?></span> ·
          <?= $kind === 'approved' ? 'last seen ' . e($ago((int) $d['last_seen'])) : e($ago((int) $d['asked_at'])) ?> · ID <?= e($d['code']) ?></div>
      </div>
      <div class="dv-act">
        <?php if ($kind === 'pending'): ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $d['id'] ?>"><button class="btn btn-green btn-sm" name="do" value="approve">✓ Approve</button></form>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $d['id'] ?>"><button class="btn btn-ghost btn-sm dv-no" name="do" value="deny">Deny</button></form>
        <?php elseif ($kind === 'approved'): ?>
          <details class="dv-ren"><summary class="btn btn-ghost btn-sm" title="Rename">✏️</summary>
            <form method="post" class="dv-renf"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
              <input name="name" maxlength="60" value="<?= e($d['name']) ?>" placeholder="Name"><button class="btn btn-blue btn-sm" name="do" value="rename">Save</button></form></details>
          <form method="post" onsubmit="return confirm('Remove this device? It will not open the website any more.')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $d['id'] ?>"><button class="btn btn-ghost btn-sm dv-no" name="do" value="remove">Remove</button></form>
        <?php else: ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $d['id'] ?>"><button class="btn btn-ghost btn-sm" name="do" value="approve">Approve</button></form>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $d['id'] ?>"><button class="btn btn-ghost btn-sm dv-no" name="do" value="remove">Delete</button></form>
        <?php endif; ?>
      </div>
    </div>
    <?php return (string) ob_get_clean();
};

page_start('Device Access', 'devices', $admin);
?>
<div class="dv-head">
  <div class="dv-hic">🛡️</div>
  <div><h1>Device Access</h1><p>Who may open the website and the games<?= site_private() ? '' : ' — <b>the website is public now</b>, turn on Private in Website' ?></p></div>
  <?php if ($pending): ?><div class="dv-badge"><?= count($pending) ?> new</div><?php endif; ?>
</div>

<div class="dv-st"><span class="dv-dot" style="background:var(--orange)"></span>WAITING FOR APPROVAL</div>
<?php if (!$pending): ?><p class="muted small dv-empty">No requests. When a new phone or PC opens the website, it can send a request — it shows here.</p><?php endif; ?>
<?php foreach ($pending as $d) echo $row($d, 'pending'); ?>

<div class="dv-st" style="margin-top:22px"><span class="dv-dot" style="background:var(--green)"></span>APPROVED DEVICES · <?= count($approved) ?></div>
<?php foreach ($approved as $d) echo $row($d, 'approved'); ?>
<div class="dv-row">
  <div class="dv-ic dv-gold">👑</div>
  <div class="dv-txt"><div class="dv-nm">Admin login</div><div class="dv-sm">Any browser where you are logged in to this admin can always open the website.</div></div>
</div>

<?php if ($denied): ?>
<details class="dv-den"><summary class="dv-st" style="margin-top:22px;cursor:pointer"><span class="dv-dot" style="background:var(--red)"></span>DENIED · <?= count($denied) ?></summary>
<?php foreach ($denied as $d) echo $row($d, 'denied'); ?>
</details>
<?php endif; ?>
<p class="muted small" style="margin-top:18px">A device is remembered by its browser, not by its IP — so changing Wi-Fi or mobile data does not lock it out. Clearing the browser's cookies, or using a private tab, needs a new request.</p>
<script>setTimeout(() => { if (!document.querySelector('details[open]') && !document.activeElement?.matches('input')) location.reload(); }, 15000);</script>
<?php page_end();
