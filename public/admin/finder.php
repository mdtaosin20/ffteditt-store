<?php
// 🔎 Video Finder: where is this video on the web? Pictures from the video → Google Vision (automatic) and Google Lens (by hand).
require __DIR__ . '/../_boot.php';
$admin = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $do = (string) ($_POST['do'] ?? '');
    if ($do === 'key') {
        $k = trim((string) ($_POST['vision_key'] ?? ''));
        if (isset($_POST['remove'])) { setting_set('vision_key', ''); flash('Vision API key removed.'); }
        elseif ($k !== '') {
            if (!preg_match('/^[A-Za-z0-9_\-]{30,60}$/', $k)) { flash('That does not look like a Google API key (it starts with AIza…). Copy it again.', 'error'); redirect('finder.php#key'); }
            setting_set('vision_key', $k); flash('Vision API key saved ✓');
        }
        redirect('finder.php#key');
    }
    header('Content-Type: application/json; charset=utf-8');
    if ($do === 'frame') {   // one picture: kept for a day (for Google Lens), and asked to Google Vision when there is a key
        vf_clean();
        $sid = preg_replace('/[^a-z0-9]/', '', (string) ($_POST['sid'] ?? '')); $i = (int) ($_POST['i'] ?? 0);
        $data = (string) ($_POST['img'] ?? '');
        if (strlen($sid) < 6 || !preg_match('#^data:image/jpeg;base64,([A-Za-z0-9+/=]+)$#', $data, $m) || ($jpg = base64_decode($m[1], true)) === false || strlen($jpg) > 2500000) { echo json_encode(['error' => 'bad picture']); exit; }
        $name = substr($sid, 0, 30) . '_' . $i . '.jpg';
        file_put_contents(vf_dir() . '/' . $name, $jpg);
        $out = ['lens' => 'https://lens.google.com/uploadbyurl?url=' . rawurlencode(vf_url($name))];
        if (!empty($_POST['vision']) && vf_key() !== '') { @set_time_limit(60); $out['vision'] = vf_vision($jpg); }
        echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit;
    }
    echo json_encode(['error' => 'unknown']); exit;
}

$key = vf_key(); $used = vf_used();
page_start('Video Finder', 'finder', $admin);
?>
<div class="page-head">
  <div>
    <h1>🔎 Video Finder</h1>
    <p class="muted">Give a short video — the panel takes pictures from it and looks where the same pictures are on the web, then tells you on which platforms the video seems to be. The video stays on your phone / computer; only the pictures are used.</p>
  </div>
</div>

<section class="card">
  <div class="vf-drop"><input type="file" id="vfile" accept="video/*"><small>mp4 / mov / webm, best under 3 minutes</small></div>
  <div class="row2">
    <label class="field"><span>📸 Pictures to take</span><select id="vn"><option value="4">4</option><option value="8" selected>8 (spread over the video)</option><option value="12">12</option></select></label>
    <label class="field"><span>How</span><select id="vmode">
      <option value="vision" <?= $key !== '' ? 'selected' : '' ?> <?= $key === '' ? 'disabled' : '' ?>>🤖 Automatic (Google Vision)<?= $key === '' ? ' — add the key below' : '' ?></option>
      <option value="lens" <?= $key === '' ? 'selected' : '' ?>>🔍 Google Lens buttons only (free, you look yourself)</option></select></label>
  </div>
  <button class="btn btn-blue vf-go" id="vgo" type="button">🔎 Find where it is</button>
  <div class="vf-msg" id="vmsg"></div>
  <div class="vf-frames" id="vframes"></div>
</section>

<section class="card" id="vres" hidden>
  <div class="vf-sum" id="vsum"></div>
  <div id="vplat"></div>
  <p class="muted small">“Not found” means Google has no matching picture — the video can still be there (TikTok and Instagram videos are often not in Google). Use the 🔍 Lens buttons on the pictures to look more.</p>
</section>

<section class="card" id="key">
  <h2>🤖 Google Vision API key</h2>
  <p class="muted">For the automatic search. Google gives about <b>1,000 pictures a month free</b> (8 pictures = 1 video → about 120 videos a month); after that Google charges a few dollars per 1,000. Used this month: <b><?= $used ?></b> pictures.</p>
  <ol class="muted small vf-steps">
    <li>console.cloud.google.com → choose the project <b>FFTEdit YouTube</b> (not CreatorDefend).</li>
    <li>APIs &amp; Services → Library → <b>Cloud Vision API</b> → Enable. (Billing must be on for the project.)</li>
    <li>APIs &amp; Services → Credentials → <b>Create credentials → API key</b>. Then “Edit API key” → API restrictions → <b>Restrict key → Cloud Vision API</b> → Save.</li>
    <li>Copy the key and paste it here — never in a chat.</li>
  </ol>
  <form method="post" class="vf-key"><?= csrf_field() ?><input type="hidden" name="do" value="key">
    <input name="vision_key" type="password" autocomplete="off" placeholder="<?= $key !== '' ? 'Saved (' . e(mask_key($key)) . ') — paste a new one to change' : 'AIza…' ?>">
    <button class="btn btn-green" type="submit">Save key</button>
    <?php if ($key !== ''): ?><button class="btn btn-ghost" name="remove" value="1" type="submit">Remove</button><?php endif; ?>
  </form>
</section>

<style>
.vf-drop{border:2px dashed #3b4b85;border-radius:12px;padding:14px;text-align:center}.vf-drop small{display:block;color:#8fa6d6;margin-top:6px}
.vf-go{width:100%;margin-top:10px;padding:12px;font-size:16px}
.vf-msg{margin-top:8px;font-size:13px;color:#fbbf24;min-height:18px}
.vf-frames{display:grid;grid-template-columns:repeat(auto-fill,minmax(92px,1fr));gap:8px;margin-top:8px}
.vf-fr{position:relative;border-radius:9px;overflow:hidden;background:#0b1230;border:1px solid #2a3a6a}
.vf-fr img{display:block;width:100%;aspect-ratio:9/16;object-fit:cover}
.vf-fr .t{position:absolute;left:4px;bottom:30px;background:#000b;color:#fff;font-size:10px;padding:1px 5px;border-radius:5px}
.vf-fr .m{position:absolute;right:4px;top:4px;background:#16a34a;color:#fff;font-size:10px;font-weight:800;padding:1px 6px;border-radius:5px}
.vf-fr a{display:block;text-align:center;font-size:11.5px;padding:5px 0;background:#1b2350;color:#cfe0ff;text-decoration:none}
.vf-sum{background:#14532d;border-radius:12px;padding:10px 12px;font-size:14px;line-height:1.5;margin-bottom:8px}
.vf-pl{border-top:1px solid #1f2b5c;padding:9px 0}.vf-pl:first-child{border:0}
.vf-ph{display:flex;align-items:center;gap:10px}.vf-ph i{font-style:normal;font-size:20px;width:30px;text-align:center}.vf-ph div{flex:1}.vf-ph small{display:block;color:#8fa6d6;font-size:11.5px}
.vf-bd{font-size:11px;font-weight:800;padding:3px 9px;border-radius:99px;color:#fff}.vf-hi{background:#16a34a}.vf-md{background:#a16207}.vf-lo{background:#334155}
.vf-links{margin:4px 0 0 40px;font-size:12.5px;line-height:1.7;word-break:break-all}.vf-links a{color:#93c5fd}
.vf-key{display:flex;flex-wrap:wrap;gap:8px}.vf-key input{flex:1 1 220px}
.vf-steps{line-height:1.7;padding-left:18px}
</style>
<script>
(() => {
  const csrf = <?= json_encode(csrf_token()) ?>, $ = (id) => document.getElementById(id);
  const PLAT = [
    ['YouTube', '▶️', /(^|\.)(youtube\.com|youtu\.be|ytimg\.com|ggpht\.com)$/],
    ['TikTok', '🎵', /(^|\.)(tiktok\.com|tiktokcdn\.com|tiktokcdn-us\.com|tiktokv\.com|ibyteimg\.com|muscdn\.com)$/],
    ['Facebook', '📘', /(^|\.)(facebook\.com|fb\.com|fb\.watch|fbcdn\.net|fbsbx\.com)$/],
    ['Instagram', '📷', /(^|\.)(instagram\.com|cdninstagram\.com)$/],
    ['X (Twitter)', '✖️', /(^|\.)(x\.com|twitter\.com|twimg\.com)$/],
    ['Snapchat', '👻', /(^|\.)(snapchat\.com)$/],
    ['Reddit', '👽', /(^|\.)(reddit\.com|redd\.it|redditmedia\.com)$/],
    ['Pinterest', '📌', /(^|\.)(pinterest\.[a-z.]+|pinimg\.com)$/],
    ['Dailymotion', '🎬', /(^|\.)(dailymotion\.com|dmcdn\.net)$/],
    ['Likee / Kwai', '🎞', /(^|\.)(likee\.video|kwai\.com|kwaicdn\.com)$/],
  ];
  const host = (u) => { try { return new URL(u).hostname.toLowerCase(); } catch (e) { return ''; } };
  const platOf = (u) => { const h = host(u); for (const p of PLAT) if (p[2].test(h)) return p[0]; return h ? 'Other websites' : ''; };
  const ytId = (u) => { const m = /ytimg\.com\/(?:vi|vi_webp)\/([\w-]{11})\//.exec(u) || /[?&]v=([\w-]{11})/.exec(u) || /\/shorts\/([\w-]{11})/.exec(u) || /youtu\.be\/([\w-]{11})/.exec(u); return m ? m[1] : ''; };
  const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

  const grab = (file, n, onPic) => new Promise((ok, bad) => {
    const v = document.createElement('video'); v.muted = true; v.playsInline = true; v.preload = 'auto'; v.src = URL.createObjectURL(file);
    v.onerror = () => bad(new Error('This browser cannot open this video. Try an mp4.'));
    v.onloadedmetadata = async () => {
      const d = v.duration; if (!isFinite(d) || d <= 0) return bad(new Error('Could not read the video length.'));
      const c = document.createElement('canvas'), g = c.getContext('2d'), long = 1024, s = Math.min(1, long / Math.max(v.videoWidth, v.videoHeight));
      c.width = Math.round(v.videoWidth * s); c.height = Math.round(v.videoHeight * s);
      for (let i = 0; i < n; i++) {
        const t = d * (i + 0.5) / n;
        await new Promise((z) => { v.onseeked = z; v.currentTime = t; });
        g.drawImage(v, 0, 0, c.width, c.height);
        onPic(i, t, c.toDataURL('image/jpeg', 0.88));
      }
      URL.revokeObjectURL(v.src); ok(d);
    };
  });

  $('vgo').addEventListener('click', async () => {
    const file = $('vfile').files[0]; if (!file) { $('vmsg').textContent = 'Choose a video first.'; return; }
    const n = Number($('vn').value), vision = $('vmode').value === 'vision', sid = Math.random().toString(36).slice(2, 12) + Date.now().toString(36);
    $('vgo').disabled = true; $('vframes').innerHTML = ''; $('vres').hidden = true; $('vmsg').textContent = '📸 Taking pictures…';
    const pics = [];
    try { await grab(file, n, (i, t, img) => pics.push({ i, t, img })); } catch (e) { $('vmsg').textContent = '⚠️ ' + e.message; $('vgo').disabled = false; return; }
    const res = [];   // per picture: Vision answer
    for (const p of pics) {
      const box = document.createElement('div'); box.className = 'vf-fr';
      box.innerHTML = `<img src="${p.img}" alt="Picture at ${Math.floor(p.t / 60)}:${String(Math.floor(p.t % 60)).padStart(2, '0')}"><span class="t">${Math.floor(p.t / 60)}:${String(Math.floor(p.t % 60)).padStart(2, '0')}</span><a href="#" aria-disabled="true">…</a>`;
      $('vframes').appendChild(box);
    }
    for (const p of pics) {
      $('vmsg').textContent = vision ? `🔎 Asking Google… picture ${p.i + 1} of ${pics.length}` : `⬆ Preparing Lens links… ${p.i + 1} of ${pics.length}`;
      const f = new FormData(); f.append('csrf', csrf); f.append('do', 'frame'); f.append('sid', sid); f.append('i', String(p.i)); f.append('img', p.img); if (vision) f.append('vision', '1');
      let j = {};
      try { j = await (await fetch('finder.php', { method: 'POST', body: f, credentials: 'same-origin' })).json(); } catch (e) { j = { error: 'The website did not answer' }; }
      const box = $('vframes').children[p.i], a = box.querySelector('a');
      if (j.lens) { a.href = j.lens; a.target = '_blank'; a.rel = 'noopener'; a.textContent = '🔍 Google Lens'; a.removeAttribute('aria-disabled'); } else a.textContent = '—';
      if (j.vision && j.vision.error) { $('vmsg').textContent = '⚠️ ' + j.vision.error; res.push(null); continue; }
      res.push(j.vision || null);
      if (j.vision) { const hits = (j.vision.pages || []).length + (j.vision.images || []).filter((x) => x.kind === 'full').length; if (hits) box.insertAdjacentHTML('beforeend', `<span class="m">✓ ${hits}</span>`); }
    }
    $('vgo').disabled = false;
    if (!vision) { $('vmsg').textContent = '✅ Done — press 🔍 Google Lens under a picture to see where it is on the web.'; return; }
    if (res.every((r) => !r)) return;
    $('vmsg').textContent = '✅ Done';
    // sort everything by platform
    const P = {};
    const add = (plat, i, url, title, full) => {
      if (!plat) return; const x = P[plat] || (P[plat] = { frames: new Set(), links: new Map(), full: 0 }); x.frames.add(i); if (full) x.full++;
      let key = url, show = url; const id = plat === 'YouTube' ? ytId(url) : '';
      if (id) { key = 'yt:' + id; show = 'https://www.youtube.com/watch?v=' + id; }
      if (!x.links.has(key) || title) x.links.set(key, { url: show, title: title || (x.links.get(key) || {}).title || '' });
    };
    const guesses = {};
    res.forEach((r, i) => { if (!r) return;
      (r.pages || []).forEach((pg) => add(platOf(pg.url), i, pg.url, pg.title, pg.full));
      (r.images || []).forEach((im) => { const pl = platOf(im.url); if (pl && pl !== 'Other websites') add(pl, i, im.url, '', im.kind === 'full'); });
      if (r.guess) guesses[r.guess] = (guesses[r.guess] || 0) + 1; });
    const asked = res.filter(Boolean).length, rank = (x) => x.frames.size / Math.max(1, asked);
    const level = (x) => !x ? 0 : (rank(x) >= 0.35 || (x.frames.size >= 2 && x.full >= 2)) ? 2 : 1;
    const names = PLAT.map((p) => p[0]).concat(['Other websites']);
    const very = names.filter((n) => level(P[n]) === 2 && n !== 'Other websites'), maybe = names.filter((n) => level(P[n]) === 1 && n !== 'Other websites'), none = ['YouTube', 'TikTok', 'Facebook', 'Instagram', 'X (Twitter)'].filter((n) => !P[n]);
    const g = Object.entries(guesses).sort((a, b) => b[1] - a[1])[0];
    $('vsum').innerHTML = (very.length ? `✅ <b>Very likely on ${esc(very.join(', '))}.</b> ` : '') + (maybe.length ? `Maybe on ${esc(maybe.join(', '))}. ` : '')
      + (!very.length && !maybe.length ? '🤷 <b>Google found no page with these pictures.</b> The video may be new, private, or on apps Google does not see (try the 🔍 Lens buttons). ' : '')
      + (none.length && (very.length || maybe.length) ? `Not found on ${esc(none.join(' / '))}. ` : '') + (g ? `<br><span class="muted small">Google thinks it shows: “${esc(g[0])}”</span>` : '');
    const order = names.filter((n) => P[n]).sort((a, b) => P[b].frames.size - P[a].frames.size).concat(none.filter((n) => !names.filter((m) => P[m]).includes(n)));
    $('vplat').innerHTML = order.map((n) => { const x = P[n], ic = (PLAT.find((p) => p[0] === n) || [0, '🌐'])[1], lv = level(x);
      const links = x ? [...x.links.values()].slice(0, 8).map((l) => `<a href="${esc(l.url)}" target="_blank" rel="noopener nofollow">${esc(l.title || l.url.replace(/^https?:\/\/(www\.)?/, '').slice(0, 70))}</a>`).join('<br>') : '';
      return `<div class="vf-pl"><div class="vf-ph"><i>${ic}</i><div>${esc(n)}<small>${x ? `${x.frames.size} of ${asked} pictures matched · ${x.links.size} link${x.links.size === 1 ? '' : 's'}` : 'no match'}</small></div><span class="vf-bd ${lv === 2 ? 'vf-hi' : lv === 1 ? 'vf-md' : 'vf-lo'}">${lv === 2 ? 'Very likely' : lv === 1 ? (n === 'Other websites' ? x.links.size : 'Maybe') : 'Not found'}</span></div>${links ? `<div class="vf-links">${links}</div>` : ''}</div>`; }).join('');
    $('vres').hidden = false;
  });
})();
</script>
<?php page_end();
