<?php
// Join Live: the owner joins a running live from this phone — camera + voice, or voice only.
require __DIR__ . '/../_boot.php';
$admin = require_login();
$streams = db()->query("SELECT id, name, channel_name, page_url, status FROM streams ORDER BY status = 'live' DESC, id")->fetchAll();
page_start('Join Live', 'golive', $admin);
?>
<style>
.gl-wrap { max-width: 560px; }
.gl-prev { position: relative; width: 240px; height: 240px; margin: 14px auto; border-radius: 50%; overflow: hidden; border: 6px solid #64748b;
  background: radial-gradient(circle at 50% 35%, #3a4a7a, #141a33 70%); display: flex; align-items: center; justify-content: center; }
.gl-prev.on { border-color: #ff3b5c; box-shadow: 0 0 30px rgba(255,59,92,.6); }
.gl-prev video { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; transform: scaleX(-1); }
.gl-prev .mic { font-size: 70px; }
.gl-level { height: 10px; border-radius: 6px; background: rgba(255,255,255,.1); overflow: hidden; margin: 8px 0 14px; }
.gl-level i { display: block; height: 100%; width: 0; background: linear-gradient(90deg, #22c55e, #facc15, #ef4444); transition: width .08s; }
.gl-status { text-align: center; font-weight: 700; margin: 6px 0 12px; }
.gl-btns { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.gl-btns .btn { justify-content: center; padding: 14px 10px; font-size: 16px; }
.gl-btns .wide { grid-column: 1 / -1; }
</style>
<div class="page-head">
  <div>
    <h1>Join Live</h1>
    <p class="muted">Join your running live from this phone. Your camera shows in a round frame in the game, your voice goes to the live, and the music and LUNA go quiet while you are on.</p>
  </div>
</div>

<section class="panel gl-wrap">
  <label class="field"><span>Which live?</span>
    <select id="gl-stream">
      <?php foreach ($streams as $s): ?>
        <option value="<?= (int) $s['id'] ?>" <?= $s['status'] === 'live' ? '' : 'data-off="1"' ?>><?= $s['status'] === 'live' ? '🔴 ' : '' ?><?= e($s['channel_name'] ?: $s['name']) ?> · <?= e(game_of_url($s['page_url'])) ?><?= $s['status'] === 'live' ? '' : ' (not live)' ?></option>
      <?php endforeach; ?>
    </select></label>
  <label class="field"><span>Your name on screen</span><input id="gl-name" maxlength="16" value="TASIN"></label>

  <div class="gl-prev" id="gl-prev"><span class="mic">🎙️</span><video id="gl-video" playsinline muted autoplay hidden></video></div>
  <div class="gl-level"><i id="gl-lvl"></i></div>
  <div class="gl-status" id="gl-status">Not on the live</div>

  <div class="gl-btns">
    <button class="btn btn-blue" id="gl-cam" type="button">📷 Camera + Voice</button>
    <button class="btn btn-blue" id="gl-voice" type="button">🎙️ Voice only</button>
    <button class="btn btn-ghost" id="gl-toggle" type="button" hidden>📷 Camera off</button>
    <button class="btn btn-ghost" id="gl-flip" type="button" hidden>🔄 Flip camera</button>
    <button class="btn btn-danger wide" id="gl-stop" type="button" hidden>⏹ Leave the live</button>
  </div>
  <p class="muted small" style="margin-top:14px">Tip: do not play the YouTube live on this same phone with sound — your voice would come back as an echo. Use another phone or earphones to watch. Your face and voice reach the live after a few seconds.</p>
</section>

<script>
(() => {
  const CSRF = <?= json_encode(csrf_token()) ?>;
  const $ = (id) => document.getElementById(id);
  const ICE = [{ urls: ['stun:stun.l.google.com:19302', 'stun:stun1.l.google.com:19302'] }];
  let pc = null, local = null, sess = '', mode = 'cam', facing = 'user', pollT = 0, levelT = 0, ctx = null, analyser = null, wantOn = false, badSince = 0;

  const post = async (data) => {
    const f = new FormData(); f.append('csrf', CSRF); f.append('stream', $('gl-stream').value);
    for (const [k, v] of Object.entries(data)) f.append(k, v);
    const r = await fetch('golive_api.php', { method: 'POST', body: f, credentials: 'same-origin' });
    return r.json();
  };
  const status = (t, ok) => { $('gl-status').textContent = t; $('gl-status').style.color = ok === true ? '#22c55e' : ok === false ? '#f59e0b' : ''; };
  const ui = () => {
    const on = !!pc;
    $('gl-cam').hidden = $('gl-voice').hidden = on;
    $('gl-stop').hidden = $('gl-toggle').hidden = !on;
    $('gl-flip').hidden = !on || mode !== 'cam';
    $('gl-toggle').textContent = mode === 'cam' ? '📷 Camera off (voice only)' : '📷 Camera on';
    $('gl-prev').classList.toggle('on', on);
    $('gl-video').hidden = mode !== 'cam' || !on;
    $('gl-stream').disabled = on;
  };
  const meter = () => {
    levelT = requestAnimationFrame(meter);
    if (!analyser) return;
    const a = new Uint8Array(analyser.frequencyBinCount); analyser.getByteFrequencyData(a);
    let s = 0; for (let i = 2; i < 40; i++) s += a[i];
    $('gl-lvl').style.width = Math.min(100, s / 38 / 1.4) + '%';
  };
  const camTrack = async () => {
    const s = await navigator.mediaDevices.getUserMedia({ video: { facingMode: facing, width: { ideal: 480 }, height: { ideal: 480 }, frameRate: { ideal: 15, max: 20 } } });
    return s.getVideoTracks()[0];
  };

  const connect = async () => {
    pc = new RTCPeerConnection({ iceServers: ICE });
    const aT = local.getAudioTracks()[0];
    pc.addTransceiver(aT, { direction: 'sendonly' });
    const vTx = pc.addTransceiver('video', { direction: 'sendonly' });
    const vT = local.getVideoTracks()[0];
    if (vT) await vTx.sender.replaceTrack(vT);
    try { // smaller, smoother video for the stream server
      const p = vTx.sender.getParameters(); p.encodings = p.encodings && p.encodings.length ? p.encodings : [{}];
      p.encodings[0].maxBitrate = 450000; await vTx.sender.setParameters(p);
    } catch (e) { /* not supported */ }
    pc.onconnectionstatechange = () => {
      const s = pc ? pc.connectionState : 'closed';
      if (s === 'connected') { status('🔴 YOU ARE ON THE LIVE', true); badSince = 0; }
      else if (s === 'connecting') status('Connecting to the live…');
      else if (s === 'disconnected' || s === 'failed') { status('Connection lost — trying again…', false); badSince = badSince || Date.now(); }
    };
    await pc.setLocalDescription(await pc.createOffer());
    await new Promise((res) => {
      if (pc.iceGatheringState === 'complete') return res();
      const t = setTimeout(res, 3000);
      pc.onicegatheringstatechange = () => { if (pc.iceGatheringState === 'complete') { clearTimeout(t); res(); } };
    });
    const j = await post({ do: 'start', mode, offer: pc.localDescription.sdp, name: $('gl-name').value });
    if (j.error) throw new Error(j.error);
    sess = j.sess;
    if (!j.live) status('This channel is not live right now — start the stream first', false);
    else status('Waiting for the live to answer…');
  };

  const poll = async () => {
    if (!pc || !sess) return;
    try {
      const j = await post({ do: 'poll', sess, mode });
      if (j.error === 'ended') { await leave(true); return; }
      if (j.answer && pc.signalingState === 'have-local-offer') await pc.setRemoteDescription({ type: 'answer', sdp: j.answer });
    } catch (e) { /* network hiccup */ }
    // lost for 8 s (e.g. the game page reloaded after an update): connect again
    if (wantOn && badSince && Date.now() - badSince > 8000) { badSince = 0; await reconnect(); }
  };
  const reconnect = async () => {
    try { pc && pc.close(); } catch (e) { /* closed */ }
    pc = null; sess = '';
    try { await connect(); } catch (e) { status('Could not connect: ' + e.message, false); }
    ui();
  };

  const start = async (m) => {
    mode = m;
    try {
      status('Asking for the ' + (m === 'cam' ? 'camera and microphone' : 'microphone') + '…');
      local = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true } });
      if (m === 'cam') local.addTrack(await camTrack());
      $('gl-video').srcObject = local;
      ctx = ctx || new AudioContext(); analyser = ctx.createAnalyser(); analyser.fftSize = 128;
      ctx.createMediaStreamSource(new MediaStream(local.getAudioTracks())).connect(analyser); ctx.resume();
      cancelAnimationFrame(levelT); meter();
      wantOn = true;
      await connect();
      clearInterval(pollT); pollT = setInterval(poll, 1500);
    } catch (e) {
      status(e.name === 'NotAllowedError' ? 'Please allow the camera / microphone for this site' : 'Could not start: ' + e.message, false);
      await leave(false);
    }
    ui();
  };
  const leave = async (quiet) => {
    wantOn = false; clearInterval(pollT);
    if (sess && !quiet) { try { await post({ do: 'stop', sess }); } catch (e) { /* ignore */ } }
    try { pc && pc.close(); } catch (e) { /* closed */ }
    pc = null; sess = '';
    if (local) local.getTracks().forEach((t) => t.stop());
    local = null; analyser = null; $('gl-lvl').style.width = '0%'; $('gl-video').srcObject = null;
    status('Not on the live'); ui();
  };
  const toggleCam = async () => {
    if (!pc) return;
    const tx = pc.getTransceivers().find((t) => t.receiver.track && t.receiver.track.kind === 'video');
    if (mode === 'cam') {
      local.getVideoTracks().forEach((t) => { t.stop(); local.removeTrack(t); });
      if (tx) await tx.sender.replaceTrack(null);
      mode = 'voice';
    } else {
      try { const t = await camTrack(); local.addTrack(t); if (tx) await tx.sender.replaceTrack(t); $('gl-video').srcObject = local; mode = 'cam'; }
      catch (e) { status('Camera not available: ' + e.message, false); }
    }
    ui(); poll();
  };
  const flip = async () => {
    facing = facing === 'user' ? 'environment' : 'user';
    if (mode !== 'cam' || !pc) return;
    const tx = pc.getTransceivers().find((t) => t.receiver.track && t.receiver.track.kind === 'video');
    try { const t = await camTrack(); local.getVideoTracks().forEach((o) => { o.stop(); local.removeTrack(o); }); local.addTrack(t); if (tx) await tx.sender.replaceTrack(t); $('gl-video').srcObject = local; }
    catch (e) { status('Could not flip: ' + e.message, false); }
  };

  $('gl-cam').onclick = () => start('cam');
  $('gl-voice').onclick = () => start('voice');
  $('gl-stop').onclick = () => leave(false);
  $('gl-toggle').onclick = toggleCam;
  $('gl-flip').onclick = flip;
  addEventListener('pagehide', () => { if (sess) navigator.sendBeacon('golive_api.php', (() => { const f = new FormData(); f.append('csrf', CSRF); f.append('stream', $('gl-stream').value); f.append('do', 'stop'); f.append('sess', sess); return f; })()); });
  if (!window.RTCPeerConnection || !navigator.mediaDevices) status('This browser cannot do live video. Use Chrome or Safari on your phone.', false);
  ui();
})();
</script>
<?php page_end(); ?>
