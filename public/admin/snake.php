<?php
require __DIR__ . '/../_boot.php';
$admin = require_login();

$cfg    = snake_config();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $do = (string) ($_POST['do'] ?? 'save');

    if ($do === 'reset') {
        $cfg['reset'] = bin2hex(random_bytes(4));
        snake_save($cfg);
        log_activity('Snake Live restarted');
        flash('Snake Live starts again from round 1 within about 30 seconds (best score and today\'s fruits are cleared).');
        redirect('snake.php');
    }

    $new   = $cfg;
    $title = mb_strtoupper(trim((string) ($_POST['title'] ?? '')));
    if ($title === '' || mb_strlen($title) > 20) $errors[] = 'Title is needed (max 20 letters).';
    else $new['title'] = $title;
    foreach ([['speed', 'Snake speed', 3, 20], ['grid', 'Board size', 12, 30], ['cooldown', 'Wait per viewer', 0, 120], ['named', 'Name fruits at once', 1, 20], ['gold', 'Golden fruit', 1, 10]] as [$k, $label, $min, $max]) {
        $v = (int) ($_POST[$k] ?? -1);
        if ($v < $min || $v > $max) $errors[] = "{$label} must be between {$min} and {$max}.";
        else $new[$k] = $v;
    }
    foreach (['chat', 'eat_sound', 'music'] as $k) $new[$k] = !empty($_POST[$k]);
    $new['chan'] = mb_substr(trim((string) ($_POST['chan'] ?? '')), 0, 40);

    if (!$errors) {
        $new['rev'] = bin2hex(random_bytes(4));
        snake_save($new);
        log_activity('Snake Live settings saved');
        flash('Saved. Live Snake pages use the new speed within about 30 seconds; a new board size starts with the next round.');
        redirect('snake.php');
    }
    $cfg = $new;
}

page_start('Snake Live', 'games', $admin);
?>
<div class="page-head">
  <div>
    <h1>Snake Live</h1>
    <p class="muted">A snake plays by itself and eats fruit, 24/7. Every live-chat message drops a fruit with the viewer's name on the board, a new subscriber drops a 🌟 golden fruit. When the snake gets stuck a new round starts. LUNA welcomes new viewers and answers the chat.</p>
  </div>
  <div class="head-actions">
    <a class="btn btn-ghost" href="/snake/" target="_blank" rel="noopener">Open game ↗</a>
    <a class="btn btn-ghost" href="/snake/?chatdemo=1" target="_blank" rel="noopener">Preview with demo chat ↗</a>
  </div>
</div>

<?php foreach ($errors as $err): ?>
  <div class="flash flash-error" role="alert"><?= e($err) ?></div>
<?php endforeach; ?>

<div class="callout">To stream this game: <strong>Live Stream Manager</strong> → edit a stream → <strong>Page to stream</strong> → choose <strong>Snake Live</strong> (<code>https://ffteditt.store/snake/</code>). Live chat must be on (YouTube Manager) so the chat fruits and LUNA work.</div>

<form method="post" class="form-wide">
  <?= csrf_field() ?>
  <section class="card">
    <h2>Snake</h2>
    <div class="row2">
      <label class="field"><span>Title at the top</span>
        <input name="title" maxlength="20" required value="<?= e($cfg['title']) ?>">
        <small class="muted">The last word is purple, e.g. SNAKE <b>LIVE</b>.</small>
      </label>
      <label class="field"><span>Snake speed (moves a second)</span>
        <input name="speed" id="speed" type="range" min="3" max="20" value="<?= (int) $cfg['speed'] ?>" oninput="speedOut.textContent=this.value+' · '+(this.value<7?'slow':this.value<12?'normal':this.value<16?'fast':'very fast')">
        <small class="muted"><b id="speedOut"><?= (int) $cfg['speed'] ?></b> &nbsp;—&nbsp; 9 = normal. Changes on the live in about 30 seconds.</small>
      </label>
      <label class="field"><span>Board size (squares on each side)</span>
        <input name="grid" type="number" min="12" max="30" required value="<?= (int) $cfg['grid'] ?>">
        <small class="muted">20 = normal. Bigger = longer rounds, smaller squares. Starts with the next round.</small>
      </label>
      <label class="field"><span>Text at the bottom</span>
        <input name="chan" maxlength="40" placeholder="@YourChannel" value="<?= e($cfg['chan']) ?>">
        <small class="muted">For example your channel name. Empty = nothing.</small>
      </label>
    </div>
  </section>

  <section class="card">
    <h2>Chat fruits</h2>
    <label class="check"><input type="checkbox" name="chat" <?= $cfg['chat'] ? 'checked' : '' ?>> Every chat message drops a fruit with the viewer's name</label>
    <div class="row2" style="margin-top:10px">
      <label class="field"><span>Wait per viewer (seconds)</span>
        <input name="cooldown" type="number" min="0" max="120" required value="<?= (int) $cfg['cooldown'] ?>">
        <small class="muted">One person gets one fruit every this many seconds (stops spam).</small>
      </label>
      <label class="field"><span>Name fruits on the board at once</span>
        <input name="named" type="number" min="1" max="20" required value="<?= (int) $cfg['named'] ?>">
      </label>
      <label class="field"><span>🌟 Golden fruit (subscribe) makes it longer by</span>
        <input name="gold" type="number" min="1" max="10" required value="<?= (int) $cfg['gold'] ?>">
      </label>
    </div>
  </section>

  <section class="card">
    <h2>Sound</h2>
    <label class="check"><input type="checkbox" name="eat_sound" <?= $cfg['eat_sound'] ? 'checked' : '' ?>> Eating sound (nom nom)</label>
    <label class="check"><input type="checkbox" name="music" <?= $cfg['music'] ? 'checked' : '' ?>> Background music</label>
    <div class="form-actions"><button class="btn btn-pink" type="submit">Save settings</button></div>
  </section>
</form>

<section class="card form-inline">
  <div>
    <h2>Start again</h2>
    <p class="muted" style="margin:4px 0 0">Back to round 1; the best score and today's fruits are cleared.</p>
  </div>
  <form method="post" onsubmit="return confirm('Start Snake Live again on all live pages?')">
    <?= csrf_field() ?><input type="hidden" name="do" value="reset">
    <button class="btn btn-outline-pink" type="submit">Start again</button>
  </form>
</section>
<?php page_end();
