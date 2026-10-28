<?php
// Live Poll & Message: start a poll in the YouTube live chat, end it and see the votes,
// or send a message as the channel (then pin it from the phone). Polls and messages can be saved for later.
require __DIR__ . '/../_boot.php';
$admin = require_login();

$lives = ytp_streams();
$sid = (int) ($_GET['s'] ?? $_POST['s'] ?? 0);
$cur = null;
foreach ($lives as $l) if ((int) $l[0]['id'] === $sid) $cur = $l;
if (!$cur) foreach ($lives as $l) if ($l[1]) { $cur = $l; break; }
if (!$cur && $lives) $cur = $lives[0];
$sid = $cur ? (int) $cur[0]['id'] : 0;
$back = 'yt_poll.php' . ($sid ? '?s=' . $sid : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $do = (string) ($_POST['do'] ?? '');
    $i = (int) ($_POST['i'] ?? -1);
    $saved = ytp_saved();
    $needLive = in_array($do, ['start_saved', 'start_new', 'end', 'send_saved', 'send_new'], true);
    try {
        if ($needLive && (!$cur || !$cur[1])) throw new RuntimeException($cur ? 'This live is ' . $cur[2] . '.' : 'No live is running right now.');
        $s = $cur[0] ?? null;
        switch ($do) {
            case 'start_saved':
                if (!isset($saved['polls'][$i])) throw new RuntimeException('That poll is not in the list any more.');
                ytp_start_poll($s, $saved['polls'][$i]);
                flash('Poll started in the live chat ✓');
                break;
            case 'start_new':
            case 'save_poll':
                [$p, $err] = ytp_clean_poll((string) ($_POST['q'] ?? ''), (array) ($_POST['o'] ?? []));
                if (!$p) throw new RuntimeException($err);
                if ($do === 'start_new') ytp_start_poll($s, $p);
                if ($do === 'save_poll' || isset($_POST['keep'])) {
                    $saved['polls'][] = $p;
                    ytp_save($saved);
                }
                flash($do === 'start_new' ? 'Poll started in the live chat ✓' : 'Poll saved ✓ — start it any time from the list.');
                break;
            case 'del_poll':
                array_splice($saved['polls'], $i, 1);
                ytp_save($saved);
                flash('Poll removed from the list.');
                break;
            case 'end':
                ytp_end_poll($s);
                flash('Poll ended ✓ — the votes are below.');
                break;
            case 'send_saved':
                if (!isset($saved['msgs'][$i])) throw new RuntimeException('That message is not in the list any more.');
                ytp_say($s, $saved['msgs'][$i]);
                flash('Message sent ✓ — now pin it: open the live chat on your phone, press and hold the message → Pin.');
                break;
            case 'send_new':
            case 'save_msg':
                [$m, $err] = ytp_clean_msg((string) ($_POST['m'] ?? ''));
                if ($m === null) throw new RuntimeException($err);
                if ($do === 'send_new') ytp_say($s, $m);
                if ($do === 'save_msg' || isset($_POST['keep'])) {
                    $saved['msgs'][] = $m;
                    ytp_save($saved);
                }
                flash($do === 'send_new' ? 'Message sent ✓ — now pin it: open the live chat on your phone, press and hold the message → Pin.' : 'Message saved ✓');
                break;
            case 'del_msg':
                array_splice($saved['msgs'], $i, 1);
                ytp_save($saved);
                flash('Message removed from the list.');
                break;
        }
    } catch (Throwable $e) {
        flash($e->getMessage(), 'error');
    }
    redirect($back);
}

$saved = ytp_saved();
$active = $cur && $cur[1] ? ytp_active($cur[0]) : null;
$last = $sid ? json_decode((string) (setting_get('ytp_last_' . $sid) ?? ''), true) : null;
$ok = $cur && $cur[1];
$tab = tab_now('poll');
page_start('Live Poll & Message', 'poll', $admin);
$hid = static fn () => csrf_field() . '<input type="hidden" name="s" value="' . $sid . '">';
?>
<style>
.yp { max-width: 760px; }
.yp-list { display: grid; gap: 10px; margin-top: 10px; }
.yp-item { display: flex; gap: 12px; align-items: flex-start; padding: 12px 14px; border: 1px solid var(--line, #2a3a55); border-radius: 10px; }
.yp-item .t { flex: 1; min-width: 0; }
.yp-item b { display: block; margin-bottom: 4px; }
.yp-item .o { display: flex; flex-wrap: wrap; gap: 6px; }
.yp-item .o span { font-size: 13px; padding: 2px 9px; border-radius: 999px; background: rgba(255,255,255,.08); }
.yp-item pre { margin: 0; white-space: pre-wrap; font: inherit; font-size: 14px; line-height: 1.4; }
.yp-item .act { display: flex; gap: 6px; flex: 0 0 auto; }
.yp-item .act form { margin: 0; }
.yp-active { border: 2px solid #22c55e; border-radius: 12px; padding: 14px; margin-bottom: 14px; background: rgba(34,197,94,.08); }
.yp-res { display: grid; gap: 6px; margin-top: 8px; }
.yp-res div { display: flex; align-items: center; gap: 10px; font-size: 14px; }
.yp-res i { flex: 1; height: 8px; border-radius: 6px; background: rgba(255,255,255,.1); overflow: hidden; }
.yp-res i u { display: block; height: 100%; background: #3b82f6; }
.yp-opts { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.yp-count { font-size: 12.5px; text-align: right; }
.yp-count.bad { color: #f87171; font-weight: 700; }
@media (max-width: 560px) { .yp-opts { grid-template-columns: 1fr; } .yp-item { flex-direction: column; } }
</style>
<div class="page-head">
  <div>
    <h1>Live Poll &amp; Message</h1>
    <p class="muted">Start a poll in your YouTube live chat, or send a message as your channel (then pin it from your phone). Save them once and use them again with one press.</p>
  </div>
</div>

<?= tab_bar('poll') ?>
<section class="panel yp">
  <label class="field"><span>Which live?</span>
    <select onchange="location.href='yt_poll.php?s='+this.value">
      <?php if (!$lives): ?><option>No live is running right now</option><?php endif; ?>
      <?php foreach ($lives as [$s, $ready, $why]): ?>
        <option value="<?= (int) $s['id'] ?>" <?= (int) $s['id'] === $sid ? 'selected' : '' ?>><?= $ready ? '🔴 ' : '⏳ ' ?><?= e($s['channel_name'] ?: $s['name']) ?> · <?= e(game_of_url($s['page_url'])) ?></option>
      <?php endforeach; ?>
    </select></label>
  <?php if ($cur && !$cur[1]): ?><p class="muted small" style="margin-top:8px">⚠️ This live is <?= e($cur[2]) ?>.</p><?php endif; ?>
  <div style="margin-top:12px"><?= yt_units_box() ?></div>
  <?php if (!$lives): ?><p class="muted small" style="margin-top:8px">Start a live first. You can still write and save polls and messages below.</p><?php endif; ?>
</section>

<?php if ($tab === 'polls'): ?>
<section class="panel yp">
  <h2>📊 Polls</h2>
  <?php if ($active): ?>
    <div class="yp-active">
      <b>🟢 Running now:</b> <?= e($active['q']) ?> <span class="muted small">(started <?= e(date('H:i', (int) $active['at'])) ?>)</span>
      <form method="post" style="margin-top:10px"><?= $hid() ?><input type="hidden" name="do" value="end">
        <button class="btn btn-danger btn-sm" type="submit">⏹ End poll &amp; show votes</button></form>
    </div>
  <?php endif; ?>
  <?php if (is_array($last) && !empty($last['res'])): $tot = array_sum(array_map(fn ($r) => (int) $r[1], $last['res'])); ?>
    <div class="yp-item" style="display:block;margin-bottom:14px">
      <b>Last poll: <?= e($last['q']) ?></b>
      <div class="yp-res">
        <?php foreach ($last['res'] as [$o, $n]): $pc = $tot > 0 && $n !== null ? round($n / $tot * 100) : 0; ?>
          <div><span style="flex:0 0 46%"><?= e($o) ?></span><i><u style="width:<?= $pc ?>%"></u></i><b style="margin:0"><?= $n === null ? '–' : (int) $n . ' (' . $pc . '%)' ?></b></div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <h3>Saved polls</h3>
  <div class="yp-list">
    <?php if (!$saved['polls']): ?><p class="muted small">No saved polls. Write one below and press "Save for later".</p><?php endif; ?>
    <?php foreach ($saved['polls'] as $i => $p): ?>
      <div class="yp-item">
        <div class="t"><b><?= e($p['q']) ?></b><div class="o"><?php foreach ($p['o'] as $o): ?><span><?= e($o) ?></span><?php endforeach; ?></div></div>
        <div class="act">
          <form method="post"><?= $hid() ?><input type="hidden" name="do" value="start_saved"><input type="hidden" name="i" value="<?= $i ?>">
            <button class="btn btn-blue btn-sm" type="submit" <?= $ok && !$active ? '' : 'disabled' ?>>▶ Start</button></form>
          <form method="post" onsubmit="return confirm('Remove this poll from the list?')"><?= $hid() ?><input type="hidden" name="do" value="del_poll"><input type="hidden" name="i" value="<?= $i ?>">
            <button class="btn btn-ghost btn-sm" type="submit" title="Remove"><?= icon('trash') ?></button></form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <h3 style="margin-top:22px">New poll</h3>
  <form method="post" class="yp-new"><?= $hid() ?>
    <label class="field"><span>Question</span><input name="q" data-max="<?= YTP_Q_MAX ?>" placeholder="Which country wins today? 🏆" required><small class="yp-count muted"></small></label>
    <div class="yp-opts" style="margin-top:8px">
      <?php for ($k = 1; $k <= 4; $k++): ?>
        <label class="field"><span>Answer <?= $k ?><?= $k > 2 ? ' (can be empty)' : '' ?></span><input name="o[]" data-max="<?= YTP_O_MAX ?>" <?= $k <= 2 ? 'required' : '' ?>><small class="yp-count muted"></small></label>
      <?php endfor; ?>
    </div>
    <label style="display:flex;gap:8px;align-items:center;margin-top:10px"><input type="checkbox" name="keep" value="1" checked> Also save it in the list</label>
    <div class="form-actions" style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap">
      <button class="btn btn-blue" type="submit" name="do" value="start_new" <?= $ok && !$active ? '' : 'disabled' ?>>▶ Start now</button>
      <button class="btn btn-ghost" type="submit" name="do" value="save_poll" formnovalidate>💾 Save for later</button>
    </div>
  </form>
</section>

<?php endif; ?>
<?php if ($tab === 'message'): ?>
<section class="panel yp">
  <h2>💬 Chat message (for the pin)</h2>
  <p class="muted small">The message goes into the live chat from your channel. To pin it: open the live chat on your phone, press and hold the message → <b>Pin</b>.</p>
  <h3>Saved messages</h3>
  <div class="yp-list">
    <?php if (!$saved['msgs']): ?><p class="muted small">No saved messages.</p><?php endif; ?>
    <?php foreach ($saved['msgs'] as $i => $m): ?>
      <div class="yp-item">
        <div class="t"><pre><?= e(ytp_fill($m)) ?></pre></div>
        <div class="act">
          <form method="post"><?= $hid() ?><input type="hidden" name="do" value="send_saved"><input type="hidden" name="i" value="<?= $i ?>">
            <button class="btn btn-blue btn-sm" type="submit" <?= $ok ? '' : 'disabled' ?>>➤ Send</button></form>
          <form method="post" onsubmit="return confirm('Remove this message from the list?')"><?= $hid() ?><input type="hidden" name="do" value="del_msg"><input type="hidden" name="i" value="<?= $i ?>">
            <button class="btn btn-ghost btn-sm" type="submit" title="Remove"><?= icon('trash') ?></button></form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <h3 style="margin-top:22px">New message</h3>
  <form method="post"><?= $hid() ?>
    <label class="field"><span>Message</span><textarea name="m" rows="7" data-max="<?= YTP_MSG_MAX ?>" required></textarea><small class="yp-count muted"></small></label>
    <label style="display:flex;gap:8px;align-items:center;margin-top:10px"><input type="checkbox" name="keep" value="1" checked> Also save it in the list</label>
    <div class="form-actions" style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap">
      <button class="btn btn-blue" type="submit" name="do" value="send_new" <?= $ok ? '' : 'disabled' ?>>➤ Send now</button>
      <button class="btn btn-ghost" type="submit" name="do" value="save_msg">💾 Save for later</button>
    </div>
  </form>
  <p class="muted small" style="margin-top:12px">Each poll or message uses 20 of YouTube's 10,000 daily units — a few a day is no problem.</p>
</section>
<?php endif; ?>
<script>
// letters left (YouTube counts an emoji as 2, like here)
document.querySelectorAll('[data-max]').forEach((el) => {
  const out = el.parentElement.querySelector('.yp-count'), max = +el.dataset.max;
  const upd = () => { const n = el.value.length; out.textContent = n ? `${n} / ${max}` : ''; out.classList.toggle('bad', n > max); };
  el.addEventListener('input', upd); upd();
});
</script>
<?php page_end(); ?>
