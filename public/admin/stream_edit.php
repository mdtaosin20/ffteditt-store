<?php
require __DIR__ . '/../_boot.php';
$admin = require_login();

$id      = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$servers = all_servers();
$q       = qualities();
$fpsList = [20, 24, 30];

$stream = null;
if ($id) {
    $st = db()->prepare('SELECT * FROM streams WHERE id = ?');
    $st->execute([$id]);
    $stream = $st->fetch() ?: null;
    if (!$stream) {
        flash('Stream not found.', 'error');
        redirect('streams.php');
    }
}

$defaults = [
    'name'         => '',
    'rtmp_url'     => 'rtmp://a.rtmp.youtube.com/live2',
    'page_url'     => 'https://ffteditt.store/ball/',
    'server_id'    => $servers ? array_key_first($servers) : null,
    'quality'      => '480p-v',
    'fps'          => 20,
    'auto_restart' => 1,
    'channel_name' => '',
    'channel_url'  => '',
];
$v      = $stream ?: $defaults;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    if (($_POST['do'] ?? '') === 'delete' && $stream) {
        db()->prepare('DELETE FROM streams WHERE id = ?')->execute([$id]);
        log_activity("Stream deleted: {$stream['name']}");
        flash("Deleted \"{$stream['name']}\".");
        redirect('streams.php');
    }

    $v = [
        'name'         => trim((string) ($_POST['name'] ?? '')),
        'rtmp_url'     => trim((string) ($_POST['rtmp_url'] ?? '')),
        'page_url'     => trim((string) ($_POST['page_url'] ?? '')),
        'server_id'    => ($_POST['server_id'] ?? '') === '' ? null : (int) $_POST['server_id'],
        'quality'      => (string) ($_POST['quality'] ?? ''),
        'fps'          => (int) ($_POST['fps'] ?? 0),
        'auto_restart' => isset($_POST['auto_restart']) ? 1 : 0,
        'channel_name' => trim((string) ($_POST['channel_name'] ?? '')),
        'channel_url'  => trim((string) ($_POST['channel_url'] ?? '')),
    ];
    $newKey = preg_replace('/\s+/', '', (string) ($_POST['stream_key'] ?? ''));

    if ($v['name'] === '' || mb_strlen($v['name']) > 60) {
        $errors[] = 'Give the stream a name (up to 60 characters).';
    }
    if (!preg_match('#^rtmps?://[^\s]+$#i', $v['rtmp_url'])) {
        $errors[] = 'Stream server must start with rtmp:// or rtmps://';
    }
    if (!filter_var($v['page_url'], FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $v['page_url'])) {
        $errors[] = 'Page to stream must be a full web address starting with https://';
    }
    if ($v['server_id'] !== null && !isset($servers[$v['server_id']])) {
        $errors[] = 'Choose a server from the list.';
    }
    if (!isset($q[$v['quality']])) {
        $errors[] = 'Choose a quality.';
    }
    if (!in_array($v['fps'], $fpsList, true)) {
        $errors[] = 'Choose frames per second.';
    }
    if ($newKey !== '' && !preg_match('/^[A-Za-z0-9_-]{8,128}$/', $newKey)) {
        $errors[] = 'That stream key looks wrong. Copy it again from YouTube Studio.';
    }
    if (mb_strlen($v['channel_name']) > 60) {
        $errors[] = 'Channel name is too long (max 60 letters).';
    }
    if ($v['channel_url'] !== '' && !preg_match('#^https://(www\.|m\.)?youtube\.com/[^\s]+$#i', $v['channel_url'])) {
        $errors[] = 'Channel link must be a YouTube address, like https://www.youtube.com/@yourchannel';
    }
    if (!$stream && $newKey === '') {
        $errors[] = 'Paste the YouTube stream key.';
    }

    if (!$errors) {
        $now = time();
        if ($stream) {
            $key = $newKey !== '' ? $newKey : $stream['stream_key'];
            // only restart the live stream when something it uses has changed
            $restart = $key !== $stream['stream_key'] || $v['rtmp_url'] !== $stream['rtmp_url'] || $v['page_url'] !== $stream['page_url']
                || (int) $v['server_id'] !== (int) $stream['server_id'] || $v['quality'] !== $stream['quality']
                || (int) $v['fps'] !== (int) $stream['fps'] || (int) $v['auto_restart'] !== (int) $stream['auto_restart'];
            db()->prepare('UPDATE streams SET name=?, stream_key=?, rtmp_url=?, page_url=?, server_id=?, quality=?,
                           fps=?, auto_restart=?, channel_name=?, channel_url=?, rev = rev + ?, updated_at=? WHERE id=?')
                ->execute([$v['name'], $key, $v['rtmp_url'], $v['page_url'], $v['server_id'], $v['quality'],
                           $v['fps'], $v['auto_restart'], $v['channel_name'], $v['channel_url'], $restart ? 1 : 0, $now, $id]);
            log_activity("Stream updated: {$v['name']}" . ($newKey !== '' ? ' (new key)' : ''));
            flash('Saved. If it is live, it restarts with the new settings in a few seconds.');
        } else {
            db()->prepare('INSERT INTO streams (name, stream_key, rtmp_url, page_url, server_id, quality, fps,
                           auto_restart, channel_name, channel_url, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$v['name'], $newKey, $v['rtmp_url'], $v['page_url'], $v['server_id'], $v['quality'],
                           $v['fps'], $v['auto_restart'], $v['channel_name'], $v['channel_url'], $now, $now]);
            log_activity("Stream added: {$v['name']}");
            flash("Added \"{$v['name']}\". Press Start when you are ready.");
        }
        redirect('streams.php');
    }
}

page_start($stream ? 'Edit stream' : 'Add stream', 'streams', $admin);
?>
<div class="page-head">
  <div>
    <a class="back" href="streams.php">← Live Stream Manager</a>
    <h1><?= $stream ? 'Edit stream' : 'Add stream' ?></h1>
  </div>
</div>

<?php foreach ($errors as $err): ?>
  <div class="flash flash-error" role="alert"><?= e($err) ?></div>
<?php endforeach; ?>

<form method="post" class="card form">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int) $id ?>">

  <label class="field"><span>Name</span>
    <input name="name" required maxlength="60" placeholder="e.g. Main channel – Ball race" value="<?= e($v['name']) ?>">
  </label>

  <div class="row2">
    <label class="field"><span>YouTube channel name</span>
      <input name="channel_name" maxlength="60" placeholder="e.g. Game Zone BD" value="<?= e($v['channel_name'] ?? '') ?>">
    </label>
    <label class="field"><span>Channel link (for the Watch button)</span>
      <input name="channel_url" type="url" placeholder="https://www.youtube.com/@yourchannel" value="<?= e($v['channel_url'] ?? '') ?>">
    </label>
  </div>

  <label class="field"><span>YouTube stream key</span>
    <input name="stream_key" type="password" autocomplete="off" spellcheck="false"
           placeholder="<?= $stream ? 'Leave empty to keep the current key (' . e(mask_key($stream['stream_key'])) . ')' : 'Paste from YouTube Studio' ?>"
           <?= $stream ? '' : 'required' ?>>
    <small class="muted">YouTube Studio → Go live → copy button next to the key. It is never shown on the public site.</small>
  </label>

  <label class="field"><span>Stream server</span>
    <input name="rtmp_url" required value="<?= e($v['rtmp_url']) ?>">
    <small class="muted">Leave as is for YouTube.</small>
  </label>

  <label class="field"><span>Page to stream</span>
    <input name="page_url" id="page_url" type="url" required value="<?= e($v['page_url']) ?>">
    <span class="pick-row">
      <button type="button" class="btn btn-ghost btn-sm" data-page="https://ffteditt.store/ball/">Ball Drop</button>
      <button type="button" class="btn btn-ghost btn-sm" data-page="https://ffteditt.store/ball/?mode=2d">Ball Drop (2D, light)</button>
      <button type="button" class="btn btn-ghost btn-sm" data-page="https://ffteditt.store/flags/">Flag Arena</button>
      <button type="button" class="btn btn-ghost btn-sm" data-page="https://ffteditt.store/war/">Territory War</button>
      <button type="button" class="btn btn-ghost btn-sm" data-page="https://ffteditt.store/tug/">Tug of War</button>
    </span>
    <small class="muted">The game page the server opens and sends to YouTube. Tap a game to fill it in.</small>
    <script>document.querySelectorAll('[data-page]').forEach(function (b) { b.addEventListener('click', function () { document.getElementById('page_url').value = b.dataset.page; }); });</script>
  </label>

  <div class="row2">
    <label class="field"><span>Server (VPS)</span>
      <select name="server_id">
        <option value="">— None —</option>
        <?php foreach ($servers as $sid => $srv): ?>
          <option value="<?= (int) $sid ?>" <?= (int) $v['server_id'] === $sid ? 'selected' : '' ?>>
            <?= e($srv['name']) ?><?= server_online($srv) ? '' : ' (offline)' ?>
          </option>
        <?php endforeach; ?>
      </select>
      <?php if (!$servers): ?><small class="warn-text">No servers yet. <a href="servers.php">Add one</a>.</small><?php endif; ?>
    </label>

    <label class="field"><span>Quality</span>
      <select name="quality">
        <?php foreach ($q as $k => $info): ?>
          <option value="<?= e($k) ?>" <?= $v['quality'] === $k ? 'selected' : '' ?>><?= e($info['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <label class="field"><span>Frames per second</span>
      <select name="fps">
        <?php foreach ($fpsList as $f): ?>
          <option value="<?= $f ?>" <?= (int) $v['fps'] === $f ? 'selected' : '' ?>><?= $f ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </div>

  <label class="check">
    <input type="checkbox" name="auto_restart" <?= $v['auto_restart'] ? 'checked' : '' ?>>
    Restart automatically if the stream drops
  </label>

  <div class="form-actions">
    <button class="btn btn-blue" type="submit"><?= $stream ? 'Save changes' : 'Add stream' ?></button>
    <a class="btn btn-ghost" href="streams.php">Cancel</a>
  </div>
</form>

<?php if ($stream): ?>
<form method="post" class="danger-zone" onsubmit="return confirm('Delete this stream? This cannot be undone.')">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int) $id ?>">
  <input type="hidden" name="do" value="delete">
  <button class="btn btn-danger" type="submit">Delete stream</button>
</form>
<?php endif; ?>
<?php page_end();
