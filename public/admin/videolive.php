<?php
// 🎞 Video Live: your own recorded videos sent to YouTube as a live (when no game runs). See app/vlive.php.
require __DIR__ . '/../_boot.php';
$admin = require_login();
$servers = all_servers();
$q = qualities();
$fpsList = [24, 25, 30, 60];

if (isset($_GET['watch'])) {   // play a library video in the panel
    $v = vl_video((string) $_GET['watch']);
    if (!$v || ($v['kind'] ?? 'file') !== 'file' || !is_file($f = vl_file($v))) { http_response_code(404); exit; }
    header('Location: ' . vl_src_url($v)); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $do = (string) ($_POST['do'] ?? '');
    $now = time();
    $json = function (array $a, int $code = 200): void { http_response_code($code); header('Content-Type: application/json'); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; };

    // ---- upload in pieces (4 MB each), so big videos pass the web server's limits
    if ($do === 'up_begin') {
        $name = mb_substr(trim((string) ($_POST['name'] ?? 'Video')), 0, 80);
        $size = (int) ($_POST['size'] ?? 0);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['mp4', 'mov', 'webm', 'mkv', 'm4v'], true)) $json(['error' => 'Use an mp4, mov, webm, mkv or m4v video.'], 400);
        if ($size <= 0 || $size > 4 * 1073741824) $json(['error' => 'The video must be smaller than 4 GB (for bigger ones paste a link).'], 400);
        $free = @disk_free_space(vl_dir());
        if ($free !== false && $free < $size + 512 * 1048576) $json(['error' => 'Not enough free space on the website for this video — paste a link instead.'], 400);
        $id = 'v' . bin2hex(random_bytes(5));
        @unlink(vl_dir() . "/{$id}.part");
        $l = vl_videos(); $l[] = ['id' => $id, 'kind' => 'file', 'ext' => $ext, 'name' => pathinfo($name, PATHINFO_FILENAME) ?: 'Video', 'size' => $size, 'have' => 0, 'ready' => false, 'added' => $now];
        vl_videos_save($l);
        $json(['id' => $id]);
    }
    if ($do === 'up_part') {
        $id = (string) ($_POST['id'] ?? ''); $v = vl_video($id);
        if (!$v || !empty($v['ready'])) $json(['error' => 'Unknown upload'], 400);
        $part = vl_dir() . '/' . preg_replace('/[^a-z0-9]/', '', $id) . '.part';
        $off = (int) ($_POST['off'] ?? -1); $have = is_file($part) ? filesize($part) : 0;
        if ($off !== $have) $json(['error' => 'wrong piece', 'have' => $have], 409);
        $f = $_FILES['blob'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name']) || $f['size'] > 8 * 1048576) $json(['error' => 'The piece did not arrive'], 400);
        file_put_contents($part, file_get_contents($f['tmp_name']), FILE_APPEND | LOCK_EX);
        clearstatcache();
        $have = filesize($part);
        if ($have >= (int) $v['size']) {
            rename($part, vl_file($v));
            $l = vl_videos(); foreach ($l as &$x) if ($x['id'] === $id) { $x['ready'] = true; $x['have'] = $have; $x['size'] = $have; } unset($x); vl_videos_save($l);
            log_activity("🎞 Video Live: video uploaded — {$v['name']} (" . round($have / 1048576) . ' MB)', 'ok');
        }
        $json(['have' => $have]);
    }
    if ($do === 'link') {
        $url = trim((string) ($_POST['url'] ?? ''));
        $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 80);
        if (!preg_match('#^https?://[^\s]{4,}$#i', $url)) { flash('Paste a full link starting with https://', 'error'); redirect('videolive.php#lib'); }
        if (preg_match('#drive\.google\.com/file/d/([A-Za-z0-9_-]+)#', $url, $m)) $url = 'https://drive.usercontent.google.com/download?id=' . $m[1] . '&export=download&confirm=t';   // a Google Drive share link → its download link
        if (preg_match('#^https://www\.dropbox\.com/#', $url)) $url = preg_replace('/([?&])dl=0/', '$1dl=1', $url) . (str_contains($url, 'dl=') ? '' : (str_contains($url, '?') ? '&' : '?') . 'dl=1');
        $l = vl_videos(); $l[] = ['id' => 'v' . bin2hex(random_bytes(5)), 'kind' => 'link', 'url' => $url, 'name' => $name !== '' ? $name : (basename((string) parse_url($url, PHP_URL_PATH)) ?: 'Video link'), 'size' => 0, 'ready' => true, 'added' => $now];
        vl_videos_save($l);
        flash('🔗 Link added — the VPS downloads it itself when a Video Live uses it.');
        redirect('videolive.php#lib');
    }
    if ($do === 'vid_del' || $do === 'vid_name') {
        $id = (string) ($_POST['vid'] ?? ''); $l = vl_videos();
        foreach ($l as $i => $x) if ($x['id'] === $id) {
            if ($do === 'vid_del') { if (($x['kind'] ?? 'file') === 'file') { @unlink(vl_file($x)); @unlink(vl_dir() . '/' . $id . '.part'); } unset($l[$i]); }
            else $l[$i]['name'] = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 80) ?: $x['name'];
        }
        vl_videos_save($l);
        flash($do === 'vid_del' ? 'Video removed.' : 'Saved ✓');
        redirect('videolive.php#lib');
    }

    // ---- the Video Lives (stream rows with source = 'video')
    $id = (int) ($_POST['id'] ?? 0);
    $row = null;
    if ($id) { $st = db()->prepare("SELECT * FROM streams WHERE id = ? AND source = 'video'"); $st->execute([$id]); $row = $st->fetch() ?: null; }
    if ($do === 'new') {
        db()->prepare("INSERT INTO streams (name, stream_key, page_url, server_id, quality, fps, auto_restart, source, vlist, created_at, updated_at)
                       VALUES (?, '', 'https://ffteditt.store/', ?, '1080p-v', 30, 1, 'video', ?, ?, ?)")
            ->execute(['Video Live ' . (1 + (int) db()->query("SELECT COUNT(*) FROM streams WHERE source = 'video'")->fetchColumn()), array_key_first($servers), json_encode(['ids' => [], 'loop' => true, 'shuffle' => false]), $now, $now]);
        $nid = (int) db()->lastInsertId();
        flash('New Video Live added — paste its stream key, pick the videos and press Save.');
        redirect('videolive.php#v' . $nid);
    }
    if (!$row) { flash('Video Live not found.', 'error'); redirect('videolive.php'); }
    if ($do === 'save') {
        $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 60) ?: $row['name'];
        $key = preg_replace('/\s+/', '', (string) ($_POST['stream_key'] ?? ''));
        if ($key !== '' && !preg_match('/^[A-Za-z0-9_-]{8,128}$/', $key)) { flash('That stream key looks wrong. Copy it again from YouTube Studio.', 'error'); redirect('videolive.php#v' . $id); }
        $key = $key !== '' ? $key : $row['stream_key'];
        $srv = ($_POST['server_id'] ?? '') === '' ? null : (int) $_POST['server_id'];
        if ($srv !== null && !isset($servers[$srv])) $srv = null;
        $qual = isset($q[$_POST['quality'] ?? '']) ? (string) $_POST['quality'] : $row['quality'];
        $fps = in_array((int) ($_POST['fps'] ?? 0), $fpsList, true) ? (int) $_POST['fps'] : (int) $row['fps'];
        $all = array_column(vl_videos(), 'id');
        $ids = array_values(array_intersect(array_map('strval', (array) ($_POST['ids'] ?? [])), $all));
        $vl = json_encode(['ids' => $ids, 'loop' => !empty($_POST['loop']), 'shuffle' => !empty($_POST['shuffle'])]);
        $restart = $key !== $row['stream_key'] || (int) $srv !== (int) $row['server_id'] || $qual !== $row['quality'] || $fps !== (int) $row['fps'] || $vl !== $row['vlist'];
        if ($row['desired'] === 'on' && $key !== $row['stream_key']) {
            $dup = db()->prepare("SELECT name FROM streams WHERE stream_key = ? AND id != ? AND desired = 'on'"); $dup->execute([$key, $id]);
            if (($dn = $dup->fetchColumn()) !== false) { flash("“{$dn}” is running with this same stream key — two lives cannot share one key.", 'error'); redirect('videolive.php#v' . $id); }
        }
        db()->prepare('UPDATE streams SET name = ?, stream_key = ?, server_id = ?, quality = ?, fps = ?, vlist = ?, rev = rev + ?, updated_at = ? WHERE id = ?')
            ->execute([$name, $key, $srv, $qual, $fps, $vl, $restart ? 1 : 0, $now, $id]);
        flash('Saved ✓' . ($restart && $row['desired'] === 'on' ? ' — the live restarts with the new settings in a few seconds.' : ''));
        redirect('videolive.php#v' . $id);
    }
    if ($do === 'start') {
        $c = vl_cfg($row);
        if ($row['stream_key'] === '' || !$row['server_id']) { flash('Paste the stream key and choose a VPS first.', 'error'); redirect('videolive.php#v' . $id); }
        if (!$c['ids']) { flash('Tick at least one video first (and press Save).', 'error'); redirect('videolive.php#v' . $id); }
        $dup = db()->prepare("SELECT name FROM streams WHERE stream_key = ? AND id != ? AND desired = 'on'"); $dup->execute([$row['stream_key'], $id]);
        if (($dn = $dup->fetchColumn()) !== false) { flash("“{$dn}” is already live with this same stream key. Stop it first — two lives cannot share one key.", 'error'); redirect('videolive.php#v' . $id); }
        db()->prepare("UPDATE streams SET desired = 'on', error = NULL, updated_at = ?, run_id = ? WHERE id = ?")->execute([$now, $now, $id]);
        log_activity("🎞 Video Live start requested: {$row['name']}");
        flash('Starting… the VPS first downloads and prepares the videos (only the first time) — then it goes live.');
    }
    if ($do === 'stop') {
        db()->prepare("UPDATE streams SET desired = 'off', updated_at = ? WHERE id = ?")->execute([$now, $id]);
        log_activity("🎞 Video Live stop requested: {$row['name']}");
        flash('Stopping…');
    }
    if ($do === 'delete') {
        if ($row['desired'] === 'on') { flash('Stop it first.', 'error'); redirect('videolive.php#v' . $id); }
        db()->prepare('DELETE FROM streams WHERE id = ?')->execute([$id]);
        flash('Video Live deleted.');
    }
    redirect('videolive.php#v' . $id);
}

$rows = db()->query("SELECT * FROM streams WHERE source = 'video' ORDER BY id")->fetchAll();
$lib = vl_videos();
$fmtSize = fn (int $b) => $b >= 1073741824 ? round($b / 1073741824, 2) . ' GB' : max(1, (int) round($b / 1048576)) . ' MB';
$dur = function (int $s): string { $h = intdiv($s, 3600); $m = intdiv($s % 3600, 60); return ($h ? $h . ':' . str_pad((string) $m, 2, '0', STR_PAD_LEFT) : $m) . ':' . str_pad((string) ($s % 60), 2, '0', STR_PAD_LEFT); };

page_start('Video Live', 'videolive', $admin);
?>
<div class="page-head">
  <div>
    <h1>🎞 Video Live</h1>
    <p class="muted">Send your own recorded videos to YouTube as a live — for times when no game is running. The VPS downloads each video once and gets it ready (the first time takes a while); after that the live uses almost no CPU.</p>
  </div>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="new"><button class="btn btn-blue" type="submit">➕ New Video Live</button></form>
</div>
<div class="callout">⚠️ The same video played again and again for days can count as <b>“repetitive content”</b> for YouTube monetization. Use several different videos (and 🔀 mix), and only videos you own.</div>

<?php if (!$rows): ?><section class="card"><p class="muted">No Video Live yet — press <b>➕ New Video Live</b>.</p></section><?php endif; ?>

<?php foreach ($rows as $r): $c = vl_cfg($r); $on = $r['desired'] === 'on'; $stat = (string) $r['status']; $sp = (string) ($r['speed_now'] ?? ''); ?>
<section class="card vl" id="v<?= (int) $r['id'] ?>">
  <?php if ($on && $stat === 'live'): ?>
    <div class="vl-st live">🔴 LIVE · <?= $r['started_at'] ? e($dur(time() - (int) $r['started_at'])) : '' ?> · <?= count($c['ids']) ?> video<?= count($c['ids']) === 1 ? '' : 's' ?><?= $c['loop'] ? ' · 🔁' : '' ?> · <?= e($servers[(int) $r['server_id']]['name'] ?? '') ?></div>
  <?php elseif ($on && $stat === 'error'): ?>
    <div class="vl-st err">⚠️ <?= e((string) $r['error']) ?></div>
  <?php elseif ($on): ?>
    <div class="vl-st wait">⏳ <?= str_starts_with($sp, 'dl ') ? 'VPS downloading the videos… ' . e(substr($sp, 3)) : (str_starts_with($sp, 'prep ') ? 'VPS getting the videos ready for live… ' . e(substr($sp, 5)) . ' (only the first time)' : 'Starting…') ?></div>
  <?php else: ?>
    <div class="vl-st off">⏹ Off</div>
  <?php endif; ?>
  <form method="post" class="form">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
    <div class="row2">
      <label class="field"><span>Name</span><input name="name" maxlength="60" value="<?= e($r['name']) ?>"></label>
      <label class="field"><span>📺 YouTube stream key</span><input name="stream_key" type="password" autocomplete="off" placeholder="<?= $r['stream_key'] !== '' ? 'Saved (' . e(mask_key($r['stream_key'])) . ') — leave empty to keep' : 'Paste from YouTube Studio' ?>"></label>
    </div>
    <div class="row2">
      <label class="field"><span>🖥 VPS</span><select name="server_id"><option value="">— None —</option>
        <?php foreach ($servers as $sid => $srv): ?><option value="<?= (int) $sid ?>" <?= (int) $r['server_id'] === (int) $sid ? 'selected' : '' ?>><?= e($srv['name']) ?><?= server_online($srv) ? '' : ' (offline)' ?></option><?php endforeach; ?></select></label>
      <label class="field"><span>Quality (the videos are fitted to it)</span><select name="quality"><?php foreach ($q as $k => $info): ?><option value="<?= e($k) ?>" <?= $r['quality'] === $k ? 'selected' : '' ?>><?= e($info['label']) ?></option><?php endforeach; ?></select></label>
      <label class="field"><span>Frames per second</span><select name="fps"><?php foreach ($fpsList as $f): ?><option value="<?= $f ?>" <?= (int) $r['fps'] === $f ? 'selected' : '' ?>><?= $f ?></option><?php endforeach; ?></select></label>
    </div>
    <div class="field"><span>🎬 Videos (ticked ones play, in this order — ↑ ↓ to change it)</span>
      <div class="vl-list">
        <?php $ordered = array_merge(array_values(array_filter(array_map(fn ($id) => vl_video($id), $c['ids']))), array_values(array_filter($lib, fn ($x) => !in_array($x['id'], $c['ids'], true))));
        foreach ($ordered as $x): $ok = !empty($x['ready']); ?>
        <div class="vl-item<?= in_array($x['id'], $c['ids'], true) ? ' on' : '' ?>">
          <label><input type="checkbox" name="ids[]" value="<?= e($x['id']) ?>" <?= in_array($x['id'], $c['ids'], true) ? 'checked' : '' ?> <?= $ok ? '' : 'disabled' ?>>
            <span><?= ($x['kind'] ?? 'file') === 'link' ? '🔗' : '🎞' ?> <?= e($x['name']) ?><small><?= ($x['kind'] ?? 'file') === 'link' ? 'link · downloaded by the VPS' : ($ok ? e($fmtSize((int) $x['size'])) : '⏳ upload not finished') ?></small></span></label>
          <button type="button" class="btn btn-ghost btn-sm" data-mv="-1" aria-label="Up">↑</button><button type="button" class="btn btn-ghost btn-sm" data-mv="1" aria-label="Down">↓</button>
        </div>
        <?php endforeach; if (!$lib): ?><p class="muted small">No videos yet — add them in 📚 Videos below.</p><?php endif; ?>
      </div>
    </div>
    <label class="check"><input type="checkbox" name="loop" value="1" <?= $c['loop'] ? 'checked' : '' ?>> 🔁 When the last video ends, start again from the first (off = the live ends after the last video)</label>
    <label class="check"><input type="checkbox" name="shuffle" value="1" <?= $c['shuffle'] ? 'checked' : '' ?>> 🔀 Mix the order every round</label>
    <div class="form-actions">
      <button class="btn btn-ghost" name="do" value="save" type="submit">💾 Save</button>
      <?php if ($on): ?><button class="btn btn-red" name="do" value="stop" type="submit">⏹ Stop video live</button>
      <?php else: ?><button class="btn btn-green" name="do" value="start" type="submit">▶ Start video live</button>
      <button class="btn btn-ghost" name="do" value="delete" type="submit" onclick="return confirm('Delete this Video Live? (the videos stay in the library)')">🗑</button><?php endif; ?>
    </div>
    <p class="muted small">Save first, then Start. The title, description and thumbnail of the live come from YouTube Manager (this live is listed there by its name).</p>
  </form>
</section>
<?php endforeach; ?>

<section class="card" id="lib">
  <h2>📚 Videos</h2>
  <p class="muted">Upload from this phone / computer (up to 4 GB, it goes up in pieces — keep this page open), or paste a direct link (Google Drive “anyone with the link”, Dropbox, or any .mp4 link) — the VPS downloads a link itself, best for very big videos.</p>
  <div class="vl-up">
    <input type="file" id="vf" accept="video/*">
    <button class="btn btn-blue" type="button" id="vgo">⬆ Upload video</button>
    <div id="vbar" class="vl-bar" hidden><i></i><span></span></div>
  </div>
  <form method="post" class="vl-link"><?= csrf_field() ?><input type="hidden" name="do" value="link">
    <input name="url" type="url" placeholder="🔗 https://… (video link)" required><input name="name" maxlength="80" placeholder="Name (optional)"><button class="btn btn-ghost" type="submit">Add link</button></form>
  <?php foreach (array_reverse($lib) as $x): ?>
  <form method="post" class="vl-lib"><?= csrf_field() ?><input type="hidden" name="vid" value="<?= e($x['id']) ?>">
    <span class="ic"><?= ($x['kind'] ?? 'file') === 'link' ? '🔗' : '🎞' ?></span>
    <input name="name" value="<?= e($x['name']) ?>" maxlength="80">
    <span class="muted small"><?= ($x['kind'] ?? 'file') === 'link' ? 'link' : (!empty($x['ready']) ? e($fmtSize((int) $x['size'])) : '⏳ not finished') ?></span>
    <?php if (($x['kind'] ?? 'file') === 'file' && !empty($x['ready'])): ?><a class="btn btn-ghost btn-sm" href="?watch=<?= e($x['id']) ?>" target="_blank" rel="noopener">▶</a><?php endif; ?>
    <button class="btn btn-ghost btn-sm" name="do" value="vid_name" type="submit">Save</button>
    <button class="btn btn-ghost btn-sm" name="do" value="vid_del" type="submit" onclick="return confirm('Remove this video?')">🗑</button>
  </form>
  <?php endforeach; ?>
</section>

<style>
.vl-st{border-radius:10px;padding:9px 12px;font-weight:700;margin-bottom:10px}.vl-st.live{background:#14532d;color:#bbf7d0}.vl-st.err{background:#7f1d1d;color:#fecaca}.vl-st.wait{background:#713f12;color:#fde68a}.vl-st.off{background:#1f2b5c;color:#cfe0ff}
.vl-list{display:flex;flex-direction:column;gap:6px;margin-top:6px}
.vl-item{display:flex;align-items:center;gap:6px;border:1px solid #2a3a6a;border-radius:10px;padding:7px 8px}.vl-item.on{border-color:#22c55e}
.vl-item label{flex:1;display:flex;gap:8px;align-items:center;cursor:pointer}.vl-item small{display:block;color:#8fa6d6;font-size:11.5px}
.vl-up{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:8px 0}
.vl-bar{flex-basis:100%;height:22px;border-radius:8px;background:#1b2350;position:relative;overflow:hidden}.vl-bar i{position:absolute;left:0;top:0;bottom:0;width:0;background:linear-gradient(90deg,#2563eb,#22c55e)}.vl-bar span{position:relative;display:block;text-align:center;font-size:12px;line-height:22px;font-weight:700}
.vl-link{display:flex;flex-wrap:wrap;gap:8px;margin:8px 0}.vl-link input{flex:1 1 180px}
.vl-lib{display:flex;flex-wrap:wrap;align-items:center;gap:8px;border-top:1px solid #1f2b5c;padding:8px 0}.vl-lib input[name=name]{flex:1 1 160px}.vl-lib .ic{font-size:18px}
</style>
<script>
(() => {
  document.querySelectorAll('[data-mv]').forEach((b) => b.addEventListener('click', () => {
    const it = b.closest('.vl-item'), d = Number(b.dataset.mv);
    if (d < 0 && it.previousElementSibling) it.parentNode.insertBefore(it, it.previousElementSibling);
    if (d > 0 && it.nextElementSibling) it.parentNode.insertBefore(it.nextElementSibling, it);
  }));
  document.querySelectorAll('.vl-item input[type=checkbox]').forEach((c) => c.addEventListener('change', () => c.closest('.vl-item').classList.toggle('on', c.checked)));
  const csrf = <?= json_encode(csrf_token()) ?>, PART = 4 * 1048576;
  const post = async (data, file) => { const f = new FormData(); f.append('csrf', csrf); Object.entries(data).forEach(([k, v]) => f.append(k, v)); if (file) f.append('blob', file, 'part');
    const r = await fetch('videolive.php', { method: 'POST', body: f, credentials: 'same-origin' }); const j = await r.json().catch(() => ({ error: 'The website did not answer (' + r.status + ')' })); return j; };
  const bar = document.getElementById('vbar'), btn = document.getElementById('vgo');
  btn.addEventListener('click', async () => {
    const file = document.getElementById('vf').files[0]; if (!file) { alert('Choose a video first.'); return; }
    btn.disabled = true; bar.hidden = false; const show = (p, t) => { bar.querySelector('i').style.width = p + '%'; bar.querySelector('span').textContent = t; };
    try {
      const b = await post({ do: 'up_begin', name: file.name, size: String(file.size) }); if (b.error) throw new Error(b.error);
      let off = 0, tries = 0;
      while (off < file.size) {
        const r = await post({ do: 'up_part', id: b.id, off: String(off) }, file.slice(off, off + PART));
        if (r.error) { if (r.have !== undefined) { off = r.have; continue; } if (++tries > 5) throw new Error(r.error); await new Promise((z) => setTimeout(z, 3000)); continue; }
        tries = 0; off = r.have; const p = Math.round(off / file.size * 100); show(p, `⬆ ${p}% · ${(off / 1048576).toFixed(0)} / ${(file.size / 1048576).toFixed(0)} MB — keep this page open`);
      }
      show(100, '✅ Uploaded'); setTimeout(() => location.reload(), 800);
    } catch (e) { show(0, '⚠️ ' + e.message); btn.disabled = false; }
  });
})();
</script>
<?php page_end();
