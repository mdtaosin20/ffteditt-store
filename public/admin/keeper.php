<?php
require __DIR__ . '/../_boot.php';
$admin = require_login();

$cfg    = keeper_config();
$errors = [];
$known  = war_known_codes();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if ((string) ($_POST['do'] ?? '') === 'reset') {
        $cfg['reset'] = bin2hex(random_bytes(4));
        keeper_save($cfg);
        log_activity('Beat The Keeper restarted');
        flash('A new tournament starts on all live Beat The Keeper pages within about 20 seconds.');
        redirect('keeper.php');
    }
    $new = $cfg;
    foreach ([['title', 'Title', 24], ['cup', 'Cup name', 20]] as [$k, $label, $max]) {
        $v = mb_strtoupper(trim((string) ($_POST[$k] ?? '')));
        if ($v === '' || mb_strlen($v) > $max) $errors[] = "{$label} is needed (max {$max} letters)."; else $new[$k] = $v;
    }
    $codes = array_values(array_unique(array_filter(array_map(fn ($c) => strtolower(trim($c)), preg_split('/[\s,;]+/', (string) ($_POST['teams'] ?? '')) ?: []))));
    $bad = array_values(array_filter($codes, fn ($c) => !isset($known[$c])));
    if ($bad) $errors[] = 'Unknown country code: ' . implode(', ', $bad) . '. Use 2-letter codes like bd, in, us.';
    elseif (count($codes) !== 36) $errors[] = 'Write exactly 36 countries (6 groups of 6) — now ' . count($codes) . '.';
    else $new['teams'] = $codes;
    foreach ([['goals', 'Goals to go through', 1, 20], ['final_goals', 'Goals to win the final', 1, 30], ['match_secs', 'Match time', 30, 900], ['bonus_chats', 'Chats for a bonus ball', 0, 500]] as [$k, $label, $min, $max]) {
        $v = (int) ($_POST[$k] ?? -1);
        if ($v < $min || $v > $max) $errors[] = "{$label} must be between {$min} and {$max}."; else $new[$k] = $v;
    }
    if (!$errors) {
        $changed = $new['teams'] !== $cfg['teams'] || $new['goals'] !== $cfg['goals'] || $new['final_goals'] !== $cfg['final_goals'];
        $new['rev'] = bin2hex(random_bytes(4));
        if ($changed) $new['reset'] = bin2hex(random_bytes(4));
        keeper_save($new);
        log_activity('Beat The Keeper settings saved');
        flash('Saved. Live Beat The Keeper pages update within about 20 seconds' . ($changed ? ' and start a new tournament.' : '.'));
        redirect('keeper.php');
    }
    $cfg = $new;
}

page_start('Beat The Keeper', 'games', $admin);
?>
<div class="page-head">
  <div>
    <h1>Beat The Keeper</h1>
    <p class="muted">Country flag balls roll down a chute, a spinning paddle kicks them at the goal and a keeper moves up and down to stop them. A whole World Cup runs by itself: 6 groups → quarterfinals → semifinals → final → champion, then a new tournament. Typing a country in the live chat gives its ball a faster shot; every few chats give a bonus ball.</p>
  </div>
  <div class="head-actions">
    <a class="btn btn-ghost" href="/keeper/" target="_blank" rel="noopener">Open game ↗</a>
    <a class="btn btn-ghost" href="/keeper/?chatdemo=1&amp;fast=8" target="_blank" rel="noopener">Fast preview ↗</a>
  </div>
</div>

<?php foreach ($errors as $err): ?>
  <div class="flash flash-error" role="alert"><?= e($err) ?></div>
<?php endforeach; ?>

<div class="callout">To stream this game: <strong>Live Stream Manager</strong> → edit a stream → <strong>Page to stream</strong> → choose <strong>Beat The Keeper</strong> (<code>https://ffteditt.store/keeper/</code>).</div>

<form method="post" class="form-wide">
  <?= csrf_field() ?>
  <section class="card">
    <div class="row2">
      <label class="field"><span>Title at the top</span><input name="title" maxlength="24" required value="<?= e($cfg['title']) ?>"></label>
      <label class="field"><span>Cup name</span><input name="cup" maxlength="20" required value="<?= e($cfg['cup']) ?>"><small class="muted">Shown as “🌍 WORLD CUP · GROUP A”.</small></label>
      <label class="field"><span>Goals to go through</span><input name="goals" type="number" min="1" max="20" required value="<?= (int) $cfg['goals'] ?>"><small class="muted">Groups: first 3 of 6 · Quarterfinals: first 4 · Semifinals: first 3.</small></label>
      <label class="field"><span>Goals to win the final</span><input name="final_goals" type="number" min="1" max="30" required value="<?= (int) $cfg['final_goals'] ?>"></label>
      <label class="field"><span>Match time (seconds)</span><input name="match_secs" type="number" min="30" max="900" required value="<?= (int) $cfg['match_secs'] ?>"><small class="muted">When the time is up the countries with most goals go through. The final gets twice as long. 150 ≈ a 35-minute tournament.</small></label>
      <label class="field"><span>Chats for a bonus ball</span><input name="bonus_chats" type="number" min="0" max="500" required value="<?= (int) $cfg['bonus_chats'] ?>"><small class="muted">Every chat moves a country's ball to the front; every this many chats = one extra ball. 0 = off.</small></label>
    </div>
    <label class="field" style="margin-top:14px"><span>The 36 countries (6 groups of 6, mixed again for every tournament)</span>
      <textarea name="teams" rows="3" spellcheck="false"><?= e(implode(', ', $cfg['teams'])) ?></textarea>
      <small class="muted">Two-letter codes. Countries your viewers type a lot join the next tournament by themselves.</small>
    </label>
    <div class="form-actions"><button class="btn btn-pink" type="submit">Save settings</button></div>
  </section>
</form>

<section class="card form-inline">
  <div><h2>New tournament</h2><p class="muted" style="margin:4px 0 0">Start a fresh tournament from the group stage.</p></div>
  <form method="post" onsubmit="return confirm('Start a new tournament on all live Beat The Keeper pages?')">
    <?= csrf_field() ?><input type="hidden" name="do" value="reset">
    <button class="btn btn-outline-pink" type="submit">Start a new tournament</button>
  </form>
</section>
<p class="muted small">Flag pictures: flag-icons (MIT licence).</p>
<?php page_end();
