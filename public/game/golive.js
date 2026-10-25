// FFTEdit · "Join Live": the channel owner joins the running live from a phone — camera + voice, or voice only.
// The phone (Admin → Join Live) sends a WebRTC offer through the panel; this page (in the stream browser)
// answers it, shows the camera in a round frame (or a microphone in voice-only mode) and plays the voice,
// which goes into the live like the game sounds. The game keeps running underneath.
//
//   goLive({ left: 40, top: 900, size: 230, duck: (on) => … });

const QS = new URLSearchParams(location.search);
const ICE = [{ urls: ['stun:stun.l.google.com:19302', 'stun:stun1.l.google.com:19302'] }];

const CSS = `
.gl-box { position: absolute; z-index: 60; border-radius: 50%; overflow: hidden; border: 8px solid #ff3b5c;
  box-shadow: 0 0 0 6px rgba(0,0,0,.6), 0 0 40px rgba(255,59,92,.7); background: radial-gradient(circle at 50% 35%, #3a4a7a, #141a33 70%);
  transform: scale(.2); opacity: 0; transition: transform .45s cubic-bezier(.2,1.4,.4,1), opacity .3s; }
.gl-box.in { transform: none; opacity: 1; }
.gl-box video { width: 100%; height: 100%; object-fit: cover; transform: scaleX(-1); }
.gl-box .gl-mic { position: absolute; inset: 0; display: none; align-items: center; justify-content: center; }
.gl-box.voice video { opacity: 0; }
.gl-box.voice .gl-mic { display: flex; }
.gl-mic svg { width: 46%; height: 46%; }
.gl-bars { position: absolute; inset: 0; display: flex; align-items: center; justify-content: space-between; padding: 0 9%; pointer-events: none; }
.gl-bars i { width: 7%; border-radius: 6px; background: #ff3b5c; height: 10%; transition: height .08s; }
.gl-tag { position: absolute; z-index: 61; padding: 5px 16px; border-radius: 999px; background: #ff3b5c; color: #fff; white-space: nowrap;
  font: 700 25px "Oswald", Impact, sans-serif; letter-spacing: 2px; box-shadow: 0 4px 0 #7a0f22; opacity: 0; transition: opacity .3s; }
.gl-tag.in { opacity: 1; }
.gl-tag::before { content: ""; display: inline-block; width: 13px; height: 13px; border-radius: 50%; background: #fff; margin-right: 8px; vertical-align: 2px; animation: glblink 1s infinite; }
@keyframes glblink { 50% { opacity: .2; } }
`;
const MIC = '<svg viewBox="0 0 100 100"><rect x="38" y="14" width="24" height="44" rx="12" fill="#fff"/><path d="M28 46 Q28 72 50 72 Q72 72 72 46" stroke="#fff" stroke-width="6" fill="none"/><rect x="47" y="72" width="6" height="12" fill="#fff"/><rect x="36" y="84" width="28" height="6" rx="3" fill="#fff"/></svg>';

export function goLive(opt) {
  const stream = QS.get('stream'), sig = QS.get('sig');
  if (!stream || !sig) return;
  const stage = document.getElementById('stage') || document.body;
  const st = document.createElement('style'); st.textContent = CSS; document.head.appendChild(st);
  const size = opt.size || 230;
  const box = document.createElement('div'); box.className = 'gl-box'; box.hidden = true;
  box.style.cssText = `left:${opt.left}px;top:${opt.top}px;width:${size}px;height:${size}px`;
  const video = document.createElement('video'); video.autoplay = true; video.playsInline = true;
  const voice = document.createElement('audio'); voice.autoplay = true;
  const mic = document.createElement('div'); mic.className = 'gl-mic'; mic.innerHTML = MIC;
  const bars = document.createElement('div'); bars.className = 'gl-bars';
  const barEls = [0, 1, 2, 3].map(() => bars.appendChild(document.createElement('i')));
  mic.appendChild(bars); box.append(video, voice, mic);
  const tag = document.createElement('div'); tag.className = 'gl-tag'; tag.hidden = true;
  tag.style.cssText = `left:${opt.left + 12}px;top:${opt.top + size - 26}px`;
  stage.append(box, tag);

  let pc = null, sess = '', mode = 'cam', name = 'HOST', shown = false, ctx = null, analyser = null, levelT = 0;
  const api = (body) => fetch('/api/golive.php', { method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ stream: Number(stream), sig, sess, ...body }) }).catch(() => {});

  const show = (on) => {
    if (on === shown) return;
    shown = on;
    if (on) { box.hidden = tag.hidden = false; requestAnimationFrame(() => { box.classList.add('in'); tag.classList.add('in'); }); }
    else { box.classList.remove('in'); tag.classList.remove('in'); setTimeout(() => { if (!shown) box.hidden = tag.hidden = true; }, 450); }
    window.fftHostLive = on;      // LUNA waits while the owner talks
    opt.duck && opt.duck(on);     // game music down
  };
  const setMode = (m) => {
    mode = m === 'voice' ? 'voice' : 'cam';
    box.classList.toggle('voice', mode === 'voice');
    tag.textContent = `${name} ${mode === 'voice' ? 'TALKING' : 'LIVE'}`;
  };
  const close = () => {
    if (pc) { try { pc.close(); } catch (e) { /* closed */ } }
    pc = null; video.srcObject = null; voice.srcObject = null; show(false);
    cancelAnimationFrame(levelT);
  };
  const level = () => { // the voice level moves the red bars (voice-only mode)
    levelT = requestAnimationFrame(level);
    if (!analyser || mode !== 'voice') return;
    const a = new Uint8Array(analyser.frequencyBinCount); analyser.getByteFrequencyData(a);
    let s = 0; for (let i = 2; i < 40; i++) s += a[i];
    const v = Math.min(1, s / 38 / 140);
    barEls.forEach((b, i) => { b.style.height = (10 + v * (i === 1 || i === 2 ? 55 : 35)) + '%'; });
  };

  const answer = async (offer) => {
    close();
    pc = new RTCPeerConnection({ iceServers: ICE });
    // The voice plays through its own <audio>: a <video> also holding a video track that gets no pictures
    // (voice-only mode) never starts, and then its sound would not play either.
    pc.ontrack = (e) => {
      if (e.track.kind === 'video') {
        video.srcObject = new MediaStream([e.track]); video.muted = true;
        video.play().catch(() => {});
        return;
      }
      voice.srcObject = new MediaStream([e.track]); voice.muted = false; voice.volume = 1;
      voice.play().catch(() => {});
      if (e.track.kind === 'audio') {
        try {
          ctx = ctx || new AudioContext();
          analyser = ctx.createAnalyser(); analyser.fftSize = 128;
          ctx.createMediaStreamSource(new MediaStream([e.track])).connect(analyser);   // only measured, not played twice
          ctx.resume();
        } catch (err) { analyser = null; }
      }
    };
    pc.onconnectionstatechange = () => {
      const s = pc ? pc.connectionState : 'closed';
      api({ state: s });
      if (s === 'connected') { show(true); cancelAnimationFrame(levelT); level(); }
      if (s === 'failed' || s === 'closed') close();
    };
    await pc.setRemoteDescription({ type: 'offer', sdp: offer });
    await pc.setLocalDescription(await pc.createAnswer());
    await new Promise((res) => { // send the answer with all our addresses in it (no trickle)
      if (pc.iceGatheringState === 'complete') return res();
      const t = setTimeout(res, 3000);
      pc.onicegatheringstatechange = () => { if (pc.iceGatheringState === 'complete') { clearTimeout(t); res(); } };
    });
    await api({ answer: pc.localDescription.sdp });
  };

  const check = async () => {
    try {
      const r = await fetch(`/api/golive.php?stream=${encodeURIComponent(stream)}&sig=${encodeURIComponent(sig)}`, { cache: 'no-store' });
      if (!r.ok) return;
      const g = await r.json();
      name = String(g.name || 'HOST').toUpperCase();
      if (!g.on) { if (sess) { sess = ''; close(); } return; }
      setMode(g.mode);
      if (shown) opt.duck && opt.duck(true);   // keep the music down while the owner is live
      if (g.sess !== sess && g.offer) { sess = g.sess; await answer(g.offer); }
    } catch (e) { /* try again soon */ }
  };
  check();
  setInterval(check, 2000);
}
