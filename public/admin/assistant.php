<?php
require __DIR__ . '/../_boot.php';
require APP_DIR . '/assistant.php';
$admin = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (($_POST['do'] ?? '') === 'as_cfg') {
        $key = trim((string) ($_POST['key'] ?? ''));
        if ($key !== '' && !preg_match('/^sk-ant-[A-Za-z0-9_\-]{20,200}$/', $key)) {
            flash('That API key looks wrong. It starts with sk-ant-. Copy it again from console.anthropic.com.', 'error');
            redirect('assistant.php?tab=settings');
        }
        if ($key !== '') setting_set('as_key', $key);
        if (isset($_POST['forget_key'])) setting_set('as_key', '');
        as_save_cfg([
            'on'    => isset($_POST['on']),
            'model' => isset(AS_MODELS[$_POST['model'] ?? '']) ? $_POST['model'] : 'claude-sonnet-5-5',
            'daily' => max(0.1, min(50.0, (float) ($_POST['daily'] ?? 2))),
        ]);
        log_activity('Ask Claude settings saved', 'ok');
        flash(as_key() === '' ? 'Saved. Paste your API key to use Ask Claude.' : 'Saved.');
    }
    redirect('assistant.php');
}

$tab = tab_now('assistant');
$c = as_cfg();
$u = as_use();
page_start('Ask Claude', 'assistant', $admin);
?>
<div class="page-head"><div><h1>🤖 Ask Claude</h1><p class="muted">Tell Claude a problem in Bangla. It looks at your whole panel, finds the cause and fixes it — every change waits for your “Yes”.</p></div></div>
<?= tab_bar('assistant') ?>

<?php if ($tab === 'chat'): ?>
<div class="cl-wrap" id="cl" data-csrf="<?= e(csrf_token()) ?>">
  <div class="cl-top">
    <span class="cl-pill">Model: <b><?= e(explode(' (', AS_MODELS[$c['model']]['label'])[0]) ?></b></span>
    <span class="cl-pill">Today: <b id="cl-usd">$<?= e(number_format((float) $u['usd'], 2)) ?></b> of $<?= e(number_format((float) $c['daily'], 2)) ?></span>
    <button type="button" class="btn btn-ghost btn-sm" id="cl-new">＋ New chat</button>
  </div>
  <?php if (as_key() === '' || !$c['on']): ?>
    <div class="flash flash-error">Ask Claude is not ready: <a href="assistant.php?tab=settings">open ⚙️ Settings</a> and paste your Anthropic API key<?= $c['on'] ? '' : ' (and tick “Use Ask Claude”)' ?>.</div>
  <?php endif; ?>
  <div class="cl-chat" id="cl-chat"><div class="cl-empty muted">Write a problem below, e.g. “Trollzen লাইভে চ্যাট কাউন্ট হচ্ছে না”.</div></div>
  <div class="cl-q" id="cl-q">
    <button type="button">সব লাইভ ঠিক আছে কিনা দেখো</button><button type="button">VPS কেন ধীর?</button>
    <button type="button">কেন দর্শক কমছে?</button><button type="button">শেষ ১ ঘণ্টার সমস্যাগুলো দেখাও</button>
  </div>
  <form class="cl-in" id="cl-form"><textarea id="cl-text" rows="2" maxlength="2000" placeholder="সমস্যাটা লিখুন…"></textarea><button class="btn btn-blue" type="submit" id="cl-send">Send</button></form>
  <p class="muted small">Claude can look at: lives, YouTube, live chat, VPS, logs and the audience report. It can restart / start / stop / move a live, reopen its chat, press “Go live” on YouTube, change quality and turn panel switches on or off — only after you press Yes. It cannot delete anything, see keys or passwords, or change the code.</p>
</div>
<?php elseif ($tab === 'log'): ?>
<section class="panel">
  <div class="ph"><h2>📜 What Claude did</h2></div>
  <?php $did = as_did(); if (!$did): ?><p class="muted">Nothing yet.</p><?php else: ?>
  <div class="tbl-wrap"><table class="tbl tbl-sm"><thead><tr><th>When</th><th>What</th><th>Result</th></tr></thead><tbody>
  <?php foreach ($did as $d): ?>
    <tr><td class="small muted" style="white-space:nowrap"><?= e(date('d M H:i', (int) $d['ts'])) ?></td><td class="bn"><?= e($d['what']) ?></td>
      <td class="small <?= $d['ok'] ? 'ok-text' : 'warn-text' ?>"><?= $d['ok'] ? '✓ ' : '✗ ' ?><?= e($d['result']) ?></td></tr>
  <?php endforeach; ?></tbody></table></div><?php endif; ?>
</section>
<?php else: ?>
<section class="panel">
  <div class="ph"><h2>⚙️ Settings</h2><?= as_key() !== '' ? '<span class="tag tag-green">Key saved</span>' : '<span class="tag tag-orange">No key yet</span>' ?></div>
  <ol class="steps">
    <li>Open <a href="https://console.anthropic.com" target="_blank" rel="noopener">console.anthropic.com</a> and log in (make an account if needed).</li>
    <li><strong>Billing</strong> → add some credit (for example $5).</li>
    <li><strong>API Keys</strong> → <strong>Create Key</strong> → name it <code>GameStream</code> → copy it (it starts with <code>sk-ant-</code>).</li>
    <li>Paste it below → <strong>Save</strong>. Paste it only here — never in a chat.</li>
  </ol>
  <form method="post" class="form" style="margin-top:12px">
    <?= csrf_field() ?><input type="hidden" name="do" value="as_cfg">
    <label class="check"><input type="checkbox" name="on" <?= $c['on'] ? 'checked' : '' ?>> Use Ask Claude</label>
    <div class="row2">
      <label class="field"><span>Anthropic API key</span><input name="key" type="password" autocomplete="off" spellcheck="false" placeholder="<?= as_key() !== '' ? 'Saved — leave empty to keep it' : 'sk-ant-…' ?>"></label>
      <label class="field"><span>Model</span><select name="model"><?php foreach (AS_MODELS as $k => $m): ?><option value="<?= e($k) ?>" <?= $c['model'] === $k ? 'selected' : '' ?>><?= e($m['label']) ?> — $<?= e((string) $m['in']) ?> / $<?= e((string) $m['out']) ?> per 1M tokens</option><?php endforeach; ?></select></label>
      <label class="field"><span>Daily limit (US$) — Claude stops for the day when it is reached</span><input name="daily" type="number" step="0.5" min="0.1" max="50" value="<?= e((string) $c['daily']) ?>"></label>
    </div>
    <?php if (as_key() !== ''): ?><label class="check"><input type="checkbox" name="forget_key"> Delete the saved key</label><?php endif; ?>
    <div class="form-actions"><button class="btn btn-blue" type="submit">Save</button></div>
  </form>
  <p class="small" style="margin-top:10px">Today: <b>$<?= e(number_format((float) $u['usd'], 3)) ?></b> · <?= (int) $u['calls'] ?> answers. One question usually costs a few cents.</p>
</section>
<?php endif; ?>
<?php page_end('assistant.js');
