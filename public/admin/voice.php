<?php
require __DIR__ . '/../_boot.php';
$admin = require_login();

$game   = game_config();
$state  = voice_state();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $do = (string) ($_POST['do'] ?? 'save');

    if ($do === 'remake') {
        foreach ($state['auto'] as $a) {
            delete_upload((string) ($a['file'] ?? ''));
        }
        $state['auto'] = [];
        $state['error'] = '';
        voice_save($state);
        log_activity('Automatic voices will be made again');
        flash('The VPS will make all automatic voices again in the next few minutes.');
        redirect('voice.php');
    }

    $state['enabled'] = !empty($_POST['enabled']);
    $state['volume']  = max(0, min(100, (int) ($_POST['volume'] ?? 95)));
    $defaults = voice_default_lines();

    foreach ($defaults as $id => [$label, $text]) {
        $t = trim(str_replace(["\r", "\n"], ' ', (string) ($_POST["t_{$id}"] ?? '')));
        if (mb_strlen($t) > 300) {
            $errors[] = "\"{$label}\" line is too long (max 300 letters).";
            continue;
        }
        if ($t === '' || $t === $text) {
            unset($state['texts'][$id]);
        } else {
            $state['texts'][$id] = $t;
        }
        if (!empty($_POST["rm_{$id}"]) && !empty($state['upload'][$id])) {
            delete_upload($state['upload'][$id]);
            unset($state['upload'][$id]);
        }
        if (!empty($_FILES["f_{$id}"]['name'])) {
            try {
                $saved = save_audio_upload($_FILES["f_{$id}"]);
                if (!empty($state['upload'][$id])) {
                    delete_upload($state['upload'][$id]);
                }
                $state['upload'][$id] = $saved;
            } catch (RuntimeException $ex) {
                $errors[] = "\"{$label}\": " . $ex->getMessage();
            }
        }
    }

    voice_save($state);
    // live games reload within ~20 s
    $game['rev'] = bin2hex(random_bytes(4));
    game_save($game);
    $fl = flags_config();
    $fl['rev'] = bin2hex(random_bytes(4));
    flags_save($fl);
    log_activity('Commentator voice settings saved');
    if (!$errors) {
        flash('Saved. Changed lines get a new automatic voice in a few minutes; live games update within about 20 seconds.');
        redirect('voice.php');
    }
}

$texts   = voice_texts($state, $game);
$missing = array_column(voice_missing($state, $game), 'id');
$ready   = 0;
foreach ($texts as $id => $l) {
    if (!empty($state['upload'][$id]) || !in_array($id, $missing, true)) $ready++;
}

page_start('Voice', 'voice', $admin);
?>
<div class="page-head">
  <div>
    <h1>Commentator voice</h1>
    <p class="muted">An English commentator talks during the games and asks viewers to comment. Each line gets an automatic voice made on your VPS. You can upload your own recording for any line (for example from an AI voice website) to replace it.</p>
  </div>
</div>

<?php foreach ($errors as $err): ?>
  <div class="flash flash-error" role="alert"><?= e($err) ?></div>
<?php endforeach; ?>

<?php if ($state['error']): ?>
  <div class="callout"><strong>Voice maker problem:</strong> <?= e($state['error']) ?></div>
<?php endif; ?>

<div class="stats">
  <div class="stat"><div class="stat-label">Lines with voice</div><div class="stat-value"><?= $ready ?> / <?= count($texts) ?></div></div>
  <div class="stat"><div class="stat-label">Being made on the VPS</div><div class="stat-value"><?= count($missing) ?></div></div>
  <div class="stat stat-actions">
    <form method="post" onsubmit="return confirm('Make all automatic voices again?')"><?= csrf_field() ?><input type="hidden" name="do" value="remake">
      <button class="btn btn-ghost" type="submit">Make auto voices again</button></form>
  </div>
</div>

<form method="post" enctype="multipart/form-data" class="form-wide">
  <?= csrf_field() ?>
  <section class="card form-inline">
    <label class="check"><input type="checkbox" name="enabled" value="1" <?= $state['enabled'] ? 'checked' : '' ?>> Commentator on</label>
    <label class="field"><span>Voice volume (0–100)</span>
      <input type="number" name="volume" min="0" max="100" value="<?= (int) $state['volume'] ?>">
    </label>
    <button class="btn btn-pink" type="submit">Save</button>
  </section>

  <p class="muted small">In the text you can write <code>{P1}</code> and <code>{P2}</code> for the player names (from the Game page) and <code>{L1}</code> <code>{L2}</code> for their first letters. In Flag Battle lines, <code>{Q}</code> is the number of countries in the final. Leave a box empty to go back to the original line.</p>

  <div class="voice-list">
  <?php $group = ''; foreach ($texts as $id => $l):
      $g = voice_is_flag($id) ? 'Country Flag Battle' : 'Ball Drop game';
      if ($g !== $group): $group = $g; ?>
    <h2 class="voice-group"><?= e($g) ?></h2>
  <?php endif;
      $up   = (string) ($state['upload'][$id] ?? '');
      $auto = $state['auto'][$id] ?? null;
      $autoOk = $auto && ($auto['hash'] ?? '') === voice_hash($l['text']);
      $play = $up !== '' ? $up : ($autoOk ? (string) $auto['file'] : '');
  ?>
    <section class="card voice-line">
      <div class="voice-top">
        <strong><?= e($l['label']) ?></strong>
        <?php if ($up !== ''): ?><span class="badge badge-live">Your recording</span>
        <?php elseif ($autoOk): ?><span class="badge badge-muted">Auto voice</span>
        <?php elseif ($state['enabled']): ?><span class="badge badge-warn">Being made…</span>
        <?php else: ?><span class="badge badge-muted">Off</span><?php endif; ?>
      </div>
      <label class="field"><span class="sr">Text</span>
        <textarea name="t_<?= e($id) ?>" rows="2" maxlength="300"><?= e($l['raw']) ?></textarea>
      </label>
      <div class="muted small">Says: “<?= e($l['text']) ?>”</div>
      <?php if ($play !== ''): ?>
        <audio controls preload="none" src="<?= e(upload_url($play)) ?>"></audio>
      <?php endif; ?>
      <div class="voice-up">
        <label class="field"><span>Upload your own recording (MP3, WAV, OGG, M4A · max 3 MB)</span>
          <input type="file" name="f_<?= e($id) ?>" accept="audio/*">
        </label>
        <?php if ($up !== ''): ?>
          <label class="check small"><input type="checkbox" name="rm_<?= e($id) ?>" value="1"> Remove my recording (use auto voice)</label>
        <?php endif; ?>
      </div>
    </section>
  <?php endforeach; ?>
  </div>

  <div class="form-actions"><button class="btn btn-pink" type="submit">Save all</button></div>
</form>
<?php page_end();
