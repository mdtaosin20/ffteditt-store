<?php
require __DIR__ . '/../_boot.php';

if (!has_admin()) {
    redirect('setup.php');
}
if (current_admin()) {
    redirect('dashboard.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $wait = login_locked_for();
    if ($wait > 0) {
        $error = 'Too many wrong tries. Try again in ' . ceil($wait / 60) . ' minutes.';
    } elseif (attempt_login(trim((string) ($_POST['username'] ?? '')), (string) ($_POST['password'] ?? ''))) {
        redirect('dashboard.php');
    } else {
        $wait  = login_locked_for();
        $error = $wait > 0
            ? 'Too many wrong tries. Login is locked for 15 minutes.'
            : 'Wrong username or password.';
    }
}

page_start('Log in');
?>
<div class="auth">
  <form method="post" class="card auth-card">
    <div class="auth-head">
      <div class="logo big"><?= icon('gamepad', 'ic login-ic') ?><span>GameStream <b>Admin Panel</b></span></div>
      <p class="muted">Manage your games, VPS and YouTube live</p>
    </div>
    <?php if ($error): ?><div class="flash flash-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <?= csrf_field() ?>
    <label class="field"><span>Username</span>
      <input name="username" autocomplete="username" required autofocus>
    </label>
    <label class="field"><span>Password</span>
      <input name="password" type="password" autocomplete="current-password" required>
    </label>
    <button class="btn btn-gold btn-block" type="submit">Log in</button>
    <p class="muted small center">5 wrong tries locks login for 15 minutes.</p>
  </form>
</div>
<?php page_end();
