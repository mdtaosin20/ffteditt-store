<?php
require __DIR__ . '/../_boot.php';
require APP_DIR . '/dash.php';
$admin = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (($_POST['do'] ?? '') === 'google_keys') {
        $cid = trim((string) ($_POST['client_id'] ?? ''));
        $sec = trim((string) ($_POST['client_secret'] ?? ''));
        if (!preg_match('/^[0-9]+-[a-z0-9]+\.apps\.googleusercontent\.com$/i', $cid)) {
            flash('Client ID looks wrong. It ends with .apps.googleusercontent.com', 'error');
        } elseif ($sec === '' && yt_client()['secret'] === '') {
            flash('Paste the Client Secret too.', 'error');
        } elseif ($sec !== '' && !preg_match('/^[A-Za-z0-9_\-]{10,80}$/', $sec)) {
            flash('Client Secret looks wrong. Copy it again from Google Cloud.', 'error');
        } else {
            setting_set('yt_client_id', $cid);
            if ($sec !== '') setting_set('yt_client_secret', $sec);
            log_activity('Google keys for YouTube saved', 'ok');
            flash('Saved. Now press "Connect YouTube" next to a channel.');
        }
    }
    if (($_POST['do'] ?? '') === 'chat_cfg') {
        $name = mb_strtoupper(trim(preg_replace('/[^\p{L}\p{N} .!-]/u', '', (string) ($_POST['robot_name'] ?? '')) ?? ''));
        chat_save([
            'enabled'    => isset($_POST['enabled']),
            'robot'      => isset($_POST['robot']),
            'voice'      => isset($_POST['voice']),
            'box'        => isset($_POST['box']),
            'subs'       => isset($_POST['subs']),
            'idle'       => isset($_POST['idle']),
            'robot_name' => mb_substr($name !== '' ? $name : 'ROBO HOST', 0, 20),
        ]);
        log_activity('Live chat & robot settings saved', 'ok');
        flash('Saved. The game pages pick it up within a few seconds.');
    }
    redirect('youtube.php');
}

$chat = chat_cfg();
$ready   = yt_ready();
$client  = yt_client();
$streams = db()->query('SELECT * FROM streams ORDER BY id')->fetchAll();

page_start('YouTube Manager', 'youtube', $admin);
?>
<div class="page-head">
  <div>
    <h1>YouTube Manager</h1>
    <p class="muted">Your YouTube channels, stream keys, and the live video’s title, description and thumbnail.</p>
  </div>
  <div class="head-actions"><a class="btn btn-blue" href="stream_edit.php"><?= icon('plus') ?>Add Channel</a></div>
</div>

<section class="panel">
  <div class="ph"><h2><span class="yt yt-lg"><?= icon('play') ?></span>Channels</h2></div>
  <?= dash_youtube('youtube.php', 200) ?>
</section>

<section class="panel">
  <div class="ph"><h2><?= icon('youtube') ?>Live title, description &amp; thumbnail</h2>
    <?= $ready ? '<span class="tag tag-green">Google keys saved</span>' : '<span class="tag tag-orange">Setup needed</span>' ?></div>
  <p class="muted small">Connect a channel once. After that, every time the stream goes live, the panel puts your title, description and thumbnail on the live video by itself (about 1 minute after it starts).</p>
  <div class="tbl-wrap"><table class="tbl tbl-sm">
    <thead><tr><th>Channel</th><th>YouTube</th><th>Last result</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if (!$streams): ?><tr><td colspan="4" class="td-empty">Add a channel first.</td></tr><?php endif; ?>
    <?php foreach ($streams as $s): $sid = (int) $s['id']; ?>
      <tr>
        <td><span class="cell-game"><?= game_badge(game_of_url($s['page_url'])) ?><?= e($s['channel_name'] ?: $s['name']) ?></span></td>
        <td><?php if ($s['yt_refresh'] !== ''): ?><span class="tag tag-green">Connected</span> <small class="muted"><?= e($s['yt_channel']) ?></small>
            <?php else: ?><span class="tag tag-gray">Not connected</span><?php endif; ?></td>
        <td class="small"><?php if ($s['yt_msg'] !== ''): ?><span class="<?= $s['yt_msg_ok'] ? 'ok-text' : 'warn-text' ?>"><?= e($s['yt_msg']) ?></span><?php else: ?><span class="muted">—</span><?php endif; ?></td>
        <td><div class="icons" style="gap:8px;flex-wrap:wrap">
          <?php if ($s['yt_refresh'] === ''): ?>
            <form method="post" action="yt_oauth.php"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $sid ?>">
              <button class="btn btn-red btn-sm" type="submit" <?= $ready ? '' : 'disabled title="Do step 1 first"' ?>><?= icon('youtube') ?>Connect YouTube</button></form>
          <?php endif; ?>
          <a class="btn btn-ghost btn-sm" href="yt_live.php?id=<?= $sid ?>"><?= icon('pencil') ?>Title &amp; thumbnail</a>
        </div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>

<section class="panel">
  <div class="ph"><h2><?= icon('bell') ?>Live chat &amp; robot host</h2>
    <?= $chat['enabled'] ? '<span class="tag tag-green">On</span>' : '<span class="tag tag-gray">Off</span>' ?></div>
  <p class="muted small">The VPS reads your live chat. Viewers who write a country get their name on that flag (Ball Drop: “M” or “R”), the busiest countries show as Top Fans, and the robot host welcomes and answers people on screen.</p>
  <form method="post" class="form" style="max-width:none">
    <?= csrf_field() ?><input type="hidden" name="do" value="chat_cfg">
    <div class="row2">
      <label class="check"><input type="checkbox" name="enabled" <?= $chat['enabled'] ? 'checked' : '' ?>> Read the live chat</label>
      <label class="check"><input type="checkbox" name="robot" <?= $chat['robot'] ? 'checked' : '' ?>> Robot host talks on screen</label>
 <label class="check"><input type="checkbox" name="voice" <?= $chat['voice'] ? 'checked' : '' ?>> Robot speaks out loud (replaces the old commentator voice)</label>
      <label class="check"><input type="checkbox" name="box" <?= $chat['box'] ? 'checked' : '' ?>> Show the chat box</label>
      <label class="check"><input type="checkbox" name="subs" <?= $chat['subs'] ? 'checked' : '' ?>> New subscriber banner</label>
      <label class="check"><input type="checkbox" name="idle" <?= $chat['idle'] ? 'checked' : '' ?>> Robot asks for likes, subscribes &amp; comments</label>
      <label class="field"><span>Robot name</span><input name="robot_name" maxlength="20" value="<?= e($chat['robot_name']) ?>"></label>
    </div>
    <div class="form-actions"><button class="btn btn-blue" type="submit">Save</button>
      <a class="btn btn-ghost" href="/flags/?chatdemo=1" target="_blank" rel="noopener">Preview Flag Battle</a>
      <a class="btn btn-ghost" href="/ball/?chatdemo=1" target="_blank" rel="noopener">Preview Ball Drop</a></div>
  </form>
  <div class="tbl-wrap" style="margin-top:14px"><table class="tbl tbl-sm">
    <thead><tr><th>Channel</th><th>Chat reader (on the VPS)</th><th>Messages</th><th>Last message</th></tr></thead>
    <tbody>
    <?php foreach ($streams as $s): ?>
      <tr>
        <td><span class="cell-game"><?= game_badge(game_of_url($s['page_url'])) ?><?= e($s['channel_name'] ?: $s['name']) ?></span></td>
        <td class="small"><?php if ($s['status'] !== 'live'): ?><span class="muted">Starts when the stream is live</span>
            <?php elseif ($s['chat_status'] === ''): ?><span class="muted">Waiting for the live video…<?= $s['yt_refresh'] === '' && $s['channel_url'] === '' ? ' (connect YouTube or add the channel link)' : '' ?></span>
            <?php else: ?><span class="<?= str_starts_with($s['chat_status'], 'Reading') ? 'ok-text' : 'warn-text' ?>"><?= e($s['chat_status']) ?></span><?php endif; ?></td>
        <td><?= (int) $s['chat_count'] ?></td>
        <td class="small muted"><?= $s['chat_seen'] ? e(ago((int) $s['chat_seen'])) : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>

<section class="panel">
  <div class="ph"><h2><?= icon('gear') ?>Step 1 · Google keys (one time only)</h2></div>
  <details <?= $ready ? '' : 'open' ?>>
    <summary class="ph-link" style="cursor:pointer;margin-bottom:12px">How to get the Client ID and Client Secret (about 10 minutes)</summary>
    <ol class="steps">
      <li>Open <a href="https://console.cloud.google.com/projectcreate" target="_blank" rel="noopener">Google Cloud → New project</a>. Name it <code>GameStream</code> → <strong>Create</strong>.</li>
      <li>Open <a href="https://console.cloud.google.com/apis/library/youtube.googleapis.com" target="_blank" rel="noopener">YouTube Data API v3</a> → <strong>Enable</strong>.</li>
      <li>Open <a href="https://console.cloud.google.com/auth/branding" target="_blank" rel="noopener">Google Auth Platform</a> → <strong>Get started</strong>. App name <code>GameStream</code>, your email → Audience: <strong>External</strong> → Create.</li>
      <li>In <strong>Audience</strong> press <strong>Publish app</strong> → Confirm. (Otherwise the connection stops working after 7 days.)</li>
      <li>Open <a href="https://console.cloud.google.com/auth/clients/create" target="_blank" rel="noopener">Clients → Create client</a>. Type: <strong>Web application</strong>.
        Under <strong>Authorized redirect URIs</strong> press Add URI and paste exactly:<br>
        <code id="ru"><?= e(yt_redirect_uri()) ?></code> <button type="button" class="btn btn-ghost btn-sm" onclick="navigator.clipboard.writeText(document.getElementById('ru').textContent);this.textContent='Copied'">Copy</button></li>
      <li>Press <strong>Create</strong>. Copy the <strong>Client ID</strong> and <strong>Client secret</strong> into the boxes below → Save.</li>
      <li>Press <strong>Connect YouTube</strong> above. Google shows “Google hasn’t verified this app” — that is normal for your own app: press <strong>Advanced</strong> → <strong>Go to GameStream</strong> → tick the box → <strong>Continue</strong>.</li>
    </ol>
  </details>
  <form method="post" class="form" style="margin-top:14px">
    <?= csrf_field() ?><input type="hidden" name="do" value="google_keys">
    <div class="row2">
      <label class="field"><span>Client ID</span>
        <input name="client_id" required spellcheck="false" autocomplete="off" placeholder="1234…apps.googleusercontent.com" value="<?= e($client['id']) ?>"></label>
      <label class="field"><span>Client Secret</span>
        <input name="client_secret" type="password" spellcheck="false" autocomplete="off"
               placeholder="<?= $client['secret'] !== '' ? 'Saved — leave empty to keep it' : 'GOCSPX-…' ?>" <?= $client['secret'] !== '' ? '' : 'required' ?>></label>
    </div>
    <div class="form-actions"><button class="btn btn-blue" type="submit">Save Google keys</button></div>
  </form>
</section>

<section class="panel">
  <div class="ph"><h2><?= icon('youtube') ?>Where to find the stream key</h2></div>
  <ol class="steps">
    <li>Open <a href="https://studio.youtube.com" target="_blank" rel="noopener">YouTube Studio</a> → <strong>Create</strong> → <strong>Go live</strong>.</li>
    <li>Choose <strong>Stream</strong>, then press the copy button next to <strong>Stream key</strong>.</li>
    <li>Here: <strong>Add Channel</strong> (or the gear of a channel) → paste it into “YouTube stream key” → Save.</li>
    <li>Add your channel link (e.g. <code>https://www.youtube.com/@yourname</code>) so the <strong>Watch</strong> button opens your live.</li>
  </ol>
</section>
<?php page_end();
