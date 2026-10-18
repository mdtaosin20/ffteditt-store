<?php
require __DIR__ . '/../_boot.php';
$admin = require_login();

$cfg    = tug_config();
$errors = [];
$pairsText = tug_pairs_text($cfg['pairs']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $do = (string) ($_POST['do'] ?? 'save');

    if ($do === 'reset') {
        $cfg['reset'] = bin2hex(random_bytes(4));
        tug_save($cfg);
        log_activity('Tug of War restarted');
        flash('Tug of War starts again from round 1 within about 20 seconds.');
        redirect('tug.php');
    }

    $new   = $cfg;
    $title = mb_strtoupper(trim((string) ($_POST['title'] ?? '')));
    if ($title === '' || mb_strlen($title) > 24) {
        $errors[] = 'Title is needed (max 24 letters).';
    } else {
        $new['title'] = $title;
    }

    $pairsText = (string) ($_POST['pairs'] ?? '');
    $bad = [];
    $pairs = tug_parse_pairs($pairsText, $bad);
    if ($bad) {
        $errors[] = 'Not understood: ' . implode(', ', array_unique($bad)) . '. Write pairs like bd-in, in-pk (two-letter codes).';
    } elseif (count($pairs) < 1 || count($pairs) > 40) {
        $errors[] = 'Write between 1 and 40 pairs.';
    } else {
        $new['pairs'] = $pairs;
    }

    foreach ([['round', 'Round length', 30, 900], ['power', 'Pull strength', 20, 400]] as [$k, $label, $min, $max]) {
        $v = (int) ($_POST[$k] ?? 0);
        if ($v < $min || $v > $max) {
            $errors[] = "{$label} must be between {$min} and {$max}.";
        } else {
            $new[$k] = $v;
        }
    }

    if (!$errors) {
        $changedPairs = $new['pairs'] !== $cfg['pairs'];
        $new['rev'] = bin2hex(random_bytes(4));
        if ($changedPairs) $new['reset'] = bin2hex(random_bytes(4));
        tug_save($new);
        log_activity('Tug of War settings saved');
        flash('Saved. Live Tug of War pages use it after the current round' . ($changedPairs ? ' and start again from the first pair.' : '.'));
        redirect('tug.php');
    }
    $cfg = $new;
}

page_start('Tug of War', 'games', $admin);
?>
<div class="page-head">
  <div>
    <h1>Tug of War</h1>
    <p class="muted">Two countries pull one rope. Every live-chat comment with one of the two country names is one pull (one viewer counts once every 3 seconds, so more people beat spam). The knot that crosses a team's line wins, or whoever is ahead when the time runs out. Then the next pair comes on. LUNA calls the fans to comment.</p>
  </div>
  <div class="head-actions">
    <a class="btn btn-ghost" href="/tug/" target="_blank" rel="noopener">Open game ↗</a>
    <a class="btn btn-ghost" href="/tug/?chatdemo=1" target="_blank" rel="noopener">Preview with demo chat ↗</a>
  </div>
</div>

<?php foreach ($errors as $err): ?>
  <div class="flash flash-error" role="alert"><?= e($err) ?></div>
<?php endforeach; ?>

<div class="callout">To stream this game: <strong>Live Stream Manager</strong> → edit a stream → <strong>Page to stream</strong> → choose <strong>Tug of War</strong> (<code>https://ffteditt.store/tug/</code>). Live chat must be on (YouTube Manager) — this game is played by the chat.</div>

<form method="post" class="form-wide">
  <?= csrf_field() ?>
  <section class="card">
    <div class="row2">
      <label class="field"><span>Title at the top</span>
        <input name="title" maxlength="24" required value="<?= e($cfg['title']) ?>">
      </label>
      <label class="field"><span>Round length (seconds)</span>
        <input name="round" type="number" min="30" max="900" required value="<?= (int) $cfg['round'] ?>">
        <small class="muted">180 = 3 minutes. A round ends early when the knot crosses a line.</small>
      </label>
      <label class="field"><span>Pull strength (%)</span>
        <input name="power" type="number" min="20" max="400" required value="<?= (int) $cfg['power'] ?>">
        <small class="muted">100 = normal. Higher = rounds end faster with few viewers.</small>
      </label>
    </div>

    <label class="field" style="margin-top:14px"><span>Country pairs, in order (two-letter codes)</span>
      <textarea name="pairs" id="pairs" rows="3" spellcheck="false"><?= e($pairsText) ?></textarea>
      <span class="pick-row">
        <?php foreach (['rivals' => 'Big rivals', 'asia' => 'Asia', 'football' => 'Football'] as $pk => $pl): ?>
          <button type="button" class="btn btn-ghost btn-sm" data-pairs="<?= e(tug_pairs_text(TUG_PRESETS[$pk])) ?>"><?= e($pl) ?></button>
        <?php endforeach; ?>
      </span>
      <small class="muted">Example: <code>bd-in, in-pk, br-ar</code>. Codes: bd Bangladesh, in India, pk Pakistan, us USA, gb UK, sa Saudi Arabia, ae UAE, my Malaysia, id Indonesia …</small>
    </label>

    <div class="form-actions"><button class="btn btn-pink" type="submit">Save settings</button></div>
  </section>
</form>

<section class="card form-inline">
  <div>
    <h2>Start again</h2>
    <p class="muted" style="margin:4px 0 0">Back to round 1 and the first pair; today's score is cleared.</p>
  </div>
  <form method="post" onsubmit="return confirm('Start Tug of War again on all live pages?')">
    <?= csrf_field() ?><input type="hidden" name="do" value="reset">
    <button class="btn btn-outline-pink" type="submit">Start again</button>
  </form>
</section>
<?php page_end('tug_admin.js');
