<?php
// 🏭 Auto Shorts: one click → the VPS makes the videos, writes titles, and the panel uploads them to YouTube on a schedule.
require __DIR__ . '/../_boot.php';
$admin = require_login();

if (isset($_GET['dl'])) {   // download one finished video
    $j = aus_job((string) $_GET['dl']);
    $f = $j ? aus_file($j['id'], 'mp4') : '';
    if (!$f || !is_file($f)) { http_response_code(404); exit('Not here (already sent to YouTube).'); }
    header('Content-Type: video/mp4'); header('Content-Length: ' . filesize($f)); header('Content-Disposition: attachment; filename="' . $j['id'] . '.mp4"');
    readfile($f); exit;
}
if (isset($_GET['th'])) {   // a thumbnail picture for the list
    $j = aus_job((string) $_GET['th']);
    $f = $j ? aus_file($j['id'], 'jpg') : '';
    if (!$f || !is_file($f)) { http_response_code(404); exit; }
    header('Content-Type: image/jpeg'); header('Cache-Control: max-age=3600'); readfile($f); exit;
}

if (isset($_GET['song'])) {   // listen to an uploaded song
    foreach (aus_songs() as $s) if ($s['id'] === (string) $_GET['song'] && is_file($f = aus_song_file($s))) { header('Content-Type: ' . (['mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'aac' => 'audio/aac', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg'][$s['ext']] ?? 'application/octet-stream')); header('Content-Length: ' . filesize($f)); readfile($f); exit; }
    http_response_code(404); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $do = (string) ($_POST['do'] ?? '');
    if ($do === 'look') {   // 🎛 Video look
        $L = aus_look(); $L['same'] = !empty($_POST['same']); $L['mix'] = !empty($_POST['mix']);
        foreach ((array) ($_POST['lk'] ?? []) as $g => $p) if (isset(aus_games()[$g]) && is_array($p)) $L['g'][$g] = aus_look_from_post($p, (string) $g);
        aus_look_save($L);
        flash('🎛 Video look saved ✓ — the next videos are made like this.');
        redirect('autoshorts.php#look');
    }
    if ($do === 'song_add') {
        $err = isset($_FILES['song']) ? aus_song_add($_FILES['song']) : 'Choose a file.';
        flash($err === '' ? '🎵 Song added ✓' : $err, $err === '' ? 'ok' : 'error');
        redirect('autoshorts.php#songs');
    }
    if ($do === 'song_save' || $do === 'song_del') {
        $l = aus_songs(); $sid = (string) ($_POST['sid'] ?? '');
        foreach ($l as $i => $x) if ($x['id'] === $sid) {
            if ($do === 'song_del') { @unlink(aus_song_file($x)); unset($l[$i]); }
            else { $l[$i]['name'] = mb_substr(trim((string) ($_POST['name'] ?? $x['name'])), 0, 40) ?: $x['name']; $l[$i]['from'] = max(0, round((float) ($_POST['from'] ?? 0), 1)); $l[$i]['to'] = max($l[$i]['from'] + 1, round((float) ($_POST['to'] ?? 30), 1)); }
        }
        aus_songs_save($l); flash($do === 'song_del' ? 'Song removed.' : 'Song saved ✓'); redirect('autoshorts.php#songs');
    }
    $c = aus_cfg();
    if ($do === 'save' || $do === 'make') {
        $c['games'] = array_values(array_intersect((array) ($_POST['games'] ?? []), array_keys(aus_games()))) ?: ['cupkick'];
        $c['count'] = max(1, min(30, (int) ($_POST['count'] ?? 10)));
        $c['level'] = in_array((int) ($_POST['level'] ?? 4), [2, 3, 4], true) ? (int) $_POST['level'] : 4;
        $c['q'] = in_array((int) ($_POST['q'] ?? 1080), [720, 1080], true) ? (int) $_POST['q'] : 1080;
        $c['fps'] = (int) ($_POST['fps'] ?? 30) === 60 ? 60 : 30;
        $c['loop'] = !empty($_POST['loop']);
        $c['ai'] = !empty($_POST['ai']);
        $c['server'] = (int) ($_POST['server'] ?? 0);
        $c['channel'] = (int) ($_POST['channel'] ?? 0);
        $c['every_h'] = max(1, min(48, (int) ($_POST['every_h'] ?? 4)));
        $t = strtotime((string) ($_POST['first_at'] ?? ''));
        $c['first_at'] = $t ?: 0;
        aus_cfg_save($c);
        if ($do === 'make') {
            if (!$c['server'] || !$c['channel']) { flash('Choose the VPS and the YouTube channel first.', 'error'); redirect('autoshorts.php'); }
            $n = aus_make($c['count']);
            log_activity("🏭 Auto Shorts: {$n} videos added", 'info');
            flash("✓ {$n} videos added. The VPS starts within a few seconds (when no live runs on it).");
        } else flash('Saved ✓');
        redirect('autoshorts.php');
    }
    if ($do === 'pause') { $c['paused'] = !$c['paused']; aus_cfg_save($c); flash($c['paused'] ? '⏸ Paused (the video being made now finishes).' : '▶ Running again.'); redirect('autoshorts.php'); }
    $id = (string) ($_POST['id'] ?? '');
    $j = aus_job($id);
    if ($j && $do === 'remove') {
        foreach (['mp4', 'jpg', 'part'] as $e) @unlink(aus_file($id, $e));
        aus_jobs_save(array_filter(aus_jobs(), fn ($x) => $x['id'] !== $id));
        flash('Removed.');
    }
    if ($j && $do === 'retry') { aus_update($id, ['st' => is_file(aus_file($id, 'mp4')) ? 'ready' : 'wait', 'tries' => 0, 'err' => '', 'retry_at' => 0]); flash('Trying again ✓'); }
    if ($j && $do === 'title') { $t = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 100); if ($t !== '') { aus_update($id, ['titles' => array_merge([$t], (array) $j['titles'])] + (empty($j['up_url']) ? ['title_lock' => $t, 'title' => $t] : [])); flash('Title saved ✓ (used when it is uploaded)'); } }
    if ($do === 'clear') { aus_jobs_save(array_filter(aus_jobs(), fn ($x) => !in_array($x['st'], ['uploaded', 'scheduled', 'failed'], true) || ((int) $x['at'] > time() && $x['st'] === 'scheduled'))); flash('Finished ones cleared from the list.'); }
    redirect('autoshorts.php');
}

$c = aus_cfg();
$jobs = aus_jobs();
$servers = db()->query('SELECT id, name, last_seen FROM servers ORDER BY id')->fetchAll();
$chans = db()->query("SELECT id, name, yt_channel, channel_name FROM streams WHERE yt_refresh != '' ORDER BY id")->fetchAll();
$busy = [];
foreach (db()->query("SELECT server_id, COUNT(*) n FROM streams WHERE desired = 'on' GROUP BY server_id") as $r) $busy[(int) $r['server_id']] = (int) $r['n'];
$count = fn ($st) => count(array_filter($jobs, fn ($j) => in_array($j['st'], (array) $st, true)));
$first = $c['first_at'] ?: (time() + 3600);
$ST = ['wait' => ['⏳', 'Waiting', '#9fb0d6'], 'making' => ['⚙️', 'Making video', '#c4b5fd'], 'ready' => ['📦', 'Ready — uploading soon', '#fcd34d'],
       'uploading' => ['📤', 'Uploading to YouTube', '#fcd34d'], 'scheduled' => ['📅', 'Scheduled', '#93c5fd'], 'uploaded' => ['✅', 'Uploaded', '#4ade80'], 'failed' => ['⚠️', 'Failed', '#f87171']];

page_start('Auto Shorts', 'autoshorts', $admin);
?>
<div class="page-head">
  <div>
    <h1>🏭 Auto Shorts</h1>
    <p class="muted">One click: the VPS plays the Shorts games by itself, records each round, writes the title, description and thumbnail — then the panel uploads the videos to YouTube one by one at the times you set (YouTube's own API, no browser tricks).</p>
  </div>
  <div class="head-actions"><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="pause"><button class="btn <?= $c['paused'] ? 'btn-green' : 'btn-ghost' ?>" type="submit"><?= $c['paused'] ? '▶ Resume' : '⏸ Pause' ?></button></form></div>
</div>
<div class="callout">⚠️ <b>Until Google approves our API project</b>, YouTube keeps every video uploaded through the API <b>Private</b> — open YouTube Studio and switch it to <b>Public</b> (one tap each). After approval they go public by themselves at their time. To ask Google: <a href="https://support.google.com/youtube/contact/yt_api_form" target="_blank" rel="noopener">YouTube API audit form ↗</a> (free).</div>

<form method="post" class="form-wide">
  <?= csrf_field() ?>
  <section class="card">
    <h2>Settings</h2>
    <div class="row2">
      <label class="field"><span>📺 YouTube channel (connected with Google)</span>
        <select name="channel"><option value="0">— choose —</option><?php foreach ($chans as $s): ?><option value="<?= (int) $s['id'] ?>" <?= (int) $c['channel'] === (int) $s['id'] ? 'selected' : '' ?>><?= e(($s['yt_channel'] ?: $s['channel_name']) . ' — via “' . $s['name'] . '”') ?></option><?php endforeach; ?></select>
        <small class="muted">The Google connection of this stream is used to upload. No channel here? YouTube Manager → connect one.</small></label>
      <label class="field"><span>🖥 VPS that makes the videos</span>
        <select name="server"><option value="0">— choose —</option><?php foreach ($servers as $s): $on = time() - (int) $s['last_seen'] <= SERVER_OFFLINE_AFTER; ?><option value="<?= (int) $s['id'] ?>" <?= (int) $c['server'] === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name'] . ($on ? '' : ' (offline)') . (!empty($busy[(int) $s['id']]) ? ' — ' . $busy[(int) $s['id']] . ' live running (it waits)' : ' — free ✓')) ?></option><?php endforeach; ?></select>
        <small class="muted">It only makes videos while <b>no live</b> runs on it. About 5–10 minutes per video. Update the worker once: Web Terminal → Update worker.</small></label>
    </div>
    <div class="l" style="margin-top:12px;font-weight:700">🎮 Games (taken in turn)</div>
    <div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:6px">
      <?php foreach (aus_games() as $k => $n): ?><label class="check"><input type="checkbox" name="games[]" value="<?= $k ?>" <?= in_array($k, $c['games'], true) ? 'checked' : '' ?>> <?= e($n) ?></label><?php endforeach; ?>
    </div>
    <div class="row2" style="margin-top:10px">
      <label class="field"><span>🎬 How many videos (one click)</span><input name="count" type="number" min="1" max="30" value="<?= (int) $c['count'] ?>"></label>
      <label class="field"><span>🔥 Level</span><select name="level"><option value="4" <?= $c['level'] === 4 ? 'selected' : '' ?>>Extreme 🔥🔥🔥</option><option value="3" <?= $c['level'] === 3 ? 'selected' : '' ?>>Very hard 🔥🔥</option><option value="2" <?= $c['level'] === 2 ? 'selected' : '' ?>>Wild 🔥</option></select></label>
      <label class="field"><span>📐 Quality</span><select name="q"><option value="1080" <?= $c['q'] === 1080 ? 'selected' : '' ?>>1080p (best for Shorts)</option><option value="720" <?= $c['q'] === 720 ? 'selected' : '' ?>>720p (faster)</option></select></label>
      <label class="field"><span>🎞 Frames</span><select name="fps"><option value="30" <?= $c['fps'] === 30 ? 'selected' : '' ?>>30 fps (faster)</option><option value="60" <?= $c['fps'] === 60 ? 'selected' : '' ?>>60 fps (smoother, 2× slower)</option></select></label>
      <label class="field"><span>📅 First video goes public at</span><input name="first_at" type="datetime-local" value="<?= e(date('Y-m-d\TH:i', $first)) ?>"><small class="muted">Bangladesh time. New videos follow the last one planned.</small></label>
      <label class="field"><span>⏱ Then every … hours</span><input name="every_h" type="number" min="1" max="48" value="<?= (int) $c['every_h'] ?>"><small class="muted">2–6 videos a day is safe for a new channel.</small></label>
    </div>
    <label class="check" style="margin-top:8px"><input type="checkbox" name="loop" <?= $c['loop'] ? 'checked' : '' ?>> 🔁 Loop videos</label>
    <label class="check"><input type="checkbox" name="ai" <?= $c['ai'] ? 'checked' : '' ?>> ✨ AI titles (the OpenAI key in YouTube Manager → AI Replies; else the ready-made ones) — never the same title twice</label>
    <div class="form-actions"><button class="btn btn-ghost" type="submit" name="do" value="save">Save settings</button>
      <button class="btn btn-green" type="submit" name="do" value="make" id="mkBtn">▶ Make <?= (int) $c['count'] ?> videos &amp; schedule them</button></div>
    <script>document.querySelector('input[name=count]').addEventListener('input', (e) => { const n = Math.max(1, Number(e.target.value) || 1); document.getElementById('mkBtn').textContent = `▶ Make ${n} video${n > 1 ? 's' : ''} & schedule ${n > 1 ? 'them' : 'it'}`; });</script>
  </section>
</form>

<?php
$L = aus_look(); $songs = aus_songs(); $mvOk = function_exists('mv_ready') && mv_ready();
$chips = function (string $name, array $opts, array $on, string $type = 'checkbox'): string {
    $h = '<div class="lk-chips">';
    foreach ($opts as $k => $lab) $h .= '<label class="lk-chip"><input type="' . $type . '" name="' . e($name) . '" value="' . e((string) $k) . '"' . (in_array((string) $k, array_map('strval', $on), true) ? ' checked' : '') . '><span>' . e($lab) . '</span></label>';
    return $h . '</div>';
};
$range = function (string $name, $v, $min, $max, $step, string $lo, string $hi): string {
    return '<div class="lk-range"><input type="range" name="' . e($name) . '" min="' . $min . '" max="' . $max . '" step="' . $step . '" value="' . e((string) $v) . '" oninput="this.nextElementSibling.textContent=this.value"><b>' . e((string) $v) . '</b></div><div class="lk-ends"><span>' . e($lo) . '</span><span>' . e($hi) . '</span></div>';
};
$sel = function (string $name, array $opts, $v): string {
    $h = '<select name="' . e($name) . '">'; foreach ($opts as $k => $lab) $h .= '<option value="' . e((string) $k) . '"' . ((string) $k === (string) $v ? ' selected' : '') . '>' . e($lab) . '</option>'; return $h . '</select>';
};
$block = function (string $g, array $k) use ($chips, $range, $sel, $songs, $mvOk): string {
    $n = fn ($f) => "lk[{$g}][{$f}]";
    $sw = fn ($f, $lab) => '<label class="check"><input type="checkbox" name="' . $n($f) . '" value="1"' . (!empty($k[$f]) ? ' checked' : '') . '> ' . $lab . '</label>';
    ob_start(); ?>
    <div class="lk-sec"><h3>🗣 Voice-over (your own voice)</h3>
      <?= $sw('voice', 'On — a new script every video, said in your cloned voice') ?>
      <?php if (!$mvOk): ?><p class="muted small">⚠️ First set up <a href="myvoice.php">🗣 My Voice</a> (ElevenLabs key + your voice). Until then videos are made without a voice.</p><?php endif; ?>
      <div class="row2"><label class="field"><span>Language</span><?= $sel($n('vlang'), AUS_VLANG, $k['vlang']) ?></label></div>
      <?= $sw('subs', '💬 Subtitles of the voice on the video') ?>
      <p class="muted small">The script: your saved scripts in <a href="scripts.php">🎬 Script Studio</a> first (each used once), then a new one by the AI, then ready-made lines. Hook at the start, a line when the ball hits the cup, “comment your answer” at the end. The answer is never said.</p></div>

    <div class="lk-sec"><h3>🎵 Background music</h3>
      <?= $sw('music', 'On') ?>
      <div class="field"><span>Songs to use (none ticked = all songs below)</span>
      <?= $songs ? $chips($n('songs') . '[]', array_column($songs, 'name', 'id'), (array) $k['songs']) : '<p class="muted small">No songs yet — upload them in 🎵 Songs below.</p>' ?></div>
      <div class="field"><span>Music loud</span><?= $range($n('mvol'), $k['mvol'], 0, 1.5, 0.05, '🔇 Off', '150%') ?></div>
      <?= $sw('fade', 'Fade in at the start and out at the end') ?></div>

    <div class="lk-sec"><h3>🏟 Background / stadium</h3>
      <?= $chips($n('places') . '[]', ['rand' => '🎲 Unlimited (new every video)'] + AUS_PLACES, (array) $k['places']) ?>
      <div class="row2"><label class="field"><span>Picture</span><?= $sel($n('quality'), ['sharp' => 'Sharp ✨', 'normal' => 'Normal'], $k['quality']) ?></label></div>
      <?= $sw('grass', '🌱 3D grass') ?></div>

    <div class="lk-sec"><h3>🎮 Game</h3>
      <div class="row2">
        <label class="field"><span>👥 Players</span><?= $sel($n('np'), [6 => '6', 5 => '5', 4 => '4', 3 => '3', 2 => '2'], $k['np']) ?></label>
        <label class="field"><span>Player size</span><?= $sel($n('size'), ['1' => 'Normal', '1.4' => 'Bigger 1.4×', '1.7' => 'Biggest 1.7×'], (string) $k['size']) ?></label>
        <label class="field"><span>⚽ Ball size</span><?= $sel($n('ball'), ['0.38' => 'Small', '0.45' => 'A little bigger', '0.52' => 'Big'], (string) $k['ball']) ?></label>
      </div>
      <div class="field"><span>Ball colours</span><?= $chips($n('bcol') . '[]', ['rand' => '🎲 Unlimited (new every video)'] + AUS_BCOL, (array) $k['bcol']) ?></div>
      <div class="row2">
        <div class="field"><span>Ball speed</span><?= $range($n('speed'), $k['speed'], 0.6, 1.6, 0.1, '🐢 Slow', '🚀 Fast') ?></div>
        <div class="field"><span>All players kick within (seconds)</span><?= $range($n('within'), $k['within'], 1, 6, 0.5, 'all together', 'one by one') ?></div>
        <div class="field"><span>⚽ Kick sound</span><?= $range($n('kvol'), $k['kvol'], 0, 3, 0.1, '🔇 Off', '🔊 300%') ?></div>
      </div>
      <?= $sw('snd', '🔊 Game sounds') ?>
      <p class="muted small">🔥 Level, 🔁 Loop, quality and frames are in Settings above. Who plays is picked at random every video (Cup Kick: countries with many fans in the USA / Europe).</p></div>

    <div class="lk-sec"><h3>🏆 Trophy</h3>
      <div class="field"><span>Design in the middle</span><?= $chips($n('necks') . '[]', AUS_NECK, (array) $k['necks']) ?></div>
      <?= $sw('cup', 'Move / resize the trophy') ?>
      <div class="row2">
        <div class="field"><span>Forward / back</span><?= $range($n('cz'), $k['cz'], -6, 2, 0.5, 'nearer the players', 'nearer you') ?></div>
        <div class="field"><span>Left / right</span><?= $range($n('cx'), $k['cx'], -2.5, 2.5, 0.25, '⬅ Left', 'Right ➡') ?></div>
        <div class="field"><span>Size</span><?= $range($n('cs'), $k['cs'], 0.7, 2, 0.1, 'Smaller', 'Bigger 2×') ?></div>
      </div></div>

    <div class="lk-sec"><h3>🎥 Camera &amp; field</h3>
      <?= $sw('cam', 'On (off = the game’s own camera)') ?>
      <div class="row2">
        <div class="field"><span>Wider view</span><?= $range($n('fov'), $k['fov'], 50, 72, 1, 'Normal', 'Much wider') ?></div>
        <div class="field"><span>Camera forward / back</span><?= $range($n('camz'), $k['camz'], -4, 6, 0.5, '⬆ Closer', 'Further ⬇') ?></div>
        <div class="field"><span>Camera up / down</span><?= $range($n('camy'), $k['camy'], -1.5, 3, 0.25, 'Lower', 'Higher') ?></div>
        <div class="field"><span>Space between players</span><?= $range($n('gap'), $k['gap'], 0.2, 1.4, 0.1, 'Normal', 'Far apart') ?></div>
      </div></div>

    <div class="lk-sec"><h3>🔤 Text on the video</h3>
      <div class="field"><span>Text style</span><?= $chips($n('tstyles') . '[]', AUS_TSTYLE, (array) $k['tstyles']) ?></div>
      <div class="row2">
        <label class="field"><span>Top text</span><textarea name="<?= $n('t1') ?>" rows="3" maxlength="1200"><?= e($k['t1']) ?></textarea></label>
        <label class="field"><span>Question</span><textarea name="<?= $n('t2') ?>" rows="3" maxlength="1200"><?= e($k['t2']) ?></textarea></label>
        <label class="field"><span>Text at the end</span><textarea name="<?= $n('t3') ?>" rows="3" maxlength="1200"><?= e($k['t3']) ?></textarea></label>
      </div>
      <p class="muted small">One text per line — every video takes one of them. An empty box = no text there.</p>
      <div class="row2"><label class="field"><span>Channel name on the field (empty = none)</span><input name="<?= $n('chan') ?>" maxlength="30" value="<?= e($k['chan']) ?>" placeholder="@YourChannel"></label></div></div>
    <?php return (string) ob_get_clean();
};
?>
<form method="post" class="form-wide" id="look">
  <?= csrf_field() ?><input type="hidden" name="do" value="look">
  <section class="card">
    <h2>🎛 Video look</h2>
    <p class="muted">Everything the game menu has — set it here once and the VPS makes every video exactly like this. Tick several choices (stadiums, ball colours, trophy designs, text styles, songs, texts) and every video takes a different one of them.</p>
    <label class="check"><input type="checkbox" name="mix" value="1" <?= $L['mix'] ? 'checked' : '' ?>> 🎲 Make every video different (off = always the first ticked choice)</label>
    <label class="check"><input type="checkbox" name="same" value="1" <?= $L['same'] ? 'checked' : '' ?> onchange="document.getElementById('lkPer').hidden=this.checked;document.getElementById('lkAll').hidden=!this.checked"> Same look for all 3 games</label>
    <div id="lkAll" <?= $L['same'] ? '' : 'hidden' ?>><?= $L['same'] ? $block('cupkick', $L['g']['cupkick']) : '' ?></div>
    <div id="lkPer" <?= $L['same'] ? 'hidden' : '' ?>>
      <?php if (!$L['same']): foreach (aus_games() as $g => $gn): ?><details class="lk-game" <?= $g === 'cupkick' ? 'open' : '' ?>><summary><?= e($gn) ?></summary><?= $block($g, $L['g'][$g]) ?></details><?php endforeach; else: ?><p class="muted small">Save once with this box unticked to set each game on its own.</p><?php endif; ?>
    </div>
    <div class="form-actions"><button class="btn btn-green" type="submit">💾 Save look</button></div>
  </section>
</form>

<section class="card" id="songs">
  <h2>🎵 Songs (for the background music)</h2>
  <p class="muted">Kept on the panel (up to 25 MB each). Set which part plays (start / end in seconds). Use music you are allowed to use on YouTube (e.g. the YouTube Audio Library) — other music can block or demonetise the video.</p>
  <form method="post" enctype="multipart/form-data" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center"><?= csrf_field() ?><input type="hidden" name="do" value="song_add"><input type="file" name="song" accept="audio/*" required><button class="btn btn-ghost" type="submit">➕ Upload song</button></form>
  <?php foreach ($songs as $s): ?>
  <form method="post" class="lk-song"><?= csrf_field() ?><input type="hidden" name="sid" value="<?= e($s['id']) ?>">
    <input name="name" value="<?= e($s['name']) ?>" maxlength="40" style="flex:1 1 140px">
    <label class="small">Start <input name="from" type="number" step="0.1" min="0" value="<?= e((string) $s['from']) ?>" style="width:74px"></label>
    <label class="small">End <input name="to" type="number" step="0.1" min="1" value="<?= e((string) $s['to']) ?>" style="width:74px"></label>
    <audio controls preload="none" src="autoshorts.php?song=<?= e($s['id']) ?>" style="height:34px;max-width:220px"></audio>
    <button class="btn btn-ghost btn-sm" name="do" value="song_save" type="submit">Save</button>
    <button class="btn btn-ghost btn-sm" name="do" value="song_del" type="submit" onclick="return confirm('Remove this song?')">🗑</button>
  </form>
  <?php endforeach; ?>
</section>
<style>
.lk-sec{border:1px solid #2a3a6a;border-radius:12px;padding:10px 12px;margin-top:12px}
.lk-sec h3{margin:0 0 6px;font-size:15px}
.lk-chips{display:flex;flex-wrap:wrap;gap:6px;margin:4px 0}
.lk-chip input{position:absolute;opacity:0;width:1px;height:1px}
.lk-chip span{display:inline-block;padding:6px 11px;border-radius:99px;background:#1b2350;color:#cfe0ff;font-size:13px;cursor:pointer;border:1px solid #2a3a6a}
.lk-chip input:checked+span{background:#2563eb;color:#fff;border-color:#60a5fa}
.lk-chip input:focus-visible+span{outline:2px solid #ffd84a}
.lk-range{display:flex;align-items:center;gap:8px}.lk-range input{flex:1}.lk-range b{min-width:34px;text-align:right;font-size:13px;color:#9fd0ff}
.lk-ends{display:flex;justify-content:space-between;font-size:11px;color:#8fa6d6}
.lk-game{border:1px solid #2a3a6a;border-radius:12px;padding:8px 12px;margin-top:10px}.lk-game summary{font-weight:800;cursor:pointer}
.lk-song{display:flex;gap:8px;flex-wrap:wrap;align-items:center;border:1px solid #2a3a6a;border-radius:10px;padding:8px;margin-top:8px}
</style>

<section class="card">
  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap"><h2 style="margin:0">📋 Queue</h2>
    <span class="muted small">✅ <?= $count('uploaded') ?> uploaded · 📅 <?= $count('scheduled') ?> scheduled · ⚙️ <?= $count(['making', 'ready', 'uploading']) ?> in work · ⏳ <?= $count('wait') ?> waiting<?= $count('failed') ? ' · ⚠️ ' . $count('failed') . ' failed' : '' ?></span>
    <form method="post" style="margin-left:auto"><?= csrf_field() ?><input type="hidden" name="do" value="clear"><button class="btn btn-ghost btn-sm" type="submit">Clear finished</button></form></div>
  <?php if (!$jobs): ?><p class="muted">Nothing yet — press ▶ Make videos.</p><?php endif; ?>
  <?php foreach (array_reverse($jobs) as $j): [$ic, $lb, $col] = $ST[$j['st']] ?? ['•', $j['st'], '#fff']; $hasThumb = is_file(aus_file($j['id'], 'jpg')); ?>
  <div style="display:flex;gap:12px;align-items:center;border:1px solid #2a3a6a;border-radius:12px;padding:8px;margin-top:8px;flex-wrap:wrap">
    <?php if ($hasThumb): ?><img src="autoshorts.php?th=<?= e($j['id']) ?>" alt="" style="width:54px;height:96px;border-radius:8px;object-fit:cover"><?php else: ?><div style="width:54px;height:96px;border-radius:8px;background:#1b2350;display:flex;align-items:center;justify-content:center;font-size:22px">🎬</div><?php endif; ?>
    <div style="flex:1;min-width:220px">
      <div><b><?= e($j['title'] ?: (($j['titles'] ?? [])[0] ?? '—')) ?></b></div>
      <div class="muted small"><?= e(aus_games()[$j['game']] ?? $j['game']) ?><?= !empty($j['info']['names']) ? ' · ' . e(implode(', ', array_slice($j['info']['names'], 0, 6))) : '' ?><?= !empty($j['info']['winner']) ? ' · answer: <b>' . e($j['info']['winner']) . '</b>' : '' ?></div>
      <?php if (in_array($j['st'], ['making', 'uploading'], true)): ?><div style="height:8px;border-radius:9px;background:#1b2350;margin-top:6px;overflow:hidden"><i style="display:block;height:100%;width:<?= (int) $j['prog'] ?>%;background:linear-gradient(90deg,#7c5cff,#22c55e)"></i></div><?php endif; ?>
      <?php if (!empty($j['look'])): ?><div class="muted small" style="margin-top:2px">🎛 <?= e($j['look']) ?></div><?php endif; ?>
      <?php if ($j['err']): ?><div class="small" style="color:#fbbf24;margin-top:4px"><?= e($j['err']) ?></div><?php endif; ?>
      <?php if (in_array($j['st'], ['wait', 'making', 'ready'], true)): ?>
      <form method="post" style="display:flex;gap:6px;margin-top:6px"><?= csrf_field() ?><input type="hidden" name="do" value="title"><input type="hidden" name="id" value="<?= e($j['id']) ?>"><input name="title" maxlength="100" placeholder="✏️ Your own title (optional)" style="flex:1"><button class="btn btn-ghost btn-sm" type="submit">Save</button></form>
      <?php endif; ?>
    </div>
    <div style="text-align:right;min-width:170px">
      <div style="font-weight:800;color:<?= $col ?>"><?= $ic ?> <?= e($lb) ?><?= in_array($j['st'], ['making', 'uploading'], true) ? ' ' . (int) $j['prog'] . '%' : '' ?></div>
      <div class="muted small"><?= $j['st'] === 'uploaded' ? 'public now' : 'public: ' . e(date('d M, h:i A', (int) $j['at'])) ?></div>
      <div style="display:flex;gap:6px;justify-content:flex-end;margin-top:6px;flex-wrap:wrap">
        <?php if ($j['vid']): ?><a class="btn btn-ghost btn-sm" href="https://studio.youtube.com/video/<?= e($j['vid']) ?>/edit" target="_blank" rel="noopener">YouTube Studio ↗</a><?php endif; ?>
        <?php if (is_file(aus_file($j['id'], 'mp4'))): ?><a class="btn btn-ghost btn-sm" href="autoshorts.php?dl=<?= e($j['id']) ?>">📥</a><?php endif; ?>
        <?php if ($j['st'] === 'failed'): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="retry"><input type="hidden" name="id" value="<?= e($j['id']) ?>"><button class="btn btn-ghost btn-sm" type="submit">↻ Retry</button></form><?php endif; ?>
        <form method="post" onsubmit="return confirm('Remove this video from the list?')"><?= csrf_field() ?><input type="hidden" name="do" value="remove"><input type="hidden" name="id" value="<?= e($j['id']) ?>"><button class="btn btn-ghost btn-sm" type="submit">🗑</button></form>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</section>
<script>
let dirty = false; document.querySelectorAll('#look, #songs form, .form-wide').forEach((f) => f.addEventListener('input', () => { dirty = true; }));
setInterval(() => { if (!dirty && (!document.activeElement || !/INPUT|SELECT|TEXTAREA|AUDIO/.test(document.activeElement.tagName))) location.reload(); }, 20000);   // the queue stays fresh (not while you change something)
</script>
<?php page_end();
