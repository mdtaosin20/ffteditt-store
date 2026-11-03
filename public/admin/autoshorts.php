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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $do = (string) ($_POST['do'] ?? '');
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
    if ($j && $do === 'title') { $t = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 100); if ($t !== '') { aus_update($id, ['titles' => array_merge([$t], (array) $j['titles'])]); flash('Title saved ✓ (used when it is uploaded)'); } }
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
<script>setTimeout(() => { if (!document.activeElement || !/INPUT|SELECT|TEXTAREA/.test(document.activeElement.tagName)) location.reload(); }, 20000);</script>
<?php page_end();
