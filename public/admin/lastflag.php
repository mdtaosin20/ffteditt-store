<?php
require __DIR__ . '/../_boot.php';
$admin = require_login();

$cfg    = lastflag_config();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $do = (string) ($_POST['do'] ?? 'save');

    if ($do === 'reset') {
        $cfg['reset'] = bin2hex(random_bytes(4));
        lastflag_save($cfg);
        log_activity('Last Flag Standing restarted');
        flash('New round started. Live Last Flag Standing pages begin again within about 20 seconds.');
        redirect('lastflag.php');
    }

    $new   = $cfg;
    $title = mb_strtoupper(trim((string) ($_POST['title'] ?? '')));
    if ($title === '' || mb_strlen($title) > 24) {
        $errors[] = 'Title is needed (max 24 letters).';
    } else {
        $new['title'] = $title;
    }
    $fields = [
        ['count',   'Countries in a round',         16, 100],
        ['gap',     'Hole opens every (seconds)',   2, 30],
        ['shield',  'Chats for a shield',           3, 500],
        ['revive',  'Chats to revive',              3, 1000],
        ['revives', 'Revives in one round',         0, 30],
        ['likes',   'Like goal step',               5, 5000],
        ['viewers', 'Viewer goal',                  5, 100000],
    ];
    foreach ($fields as [$k, $label, $min, $max]) {
        $v = (int) ($_POST[$k] ?? -1);
        if ($v < $min || $v > $max) {
            $errors[] = "{$label} must be between {$min} and {$max}.";
        } else {
            $new[$k] = $v;
        }
    }
    $new['events'] = isset($_POST['events']);
    $new['music'] = isset($_POST['music']);
    $style = (string) ($_POST['music_style'] ?? 'upbeat');
    $new['music_style'] = in_array($style, ['upbeat', 'chill', 'epic'], true) ? $style : 'upbeat';
    $new['music_src'] = ($_POST['music_src'] ?? '') === 'own' ? 'own' : 'songs';
    $mv = (int) ($_POST['music_vol'] ?? -1);
    if ($mv < 0 || $mv > 100) $errors[] = 'Music volume must be between 0 and 100.'; else $new['music_vol'] = $mv;

    if (!$errors) {
        $newRound = $new['count'] !== $cfg['count'];
        $new['rev'] = bin2hex(random_bytes(4));
        if ($newRound) $new['reset'] = bin2hex(random_bytes(4));
        lastflag_save($new);
        log_activity('Last Flag Standing settings saved');
        flash('Saved. Live Last Flag Standing pages update within about 20 seconds' . ($newRound ? ' and start a new round.' : '.'));
        redirect('lastflag.php');
    }
    $cfg = $new;
}

page_start('Last Flag Standing', 'games', $admin);
?>
<div class="page-head">
  <div>
    <h1>Last Flag Standing</h1>
    <p class="muted">The new neon Flag Arena: country flags bounce in a six-sided arena with a turning bearing (a ring with three bars) around the black hole. Every few seconds the black hole in the middle draws the flag nearest to it — that country is out. The live chat saves countries: enough chats give a shield, a country that is out can be revived, a new subscriber gives a free shield and a Super Chat gives both. Like and viewer goals help everyone. The last flag is the round champion, then a new round starts.</p>
  </div>
  <div class="head-actions">
    <a class="btn btn-ghost" href="/lastflag/" target="_blank" rel="noopener">Open game ↗</a>
    <a class="btn btn-ghost" href="/lastflag/?chatdemo=1&amp;fast=10" target="_blank" rel="noopener">Fast preview ↗</a>
  </div>
</div>

<?php foreach ($errors as $err): ?>
  <div class="flash flash-error" role="alert"><?= e($err) ?></div>
<?php endforeach; ?>

<div class="callout">To stream this game: <strong>Live Stream Manager</strong> → edit a stream → <strong>Page to stream</strong> → choose <strong>Last Flag Standing</strong> (<code>https://ffteditt.store/lastflag/</code>). Flag Arena stays as it is — this is a separate game with its own settings. Live chat must be on (YouTube Manager). Like and viewer goals need the channel connected to YouTube.</div>

<form method="post" class="form-wide">
  <?= csrf_field() ?>
  <section class="card">
    <div class="row2">
      <label class="field"><span>Title at the top</span>
        <input name="title" maxlength="24" required value="<?= e($cfg['title']) ?>">
      </label>
      <label class="field"><span>Countries in a round</span>
        <input name="count" type="number" min="16" max="100" required value="<?= (int) $cfg['count'] ?>">
        <small class="muted">99 = normal (fewer = bigger flags). Your viewers' countries are always in; countries people comment join the next round.</small>
      </label>
      <label class="field"><span>Black hole opens every (seconds)</span>
        <input name="gap" type="number" min="2" max="30" required value="<?= (int) $cfg['gap'] ?>">
        <small class="muted">5 s ≈ 15 min a round with no chat · 10 s ≈ 27 min · 20 s ≈ 50 min. Chat shields and revives make rounds longer.</small>
      </label>
      <label class="field"><span>Chats for a shield</span>
        <input name="shield" type="number" min="3" max="500" required value="<?= (int) $cfg['shield'] ?>">
      </label>
      <label class="field"><span>Chats to revive a country that is out</span>
        <input name="revive" type="number" min="3" max="1000" required value="<?= (int) $cfg['revive'] ?>">
      </label>
      <label class="field"><span>Revives in one round</span>
        <input name="revives" type="number" min="0" max="30" required value="<?= (int) $cfg['revives'] ?>">
        <small class="muted">0 = no limit: a country can come back again and again.</small>
      </label>
      <label class="field"><span>Like goal: every … new likes</span>
        <input name="likes" type="number" min="5" max="5000" required value="<?= (int) $cfg['likes'] ?>">
        <small class="muted">Each goal closes the black hole for 30 seconds.</small>
      </label>
      <label class="field"><span>Viewer goal: every … people watching</span>
        <input name="viewers" type="number" min="5" max="100000" required value="<?= (int) $cfg['viewers'] ?>">
        <small class="muted">The top 3 chat countries get free shields.</small>
      </label>
    </div>
    <label class="check" style="margin-top:10px"><input type="checkbox" name="events" <?= $cfg['events'] ? 'checked' : '' ?>> Mystery boxes every few minutes (Big Hole, Lucky Shields, Speed Storm, Lucky Comeback)</label>

    <h3 style="margin:18px 0 6px">Background music</h3>
    <p class="muted small" style="margin:0 0 8px">The music is made by the game itself while it plays (no music files), so it is our own and copyright free — YouTube has nothing to claim. It gets quieter while LUNA talks.</p>
    <div class="row2">
      <label class="check"><input type="checkbox" name="music" <?= $cfg['music'] ? 'checked' : '' ?>> Play background music</label>
      <label class="field"><span>Music</span>
        <select name="music_src">
          <option value="songs" <?= $cfg['music_src'] === 'songs' ? 'selected' : '' ?>>NCS songs (<?= count(music_tracks()) ?> added) — on the live stream</option>
          <option value="own" <?= $cfg['music_src'] === 'own' ? 'selected' : '' ?>>Our own music made in code</option>
        </select>
        <small class="muted">With no songs added, our own music plays.</small></label>
      <label class="field"><span>Style (own music)</span>
        <select name="music_style">
          <?php foreach (['upbeat' => 'Upbeat (pop / EDM)', 'chill' => 'Chill (lo-fi)', 'epic' => 'Epic (battle)'] as $k => $l): ?>
            <option value="<?= $k ?>" <?= $cfg['music_style'] === $k ? 'selected' : '' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select></label>
      <label class="field"><span>Music volume (%)</span>
        <input name="music_vol" type="number" min="0" max="100" required value="<?= (int) $cfg['music_vol'] ?>">
        <small class="muted">35 = soft background (it drops lower while LUNA talks).</small></label>
    </div>
    <div class="form-actions"><button class="btn btn-pink" type="submit">Save settings</button></div>
  </section>
</form>

<section class="card form-inline">
  <div>
    <h2>New round</h2>
    <p class="muted" style="margin:4px 0 0">Start a fresh round with all countries back in the arena.</p>
  </div>
  <form method="post" onsubmit="return confirm('Start a new round on all live Last Flag Standing pages?')">
    <?= csrf_field() ?><input type="hidden" name="do" value="reset">
    <button class="btn btn-outline-pink" type="submit">Start a new round</button>
  </form>
</section>

<p class="muted small">Flag pictures: flag-icons (MIT licence).</p>
<?php page_end();
