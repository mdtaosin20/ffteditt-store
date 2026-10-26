<?php
// Screen Notification: a card over the game on the lives — "subscribe / share / like" — shown when you press
// "Show now", and (if you want) by itself every few minutes. Works on every game.
require __DIR__ . '/../_boot.php';
$admin = require_login();

$c = promo_get();
if (isset($_GET['json'])) { header('Content-Type: application/json'); echo json_encode(promo_for_stream(0), JSON_UNESCAPED_UNICODE); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $clean = fn ($t, $n) => mb_substr(trim(str_replace(['<', '>', "\r", "\n"], ['', '', ' ', ' '], (string) $t)), 0, $n);
    $c['title'] = $clean($_POST['title'] ?? '', 40) ?: promo_defaults()['title'];
    $c['sub'] = $clean($_POST['sub'] ?? '', 60);
    $lines = [];
    for ($k = 0; $k < 3; $k++) {
        $b = $clean($_POST['b'][$k] ?? '', 18);
        if ($b === '') continue;
        $col = (string) ($_POST['c'][$k] ?? '#33f0ff');
        $lines[] = ['i' => $clean($_POST['i'][$k] ?? '', 4), 'b' => $b, 's' => $clean($_POST['s'][$k] ?? '', 60), 'r' => $clean($_POST['r'][$k] ?? '', 16),
                    'c' => preg_match('/^#[0-9a-f]{6}$/i', $col) ? $col : '#33f0ff'];
    }
    $c['lines'] = $lines;
    $c['secs'] = max(4, min(15, (int) ($_POST['secs'] ?? 7)));
    $c['every'] = in_array((int) ($_POST['every'] ?? 0), [0, 3, 5, 10, 15, 30], true) ? (int) $_POST['every'] : 0;
    if (($_POST['do'] ?? '') === 'reset') { $p = $c['push']; $c = promo_defaults(); $c['push'] = $p; }
    if (($_POST['do'] ?? '') === 'show') {
        $c['push'] = (int) $c['push'] + 1;
        $c['push_stream'] = (int) ($_POST['target'] ?? 0);
        log_activity('Screen notification shown on ' . ($c['push_stream'] ? 'one live' : 'all lives'), 'ok');
        flash('Sent ✓ — it shows on ' . ($c['push_stream'] ? 'that live' : 'every live') . ' within about 8 seconds.');
    } else {
        flash('Saved ✓');
    }
    promo_save($c);
    redirect('promo.php');
}
$lives = db()->query("SELECT id, name, channel_name, page_url FROM streams WHERE status = 'live' ORDER BY id")->fetchAll();
$colors = ['#ff2d55' => 'Red', '#33d17a' => 'Green', '#33f0ff' => 'Cyan', '#ffd23a' => 'Yellow', '#ff2bd6' => 'Pink', '#a78bfa' => 'Purple'];
page_start('Screen Notification', 'promo', $admin);
?>
<div class="page-head">
  <div>
    <h1>Screen Notification</h1>
    <p class="muted">A card over the game on your lives that reminds viewers to subscribe, share and like. Press <b>Show now</b> whenever you want, or let it show by itself every few minutes. It works on every game.</p>
  </div>
</div>

<div class="callout">In Flag Arena and Last Flag Standing: a viewer who subscribes, or shares the live and types <b>“shared” + their country</b> (or <b>“subscribed” + their country</b>), gets a <b>shield</b> for that country — or the country <b>comes back</b> if it is out. Subscribe once per viewer, share once every 10 minutes per viewer. (YouTube does not tell us about shares, so the game trusts the viewer.)</div>

<form method="post" class="card form" style="max-width:none">
  <?= csrf_field() ?>
  <div class="row2">
    <label class="field"><span>Title</span><input name="title" maxlength="40" value="<?= e($c['title']) ?>"></label>
    <label class="field"><span>Small line under the title</span><input name="sub" maxlength="60" value="<?= e($c['sub']) ?>"></label>
  </div>
  <h3 style="margin:14px 0 6px">Lines (leave the big word empty to hide a line)</h3>
  <?php for ($k = 0; $k < 3; $k++): $l = $c['lines'][$k] ?? ['i' => '', 'b' => '', 's' => '', 'r' => '', 'c' => '#33f0ff']; ?>
    <div class="row2" style="grid-template-columns:70px 1fr 2fr 1fr 120px;align-items:end;gap:8px;margin-bottom:6px">
      <label class="field"><span>Icon</span><input name="i[]" maxlength="4" value="<?= e($l['i']) ?>"></label>
      <label class="field"><span>Big word</span><input name="b[]" maxlength="18" value="<?= e($l['b']) ?>"></label>
      <label class="field"><span>Small text</span><input name="s[]" maxlength="60" value="<?= e($l['s']) ?>"></label>
      <label class="field"><span>Result</span><input name="r[]" maxlength="16" value="<?= e($l['r']) ?>"></label>
      <label class="field"><span>Colour</span><select name="c[]"><?php foreach ($colors as $hex => $nm): ?><option value="<?= $hex ?>" <?= strtolower($l['c']) === $hex ? 'selected' : '' ?>><?= $nm ?></option><?php endforeach; ?></select></label>
    </div>
  <?php endfor; ?>
  <div class="row2" style="margin-top:10px">
    <label class="field"><span>Show for (seconds)</span><input name="secs" type="number" min="4" max="15" value="<?= (int) $c['secs'] ?>"></label>
    <label class="field"><span>Show by itself</span><select name="every">
      <?php foreach ([0 => 'Only when I press “Show now”', 3 => 'Every 3 minutes', 5 => 'Every 5 minutes', 10 => 'Every 10 minutes', 15 => 'Every 15 minutes', 30 => 'Every 30 minutes'] as $v => $t): ?>
        <option value="<?= $v ?>" <?= (int) $c['every'] === $v ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></label>
  </div>
  <div class="form-actions" style="flex-wrap:wrap;gap:8px">
    <button class="btn btn-blue" type="submit" name="do" value="save">Save</button>
    <select name="target" style="width:auto"><option value="0">All lives (<?= count($lives) ?>)</option>
      <?php foreach ($lives as $lv): ?><option value="<?= (int) $lv['id'] ?>"><?= e(($lv['channel_name'] ?: $lv['name']) . ' · ' . (games_list()[game_of_url($lv['page_url'])]['name'] ?? '')) ?></option><?php endforeach; ?></select>
    <button class="btn btn-green" type="submit" name="do" value="show">📣 Save &amp; show now</button>
    <button class="btn btn-ghost" type="submit" name="do" value="reset" onclick="return confirm('Back to the ready-made texts?')">Ready-made texts</button>
    <a class="btn btn-ghost" href="/lastflag/?switchdemo=promo" target="_blank" rel="noopener">Preview ↗</a>
  </div>
</form>
<?php page_end();
