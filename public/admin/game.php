<?php
require __DIR__ . '/../_boot.php';
$admin = require_login();

$cfg    = game_config();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $do = (string) ($_POST['do'] ?? 'save');

    if ($do === 'reset') {
        $cfg['reset'] = bin2hex(random_bytes(4));
        game_save($cfg);
        log_activity('Game scores reset to 0 – 0');
        flash('Scores reset. Live games show 0 – 0 within about 20 seconds.');
        redirect('game.php');
    }

    $new = $cfg;
    $t = (int) ($_POST['target'] ?? 0);
    if ($t < 1 || $t > 999) {
        $errors[] = 'Points to win must be between 1 and 999.';
    } else {
        $new['target'] = $t;
    }

    foreach ([0, 1] as $i) {
        $n     = $i + 1;
        $name  = strtoupper(trim((string) ($_POST["p{$n}_name"] ?? '')));
        $team  = strtoupper(trim((string) ($_POST["p{$n}_team"] ?? '')));
        $color = strtolower(trim((string) ($_POST["p{$n}_color"] ?? '')));
        $num   = trim((string) ($_POST["p{$n}_number"] ?? ''));

        if ($name === '' || mb_strlen($name) > 16) $errors[] = "Player {$n}: name is needed (max 16 letters).";
        if (mb_strlen($team) > 20) $errors[] = "Player {$n}: team is too long (max 20 letters).";
        if (!preg_match('/^#[0-9a-f]{6}$/', $color)) $errors[] = "Player {$n}: choose a colour.";
        if (!preg_match('/^[0-9A-Za-z]{0,3}$/', $num)) $errors[] = "Player {$n}: shirt number can be up to 3 characters.";

        $new['players'][$i]['name']   = $name;
        $new['players'][$i]['team']   = $team;
        $new['players'][$i]['color']  = $color;
        $new['players'][$i]['number'] = $num;

        foreach (['photo', 'ball'] as $kind) {
            $field = "p{$n}_{$kind}";
            if (!empty($_POST["{$field}_remove"])) {
                delete_upload($cfg['players'][$i][$kind]);
                $new['players'][$i][$kind] = '';
            }
            if (!empty($_FILES[$field]['name'])) {
                try {
                    $saved = save_upload($_FILES[$field]);
                    delete_upload($cfg['players'][$i][$kind]);
                    $new['players'][$i][$kind] = $saved;
                } catch (RuntimeException $ex) {
                    $errors[] = "Player {$n} " . ($kind === 'ball' ? 'ball picture' : 'photo') . ': ' . $ex->getMessage();
                }
            }
        }
    }

    if (!$errors) {
        $new['rev'] = bin2hex(random_bytes(4));
        game_save($new);
        log_activity("Game settings saved: {$new['players'][0]['name']} vs {$new['players'][1]['name']}");
        flash('Saved. Live games update within about 20 seconds.');
        redirect('game.php');
    }
    $cfg = $new;
}

page_start('Game', 'game', $admin);
?>
<div class="page-head">
  <div>
    <h1>Game settings</h1>
    <p class="muted">Names, colours and photos for the Ball Drop game. Changes reach every live stream within about 20 seconds.</p>
  </div>
  <div class="head-actions">
    <a class="btn btn-ghost" href="/" target="_blank" rel="noopener">Open site ↗</a>
  </div>
</div>

<?php foreach ($errors as $err): ?>
  <div class="flash flash-error" role="alert"><?= e($err) ?></div>
<?php endforeach; ?>

<form method="post" enctype="multipart/form-data" class="form-wide">
  <?= csrf_field() ?>
  <div class="grid">
    <?php foreach ($cfg['players'] as $i => $p): $n = $i + 1; ?>
    <section class="card player" style="--pc: <?= e($p['color']) ?>">
      <h2>Player <?= $n ?> <span class="muted small">(<?= $n === 1 ? 'left' : 'right' ?> side)</span></h2>

      <div class="row2">
        <label class="field"><span>Name</span>
          <input name="p<?= $n ?>_name" maxlength="16" required value="<?= e($p['name']) ?>">
        </label>
        <label class="field"><span>Team label</span>
          <input name="p<?= $n ?>_team" maxlength="20" value="<?= e($p['team']) ?>">
        </label>
        <label class="field"><span>Colour</span>
          <input name="p<?= $n ?>_color" type="color" value="<?= e($p['color']) ?>" class="color">
        </label>
        <label class="field"><span>Shirt number</span>
          <input name="p<?= $n ?>_number" maxlength="3" value="<?= e($p['number']) ?>">
        </label>
      </div>

      <?php foreach (['photo' => ['Photo', 'Replaces the built-in picture in the header, score card, ball and winner screen. A portrait works best.'],
                      'ball'  => ['Ball picture (optional)', 'Printed on the ball. If empty, the photo is used.']] as $kind => [$label, $help]): ?>
      <div class="upload">
        <div class="thumb">
          <?php if ($p[$kind] !== ''): ?>
            <img src="<?= e(upload_url($p[$kind])) ?>" alt="<?= e($label) ?> of <?= e($p['name']) ?>">
          <?php else: ?>
            <span class="muted small">No picture</span>
          <?php endif; ?>
        </div>
        <div class="upload-body">
          <label class="field"><span><?= e($label) ?></span>
            <input type="file" name="p<?= $n ?>_<?= $kind ?>" accept="image/jpeg,image/png,image/webp">
          </label>
          <small class="muted"><?= e($help) ?> JPG, PNG or WEBP, max 5 MB.</small>
          <?php if ($p[$kind] !== ''): ?>
            <label class="check small"><input type="checkbox" name="p<?= $n ?>_<?= $kind ?>_remove" value="1"> Remove this picture</label>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </section>
    <?php endforeach; ?>
  </div>

  <section class="card form-inline">
    <label class="field"><span>Points to win a game</span>
      <input name="target" type="number" min="1" max="999" required value="<?= (int) $cfg['target'] ?>">
    </label>
    <button class="btn btn-pink" type="submit">Save settings</button>
  </section>
</form>

<section class="card form-inline">
  <div>
    <h2>Scores</h2>
    <p class="muted" style="margin:4px 0 0">Start every live game again from 0 – 0.</p>
  </div>
  <form method="post" onsubmit="return confirm('Reset the score on all live games to 0 – 0?')">
    <?= csrf_field() ?><input type="hidden" name="do" value="reset">
    <button class="btn btn-outline-pink" type="submit">Reset scores</button>
  </form>
</section>

<p class="muted small">Use photos you have the right to use. Pictures of well-known people or brand logos can lead to copyright claims on YouTube.</p>
<?php page_end();
