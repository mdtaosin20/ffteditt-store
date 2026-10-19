<?php
require __DIR__ . '/../_boot.php';
$admin = require_login();

$cfg    = war_config();
$known  = war_known_codes();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $do = (string) ($_POST['do'] ?? 'save');

    if ($do === 'reset') {
        $cfg['reset'] = bin2hex(random_bytes(4));
        war_save($cfg);
        log_activity('Territory War restarted');
        flash('New war started. Live Territory War pages begin again within about 20 seconds.');
        redirect('war.php');
    }

    $new   = $cfg;
    $title = mb_strtoupper(trim((string) ($_POST['title'] ?? '')));
    if ($title === '' || mb_strlen($title) > 24) {
        $errors[] = 'Title is needed (max 24 letters).';
    } else {
        $new['title'] = $title;
    }

    $codes = array_values(array_unique(array_filter(array_map(
        fn ($c) => strtolower(trim($c)),
        preg_split('/[\s,;]+/', (string) ($_POST['teams'] ?? '')) ?: []
    ))));
    $bad = array_values(array_filter($codes, fn ($c) => !isset($known[$c])));
    if ($bad) {
        $errors[] = 'Unknown country code: ' . implode(', ', $bad) . '. Use 2-letter codes like bd, in, us.';
    } elseif (count($codes) < 2 || count($codes) > 200) {
        $errors[] = 'Choose between 2 and 200 countries (now ' . count($codes) . ').';
    } else {
        $new['teams'] = $codes;
    }

    foreach ([['grid', 'Map size', 20, 120], ['speed', 'Ball speed', 40, 300], ['sudden', 'Sudden Death after', 10, 600]] as [$k, $label, $min, $max]) {
        $v = (int) ($_POST[$k] ?? 0);
        if ($v < $min || $v > $max) {
            $errors[] = "{$label} must be between {$min} and {$max}.";
        } else {
            $new[$k] = $v;
        }
    }
    $new['boost'] = isset($_POST['boost']);
    $new['fill'] = ($_POST['fill'] ?? '') === 'color' ? 'color' : 'flag';
    foreach ([['ball_chats', 'Chats for one more ball'], ['revive_chats', 'Chats to revive']] as [$k, $label]) {
        $v = (int) ($_POST[$k] ?? -1);
        if ($v < 0 || $v > 1000) $errors[] = "{$label} must be between 0 and 1000."; else $new[$k] = $v;
    }

    if (!$errors) {
        $changedGame = $new['teams'] !== $cfg['teams'] || $new['grid'] !== $cfg['grid'];
        $new['rev'] = bin2hex(random_bytes(4));
        if ($changedGame) $new['reset'] = bin2hex(random_bytes(4)); // other countries or map: start a new war
        war_save($new);
        log_activity('Territory War settings saved');
        flash('Saved. Live Territory War pages update within about 20 seconds' . ($changedGame ? ' and start a new war.' : '.'));
        redirect('war.php');
    }
    $cfg = $new;
}

page_start('Territory War', 'games', $admin);
?>
<div class="page-head">
  <div>
    <h1>Territory War</h1>
    <p class="muted">Every country has a flag ball that bounces around the map and takes the squares it hits. A country whose land gets too small is out, and its land goes to the country that beat it. The last country left wins the world, then a new war starts.</p>
  </div>
  <div class="head-actions">
    <a class="btn btn-ghost" href="/war/" target="_blank" rel="noopener">Open game ↗</a>
    <a class="btn btn-ghost" href="/war/?chatdemo=1&amp;fast=10" target="_blank" rel="noopener">Fast preview ↗</a>
  </div>
</div>

<?php foreach ($errors as $err): ?>
  <div class="flash flash-error" role="alert"><?= e($err) ?></div>
<?php endforeach; ?>

<div class="callout">To stream this game: <strong>Live Stream Manager</strong> → edit a stream → <strong>Page to stream</strong> → choose <strong>Territory War</strong> (<code>https://ffteditt.store/war/</code>).</div>

<form method="post" class="form-wide">
  <?= csrf_field() ?>
  <section class="card">
    <div class="row2">
      <label class="field"><span>Title at the top</span>
        <input name="title" maxlength="24" required value="<?= e($cfg['title']) ?>">
      </label>
      <label class="field"><span>Map size (squares across)</span>
        <input name="grid" type="number" min="20" max="120" required value="<?= (int) $cfg['grid'] ?>">
        <small class="muted">40 = normal. Bigger map = longer war. With many countries the map is made bigger by itself.</small>
      </label>
      <label class="field"><span>Ball speed (%)</span>
        <input name="speed" type="number" min="40" max="300" required value="<?= (int) $cfg['speed'] ?>">
        <small class="muted">100 = normal. Faster = shorter war.</small>
      </label>
      <label class="field"><span>Sudden Death after (minutes)</span>
        <input name="sudden" type="number" min="10" max="600" required value="<?= (int) $cfg['sudden'] ?>">
        <small class="muted">Then the smallest country is out every 90 seconds, so every war ends.</small>
      </label>
      <label class="field"><span>Land looks like</span>
        <select name="fill">
          <option value="flag" <?= $cfg['fill'] === 'flag' ? 'selected' : '' ?>>The country's flag</option>
          <option value="color" <?= $cfg['fill'] === 'color' ? 'selected' : '' ?>>A plain colour (round flag balls show the country)</option>
        </select></label>
      <label class="field"><span>Chats for one more ball</span>
        <input name="ball_chats" type="number" min="0" max="1000" required value="<?= (int) $cfg['ball_chats'] ?>">
        <small class="muted">Every this many chats a country gets one more ball for 3 minutes (max 4 extra). 0 = off.</small></label>
      <label class="field"><span>Chats to revive a country that is out</span>
        <input name="revive_chats" type="number" min="0" max="1000" required value="<?= (int) $cfg['revive_chats'] ?>">
        <small class="muted">It comes back with a piece of the biggest country's land — as often as the chat wants. 0 = off.</small></label>
    </div>

    <label class="field" style="margin-top:14px"><span>Countries (2–200, two-letter codes)</span>
      <textarea name="teams" id="teams" rows="3" spellcheck="false"><?= e(implode(', ', $cfg['teams'])) ?></textarea>
      <span class="pick-row">
        <?php foreach (['popular20' => 'Popular 20', 'popular' => 'Popular 16', 'asia' => 'Asia 16', 'football' => 'Football 16', 'muslim' => 'Muslim world 16', 'world150' => 'World 150'] as $pk => $pl): ?>
          <button type="button" class="btn btn-ghost btn-sm" data-teams="<?= e(implode(', ', WAR_PRESETS[$pk])) ?>"><?= e($pl) ?></button>
        <?php endforeach; ?>
      </span>
      <small class="muted">Changing the countries or the map starts a new war. Codes: bd Bangladesh, in India, pk Pakistan, us USA, gb UK, sa Saudi Arabia, ae UAE, tr Türkiye …</small>
    </label>

    <label class="check" style="margin-top:10px"><input type="checkbox" name="boost" <?= $cfg['boost'] ? 'checked' : '' ?>> A comment with a country name speeds up that country's ball for a few seconds</label>

    <div class="form-actions"><button class="btn btn-pink" type="submit">Save settings</button></div>
  </section>
</form>

<section class="card form-inline">
  <div>
    <h2>New war</h2>
    <p class="muted" style="margin:4px 0 0">Start a fresh war with all countries back on the map.</p>
  </div>
  <form method="post" onsubmit="return confirm('Start a new war on all live Territory War pages?')">
    <?= csrf_field() ?><input type="hidden" name="do" value="reset">
    <button class="btn btn-outline-pink" type="submit">Start a new war</button>
  </form>
</section>
<?php page_end('war_admin.js');
