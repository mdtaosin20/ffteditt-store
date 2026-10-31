<?php
// Client Links: send one link — the client connects his own channel (stream key + Google) and picks a game;
// you see “Connected ✓” here and start / stop his live. He never sees this panel.
require __DIR__ . '/../_boot.php';
$admin = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $do = (string) ($_POST['do'] ?? '');
    $id = (string) ($_POST['lid'] ?? '');
    if ($do === 'make') {
        $v = cli_create((string) ($_POST['label'] ?? ''));
        log_activity('👥 Client link made' . ($v['label'] !== '' ? " for {$v['label']}" : ''), 'info');
        flash('Link ready ✓ — press Copy and send it.');
        redirect('clients.php#l' . $v['id']);
    }
    if ($do === 'cancel') {
        cli_update($id, ['off' => true]);
        flash('Link cancelled — it does not work any more. (His stream stays in Live Stream Manager.)');
    }
    if ($do === 'remove') {   // only for a link that was never filled or already cancelled
        cli_save(array_filter(cli_links(), fn ($v) => $v['id'] !== $id));
        flash('Removed from the list.');
    }
    redirect('clients.php');
}

$links = array_reverse(cli_links());
$games = games_list();
$servers = [];
foreach (db()->query('SELECT id, name, last_seen FROM servers') as $r) $servers[(int) $r['id']] = $r;
page_start('Client Links', 'clients', $admin);
?>
<div class="dv-head" style="background:linear-gradient(120deg,#0d2a1c,#10304a)">
  <div class="dv-hic">👥</div>
  <div><h1>Client Links</h1><p>Send one link. Your client fills his channel, stream key and the game, and connects his Google account — all from his own phone. Then you see <b>Connected ✓</b> here and start his live. He never sees your admin.</p></div>
</div>

<form method="post" class="card" style="max-width:none;display:flex;gap:10px;flex-wrap:wrap;align-items:center">
  <?= csrf_field() ?><input type="hidden" name="do" value="make">
  <input name="label" maxlength="40" placeholder="Who is it for? (e.g. Rahim)" style="flex:1;min-width:180px">
  <button class="btn btn-blue">🔗 Make link</button>
</form>
<?php if (!yt_ready()): ?><div class="flash flash-error">Google step: first finish step 1 in <a href="youtube.php">YouTube Manager</a> (Google Client ID and Secret). Until then clients can only give a stream key.</div><?php endif; ?>

<?php foreach ($links as $l):
    $s = (int) $l['sid'] > 0 ? cli_stream((int) $l['sid']) : null;
    $url = cli_url((string) $l['t']);
    $dead = !empty($l['off']) || (!$s && (int) $l['exp'] < time()) || ((int) $l['sid'] > 0 && !$s);
?>
  <div class="dv-row ow-row" id="l<?= e($l['id']) ?>">
  <?php if ($s):
      $g = $games[game_of_url($s['page_url'])]['name'] ?? 'Game';
      $on = $s['desired'] === 'on'; $run = $on && $s['status'] === 'running';
      $srv = $servers[(int) $s['server_id']] ?? null;
  ?>
    <div class="dv-ic" style="background:<?= $run ? '#7f1d1d' : ($s['stream_key'] !== '' ? '#14532d' : '#3a2a0a') ?>"><?= $run ? '🔴' : ($s['stream_key'] !== '' ? '✅' : '⚠️') ?></div>
    <div class="dv-txt">
      <div class="dv-nm"><?= e($s['channel_name'] ?: $s['name']) ?> <span style="font-size:11px;font-weight:700;padding:2px 8px;border-radius:99px;background:#1e3a8a;color:#dbeafe;margin-left:4px"><?= e($g) ?></span></div>
      <div class="dv-sm">
        <?= $s['stream_key'] !== '' ? '<b style="color:var(--green-t)">Key ✓ ••••' . e(substr($s['stream_key'], -4)) . '</b>' : '<b style="color:#fbbf24">No stream key yet</b>' ?>
        · <?= $s['yt_refresh'] !== '' ? '<b style="color:var(--green-t)">Google ✓ ' . e($s['yt_channel']) . '</b>' : '<span style="color:#fca5a5">Google ✗</span>' ?>
      </div>
      <div class="dv-sm"><?= $run ? '<b style="color:#f87171">LIVE</b> since ' . e(date('j M, g:i A', (int) $s['started_at'])) : ($on ? '⏳ Starting…' : 'Off') ?>
        · VPS: <?= $srv ? e($srv['name']) . (server_online($srv) ? '' : ' <span style="color:#fca5a5">(offline)</span>') : '<span style="color:#fca5a5">none — choose one in Edit</span>' ?>
        <?= !empty($l['filled']) ? ' · filled ' . e(date('j M, g:i A', (int) $l['filled'])) : '' ?></div>
      <?php if (!empty($l['off'])): ?><div class="dv-sm" style="color:#fca5a5">Link cancelled (his stream stays)</div><?php else: ?>
        <div class="ow-link"><input readonly value="<?= e($url) ?>" onclick="this.select()" aria-label="Link"><button class="btn btn-ghost btn-sm" type="button" data-copy="<?= e($url) ?>">Copy</button></div>
        <div class="dv-sm">He can open this link again to see if his live is on<?= $s['yt_refresh'] === '' ? ' — or to connect Google later' : '' ?>.</div>
      <?php endif; ?>
    </div>
    <div class="dv-act">
      <form method="post" action="stream_action.php"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $s['id'] ?>"><input type="hidden" name="back" value="clients.php">
        <?php if ($on): ?><button class="btn btn-ghost btn-sm dv-no" name="do" value="stop">■ Stop</button>
        <?php else: ?><button class="btn btn-green btn-sm" name="do" value="start" <?= $s['stream_key'] !== '' && $s['server_id'] ? '' : 'disabled' ?>>▶ Start live</button><?php endif; ?>
      </form>
      <a class="btn btn-ghost btn-sm" href="stream_edit.php?id=<?= (int) $s['id'] ?>">✏️ Edit</a>
      <?php if (empty($l['off'])): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="lid" value="<?= e($l['id']) ?>"><button class="btn btn-ghost btn-sm" name="do" value="cancel" onclick="return confirm('Cancel his link? His stream stays.')">Cancel link</button></form><?php endif; ?>
    </div>
  <?php else: ?>
    <div class="dv-ic" style="background:#3a2a0a"><?= $dead ? '✖' : '⏳' ?></div>
    <div class="dv-txt">
      <div class="dv-nm">For: <?= e($l['label'] !== '' ? $l['label'] : 'a client') ?></div>
      <?php if ($dead): ?><div class="dv-sm"><?= !empty($l['off']) ? 'Cancelled' : 'Not used — ended ' . e(date('j M', (int) $l['exp'])) ?></div>
      <?php else: ?>
        <div class="ow-link"><input readonly value="<?= e($url) ?>" onclick="this.select()" aria-label="Link">
          <button class="btn btn-green btn-sm" type="button" data-copy="<?= e($url) ?>">Copy</button>
          <a class="btn btn-ghost btn-sm" href="https://wa.me/?text=<?= rawurlencode('Connect your YouTube channel for the 24/7 game live: ' . $url) ?>" target="_blank" rel="noopener">WhatsApp</a></div>
        <div class="dv-sm">Waiting for him · works until <?= e(date('j M, g:i A', (int) $l['exp'])) ?></div>
      <?php endif; ?>
    </div>
    <div class="dv-act"><form method="post"><?= csrf_field() ?><input type="hidden" name="lid" value="<?= e($l['id']) ?>">
      <?php if ($dead): ?><button class="btn btn-ghost btn-sm" name="do" value="remove">Remove</button>
      <?php else: ?><button class="btn btn-ghost btn-sm dv-no" name="do" value="cancel">Cancel</button><?php endif; ?></form></div>
  <?php endif; ?>
  </div>
<?php endforeach; ?>
<?php if (!$links): ?><p class="muted">No links yet. Write a name above and press <b>Make link</b>.</p><?php endif; ?>

<div class="card" style="max-width:none;margin-top:18px">
  <h3 style="margin-top:0">Before you send a link (Google step)</h3>
  <p class="muted small" style="margin:0">While your Google app is in <b>Testing</b>, only Gmail addresses on its list can connect: ask the client for his Gmail → Google Cloud → <b>APIs &amp; Services → OAuth consent screen</b> (Audience) → <b>Test users → Add users</b> → his Gmail → Save. Then send the link. (Up to 100 test users.) Without it he can still give only the stream key.</p>
</div>
<script>
document.querySelectorAll('[data-copy]').forEach((b) => b.addEventListener('click', async () => {
  try { await navigator.clipboard.writeText(b.dataset.copy); } catch (e) { const i = b.parentNode.querySelector('input'); i.select(); document.execCommand('copy'); }
  const t = b.textContent; b.textContent = 'Copied ✓'; setTimeout(() => { b.textContent = t; }, 1800);
}));
</script>
<?php page_end();
