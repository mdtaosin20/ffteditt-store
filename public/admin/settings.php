<?php
require __DIR__ . '/../_boot.php';
$admin = require_login();

$errors = [];
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

page_start('Settings', 'settings', $admin);
?>
<div class="page-head"><div><h1>Settings</h1><p class="muted">Your VPS details and your login.</p></div></div>
<?php $o = panel_opts(); ?>
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
<?php page_end();
