<?php
require __DIR__ . '/../_boot.php';
$admin = require_login();

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (($_POST['do'] ?? '') === 'lock') {
        terminal_lock();
        log_activity('Terminal locked');
        redirect('terminal.php');
    }
    $wait = login_locked_for();
    if ($wait > 0) {
        $error = 'Too many wrong tries. Try again in ' . ceil($wait / 60) . ' minutes.';
    } elseif (terminal_unlock((int) $admin['id'], (string) ($_POST['password'] ?? ''))) {
        redirect('terminal.php' . (isset($_POST['server']) ? '?server=' . (int) $_POST['server'] : ''));
    } else {
        $error = login_locked_for() > 0 ? 'Too many wrong tries. Locked for 15 minutes.' : 'Wrong password.';
    }
}

$servers  = all_servers();
$selected = (int) ($_GET['server'] ?? 0);
if (!isset($servers[$selected])) {
    $selected = $servers ? (int) array_key_first($servers) : 0;
}

// One-tap commands. The panel address is read on the VPS from its own config.
$quick = [
    ['Worker status',  'systemctl status ffteditt-worker --no-pager -n 0', ''],
    ['Worker log',     'journalctl -u ffteditt-worker -n 40 --no-pager -o short', ''],
    ['Streams running', 'ps -eo pid,pcpu,pmem,etime,args --sort=-pcpu | grep -E "ffmpeg|Xvfb|chrome" | grep -v grep | cut -c1-140 | head -15 || echo "Nothing running"', ''],
    ['CPU / RAM',      'uptime; echo; free -h; echo; echo "CPU cores: $(nproc)"', ''],
    ['Disk space',     'df -h /', ''],
    ['Restart worker', 'systemd-run --on-active=3 systemctl restart ffteditt-worker >/dev/null && echo "Worker restarts in 3 seconds. Running streams will start again by themselves."', 'Restart the worker? Live streams will reconnect.'],
    ['Update worker',  'P=$(python3 -c "import json;print(json.load(open(\'/etc/ffteditt-worker.json\'))[\'panel\'])") && curl -fsSL "$P/worker/agent.py" -o /tmp/fft-agent.py && python3 -m py_compile /tmp/fft-agent.py && mv /tmp/fft-agent.py /opt/ffteditt-worker/agent.py && systemd-run --on-active=3 systemctl restart ffteditt-worker >/dev/null && echo "Worker updated. It restarts in 3 seconds."', 'Download the newest worker and restart it?'],
    ['Reboot VPS',     'systemd-run --on-active=5 systemctl reboot >/dev/null && echo "VPS reboots in 5 seconds. It comes back online in about 1–2 minutes."', 'Reboot the whole VPS? All streams on it stop for 1–2 minutes.'],
];

page_start('Terminal', 'terminal', $admin);
?>
<div class="page-head">
  <div>
    <h1>Terminal</h1>
    <p class="muted">Run commands on your VPS from any phone or computer.</p>
  </div>
  <?php if (terminal_unlocked()): ?>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="lock">
    <button class="btn btn-ghost" type="submit">Lock terminal</button></form>
  <?php endif; ?>
</div>

<?php if (!$servers): ?>
  <div class="card empty"><h2>No servers yet</h2><p class="muted">Add a server first on the <a href="servers.php">Servers</a> page.</p></div>

<?php elseif (!terminal_unlocked()): ?>
  <form method="post" class="card form narrow">
    <h2>Enter your password to open the terminal</h2>
    <p class="muted" style="margin:0">The terminal has full control of your VPS, so it asks again. It locks itself after 15 minutes without a command.</p>
    <?php if ($error): ?><div class="flash flash-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <?= csrf_field() ?>
    <input type="hidden" name="server" value="<?= (int) $selected ?>">
    <label class="field"><span>Admin password</span>
      <input name="password" type="password" autocomplete="current-password" required autofocus>
    </label>
    <div class="form-actions"><button class="btn btn-gold" type="submit">Open terminal</button></div>
  </form>

<?php else: ?>
  <div id="term-app" data-csrf="<?= e(csrf_token()) ?>">
    <div class="term-top">
      <label class="field term-server"><span>Server</span>
        <select id="term-server">
          <?php foreach ($servers as $sid => $srv): ?>
            <option value="<?= (int) $sid ?>" <?= $sid === $selected ? 'selected' : '' ?>><?= e($srv['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <span class="badge badge-muted" id="term-state">Checking…</span>
    </div>

    <div class="quick" aria-label="One-tap commands">
      <?php foreach ($quick as [$label, $cmd, $confirm]): ?>
        <button type="button" class="btn btn-ghost btn-sm" data-cmd="<?= e($cmd) ?>" data-confirm="<?= e($confirm) ?>"><?= e($label) ?></button>
      <?php endforeach; ?>
    </div>

    <section class="term" aria-label="Terminal output">
      <div id="term-out" aria-live="polite"></div>
      <form id="term-form" class="term-input" autocomplete="off">
        <label for="term-cmd" class="term-prompt" id="term-prompt">root#</label>
        <input id="term-cmd" type="text" autocapitalize="off" autocorrect="off" spellcheck="false"
               placeholder="Type a command, e.g. df -h" maxlength="2000">
        <button class="btn btn-gold" type="submit">Run</button>
      </form>
    </section>
    <div class="term-foot">
      <p class="muted small">Commands run as root with a 60-second limit. Programs that wait for typing (nano, top, less) don't work here; use <code>top -bn1</code> instead of <code>top</code>. Every command is saved in Activity.</p>
      <button type="button" class="btn btn-ghost btn-sm" id="term-clear">Clear history</button>
    </div>
  </div>
<?php endif; ?>
<?php page_end(terminal_unlocked() && $servers ? 'terminal.js' : '');
