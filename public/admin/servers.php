<?php
require __DIR__ . '/../_boot.php';
$admin = require_login();

function new_token(): string
{
    return 'fft_' . bin2hex(random_bytes(24));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $do = (string) ($_POST['do'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    if ($do === 'add') {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 40) {
            flash('Give the server a name (up to 40 characters).', 'error');
        } else {
            $token = new_token();
            db()->prepare('INSERT INTO servers (name, token_hash, created_at) VALUES (?, ?, ?)')
                ->execute([$name, hash('sha256', $token), time()]);
            $_SESSION['new_token'] = ['id' => (int) db()->lastInsertId(), 'token' => $token];
            log_activity("Server added: {$name}");
        }
    } elseif ($do === 'rotate' && $id) {
        $token = new_token();
        db()->prepare('UPDATE servers SET token_hash = ?, last_seen = 0 WHERE id = ?')
            ->execute([hash('sha256', $token), $id]);
        $_SESSION['new_token'] = ['id' => $id, 'token' => $token];
        log_activity('Server token replaced (old one stops working)');
    } elseif ($do === 'delete' && $id) {
        db()->prepare("UPDATE streams SET desired = 'off', server_id = NULL WHERE server_id = ?")->execute([$id]);
        db()->prepare('DELETE FROM servers WHERE id = ?')->execute([$id]);
        log_activity('Server removed');
        flash('Server removed. Its streams were stopped and need a new server.');
    } elseif ($do === 'rename' && $id) {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name !== '' && mb_strlen($name) <= 40) {
            db()->prepare('UPDATE servers SET name = ? WHERE id = ?')->execute([$name, $id]);
        }
    }
    redirect('servers.php');
}

start_session();
$newToken = $_SESSION['new_token'] ?? null;
unset($_SESSION['new_token']);

$servers = all_servers();
$counts  = [];
foreach (db()->query("SELECT server_id, COUNT(*) n, SUM(status = 'live') live FROM streams
                       WHERE server_id IS NOT NULL GROUP BY server_id") as $r) {
    $counts[(int) $r['server_id']] = $r;
}

$panelUrl = 'https://' . preg_replace('/[^A-Za-z0-9.:-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'ffteditt.store'));

page_start('Servers', 'servers', $admin);
?>
<div class="page-head">
  <div>
    <h1>Servers (VPS)</h1>
    <p class="muted">Each server sends video to YouTube. Add more servers when you need more streams at the same time.</p>
  </div>
</div>

<?php if ($newToken): ?>
<section class="card token-box" aria-live="polite">
  <h2>Install the worker on this server</h2>
  <p>Log in to the VPS with Terminal (<code>ssh root@YOUR_VPS_IP</code>) and paste this one line. It installs everything and connects the server to this panel.</p>
  <div class="copy-row">
    <code class="cmd" id="install-cmd">curl -fsSL <?= e($panelUrl) ?>/worker/install.sh | bash -s -- <?= e($panelUrl) ?> <?= e($newToken['token']) ?></code>
    <button class="btn btn-gold" type="button" data-copy="install-cmd">Copy</button>
  </div>
  <p class="warn-text">This command contains a secret token and is shown only once. Don't share it. If you lose it, press “New token”.</p>
</section>
<?php endif; ?>

<form method="post" class="card add-server">
  <?= csrf_field() ?>
  <input type="hidden" name="do" value="add">
  <label class="field grow"><span>New server name</span>
    <input name="name" required maxlength="40" placeholder="e.g. VPS 1 – New Jersey">
  </label>
  <button class="btn btn-pink" type="submit">+ Add server</button>
</form>

<?php if (!$servers): ?>
  <div class="card empty"><h2>No servers yet</h2><p class="muted">Add your VPS above, then run the install line on it.</p></div>
<?php else: ?>
<div class="grid">
  <?php foreach ($servers as $sid => $srv):
      $info   = json_decode($srv['info_json'] ?: '{}', true) ?: [];
      $online = server_online($srv);
      $c      = $counts[$sid] ?? ['n' => 0, 'live' => 0];
  ?>
  <section class="card">
    <div class="stream-top">
      <h2><?= e($srv['name']) ?></h2>
      <span class="badge <?= $online ? 'badge-live' : 'badge-muted' ?>"><?= $online ? 'Online' : 'Offline' ?></span>
    </div>
    <dl class="meta">
      <div><dt>Last check-in</dt><dd><?= e(ago((int) $srv['last_seen'])) ?></dd></div>
      <div><dt>Streams</dt><dd><?= (int) $c['live'] ?> live / <?= (int) $c['n'] ?> assigned</dd></div>
      <?php if ($info): ?>
      <div><dt>Machine</dt><dd><?= e($info['hostname'] ?? '?') ?> · <?= (int) ($info['cpus'] ?? 0) ?> CPU</dd></div>
      <div><dt>CPU load</dt><dd><?= e($info['load1'] ?? '—') ?></dd></div>
      <div><dt>Memory</dt><dd><?= (int) ($info['mem_used_mb'] ?? 0) ?> / <?= (int) ($info['mem_total_mb'] ?? 0) ?> MB</dd></div>
      <?php endif; ?>
    </dl>
    <div class="stream-actions">
      <form method="post" onsubmit="return confirm('Make a new token? The old one stops working and you must run the install line again.')">
        <?= csrf_field() ?><input type="hidden" name="do" value="rotate"><input type="hidden" name="id" value="<?= (int) $sid ?>">
        <button class="btn btn-ghost" type="submit">New token</button>
      </form>
      <form method="post" onsubmit="return confirm('Remove this server? Its streams will stop.')">
        <?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int) $sid ?>">
        <button class="btn btn-danger" type="submit">Remove</button>
      </form>
    </div>
  </section>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php page_end('admin.js');
