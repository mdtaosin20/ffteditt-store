<?php
require __DIR__ . '/../_boot.php';
$admin = require_login();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$st = db()->prepare('SELECT * FROM streams WHERE id = ?');
$st->execute([$id]);
$s = $st->fetch();
if (!$s) {
    flash('Channel not found.', 'error');
    redirect('youtube.php');
}
$game   = game_of_url($s['page_url']);
$def    = yt_defaults($game);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $title = trim(str_replace(["\r", "\n", '<', '>'], [' ', ' ', '', ''], (string) ($_POST['title'] ?? '')));
    $desc  = trim(str_replace(["\r\n", '<', '>'], ["\n", '', ''], (string) ($_POST['desc'] ?? '')));
    $thumb = (string) ($_POST['thumb'] ?? 'default');
    $auto  = isset($_POST['auto']) ? 1 : 0;
    $vary  = isset($_POST['vary']) ? 1 : 0;
    $badges = implode("\n", array_slice(array_filter(array_map(fn ($l) => mb_substr(trim(preg_replace('/[^\p{L}\p{N} *?!\/&+#.,:-]/u', '', $l) ?? ''), 0, 24), preg_split('/\R/', (string) ($_POST['badges'] ?? '')) ?: [])), 0, 12));

    if ($title === '') $errors[] = 'Write a title.';
    if (mb_strlen($title) > 100) $errors[] = 'The title is too long. YouTube allows 100 letters (now ' . mb_strlen($title) . ').';
    if (strlen($desc) > 4900) $errors[] = 'The description is too long for YouTube.';
    if (!in_array($thumb, ['default', 'own', 'none'], true)) $thumb = 'default';

    $newThumb = $s['yt_thumb'];
    if ($thumb === 'default' || $thumb === 'none') {
        $newThumb = $thumb;
    }
    $up = $_FILES['image'] ?? null;
    $small = (string) ($_POST['image_data'] ?? '');   // already made 1280 x 720 JPG by the browser
    if ($small !== '' && preg_match('#^data:image/jpeg;base64,([A-Za-z0-9+/=]+)$#', $small, $mm)) {
        $bin = base64_decode($mm[1], true);
        $si = $bin !== false ? @getimagesizefromstring($bin) : false;
        if (!$si || $si[2] !== IMAGETYPE_JPEG || strlen($bin) > 2 * 1024 * 1024) {
            $errors[] = 'Could not read the picture. Try another one.';
        } else {
            foreach (['jpg', 'png'] as $old) @unlink(yt_thumb_dir() . "/thumb_{$id}.{$old}");
            if (file_put_contents(yt_thumb_dir() . "/thumb_{$id}.jpg", $bin) !== false) {
                $newThumb = 'jpg';
                $up = ['error' => UPLOAD_ERR_OK, 'via' => 'browser'];
            } else {
                $errors[] = 'Could not save the picture. Try again.';
            }
        }
    } elseif ($up && ($up['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $info = $up['error'] === UPLOAD_ERR_OK ? @getimagesize($up['tmp_name']) : false;
        if (in_array($up['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            $errors[] = 'This picture is too big for the server to receive. Use one under ' . ini_get('upload_max_filesize') . 'B.';
        } elseif (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            $errors[] = 'The thumbnail must be a JPG, PNG or WEBP picture.';
        } elseif ($info[0] < 640) {
            $errors[] = 'The thumbnail is too small. Use 1280 × 720 pixels.';
        } elseif (!$errors) {
            foreach (['jpg', 'png'] as $old) @unlink(yt_thumb_dir() . "/thumb_{$id}.{$old}");
            $ok = false;
            $smallEnough = $up['size'] <= 2 * 1024 * 1024 && $info[2] !== IMAGETYPE_WEBP && $info[0] <= 2560;
            if ($smallEnough) {
                $ext = $info[2] === IMAGETYPE_PNG ? 'png' : 'jpg';
                $ok = move_uploaded_file($up['tmp_name'], yt_thumb_dir() . "/thumb_{$id}.{$ext}");
            } else {
                // big picture: make it a 1280 × 720 JPG under 2 MB (YouTube's limit)
                $ext = 'jpg';
                $ok = yt_shrink_thumb($up['tmp_name'], $info[2], yt_thumb_dir() . "/thumb_{$id}.jpg");
                if (!$ok) $errors[] = 'The picture is bigger than 2 MB and the server could not make it smaller. Save it as JPG (1280 × 720) and try again.';
            }
            if ($ok) {
                $newThumb = $ext;
            } elseif (!$errors) {
                $errors[] = 'Could not save the picture. Try again.';
            }
        }
    } elseif ($thumb === 'own' && !in_array($s['yt_thumb'], ['jpg', 'png'], true)) {
        $errors[] = 'Choose a picture to upload, or pick the ready-made thumbnail.';
    }

    // more choice: a list of titles, more thumbnails, a message for pinning
    $x = ytx_get($id);
    $x['tmode'] = ($_POST['tmode'] ?? '') === 'list' ? 'list' : 'one';
    $x['titles'] = array_slice(array_values(array_filter(array_map(fn ($l) => trim(str_replace(['<', '>'], '', $l)), preg_split('/\R/', (string) ($_POST['titles'] ?? '')) ?: []), fn ($l) => $l !== '')), 0, YTX_MAX_TITLES);
    foreach ($x['titles'] as $tl) if (mb_strlen($tl) > 100) $errors[] = 'A title in the list is too long (YouTube allows 100 letters): “' . mb_substr($tl, 0, 40) . '…”';
    if ($x['tmode'] === 'list' && count($x['titles']) < 2) $errors[] = 'Write at least 2 titles in the list (one per line), or choose “Always the title above”.';
    $x['thmode'] = in_array($_POST['thmode'] ?? 'one', ['list', ''], true) ? (string) $_POST['thmode'] : 'one';
    $del = array_map('strval', (array) ($_POST['xdel'] ?? []));
    foreach ($del as $dn) if (in_array($dn, $x['thumbs'], true)) { @unlink(ytx_thumb_path($id, $dn)); }
    $x['thumbs'] = array_values(array_filter($x['thumbs'], fn ($n) => !in_array($n, $del, true) && is_file(ytx_thumb_path($id, $n))));
    $xf = $_FILES['xthumbs'] ?? null;
    if ($xf && is_array($xf['name'] ?? null)) {
        foreach (array_keys($xf['name']) as $i) {
            if (($xf['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
            if (count($x['thumbs']) >= YTX_MAX_THUMBS) { $errors[] = 'At most ' . YTX_MAX_THUMBS . ' extra pictures — remove one first.'; break; }
            $inf = ($xf['error'][$i] === UPLOAD_ERR_OK) ? @getimagesize($xf['tmp_name'][$i]) : false;
            if (!$inf || !in_array($inf[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) { $errors[] = '“' . $xf['name'][$i] . '” is not a JPG, PNG or WEBP picture (or it is too big to upload).'; continue; }
            if ($inf[0] < 640) { $errors[] = '“' . $xf['name'][$i] . '” is too small. Use 1280 × 720 pixels.'; continue; }
            $nm = bin2hex(random_bytes(4));
            if (yt_shrink_thumb($xf['tmp_name'][$i], $inf[2], ytx_thumb_path($id, $nm))) $x['thumbs'][] = $nm;
            else $errors[] = 'Could not save “' . $xf['name'][$i] . '”. Save it as JPG (1280 × 720) and try again.';
        }
    }
    if ($x['thmode'] === 'list' && !$x['thumbs']) $errors[] = 'Add at least one more picture for “a different picture on every live”, or choose “Always one picture”.';
    $x['pin_on'] = isset($_POST['pin_on']);
    [$pinText, $pinErr] = ytp_clean_msg((string) ($_POST['pin_text'] ?? ''));
    if ($x['pin_on'] && $pinErr !== '') $errors[] = 'Message for pinning: ' . $pinErr;
    $x['pin_text'] = $pinText ?? trim((string) ($_POST['pin_text'] ?? ''));
    $x['pin_every'] = in_array((int) ($_POST['pin_every'] ?? 60), [0, 30, 60, 120], true) ? (int) $_POST['pin_every'] : 60;
    if ($x['pin_every'] === 0) $x['pin_every'] = 100000;   // only once, at the start
    if ($errors) { $x0 = ytx_get($id); $x0['thumbs'] = $x['thumbs']; ytx_save($id, $x0); }   // (pictures already saved stay listed)
    else ytx_save($id, $x);

    if (!$errors) {
        $saveTitle = $title === $def['title'] ? '' : $title;
        $saveDesc  = $desc === $def['desc'] ? '' : $desc;
        $changed = $saveTitle !== $s['yt_title'] || $saveDesc !== $s['yt_desc'] || $newThumb !== $s['yt_thumb'] || $up && ($up['error'] ?? 4) === UPLOAD_ERR_OK;
        db()->prepare('UPDATE streams SET yt_title = ?, yt_desc = ?, yt_thumb = ?, yt_auto = ?, yt_vary = ?, yt_badges = ?, yt_rev = yt_rev + ?, yt_fails = 0 WHERE id = ?')
            ->execute([$saveTitle, $saveDesc, $newThumb, $auto, $vary, $badges === implode("\n", YTV_BADGES) ? '' : $badges, $changed ? 1 : 0, $id]);
        $st->execute([$id]);
        $s = $st->fetch();

        if (($_POST['do'] ?? '') === 'apply') {
            [$ok, $msg] = yt_apply($s);
            flash($ok ? 'Done on YouTube: ' . $msg : $msg, $ok ? 'ok' : 'error');
        } else {
            flash($s['status'] === 'live' && $s['yt_refresh'] !== '' && $auto
                ? 'Saved. The live video updates within about 3 minutes (or press “Save & update live now”).'
                : 'Saved. It is put on the video automatically the next time the stream goes live.');
        }
        redirect('yt_live.php?id=' . $id);
    }
}

$t      = yt_texts($s);
$vTitle = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string) ($_POST['title'] ?? '') : $t['title'];
$vDesc  = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string) ($_POST['desc'] ?? '') : $t['desc'];
$thumbChoice = in_array($s['yt_thumb'], ['jpg', 'png'], true) ? 'own' : $s['yt_thumb'];
$connected = $s['yt_refresh'] !== '';

page_start('Title & thumbnail', 'youtube', $admin);
?>
<div class="page-head">
  <div>
    <a class="back" href="youtube.php">← YouTube Manager</a>
    <h1><?= e($s['channel_name'] ?: $s['name']) ?> · Live title &amp; thumbnail</h1>
    <p class="muted"><?php if ($connected): ?>Connected to YouTube channel <strong><?= e($s['yt_channel']) ?></strong>.<?php else: ?>Not connected to YouTube yet — you can still write everything now.<?php endif; ?>
      <?php if ($s['status'] === 'live'): ?><span class="tag tag-green">LIVE now</span><?php endif; ?></p>
  </div>
</div>

<?php foreach ($errors as $err): ?><div class="flash flash-error" role="alert"><?= e($err) ?></div><?php endforeach; ?>
<?php if ($s['yt_msg'] !== ''): ?>
  <div class="flash <?= $s['yt_msg_ok'] ? 'flash-ok' : 'flash-error' ?>">Last try: <?= e($s['yt_msg']) ?></div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="card form" style="max-width:none">
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
  <div class="yt-edit">
    <div class="form">
      <label class="field"><span>Title <b class="count" id="tc"></b></span>
        <input name="title" id="title" maxlength="140" required value="<?= e($vTitle) ?>"></label>
      <?php $x = ytx_get($id); $gameTitles = $def['titles'] ?? []; $xt = $x['titles'] ?: ($gameTitles ?: [$vTitle]); $listOn = $x['tmode'] === 'list' || ($x['tmode'] === '' && $gameTitles); ?>
      <div class="field xbox"><span>Titles</span>
        <div class="radios">
          <label><input type="radio" name="tmode" value="one" <?= !$listOn ? 'checked' : '' ?>> Always the title above</label>
          <label><input type="radio" name="tmode" value="list" <?= $listOn ? 'checked' : '' ?>> 🎲 A different title from this list on every live</label>
        </div>
        <textarea name="titles" rows="5" placeholder="One title per line"><?= e(implode("\n", $xt)) ?></textarea>
        <small class="muted">One title per line (up to <?= YTX_MAX_TITLES ?>, 100 letters each). Each time the stream starts the panel picks one at random — never the same twice in a row.</small></div>
      <label class="field"><span>Description</span>
        <textarea name="desc" id="desc" rows="12"><?= e($vDesc) ?></textarea>
        <small class="muted">Words with # (like <code>#messi</code>) are also added as video tags.</small></label>
      <div class="field"><span>Thumbnail</span>
        <div class="radios">
          <label><input type="radio" name="thumb" value="default" <?= $thumbChoice === 'default' ? 'checked' : '' ?>> Ready-made <?= ['flags' => 'Flag Arena', 'war' => 'Territory War', 'tug' => 'Tug of War', 'keeper' => 'Beat The Keeper', 'race' => 'Flag Race', 'lastflag' => 'Last Flag Standing'][$game] ?? 'Ball Drop' ?> thumbnail</label>
          <label><input type="radio" name="thumb" value="own" <?= $thumbChoice === 'own' ? 'checked' : '' ?>> My own picture</label>
          <label><input type="radio" name="thumb" value="none" <?= $thumbChoice === 'none' ? 'checked' : '' ?>> Don’t change the thumbnail</label>
        </div>
        <input type="file" name="image" id="image" accept="image/jpeg,image/png,image/webp">
        <input type="hidden" name="image_data" id="image_data" value="">
        <small class="muted">JPG, PNG or WEBP, best 1280 × 720. Big pictures are made smaller by the panel. Custom thumbnails need a phone-verified channel (youtube.com/verify).</small>
      </div>
      <div class="field xbox"><span>More thumbnails</span>
        <div class="radios">
          <label><input type="radio" name="thmode" value="one" <?= $x['thmode'] === 'one' || ($x['thmode'] === '' && !ytx_game_thumbs($s)) ? 'checked' : '' ?>> Always one picture (the one above)</label>
          <?php if (ytx_game_thumbs(['yt_thumb' => 'default'] + $s)): ?><label><input type="radio" name="thmode" value="" <?= $x['thmode'] === '' && $thumbChoice === 'default' ? 'checked' : '' ?>> 🎲 The game's <?= count(ytx_game_thumbs(['yt_thumb' => 'default'] + $s)) ?> ready-made pictures, a different one on every live</label><?php endif; ?>
          <label><input type="radio" name="thmode" value="list" <?= $x['thmode'] === 'list' ? 'checked' : '' ?>> 🎲 A different picture from these on every live</label>
        </div>
        <div class="xthumbs">
          <figure><img alt="Main thumbnail" src="yt_thumb.php?id=<?= $id ?>&amp;v=<?= (int) $s['yt_rev'] ?>" onerror="this.style.visibility='hidden'"><figcaption>The one above</figcaption></figure>
          <?php foreach (array_slice(ytx_game_thumbs(['yt_thumb' => 'default'] + $s), 1) as $gi => $gf): ?>
            <figure><img alt="Ready-made thumbnail" src="yt_thumb.php?id=<?= $id ?>&amp;g=<?= $gi + 2 ?>"><figcaption>Ready-made <?= $gi + 2 ?></figcaption></figure>
          <?php endforeach; ?>
          <?php foreach ($x['thumbs'] as $xn): ?>
            <figure><img alt="Extra thumbnail" src="yt_thumb.php?id=<?= $id ?>&amp;x=<?= e($xn) ?>"><figcaption><label class="check"><input type="checkbox" name="xdel[]" value="<?= e($xn) ?>"> remove</label></figcaption></figure>
          <?php endforeach; ?>
        </div>
        <?php if (count($x['thumbs']) < YTX_MAX_THUMBS): ?><input type="file" name="xthumbs[]" accept="image/jpeg,image/png,image/webp" multiple><?php endif; ?>
        <small class="muted">Add up to <?= YTX_MAX_THUMBS ?> more pictures (1280 × 720; big ones are made smaller). Each live gets one of them, or the one above, at random — never the same twice in a row.</small></div>
      <label class="check"><input type="checkbox" name="auto" <?= $s['yt_auto'] ? 'checked' : '' ?>> Put these on the live video automatically every time the stream starts</label>
      <label class="check"><input type="checkbox" name="vary" <?= $s['yt_vary'] ? 'checked' : '' ?>> Change them a little on every start (title words, the first line of the description, tags and a badge on the thumbnail) — Audience Insights shows which version gets the most viewers</label>
      <label class="field"><span>Thumbnail badges — one per line, used in turn (start with * for a white dot)</span>
        <textarea name="badges" rows="4" placeholder="<?= e(implode("\n", YTV_BADGES)) ?>"><?= e($s['yt_badges'] !== '' ? $s['yt_badges'] : implode("\n", YTV_BADGES)) ?></textarea></label>
      <?php if ($s['yt_vary'] && $s['yt_thumb'] !== 'none'): ?>
      <div class="field"><span>The next thumbnail versions</span>
        <div class="ytv-prev"><?php $nn = (int) db()->query('SELECT COUNT(*) FROM yt_variants WHERE stream_id = ' . $id)->fetchColumn();
          for ($k = 0; $k < 4; $k++): ?><figure><img loading="lazy" alt="Version <?= e(ytv_letter($nn + $k)) ?>" src="yt_thumb.php?id=<?= $id ?>&amp;var=<?= $nn + $k ?>&amp;r=<?= (int) $s['yt_rev'] ?>"><figcaption><?= e(ytv_letter($nn + $k)) ?></figcaption></figure><?php endfor; ?></div></div>
      <?php endif; ?>
      <div class="field xbox"><span>Message for pinning</span>
        <label class="check"><input type="checkbox" name="pin_on" <?= $x['pin_on'] ? 'checked' : '' ?>> Send this message in the live chat by itself when the live starts</label>
        <textarea name="pin_text" rows="7" maxlength="400"><?= e($x['pin_text'] !== '' ? $x['pin_text'] : ytx_pin_default($game)) ?></textarea>
        <label class="field" style="margin-top:6px"><span>Send it again</span>
          <select name="pin_every"><?php foreach ([0 => 'Only once, at the start', 30 => 'Every 30 minutes', 60 => 'Every 60 minutes', 120 => 'Every 2 hours'] as $mv => $ml): ?>
            <option value="<?= $mv ?>" <?= ($x['pin_every'] >= 100000 ? 0 : (int) $x['pin_every']) === $mv ? 'selected' : '' ?>><?= $ml ?></option><?php endforeach; ?></select></label>
        <small class="muted">YouTube does not let a program pin messages: when it shows in the chat, tap it on your phone → <b>Pin</b>. Up to 200 letters. “N chats = 🛡” / “N chats = ♻” take the game's numbers by themselves. Each message uses 20 of the 10,000 daily YouTube units.</small>
        <?php $pk = json_decode((string) (setting_get('ytx_pin_' . $id) ?? ''), true) ?: []; if (!empty($pk['msg'])): ?><small class="muted">Last: <?= e($pk['msg']) ?></small><?php endif; ?></div>
      <div class="form-actions">
        <button class="btn btn-blue" type="submit" name="do" value="save">Save</button>
        <?php if ($connected): ?><button class="btn btn-green" type="submit" name="do" value="apply">Save &amp; update live now</button><?php endif; ?>
        <a class="btn btn-ghost" href="youtube.php">Back</a>
      </div>
    </div>

    <aside>
      <div class="yt-prev">
        <img class="yt-thumb" id="pv-img" alt="Thumbnail preview" src="yt_thumb.php?id=<?= $id ?>&amp;v=<?= (int) $s['yt_rev'] ?>"
             onerror="this.style.visibility='hidden'">
        <h4 id="pv-title"></h4>
        <p id="pv-desc"></p>
      </div>
      <p class="muted small" style="margin-top:8px">How it looks on YouTube.</p>
    </aside>
  </div>
</form>
<?php $vlist = ytv_results(0)[$id] ?? []; if ($vlist): ?>
<section class="card" style="max-width:none;margin-top:14px">
  <h2>Versions used on your lives</h2>
  <div class="tbl-wrap"><table class="tbl tbl-sm"><thead><tr><th></th><th>When</th><th>Title</th><th>Avg viewers</th><th>Peak</th><th>New likes</th><th>Minutes</th></tr></thead><tbody>
  <?php foreach (array_reverse(array_slice($vlist, -15)) as $v): ?>
    <tr><td><b><?= e($v['letter']) ?></b><?= $v['best'] ? ' ⭐' : '' ?></td><td class="small muted"><?= e(date('d M H:i', $v['ts'])) ?></td><td class="small"><?= e($v['title']) ?></td>
      <td><?= e((string) $v['avg']) ?></td><td><?= (int) $v['peak'] ?></td><td><?= (int) $v['likes'] ?></td><td><?= (int) $v['mins'] ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
  <p class="muted small">⭐ = most viewers on average (lives of 30+ minutes). Run each live 2–3 hours for a fair test.</p>
</section>
<?php endif; ?>
<?php if ($connected): ?>
      <form method="post" action="yt_oauth.php" onsubmit="return confirm('Disconnect this channel from YouTube?')" style="margin-top:14px">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="do" value="disconnect">
        <button class="btn btn-danger btn-sm" type="submit">Disconnect YouTube</button></form>
      <?php endif; ?>
<?php page_end('yt_live.js');
