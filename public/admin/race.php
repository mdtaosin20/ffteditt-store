<?php
require __DIR__ . '/../_boot.php';
$admin = require_login();

$cfg    = race_config();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $do = (string) ($_POST['do'] ?? 'save');

    if ($do === 'reset') {
        $cfg['reset'] = bin2hex(random_bytes(4));
        race_save($cfg);
        log_activity('Flag Race restarted');
        flash('Flag Race starts again from race 1 within about 20 seconds (today\'s wins are cleared).');
        redirect('race.php');
    }

    $new   = $cfg;
    $title = mb_strtoupper(trim((string) ($_POST['title'] ?? '')));
    if ($title === '' || mb_strlen($title) > 24) {
        $errors[] = 'Title is needed (max 24 letters).';
    } else {
        $new['title'] = $title;
    }
    foreach ([['racers', 'Flags in a race', 6, 12], ['secs', 'Race length', 30, 180], ['boost', 'Boost strength', 20, 400]] as [$k, $label, $min, $max]) {
        $v = (int) ($_POST[$k] ?? 0);
        if ($v < $min || $v > $max) {
            $errors[] = "{$label} must be between {$min} and {$max}.";
        } else {
            $new[$k] = $v;
        }
    }

    if (!$errors) {
        $new['rev'] = bin2hex(random_bytes(4));
        race_save($new);
        log_activity('Flag Race settings saved');
        flash('Saved. Live Flag Race pages use it after the current race.');
        redirect('race.php');
    }
    $cfg = $new;
}

page_start('Flag Race', 'games', $admin);
?>
<div class="page-head">
  <div>
    <h1>Flag Race</h1>
    <p class="muted">Country flags race up the track to the finish line. Every live-chat comment with a country is a ⚡ boost for it (more people typing = faster), a new subscriber gives a 🚀 turbo, a Super Chat a 🚀🚀 mega turbo. About one race a minute, then the podium and the next race. Countries asked for in chat, and the ones whose people chat most, get the lanes; today's wins are counted. LUNA hosts.</p>
  </div>
  <div class="head-actions">
    <a class="btn btn-ghost" href="/race/" target="_blank" rel="noopener">Open game ↗</a>
    <a class="btn btn-ghost" href="/race/?chatdemo=1" target="_blank" rel="noopener">Preview with demo chat ↗</a>
  </div>
</div>

<?php foreach ($errors as $err): ?>
  <div class="flash flash-error" role="alert"><?= e($err) ?></div>
<?php endforeach; ?>

<div class="callout">To stream this game: <strong>Live Stream Manager</strong> → edit a stream → <strong>Page to stream</strong> → choose <strong>Flag Race</strong> (<code>https://ffteditt.store/race/</code>). Live chat must be on (YouTube Manager) — this game is played by the chat.</div>

<form method="post" class="form-wide">
  <?= csrf_field() ?>
  <section class="card">
    <div class="row2">
      <label class="field"><span>Title at the top</span>
        <input name="title" maxlength="24" required value="<?= e($cfg['title']) ?>">
      </label>
      <label class="field"><span>Flags in a race</span>
        <input name="racers" type="number" min="6" max="12" required value="<?= (int) $cfg['racers'] ?>">
        <small class="muted">10 = best on phones.</small>
      </label>
      <label class="field"><span>Race length (seconds)</span>
        <input name="secs" type="number" min="30" max="180" required value="<?= (int) $cfg['secs'] ?>">
        <small class="muted">When nobody chats. Chat boosts make races faster.</small>
      </label>
      <label class="field"><span>Boost strength (%)</span>
        <input name="boost" type="number" min="20" max="400" required value="<?= (int) $cfg['boost'] ?>">
        <small class="muted">100 = normal. Higher = one chat pushes a flag further.</small>
      </label>
    </div>
    <div class="form-actions"><button class="btn btn-pink" type="submit">Save settings</button></div>
  </section>
</form>

<section class="card form-inline">
  <div>
    <h2>Start again</h2>
    <p class="muted" style="margin:4px 0 0">Back to race 1; today's wins are cleared.</p>
  </div>
  <form method="post" onsubmit="return confirm('Start Flag Race again on all live pages?')">
    <?= csrf_field() ?><input type="hidden" name="do" value="reset">
    <button class="btn btn-outline-pink" type="submit">Start again</button>
  </form>
</section>
<?php page_end();
