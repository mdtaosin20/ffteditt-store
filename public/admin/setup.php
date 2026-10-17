<?php
require __DIR__ . '/../_boot.php';

// Only works while no admin account exists yet.
if (has_admin()) {
    redirect('index.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $user  = trim((string) ($_POST['username'] ?? ''));
    $pass  = (string) ($_POST['password'] ?? '');
    $pass2 = (string) ($_POST['password2'] ?? '');

    if (!preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $user)) {
        $error = 'Username: 3–32 letters, numbers, dot, dash or underscore.';
    } elseif (strlen($pass) < 10) {
        $error = 'Password must be at least 10 characters.';
    } elseif ($pass !== $pass2) {
        $error = 'The two passwords do not match.';
    } else {
        db()->prepare('INSERT INTO admins (username, pass_hash, created_at) VALUES (?, ?, ?)')
            ->execute([$user, password_hash($pass, PASSWORD_DEFAULT), time()]);
        log_activity('Admin account created');
        attempt_login($user, $pass);
        flash('Welcome! Your admin account is ready.');
        redirect('dashboard.php');
    }
}

page_start('Create admin');
?>
<div class="auth">
  <form method="post" class="card auth-card">
    <div class="auth-head">
      <div class="logo big"><?= icon('gamepad', 'ic login-ic') ?><span>GameStream <b>Admin Panel</b></span></div>
      <p class="muted">First-time setup: create your admin login</p>
    </div>
    <?php if ($error): ?><div class="flash flash-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <?= csrf_field() ?>
    <label class="field"><span>Username</span>
      <input name="username" autocomplete="username" required value="<?= e($_POST['username'] ?? '') ?>">
    </label>
    <label class="field"><span>Password (10+ characters)</span>
      <input name="password" type="password" autocomplete="new-password" required minlength="10">
    </label>
    <label class="field"><span>Password again</span>
      <input name="password2" type="password" autocomplete="new-password" required minlength="10">
    </label>
    <button class="btn btn-gold btn-block" type="submit">Create admin</button>
  </form>
</div>
<?php page_end();
