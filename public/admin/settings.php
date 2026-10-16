<?php
require __DIR__ . '/../_boot.php';
$admin = require_login();

$errors = [];
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
<div class="page-head"><div><h1>Settings</h1><p class="muted">Change your login.</p></div></div>

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
  <div class="form-actions"><button class="btn btn-pink" type="submit">Save</button></div>
</form>
<?php page_end();
