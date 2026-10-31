<?php
require __DIR__ . '/../_boot.php';
require APP_DIR . '/dash.php';
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
            $nid = (int) db()->lastInsertId();
            $_SESSION['new_token'] = ['id' => $nid, 'token' => $token];
            $rep = (int) ($_POST['replaces'] ?? 0);
            if ($rep > 0) {   // its streams move over when the new VPS checks in for the first time
                setting_set('srv_replace_' . $nid, (string) $rep);
                $_SESSION['new_token']['replaces'] = $rep;
            }
            log_activity("Server added: {$name}" . ($rep > 0 ? ' (replaces another VPS)' : ''));
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
    } elseif ($do === 'term_toggle' && $id) {
        $on = ($_POST['to'] ?? '') === 'on' ? 1 : 0;
        db()->prepare('UPDATE servers SET terminal_enabled = ? WHERE id = ?')->execute([$on, $id]);
        $st = db()->prepare('SELECT name FROM servers WHERE id = ?');
        $st->execute([$id]);
        log_activity('Terminal switched ' . ($on ? 'on' : 'off') . ' for ' . (string) $st->fetchColumn());
    } elseif ($do === 'share') {
        [$n, $live] = vps_share_load();
        log_activity("⚖️ Share the load: moved {$n} stream(s)" . ($live ? ", {$live} live (they start again in ~20 s)" : ''), 'info');
        flash($n ? "Done ✓ — moved {$n} stream(s)" . ($live ? ", {$live} of them live (back in about 20 seconds)" : '') . '.' : 'Already shared well — nothing to move.');
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

$srv  = dash_server();
$live = dash_live_data($srv);

$tab = tab_now('servers', $newToken ? 'list' : null);
page_start('VPS Monitor', 'servers', $admin);
?>
<div class="page-head">
  <div>
    <h1>VPS Monitor</h1>
    <p class="muted">Live numbers from your VPS (updated every 5 seconds), and the servers connected to this panel.</p>
  </div>
  <?php if ($tab === 'monitor' && count($servers) > 1): ?>
  <form class="srv-pick" method="get"><label><span class="muted small">Showing server</span>
    <select name="server" data-autosubmit>
      <?php foreach ($servers as $sid => $s): ?><option value="<?= (int) $sid ?>" <?= $srv && (int) $srv['id'] === $sid ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
    </select></label></form>
  <?php endif; ?>
</div>
<?= tab_bar('servers') ?>
<?php if ($tab === 'monitor'): ?>
<div class="dash" id="dash" data-server="<?= $srv ? (int) $srv['id'] : 0 ?>">
  <section class="kcards" data-live="cards"><?= dash_cards($srv) ?></section>
  <?= dash_monitor($srv, $live) ?>
</div>
<script type="application/json" id="dash-data"><?= json_encode($live) ?></script>
<?php else: ?>

<h2 class="sec-h">Servers</h2>

<?php if ($newToken): ?>
<section class="card token-box" aria-live="polite">
  <h2>Install the worker on this server</h2>
  <p>Log in to the VPS with Terminal (<code>ssh root@YOUR_VPS_IP</code>) and paste this one line. It installs everything and connects the server to this panel.</p>
  <div class="copy-row">
    <code class="cmd" id="install-cmd">curl -fsSL <?= e($panelUrl) ?>/worker/install.sh | bash -s -- <?= e($panelUrl) ?> <?= e($newToken['token']) ?></code>
    <button class="btn btn-gold" type="button" data-copy="install-cmd">Copy</button>
  </div>
  <p class="warn-text">This command contains a secret token and is shown only once. Don't share it. If you lose it, press “New token”.</p>
  <?php if (!empty($newToken['replaces']) && isset($servers[(int) $newToken['replaces']])): ?>
  <p>✅ When this VPS is online, all streams of <b><?= e($servers[(int) $newToken['replaces']]['name']) ?></b> move to it by themselves (live ones start again in about 20 seconds).</p>
  <?php endif; ?>
</section>
<?php endif; ?>

<form method="post" class="card add-server">
  <?= csrf_field() ?>
  <input type="hidden" name="do" value="add">
  <label class="field grow"><span>New server name</span>
    <input name="name" required maxlength="40" placeholder="e.g. VPS 1 – New Jersey">
  </label>
  <?php if ($servers): ?>
  <label class="field"><span>This VPS replaces</span>
    <select name="replaces">
      <option value="0">Nothing — just an extra VPS</option>
      <?php foreach ($servers as $sid => $s): ?><option value="<?= (int) $sid ?>"><?= e($s['name']) ?> (<?= (int) ($counts[$sid]['n'] ?? 0) ?> streams)</option><?php endforeach; ?>
    </select></label>
  <?php endif; ?>
  <button class="btn btn-blue" type="submit"><?= icon('plus') ?>Add server</button>
</form>

<?php $onlineN = count(array_filter($servers, 'server_online')); if ($onlineN >= 2): ?>
<form method="post" class="card" style="max-width:none;display:flex;gap:12px;align-items:center;flex-wrap:wrap" onsubmit="return confirm('Share the streams over your online VPS? Live streams that move stop for about 20 seconds and start again on the other VPS.')">
  <?= csrf_field() ?><input type="hidden" name="do" value="share">
  <div style="flex:1;min-width:220px"><b>⚖️ Share the load</b><div class="muted small">Spreads your streams over the <?= $onlineN ?> online VPS (a VPS with more CPUs gets more). Only as few streams as needed move.</div></div>
  <button class="btn btn-blue" type="submit">⚖️ Share the load</button>
</form>
<?php endif; ?>
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
      <span class="tag <?= $online ? 'tag-green' : 'tag-gray' ?>"><?= $online ? 'Online' : 'Offline' ?></span>
      <?php $rpl = json_decode((string) (setting_get('srv_replaced_' . $sid) ?? ''), true); if (is_array($rpl) && (int) ($c['n'] ?? 0) === 0): ?><span class="tag tag-gray" style="background:#3a2a0a;color:#fbbf24">Replaced — you can remove it</span><?php endif; ?>
      <?php if ((int) (setting_get('srv_replace_' . $sid) ?? 0) > 0): ?><span class="tag tag-gray">Waiting: will take over <?= e($servers[(int) setting_get('srv_replace_' . $sid)]['name'] ?? 'the old VPS') ?></span><?php endif; ?>
    </div>
    <dl class="meta">
      <div><dt>Last check-in</dt><dd><?= e(ago((int) $srv['last_seen'])) ?></dd></div>
      <div><dt>Streams</dt><dd><?= (int) $c['live'] ?> live / <?= (int) $c['n'] ?> assigned</dd></div>
      <div><dt>Terminal</dt><dd><?= (int) $srv['terminal_enabled'] === 1 ? 'On' : 'Off' ?></dd></div>
      <?php if ($info): ?>
      <div><dt>Machine</dt><dd><?= e($info['hostname'] ?? '?') ?> · <?= (int) ($info['cpus'] ?? 0) ?> CPU</dd></div>
      <div><dt>CPU load</dt><dd><?= e($info['load1'] ?? '—') ?></dd></div>
      <div><dt>Worker</dt><dd>v<?= e($info['agent_version'] ?? '?') ?><?= version_compare((string) ($info['agent_version'] ?? '0'), '1.4.0', '<') ? ' — <a href="terminal.php?server=' . (int) $sid . '">update it</a> for live graphs' : '' ?></dd></div>
      <div><dt>Memory</dt><dd><?= (int) ($info['mem_used_mb'] ?? 0) ?> / <?= (int) ($info['mem_total_mb'] ?? 0) ?> MB</dd></div>
      <?php endif; ?>
    </dl>
    <div class="stream-actions">
      <?php $termOn = (int) $srv['terminal_enabled'] === 1; ?>
      <a class="btn btn-ghost" href="terminal.php?server=<?= (int) $sid ?>">Terminal</a>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="do" value="term_toggle"><input type="hidden" name="id" value="<?= (int) $sid ?>">
        <input type="hidden" name="to" value="<?= $termOn ? 'off' : 'on' ?>">
        <button class="btn btn-ghost" type="submit"><?= $termOn ? 'Turn terminal off' : 'Turn terminal on' ?></button>
      </form>
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
<?php endif; ?>
<?php page_end();
