<?php
// Camera Live link (Admin → Camera Live Links): he puts his own stream key, allows the camera and microphone,
// and goes live on his own channel from this phone. The link works until its end time.
require __DIR__ . '/_boot.php';
$tok = (string) ($_GET['t'] ?? '');
$link = cam_find($tok);
if (!$link) {
    inv_page('Link not valid', '<h1>This link does not work</h1><div class="p">Its time is over, or it was cancelled.<br>Ask for a new link.</div>', 'bad');
}
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: camera=(self), microphone=(self)');
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="robots" content="noindex,nofollow">
<title>Go live with your camera</title>
<style>
*{box-sizing:border-box}html,body{margin:0;height:100%;background:#070a18;color:#e8eefc;font:15px/1.5 Inter,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
.pg{max-width:440px;margin:0 auto;padding:28px 20px 40px}
h1{font-size:24px;font-weight:900;margin:8px 0 4px}.p{color:#b3bdd6;font-size:14px}
label{display:block;font-size:13px;font-weight:700;margin-top:14px}
input{width:100%;margin-top:6px;padding:12px;border-radius:11px;border:1px solid #ffffff2e;background:#0b1024;color:#fff;font-size:15px}
small{display:block;font-size:12px;color:#8e98b8;margin-top:5px;line-height:1.45}
.seg{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:6px}.seg button{padding:11px;border-radius:11px;border:2px solid transparent;background:#ffffff12;color:#fff;font-weight:700;font-size:14px}
.seg button.on{border-color:#3b82f6;background:#1b2a6a}
.bt{display:block;width:100%;margin-top:20px;padding:15px;border:0;border-radius:13px;background:linear-gradient(90deg,#2563eb,#3b82f6);color:#fff;font-weight:800;font-size:16px}
.er{margin-top:12px;padding:10px;border-radius:10px;background:#f59e0b22;border:1px solid #f59e0b66;color:#fcd34d;font-size:14px}
.er:empty{display:none}
#live{position:fixed;inset:0;background:#000;display:none}
#live video{position:absolute;inset:0;width:100%;height:100%;object-fit:contain;background:#000}
#live video.mirror{transform:scaleX(-1)}
.top{position:absolute;left:12px;right:12px;top:calc(12px + env(safe-area-inset-top));display:flex;justify-content:space-between;gap:8px;font-weight:800;font-size:13px}
.pill{padding:6px 11px;border-radius:99px;background:#000a;color:#fff}.pill.red{background:#dc2626}
.bot{position:absolute;left:14px;right:14px;bottom:calc(16px + env(safe-area-inset-bottom))}
.lvl{height:8px;border-radius:6px;background:#ffffff26;overflow:hidden}.lvl i{display:block;height:100%;width:0;background:linear-gradient(90deg,#22c55e,#facc15,#ef4444);transition:width .08s}
.zm{display:flex;align-items:center;gap:10px;margin-top:10px;padding:8px 12px;border-radius:12px;background:#0009;color:#fff;font-weight:700;font-size:13px}.zm[hidden]{display:none}.zm input{flex:1;margin:0;padding:0;accent-color:#3b82f6}.zm b{min-width:42px;text-align:right}
.ctr{display:flex;gap:8px;margin-top:10px}.ctr button{flex:1;padding:11px 4px;border-radius:12px;border:0;background:#ffffff2b;color:#fff;font-weight:700;font-size:13px;backdrop-filter:blur(6px)}
.ctr button.off{background:#991b1b}
#go{display:block;width:100%;margin-top:10px;padding:16px;border:0;border-radius:15px;background:#dc2626;color:#fff;font-weight:900;font-size:17px}
#go.end{background:#1f2937;border:2px solid #dc2626}
#msg{margin-top:8px;text-align:center;font-size:13px;font-weight:700;color:#fff;text-shadow:0 1px 3px #000}
</style></head><body>
<div class="pg" id="s1">
  <h1>📱 Go live with your camera</h1>
  <div class="p">Hi <b><?= e($link['label']) ?></b>! This link works until <b id="until"></b>.</div>
  <label>Your stream key 🔒<input id="key" type="password" autocomplete="off" maxlength="80" placeholder="paste your stream key"></label>
  <small>YouTube Studio → <b>Create → Go live</b> → <b>Stream</b> → copy <b>Stream key</b>. It is used only for your live.</small>
  <label>Which way will you hold the phone?</label>
  <div class="seg"><button type="button" data-o="v" class="on">📱 Standing (9:16)</button><button type="button" data-o="h">🖥 Lying (16:9)</button></div>
  <div class="er" id="e1"></div>
  <button class="bt" id="next">Next → allow camera &amp; mic</button>
  <small style="text-align:center;margin-top:14px">Keep this page open while you are live. Use earphones if you watch your live on another phone.</small>
</div>
<div id="live">
  <video id="pv" playsinline muted autoplay class="mirror"></video>
  <div class="top"><span class="pill" id="st">● Not live</span><span class="pill" id="left">⏳</span></div>
  <div class="bot">
    <div class="lvl"><i id="lv"></i></div>
    <div class="zm" id="zm" hidden>🔍 Zoom <input type="range" id="zoom"><b id="zv">1.0×</b></div>
    <div class="ctr"><button type="button" id="flip">🔄 Flip</button><button type="button" id="mic">🎤 Mic on</button><button type="button" id="torch">🔆 Light</button></div>
    <button type="button" id="go">● Go live</button>
    <div id="msg"></div>
  </div>
</div>
<script>
(() => {
  const T = <?= json_encode($tok) ?>, ICE = [{ urls: ['stun:stun.l.google.com:19302', 'stun:stun1.l.google.com:19302'] }];
  const $ = (id) => document.getElementById(id);
  let orient = 'v', facing = 'user', local = null, pc = null, sess = '', want = false, pollT = 0, badSince = 0, liveAt = 0, left = 0, lock = null, torchOn = false, ac = null;
  const api = async (body) => { const r = await fetch('/api/camlive.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ t: T, ...body }) }); return r.json(); };
  const over = (m) => { want = false; stopAll(); document.body.innerHTML = '<div class="pg" style="text-align:center;padding-top:80px"><h1>⌛ Link over</h1><div class="p">' + (m || 'This link does not work any more.') + '</div></div>'; };
  const msg = (t) => { $('msg').textContent = t || ''; };
  const fmt = (s) => s >= 86400 ? Math.floor(s / 86400) + 'd ' + Math.floor(s % 86400 / 3600) + 'h' : s >= 3600 ? Math.floor(s / 3600) + 'h ' + Math.floor(s % 3600 / 60) + 'm' : s >= 60 ? Math.floor(s / 60) + 'm' : Math.max(0, s) + 's';
  const info = (j) => {
    if (typeof j.left === 'number') { left = j.left; $('until').textContent = new Date(Date.now() + j.left * 1000).toLocaleString([], { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' }); }
  };
  setInterval(() => { left--; $('left').textContent = '⏳ ' + fmt(left) + ' left'; if (left <= 0 && want) over('The time of this link is over. Your live was stopped.');
    if (liveAt) { const s = Math.floor((Date.now() - liveAt) / 1000); $('st').textContent = '● LIVE ' + Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0'); } }, 1000);

  api({ do: 'info' }).then((j) => {
    if (j.error === 'over') return over(j.msg);
    info(j); orient = j.orient || 'v';
    document.querySelectorAll('[data-o]').forEach((b) => b.classList.toggle('on', b.dataset.o === orient));
    if (j.has_key) $('key').placeholder = '•••••••• saved — leave empty to keep it';
  }).catch(() => {});
  document.querySelectorAll('[data-o]').forEach((b) => b.onclick = () => { orient = b.dataset.o; document.querySelectorAll('[data-o]').forEach((x) => x.classList.toggle('on', x === b)); });

  // the whole picture of the camera, never cut: asking for a size it does not have made the phone cut the middle out
  // (it looked zoomed in and blurry). resizeMode "none" = the camera's own size, about 720 lines.
  const cam = () => navigator.mediaDevices.getUserMedia({ video: { facingMode: facing, height: { ideal: 720 }, frameRate: { ideal: 24, max: 30 }, resizeMode: 'none' } })
    .catch(() => navigator.mediaDevices.getUserMedia({ video: { facingMode: facing, frameRate: { ideal: 24, max: 30 } } }));
  // 🔍 zoom: the camera's own zoom (Android Chrome and most phones); it starts at the widest normal view (1×, or less if the phone has a wide lens)
  const zoomSetup = async (t) => {
    const c = t.getCapabilities ? t.getCapabilities() : {};
    if (!c.zoom || c.zoom.max <= c.zoom.min) { $('zm').hidden = true; return; }
    const z = $('zoom'), start = Math.max(c.zoom.min, Math.min(1, c.zoom.max));
    z.min = c.zoom.min; z.max = c.zoom.max; z.step = c.zoom.step || 0.1; z.value = start;
    const set = async () => { try { await t.applyConstraints({ advanced: [{ zoom: Number(z.value) }] }); } catch (e) { /* not this time */ } $('zv').textContent = Number(z.value).toFixed(1) + '×'; };
    z.oninput = set; await set(); $('zm').hidden = false;
  };
  $('next').onclick = async () => {
    $('e1').textContent = '';
    const j = await api({ do: 'save', key: $('key').value.trim(), orient }).catch(() => ({ error: 'net', msg: 'No internet?' }));
    if (j.error === 'over') return over(j.msg);
    if (j.error) { $('e1').textContent = j.msg || 'Something went wrong.'; return; }
    info(j);
    try {
      local = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true } });
      local.addTrack((await cam()).getVideoTracks()[0]);
      zoomSetup(local.getVideoTracks()[0]);
    } catch (e) {
      $('e1').textContent = e.name === 'NotAllowedError' ? '⚠️ The camera or microphone is blocked. Allow them for this site (tap the 🔒 / ⓘ next to the address), then press Next again.'
        : e.name === 'NotFoundError' ? '⚠️ No camera or microphone found.' : e.name === 'NotReadableError' ? '⚠️ The camera is busy in another app. Close it and try again.' : '⚠️ ' + (e.message || e.name);
      return;
    }
    $('s1').style.display = 'none'; $('live').style.display = 'block';
    $('pv').srcObject = local; $('pv').classList.toggle('mirror', facing === 'user');
    try { ac = new AudioContext(); const an = ac.createAnalyser(); an.fftSize = 128; ac.createMediaStreamSource(new MediaStream(local.getAudioTracks())).connect(an);
      const a = new Uint8Array(an.frequencyBinCount); const m = () => { requestAnimationFrame(m); an.getByteFrequencyData(a); let s = 0; for (let i = 2; i < 40; i++) s += a[i]; $('lv').style.width = Math.min(100, s / 38 / 1.4) + '%'; }; m(); } catch (e) { /* no meter */ }
    msg('Your camera is ready. Press ● Go live when you are ready.');
  };

  const connect = async () => {
    pc = new RTCPeerConnection({ iceServers: ICE });
    pc.addTransceiver(local.getAudioTracks()[0], { direction: 'sendonly' });
    const vTx = pc.addTransceiver(local.getVideoTracks()[0], { direction: 'sendonly' });
    try { const p = vTx.sender.getParameters(); p.encodings = p.encodings && p.encodings.length ? p.encodings : [{}]; p.encodings[0].maxBitrate = 2500000; await vTx.sender.setParameters(p); } catch (e) { /* not supported */ }
    pc.onconnectionstatechange = () => {
      const s = pc ? pc.connectionState : 'closed';
      if (s === 'connected') { badSince = 0; if (!liveAt) liveAt = Date.now(); $('st').classList.add('red'); msg('🔴 You are LIVE on your channel (YouTube shows it after a few seconds).'); }
      else if (s === 'disconnected' || s === 'failed') { badSince = badSince || Date.now(); msg('Connection lost — trying again…'); }
    };
    await pc.setLocalDescription(await pc.createOffer());
    await new Promise((res) => { if (pc.iceGatheringState === 'complete') return res(); const t = setTimeout(res, 3000); pc.onicegatheringstatechange = () => { if (pc.iceGatheringState === 'complete') { clearTimeout(t); res(); } }; });
    const j = await api({ do: 'offer', offer: pc.localDescription.sdp });
    if (j.error === 'over') return over(j.msg);
    sess = j.sess || '';
  };
  const poll = async () => {
    if (!want) return;
    try {
      const j = await api({ do: 'poll', sess });
      if (j.error === 'over') return over(j.msg);
      info(j);
      if (j.error === 'ended') { if (!j.on) { msg('The live was stopped.'); return end(true); } badSince = badSince || Date.now() - 9000; }
      else if (j.answer && pc && pc.signalingState === 'have-local-offer') await pc.setRemoteDescription({ type: 'answer', sdp: j.answer });
      if (!liveAt) msg(j.running ? 'Connecting your camera…' : 'Starting your live… (about 20 seconds)');
    } catch (e) { /* network hiccup */ }
    if (want && badSince && Date.now() - badSince > 8000) { badSince = 0; try { pc && pc.close(); } catch (e) { /* closed */ } pc = null; try { await connect(); } catch (e) { /* next time */ } }
  };
  const stopAll = () => { clearInterval(pollT); try { pc && pc.close(); } catch (e) { /* closed */ } pc = null; sess = ''; liveAt = 0; if (lock) { lock.release().catch(() => {}); lock = null; } };
  const end = async (quiet) => {
    want = false; stopAll();
    if (!quiet) { try { await api({ do: 'stop' }); } catch (e) { /* ignore */ } msg('Live ended. Press ● Go live to start again.'); }
    $('go').textContent = '● Go live'; $('go').classList.remove('end'); $('st').textContent = '● Not live'; $('st').classList.remove('red');
  };
  $('go').onclick = async () => {
    if (want) { if (confirm('End your live?')) end(false); return; }
    $('go').disabled = true;
    try {
      const j = await api({ do: 'start' });
      if (j.error === 'over') return over(j.msg);
      if (j.error) { msg('⚠️ ' + (j.msg || 'Could not start.')); return; }
      want = true; $('go').textContent = '■ End live'; $('go').classList.add('end');
      if (navigator.wakeLock) navigator.wakeLock.request('screen').then((l) => { lock = l; }).catch(() => {});
      msg('Starting your live… (about 20 seconds)');
      await connect();
      clearInterval(pollT); pollT = setInterval(poll, 1500);
    } catch (e) { msg('⚠️ ' + (e.message || 'Could not start.')); } finally { $('go').disabled = false; }
  };
  const swap = async () => {
    const t = (await cam()).getVideoTracks()[0];
    local.getVideoTracks().forEach((o) => { o.stop(); local.removeTrack(o); }); local.addTrack(t);
    zoomSetup(t);
    $('pv').srcObject = local; $('pv').classList.toggle('mirror', facing === 'user');
    const tx = pc && pc.getTransceivers().find((x) => x.receiver.track && x.receiver.track.kind === 'video');
    if (tx) await tx.sender.replaceTrack(t);
  };
  $('flip').onclick = async () => { facing = facing === 'user' ? 'environment' : 'user'; torchOn = false; $('torch').classList.remove('off'); try { await swap(); } catch (e) { msg('Could not flip: ' + e.message); } };
  $('mic').onclick = () => { const a = local && local.getAudioTracks()[0]; if (!a) return; a.enabled = !a.enabled; $('mic').textContent = a.enabled ? '🎤 Mic on' : '🔇 Mic off'; $('mic').classList.toggle('off', !a.enabled); };
  $('torch').onclick = async () => {
    const v = local && local.getVideoTracks()[0]; if (!v) return;
    try { torchOn = !torchOn; await v.applyConstraints({ advanced: [{ torch: torchOn }] }); $('torch').classList.toggle('off', torchOn); }
    catch (e) { torchOn = false; msg('This camera has no light (try the back camera).'); }
  };
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible' && want && navigator.wakeLock && !lock) navigator.wakeLock.request('screen').then((l) => { lock = l; }).catch(() => {}); });
})();
</script>
</body></html>
