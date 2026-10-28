<?php
require __DIR__ . '/../_boot.php';
$admin = require_login();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['do'] ?? '', ['pk_remove', 'pk_rename'], true)) {
    check_csrf();
    pk_table();
    $pid = (int) ($_POST['pk'] ?? 0);
    if ($_POST['do'] === 'pk_remove') {
        db()->prepare('DELETE FROM passkeys WHERE id = ? AND admin_id = ?')->execute([$pid, (int) $admin['id']]);
        log_activity('Fingerprint login removed from a device', 'info');
        flash('Removed — that device needs the password again.');
    } else {
        $nm = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 60);
        if ($nm !== '') db()->prepare('UPDATE passkeys SET name = ? WHERE id = ? AND admin_id = ?')->execute([$nm, $pid, (int) $admin['id']]);
        flash('Name saved ✓');
    }
    redirect('settings.php#fingerprint');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'vps') {
    check_csrf();
    $o = [
        'provider'    => mb_substr(trim((string) ($_POST['provider'] ?? '')), 0, 30),
        'location'    => mb_substr(trim((string) ($_POST['location'] ?? '')), 0, 40),
        'plan'        => mb_substr(trim((string) ($_POST['plan'] ?? '')), 0, 30),
        'bw_limit_gb' => max(1, min(1000000, (int) ($_POST['bw_limit_gb'] ?? 2000))),
        'upgrade_url' => trim((string) ($_POST['upgrade_url'] ?? '')),
    ];
    if (!preg_match('#^https://[^\s]+$#', $o['upgrade_url'])) {
        $o['upgrade_url'] = panel_defaults()['upgrade_url'];
    }
    panel_save($o);
    flash('VPS info saved.');
    redirect('settings.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $current = (string) ($_POST['current'] ?? '');
    $user    = trim((string) ($_POST['username'] ?? ''));
    $new     = (string) ($_POST['new'] ?? '');
    $new2    = (string) ($_POST['new2'] ?? '');

    $st = db()->prepare('SELECT pass_hash FROM admins WHERE id = ?');
    $st->execute([$admin['id']]);
    if (!password_verify($current, (string) $st->fetchColumn())) {
        $errors[] = 'Current password is wrong.';
    }
    if (!preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $user)) {
        $errors[] = 'Username: 3–32 letters, numbers, dot, dash or underscore.';
    }
    if ($new !== '' && strlen($new) < 10) {
        $errors[] = 'New password must be at least 10 characters.';
    }
    if ($new !== $new2) {
        $errors[] = 'The two new passwords do not match.';
    }

    if (!$errors) {
        if ($new !== '') {
            db()->prepare('UPDATE admins SET username = ?, pass_hash = ? WHERE id = ?')
                ->execute([$user, password_hash($new, PASSWORD_DEFAULT), $admin['id']]);
            log_activity('Admin password changed');
        } else {
            db()->prepare('UPDATE admins SET username = ? WHERE id = ?')->execute([$user, $admin['id']]);
        }
        flash('Saved.');
        redirect('settings.php');
    }
}

$tab = tab_now('settings', $errors ? 'login' : null);
page_start('Settings', 'settings', $admin);
?>
<div class="page-head"><div><h1>Settings</h1><p class="muted">Your VPS details and your login.</p></div></div>
<?= tab_bar('settings') ?>
<?php $o = panel_opts(); ?>
<?php if ($tab === 'vps'): ?>
<form method="post" class="panel form">
  <?= csrf_field() ?><input type="hidden" name="do" value="vps">
  <div class="ph"><h2><?= icon('server') ?>Your VPS Info</h2></div>
  <p class="muted small" style="margin:0">Shown in the box at the bottom of the menu. CPU, RAM and storage are read from the VPS automatically.</p>
  <div class="row2">
    <label class="field"><span>Provider</span><input name="provider" maxlength="30" value="<?= e($o['provider']) ?>" placeholder="InterServer"></label>
    <label class="field"><span>Location</span><input name="location" maxlength="40" value="<?= e($o['location']) ?>" placeholder="New Jersey, USA"></label>
    <label class="field"><span>Plan</span><input name="plan" maxlength="30" value="<?= e($o['plan']) ?>" placeholder="1 Slice"></label>
    <label class="field"><span>Monthly bandwidth (GB)</span><input name="bw_limit_gb" type="number" min="1" value="<?= (int) $o['bw_limit_gb'] ?>"></label>
  </div>
  <label class="field"><span>“Upgrade Plan” button link</span><input name="upgrade_url" type="url" value="<?= e($o['upgrade_url']) ?>"></label>
  <div class="form-actions"><button class="btn btn-blue" type="submit">Save VPS info</button></div>
</form>
<?php endif; ?>
<?php if ($tab === 'fingerprint'): ?>
<?php pk_table(); $pks = db()->prepare('SELECT * FROM passkeys WHERE admin_id = ? ORDER BY last_used DESC, id DESC'); $pks->execute([(int) $admin['id']]); $pks = $pks->fetchAll(); ?>
<h2 class="sec-h" id="fingerprint">🔐 Fingerprint / Face login</h2>
<div class="card" style="max-width:none">
  <p class="muted" style="margin-top:0">Log in with the fingerprint, face or screen lock of your phone or PC — no password typing. The fingerprint never leaves your device.</p>
  <?php foreach ($pks as $k): $ph = preg_match('/phone|android|iphone|samsung|sm-|pixel/i', $k['name']); ?>
    <div class="dv-row">
      <div class="dv-ic <?= $ph ? 'dv-ph' : 'dv-pc' ?>"><?= $ph ? '📱' : '💻' ?></div>
      <div class="dv-txt"><div class="dv-nm"><?= e($k['name']) ?></div>
        <div class="dv-sm">Added <?= e(date('j M Y', (int) $k['created_at'])) ?> · last used <?= $k['last_used'] ? e(date('j M, g:i A', (int) $k['last_used'])) : 'never' ?></div></div>
      <div class="dv-act">
        <details class="dv-ren"><summary class="btn btn-ghost btn-sm" title="Rename">✏️</summary>
          <form method="post" class="dv-renf"><?= csrf_field() ?><input type="hidden" name="pk" value="<?= (int) $k['id'] ?>"><input name="name" maxlength="60" value="<?= e($k['name']) ?>"><button class="btn btn-blue btn-sm" name="do" value="pk_rename">Save</button></form></details>
        <form method="post" onsubmit="return confirm('Remove fingerprint login from this device?')"><?= csrf_field() ?><input type="hidden" name="pk" value="<?= (int) $k['id'] ?>"><button class="btn btn-ghost btn-sm dv-no" name="do" value="pk_remove">Remove</button></form>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!$pks): ?><p class="muted small">No device yet.</p><?php endif; ?>
  <div class="form-actions" style="align-items:center;gap:12px"><button class="btn btn-green" type="button" id="pk-add" data-csrf="<?= e(csrf_token()) ?>">+ Add this device</button><span class="muted small" id="pk-add-msg"></span></div>
</div>

<?php endif; ?>
<?php if ($tab === 'login'): ?>
<h2 class="sec-h">Login</h2>

<?php foreach ($errors as $err): ?>
  <div class="flash flash-error" role="alert"><?= e($err) ?></div>
<?php endforeach; ?>

<form method="post" class="card form narrow">
  <?= csrf_field() ?>
  <label class="field"><span>Username</span>
    <input name="username" autocomplete="username" required value="<?= e($_POST['username'] ?? $admin['username']) ?>">
  </label>
  <label class="field"><span>New password (leave empty to keep)</span>
    <input name="new" type="password" autocomplete="new-password" minlength="10">
  </label>
  <label class="field"><span>New password again</span>
    <input name="new2" type="password" autocomplete="new-password" minlength="10">
  </label>
  <label class="field"><span>Current password (required to save)</span>
    <input name="current" type="password" autocomplete="current-password" required>
  </label>
  <div class="form-actions"><button class="btn btn-blue" type="submit">Save login</button></div>
</form>
<?php endif; ?>
<?php page_end();
