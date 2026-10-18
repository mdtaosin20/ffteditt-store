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

    if (!$errors) {
        $saveTitle = $title === $def['title'] ? '' : $title;
        $saveDesc  = $desc === $def['desc'] ? '' : $desc;
        $changed = $saveTitle !== $s['yt_title'] || $saveDesc !== $s['yt_desc'] || $newThumb !== $s['yt_thumb'] || $up && ($up['error'] ?? 4) === UPLOAD_ERR_OK;
        db()->prepare('UPDATE streams SET yt_title = ?, yt_desc = ?, yt_thumb = ?, yt_auto = ?, yt_rev = yt_rev + ?, yt_fails = 0 WHERE id = ?')
            ->execute([$saveTitle, $saveDesc, $newThumb, $auto, $changed ? 1 : 0, $id]);
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
      <label class="field"><span>Description</span>
        <textarea name="desc" id="desc" rows="12"><?= e($vDesc) ?></textarea>
        <small class="muted">Words with # (like <code>#messi</code>) are also added as video tags.</small></label>
      <div class="field"><span>Thumbnail</span>
        <div class="radios">
          <label><input type="radio" name="thumb" value="default" <?= $thumbChoice === 'default' ? 'checked' : '' ?>> Ready-made <?= ['flags' => 'Flag Battle', 'war' => 'Territory War'][$game] ?? 'Ball Drop' ?> thumbnail</label>
          <label><input type="radio" name="thumb" value="own" <?= $thumbChoice === 'own' ? 'checked' : '' ?>> My own picture</label>
          <label><input type="radio" name="thumb" value="none" <?= $thumbChoice === 'none' ? 'checked' : '' ?>> Don’t change the thumbnail</label>
        </div>
        <input type="file" name="image" id="image" accept="image/jpeg,image/png,image/webp">
        <input type="hidden" name="image_data" id="image_data" value="">
        <small class="muted">JPG, PNG or WEBP, best 1280 × 720. Big pictures are made smaller by the panel. Custom thumbnails need a phone-verified channel (youtube.com/verify).</small>
      </div>
      <label class="check"><input type="checkbox" name="auto" <?= $s['yt_auto'] ? 'checked' : '' ?>> Put these on the live video automatically every time the stream starts</label>
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
<?php if ($connected): ?>
      <form method="post" action="yt_oauth.php" onsubmit="return confirm('Disconnect this channel from YouTube?')" style="margin-top:14px">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="do" value="disconnect">
        <button class="btn btn-danger btn-sm" type="submit">Disconnect YouTube</button></form>
      <?php endif; ?>
<?php page_end('yt_live.js');
