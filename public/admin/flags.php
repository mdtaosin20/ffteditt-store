<?php
require __DIR__ . '/../_boot.php';
$admin = require_login();

$cfg    = flags_config();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $do = (string) ($_POST['do'] ?? 'save');

    if ($do === 'reset') {
        $cfg['reset'] = bin2hex(random_bytes(4));
        flags_save($cfg);
        log_activity('Flag Battle tournament restarted');
        flash('Tournament restarted. Live Flag Battle pages start again from round 1 within about 20 seconds.');
        redirect('flags.php');
    }

    $new   = $cfg;
    $title = strtoupper(trim((string) ($_POST['title'] ?? '')));
    if ($title === '' || mb_strlen($title) > 28) {
        $errors[] = 'Title is needed (max 28 letters).';
    } else {
        $new['title'] = $title;
    }
    $t = (int) ($_POST['target'] ?? 0);
    if ($t < 2 || $t > 150) {
        $errors[] = 'Countries in the final must be between 2 and 150.';
    } else {
        $new['target'] = $t;
    }
    foreach (['ring' => 'Ring speed', 'speed' => 'Flag speed', 'gap' => 'Gap size'] as $k => $label) {
        $v = (int) ($_POST[$k] ?? 0);
        if ($v < 30 || $v > 300) {
            $errors[] = "{$label} must be between 30 and 300.";
        } else {
            $new[$k] = $v;
        }
    }

    if (!$errors) {
        $new['rev'] = bin2hex(random_bytes(4));
        flags_save($new);
        log_activity('Flag Battle settings saved');
        flash('Saved. Live Flag Battle pages update within about 20 seconds.');
        redirect('flags.php');
    }
    $cfg = $new;
}

page_start('Flag Battle', 'flags', $admin);
?>
<div class="page-head">
  <div>
    <h1>Country Flag Battle</h1>
    <p class="muted">195 country flags bounce inside a turning ring. A flag that slips through the gap is out. The last flag left wins the round and goes to the final. When the final is full, the final round finds the champion, then a new tournament starts.</p>
  </div>
  <div class="head-actions">
    <a class="btn btn-ghost" href="/flags/" target="_blank" rel="noopener">Open game ↗</a>
  </div>
</div>

<?php foreach ($errors as $err): ?>
  <div class="flash flash-error" role="alert"><?= e($err) ?></div>
<?php endforeach; ?>

<div class="callout">To stream this game: <strong>Streams</strong> → edit a stream → <strong>Page to stream</strong> → choose <strong>Country Flag Battle</strong> (<code>https://ffteditt.store/flags/</code>). On a 1-CPU server it runs smoothly.</div>

<form method="post" class="form-wide">
  <?= csrf_field() ?>
  <section class="card">
    <div class="row2">
      <label class="field"><span>Title at the bottom</span>
        <input name="title" maxlength="28" required value="<?= e($cfg['title']) ?>">
      </label>
      <label class="field"><span>Countries in the final</span>
        <input name="target" type="number" min="2" max="150" required value="<?= (int) $cfg['target'] ?>">
        <small class="muted">One country qualifies per round (about 1–2 minutes each).</small>
      </label>
      <label class="field"><span>Ring speed (%)</span>
        <input name="ring" type="number" min="30" max="300" required value="<?= (int) $cfg['ring'] ?>">
        <small class="muted">100 = normal. Higher turns faster.</small>
      </label>
      <label class="field"><span>Flag speed (%)</span>
        <input name="speed" type="number" min="30" max="300" required value="<?= (int) $cfg['speed'] ?>">
        <small class="muted">Higher = flags move faster, rounds end sooner.</small>
      </label>
      <label class="field"><span>Gap size (%)</span>
        <input name="gap" type="number" min="30" max="300" required value="<?= (int) $cfg['gap'] ?>">
        <small class="muted">Bigger gap = flags get out faster, shorter rounds.</small>
      </label>
    </div>
    <div class="form-actions"><button class="btn btn-pink" type="submit">Save settings</button></div>
  </section>
</form>

<section class="card form-inline">
  <div>
    <h2>Tournament</h2>
    <p class="muted" style="margin:4px 0 0">Start again from round 1 with all 195 countries.</p>
  </div>
  <form method="post" onsubmit="return confirm('Restart the tournament on all live Flag Battle pages?')">
    <?= csrf_field() ?><input type="hidden" name="do" value="reset">
    <button class="btn btn-outline-pink" type="submit">Restart tournament</button>
  </form>
</section>

<p class="muted small">The commentator lines for this game are on the <a href="voice.php">Voice</a> page under “Country Flag Battle”. Flag pictures: flag-icons (MIT licence).</p>
<?php page_end();
