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
    $rs = (int) ($_POST['round_secs'] ?? 0);
    if ($rs < 30 || $rs > 1800) {
        $errors[] = 'Round length must be between 30 and 1800 seconds.';
    } else {
        $new['round_secs'] = $rs;
    }
    $rn = (int) ($_POST['rounds'] ?? 3);
    $new['rounds'] = in_array($rn, [1, 3, 5], true) ? $rn : 3;
    $new['ball_style'] = ($_POST['ball_style'] ?? '') === 'face' ? 'face' : 'football';

    foreach ([0, 1] as $i) {
        $n     = $i + 1;
        $name  = strtoupper(trim((string) ($_POST["p{$n}_name"] ?? '')));
        $team  = strtoupper(trim((string) ($_POST["p{$n}_team"] ?? '')));
        $color = strtolower(trim((string) ($_POST["p{$n}_color"] ?? '')));
        $num   = trim((string) ($_POST["p{$n}_number"] ?? ''));
        $flag  = strtolower(trim((string) ($_POST["p{$n}_flag"] ?? '')));
        if (!preg_match('/^([a-z]{2})?$/', $flag)) $errors[] = "Player {$n}: country code must be 2 letters (e.g. ar, pt, bd) or empty.";
        $new['players'][$i]['flag'] = $flag;

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
    <a class="btn btn-ghost" href="/ball/" target="_blank" rel="noopener">Open game ↗</a>
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
        <label class="field"><span>Country flag (2-letter code)</span>
          <input name="p<?= $n ?>_flag" maxlength="2" value="<?= e($p['flag']) ?>" placeholder="ar">
          <small class="muted">ar = Argentina, pt = Portugal, br = Brazil, bd = Bangladesh. Empty = no flag.</small>
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
    <label class="field"><span>Round length (seconds)</span>
      <input name="round_secs" type="number" min="30" max="1800" required value="<?= (int) $cfg['round_secs'] ?>">
    </label>
    <label class="field"><span>Match</span>
      <select name="rounds">
        <?php foreach ([1 => 'Just 1 round', 3 => 'Best of 3 rounds', 5 => 'Best of 5 rounds'] as $k => $l): ?>
          <option value="<?= $k ?>" <?= (int) $cfg['rounds'] === $k ? 'selected' : '' ?>><?= $l ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="field"><span>Ball look</span>
      <select name="ball_style">
        <option value="football" <?= $cfg['ball_style'] !== 'face' ? 'selected' : '' ?>>Football (team colour)</option>
        <option value="face" <?= $cfg['ball_style'] === 'face' ? 'selected' : '' ?>>Player photo</option>
      </select>
    </label>
    <button class="btn btn-pink" type="submit">Save settings</button>
  </section>
</form>

<section class="card form-inline">
  <div>
    <h2>Scores</h2>
    <p class="muted" style="margin:4px 0 0">Start a new match on every live stream (round 1, 0 – 0).</p>
  </div>
  <form method="post" onsubmit="return confirm('Reset the score on all live games to 0 – 0?')">
    <?= csrf_field() ?><input type="hidden" name="do" value="reset">
    <button class="btn btn-outline-pink" type="submit">Reset scores</button>
  </form>
</section>

<p class="muted small">Use photos you have the right to use. Pictures of well-known people or brand logos can lead to copyright claims on YouTube.</p>
<?php page_end();
