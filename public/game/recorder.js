// Auto record for the Shorts games (Cup Kick, Action Kick).
// START → recording starts, the play ends → it stops by itself, then Save / Share.
// Only the game picture is recorded (the 3D scene + the two lines of text + the channel name), never the menu.
//
// Two ways:
//  ⚡ Perfect: the phone makes the video frame by frame (not in real time), so even 4K 60 fps comes out
//     smooth on a slow phone. The sounds are made afterwards exactly at the right moments. (WebCodecs + MP4)
//  🔴 Live: records while you watch (MediaRecorder). Quicker, but a slow phone can stutter at big sizes.
import { Muxer, ArrayBufferTarget } from './vendor/mp4-muxer.esm.js';

const SIZES = { 720: [720, 1280], 1080: [1080, 1920], 1440: [1440, 2560], 2160: [2160, 3840] };
const SIZE_NAME = { 720: '720p', 1080: '1080p', 1440: '2K', 2160: '4K' };
const RATE = { 720: [7.5, 10], 1080: [12, 18], 1440: [24, 36], 2160: [45, 65] };   // Mbit/s for 30 / 60 fps (like YouTube's own advice)
const LEAD = 0.6, TAIL = 0.7;   // seconds before the kick-off and after the end text
const SOUNDS = ['kick', 'bump', 'land', 'ding', 'clank', 'win'];

const css = `
.rec{margin-top:14px;padding:12px 14px;border:1px solid #ff3355aa;border-radius:12px;background:linear-gradient(180deg,#2a0f22,#160d24)}
.rec-h{display:flex;align-items:center;gap:8px;font-weight:800;color:#fff;font-size:15px;cursor:pointer}
.rec-h .dot{width:12px;height:12px;border-radius:50%;background:#ff2d55;box-shadow:0 0 10px #ff2d55;flex:0 0 auto}
.rec-h input{position:absolute;opacity:0;width:1px;height:1px}
.rec-sw{margin-left:auto;width:44px;height:24px;border-radius:99px;background:#3a4470;position:relative;transition:background .2s;flex:0 0 auto}
.rec-sw:after{content:'';position:absolute;left:3px;top:3px;width:18px;height:18px;border-radius:50%;background:#fff;transition:left .2s}
.rec-h input:checked+.rec-sw{background:#22c55e}.rec-h input:checked+.rec-sw:after{left:23px}
.rec-body[hidden]{display:none}
.rec p{font-size:12px;color:#9fb0d6;margin-top:8px;line-height:1.4}
.rec-seg{display:grid;gap:6px;margin-top:8px}
.rec-seg.q{grid-template-columns:repeat(4,1fr)}.rec-seg.f,.rec-seg.m{grid-template-columns:1fr 1fr}
.rec-seg button{flex:none;width:auto;padding:8px 2px;border-radius:9px;background:#1b2350;color:#cfe0ff;font-size:13px;font-weight:700;line-height:1.2}
.rec-seg button small{display:block;font-weight:400;font-size:10px;color:#8fa6d6}
.rec-seg.q button.on{background:linear-gradient(180deg,#ffd84a,#ff9f1a);color:#1a1200}.rec-seg.q button.on small{color:#3b2a00}
.rec-seg.f button.on,.rec-seg.m button.on{background:#2a4a9a;color:#fff}
.rec-badge{position:fixed;top:12px;left:50%;transform:translateX(-50%);z-index:30;display:flex;align-items:center;gap:8px;background:rgba(0,0,0,.62);border:1px solid #ff2d55;border-radius:99px;padding:6px 14px;font:700 14px Arial,sans-serif;color:#fff;white-space:nowrap;pointer-events:none}
.rec-badge i{width:10px;height:10px;border-radius:50%;background:#ff2d55;box-shadow:0 0 8px #ff2d55;animation:recblink 1s steps(2) infinite}
.rec-badge.mk i{background:#ffd84a;box-shadow:0 0 8px #ffd84a}
.rec-bar{position:fixed;left:50%;bottom:16px;transform:translateX(-50%);z-index:30;width:min(86vw,340px);background:rgba(0,0,0,.7);border:1px solid #2a4a9a;border-radius:12px;padding:10px 12px;font:13px Arial,sans-serif;color:#cfe0ff;text-align:center;pointer-events:none}
.rec-bar b{color:#ffd84a}.rec-bar .tr{height:8px;border-radius:99px;background:#1b2350;margin-top:8px;overflow:hidden}.rec-bar .tr i{display:block;height:100%;width:0;background:linear-gradient(90deg,#ff2d55,#ff9f1a)}
@keyframes recblink{50%{opacity:.25}}
.rec-done{position:fixed;inset:0;z-index:40;display:flex;align-items:flex-start;justify-content:center;overflow:auto;background:rgba(4,8,20,.88);padding:18px}
.rec-done .bx{margin:auto 0;width:100%;max-width:340px;background:#0f1630;border:2px solid #2a4a9a;border-radius:18px;padding:16px;text-align:center;color:#fff;font-family:Arial,sans-serif;position:relative}
.rec-done video{display:block;width:140px;height:249px;margin:0 auto 10px;border-radius:10px;border:2px solid #ffd23a;background:#000;object-fit:cover}
.rec-done h3{font-size:20px;color:#ffd23a}.rec-done .inf{font-size:13px;color:#9fb0d6;margin:4px 0 6px}.rec-done .msg{font-size:12px;color:#fbbf24;margin-bottom:6px}
.rec-done button{display:block;width:100%;margin-top:8px}
.rec-done .x{position:absolute;right:8px;top:6px;width:auto;margin:0;background:none;font-size:22px;padding:4px 8px;color:#8fa6d6}
`;

/** o = { game, renderer, canvas, stage, words, wm, cssUrl, after (element to put the box after), Sound, snd, setSize(w,h), fit(), onOpenMenu() } */
export function setupRecorder(o) {
  const st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);
  const key = 'fft_rec_' + o.game;
  let cfg = { on: false, q: 1080, fps: 60, mode: 'perfect' };
  try { Object.assign(cfg, JSON.parse(localStorage.getItem(key) || '{}')); } catch (e) { /* ignore */ }
  if (!SIZES[cfg.q]) cfg.q = 1080;
  if (![30, 60].includes(cfg.fps)) cfg.fps = 60;
  const canPerfect = typeof VideoEncoder === 'function' && typeof VideoFrame === 'function';
  const canLive = typeof MediaRecorder === 'function' && !!HTMLCanvasElement.prototype.captureStream;
  if (!canPerfect) cfg.mode = 'live';
  const save = () => { try { localStorage.setItem(key, JSON.stringify(cfg)); } catch (e) { /* ignore */ } };

  /* ---------- the box in the menu ---------- */
  const box = document.createElement('div'); box.className = 'rec';
  box.innerHTML = `<label class="rec-h"><span class="dot"></span>🎥 Auto record<input type="checkbox" id="recOn"><span class="rec-sw"></span></label>
  <div class="rec-body"><p style="margin-top:4px">Starts with START, stops by itself at the end. Only the game is recorded (no menu, no phone notifications).</p>
  <div class="rec-seg q">${Object.keys(SIZES).map((q) => `<button type="button" data-q="${q}">${SIZE_NAME[q]}<small>${{ 720: 'light', 1080: 'Full HD', 1440: '1440p', 2160: '2160p' }[q]}</small></button>`).join('')}</div>
  <div class="rec-seg f"><button type="button" data-f="30">30 fps</button><button type="button" data-f="60">60 fps</button></div>
  <div class="rec-seg m"><button type="button" data-m="perfect">⚡ Perfect (no lag)</button><button type="button" data-m="live">🔴 Live (real time)</button></div>
  <p class="rec-help"></p></div>`;
  o.after.insertAdjacentElement('afterend', box);
  const onEl = box.querySelector('#recOn'), body = box.querySelector('.rec-body'), help = box.querySelector('.rec-help');
  const show = () => {
    onEl.checked = cfg.on; body.hidden = !cfg.on;
    box.querySelectorAll('[data-q]').forEach((b) => b.classList.toggle('on', Number(b.dataset.q) === cfg.q));
    box.querySelectorAll('[data-f]').forEach((b) => b.classList.toggle('on', Number(b.dataset.f) === cfg.fps));
    box.querySelectorAll('[data-m]').forEach((b) => { b.classList.toggle('on', b.dataset.m === cfg.mode); b.disabled = b.dataset.m === 'perfect' ? !canPerfect : !canLive; });
    help.textContent = !canPerfect && !canLive ? '⚠️ This browser cannot record. Open the game in Chrome.'
      : cfg.mode === 'perfect' ? '⚡ Perfect: the phone makes the video frame by frame, so even 4K comes out smooth on a slow phone (it takes a little longer than the play).'
        : '🔴 Live: records while you watch. On a slow phone choose 1080p or 30 fps so it does not stutter.';
  };
  onEl.addEventListener('change', () => { cfg.on = onEl.checked; save(); show(); });
  box.addEventListener('click', (e) => {
    const b = e.target.closest('button'); if (!b) return;
    if (b.dataset.q) cfg.q = Number(b.dataset.q); if (b.dataset.f) cfg.fps = Number(b.dataset.f); if (b.dataset.m) cfg.mode = b.dataset.m;
    save(); show();
  });
  show();

  /* ---------- drawing one picture of the video ---------- */
  let comp = null, g = null, TW = 0, TH = 0, vnow = null;
  const clock = () => (vnow !== null ? vnow : performance.now());
  let cssText = null;
  const loadCss = async () => {
    if (cssText) return cssText;
    let t = await (await fetch(o.cssUrl)).text();
    for (const m of [...t.matchAll(/url\("([^"]+\.(?:ttf|woff2?))"\)/g)]) {
      const bl = await (await fetch(m[1])).blob();
      const d = await new Promise((r) => { const f = new FileReader(); f.onload = () => r(f.result); f.readAsDataURL(bl); });
      t = t.split(m[0]).join(`url("${d}")`);
    }
    return (cssText = t.replace(/<\/style/gi, ''));
  };
  // the two lines of text: the page draws them with CSS, so they are turned into a picture (an SVG) at the video size
  let wImg = null, wBox = null, popAt = -1e9, wJob = 0, wBusy = null;
  const rasterWords = async () => {
    const job = ++wJob;
    await new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)));   // let the page fit the lines first
    if (document.fonts) await document.fonts.ready;
    if (job !== wJob) return;
    const w = o.words;
    if (w.hidden || !w.textContent.trim()) { wImg = null; return; }
    const W = o.stage.clientWidth, H = o.stage.clientHeight;
    const c = (await loadCss()).replace(/100vw/g, W + 'px').replace(/100vh/g, H + 'px');
    const cl = w.cloneNode(true); cl.querySelectorAll('.pop').forEach((e) => e.classList.remove('pop'));
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${TW}" height="${TH}" viewBox="0 0 ${W} ${H}"><foreignObject x="0" y="0" width="${W}" height="${H}">`
      + `<div xmlns="http://www.w3.org/1999/xhtml" style="position:relative;width:${W}px;height:${H}px;overflow:hidden;color:#fff;font-family:Arial,'Noto Color Emoji',sans-serif"><style>${c}</style>`
      + new XMLSerializer().serializeToString(cl) + '</div></foreignObject></svg>';
    const img = new Image(); img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
    try { await img.decode(); } catch (e) { return; }
    if (job !== wJob) return;
    const sr = o.stage.getBoundingClientRect(), br = (w.firstElementChild || w).getBoundingClientRect();
    wBox = { x: (br.left + br.width / 2 - sr.left) / W, y: (br.top + br.height / 2 - sr.top) / H };
    wImg = img;
  };
  // the "pop" of the text, like the page: cubic-bezier(.2, 1.5, .4, 1) in 0.5 s
  const bez = (p) => { let lo = 0, hi = 1, t = p; for (let i = 0; i < 24; i++) { t = (lo + hi) / 2; const x = 3 * (1 - t) * (1 - t) * t * 0.2 + 3 * (1 - t) * t * t * 0.4 + t * t * t; if (x < p) lo = t; else hi = t; } return 3 * (1 - t) * (1 - t) * t * 1.5 + 3 * (1 - t) * t * t + t * t * t; };
  const compose = () => {
    g.globalAlpha = 1; g.setTransform(1, 0, 0, 1, 0, 0);
    g.drawImage(o.canvas, 0, 0, TW, TH);
    const wm = o.wm && o.wm();
    if (wm && wm.text) {
      const S = TW / o.stage.clientWidth;
      g.save(); g.translate((wm.x + wm.w / 2) * S, (wm.y + wm.h / 2) * S); g.rotate(-8 * Math.PI / 180);
      g.font = `900 ${wm.fs * S}px Arial, "Noto Color Emoji", sans-serif`; g.textAlign = 'center'; g.textBaseline = 'middle';
      g.lineWidth = wm.fs * S * 0.06; g.strokeStyle = 'rgba(0,0,0,.25)'; g.strokeText(wm.text, 0, 0);
      g.fillStyle = 'rgba(255,255,255,.38)'; g.fillText(wm.text, 0, 0); g.restore();
    }
    if (wImg) {
      const p = Math.min(1, Math.max(0, (clock() - popAt) / 500)), e = p >= 1 ? 1 : bez(p);
      const s = 0.4 + 0.6 * e, cx = wBox.x * TW, cy = wBox.y * TH;
      g.globalAlpha = Math.min(1, Math.max(0, e));
      g.setTransform(s, 0, 0, s, cx - cx * s, cy - cy * s);
      g.drawImage(wImg, 0, 0, TW, TH);
      g.globalAlpha = 1; g.setTransform(1, 0, 0, 1, 0, 0);
    }
  };

  /* ---------- on screen while recording, and the result ---------- */
  let badge = null, bar = null, t0 = 0, tmr = 0, lock = null;
  const ui = (kind) => {
    badge = document.createElement('div'); badge.className = 'rec-badge' + (kind === 'perfect' ? ' mk' : '');
    document.body.appendChild(badge);
    if (kind === 'perfect') { bar = document.createElement('div'); bar.className = 'rec-bar'; bar.innerHTML = `<b>⚡ Making your ${SIZE_NAME[cfg.q]} video…</b> <span>0%</span><div class="tr"><i></i></div><div style="margin-top:6px;font-size:11px">Keep this screen open.</div>`; document.body.appendChild(bar); }
    t0 = performance.now();
    const label = `${SIZE_NAME[cfg.q]} ${cfg.fps}fps`;
    const tick = () => { const s = Math.floor((performance.now() - t0) / 1000); badge.innerHTML = `<i></i>${kind === 'perfect' ? 'MAKING' : 'REC'} ${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')} · ${label}`; };
    tick(); tmr = setInterval(tick, 500);
    if (navigator.wakeLock) navigator.wakeLock.request('screen').then((l) => { lock = l; }).catch(() => {});
  };
  const uiOff = () => { clearInterval(tmr); badge && badge.remove(); bar && bar.remove(); badge = bar = null; if (lock) { lock.release().catch(() => {}); lock = null; } };
  const setBar = (p) => { if (!bar) return; bar.querySelector('span').textContent = Math.round(p * 100) + '%'; bar.querySelector('i').style.width = (p * 100).toFixed(1) + '%'; };
  const done = (blob, info, msg) => {
    const ext = /mp4/.test(blob.type) ? 'mp4' : 'webm';
    const d = new Date(), pad = (n) => String(n).padStart(2, '0');
    const name = `${o.game}-${d.getFullYear()}${pad(d.getMonth() + 1)}${pad(d.getDate())}-${pad(d.getHours())}${pad(d.getMinutes())}${pad(d.getSeconds())}.${ext}`;
    const url = URL.createObjectURL(blob);
    const el = document.createElement('div'); el.className = 'rec-done';
    el.innerHTML = `<div class="bx"><button type="button" class="x" data-a="close">✕</button><video src="${url}" autoplay loop muted playsinline></video>
      <h3>✓ Video ready!</h3><div class="inf">${info} · ${ext.toUpperCase()} · ${(blob.size / 1048576).toFixed(1)} MB</div>${msg ? `<div class="msg">${msg}</div>` : ''}
      <button type="button" data-a="save">💾 Save to phone</button>
      <button type="button" class="ghost" data-a="share" style="background:#1f2b5c">📤 Share (YouTube, gallery…)</button>
      <button type="button" class="ghost" data-a="again" style="background:transparent;border:1px solid #2a4a9a">🔁 Play again</button></div>`;
    document.body.appendChild(el);
    const file = new File([blob], name, { type: blob.type });
    const shareBtn = el.querySelector('[data-a=share]');
    if (!(navigator.canShare && navigator.canShare({ files: [file] }))) shareBtn.hidden = true;
    const close = () => { el.remove(); URL.revokeObjectURL(url); };
    el.addEventListener('click', async (e) => {
      const a = e.target.closest('[data-a]'); if (!a) return;
      if (a.dataset.a === 'save') { const l = document.createElement('a'); l.href = url; l.download = name; document.body.appendChild(l); l.click(); l.remove(); a.textContent = '✓ Saved (look in Downloads)'; }
      if (a.dataset.a === 'share') { try { await navigator.share({ files: [file], title: name }); } catch (err) { /* cancelled */ } }
      if (a.dataset.a === 'close') close();
      if (a.dataset.a === 'again') { close(); o.onOpenMenu && o.onOpenMenu(); }
    });
  };
  const fail = (err) => {
    uiOff();
    const el = document.createElement('div'); el.className = 'rec-done';
    el.innerHTML = `<div class="bx"><h3 style="color:#fbbf24">⚠️ Recording failed</h3><div class="inf" style="margin:10px 0">${String(err && err.message || err).replace(/</g, '&lt;')}</div><p class="inf">Try a smaller size (1080p) or 🔴 Live.</p><button type="button">OK</button></div>`;
    el.querySelector('button').onclick = () => { el.remove(); o.onOpenMenu && o.onOpenMenu(); };
    document.body.appendChild(el);
  };

  const begin = (q) => {
    [TW, TH] = SIZES[q];
    comp = document.createElement('canvas'); comp.width = TW; comp.height = TH; g = comp.getContext('2d', { alpha: false });
    o.setSize(TW, TH);
    wImg = null; popAt = clock(); wBusy = rasterWords().then(() => { wBusy = null; });
  };
  const end = () => { o.fit(); comp = g = null; vnow = null; wImg = null; };

  /* ---------- ⚡ Perfect: frame by frame ---------- */
  const avcCodec = (w, h, fps) => {
    const mbps = (w * h / 256) * fps;
    const lv = mbps <= 108000 ? '1f' : mbps <= 216000 ? '20' : mbps <= 245760 && w * h <= 2097152 ? '28' : mbps <= 522240 && w * h <= 2228224 ? '2a' : mbps <= 589824 ? '32' : mbps <= 983040 ? '33' : '34';
    return ['avc1.6400' + lv, 'avc1.4d00' + lv, 'avc1.4200' + lv];
  };
  const VP9_LV = { 720: '31', 1080: '41', 1440: '51', 2160: '52' };
  const pickVideo = async (q, fps) => {
    // H.264 first (every phone and YouTube love it), then VP9 (also inside MP4) if the phone has no H.264 maker
    for (const qq of Object.keys(SIZES).map(Number).filter((x) => x <= q).sort((a, b) => b - a)) {
      const [w, h] = SIZES[qq], bitrate = RATE[qq][fps === 60 ? 1 : 0] * 1e6;
      const tries = [...avcCodec(w, h, fps).map((codec) => ({ mux: 'avc', c: { codec, avc: { format: 'avc' } } })), { mux: 'vp9', c: { codec: `vp09.00.${VP9_LV[qq]}.08` } }];
      for (const t of tries) {
        for (const hw of ['prefer-hardware', 'no-preference']) {
          const c = { ...t.c, width: w, height: h, bitrate, framerate: fps, hardwareAcceleration: hw, latencyMode: 'quality' };
          try { const s = await VideoEncoder.isConfigSupported(c); if (s.supported) return { q: qq, config: s.config, mux: t.mux }; } catch (e) { /* next */ }
        }
      }
    }
    return null;
  };
  const pickAudio = async () => {
    if (typeof AudioEncoder !== 'function') return null;
    for (const [codec, mux] of [['mp4a.40.2', 'aac'], ['opus', 'opus']]) {
      const c = { codec, sampleRate: 48000, numberOfChannels: 2, bitrate: 192000 };
      try { const s = await AudioEncoder.isConfigSupported(c); if (s.supported) return { config: s.config, mux }; } catch (e) { /* next */ }
    }
    return null;
  };

  // driver = { go() kick-off, tick(nowMs, dtSec) one step + render, redraw(), done() play finished, progress() 0…1, finish() }
  const perfect = async (driver) => {
    const fps = cfg.fps;
    const pv = await pickVideo(cfg.q, fps);
    if (!pv) throw new Error('This phone cannot make MP4 video here.');
    const msg = pv.q < cfg.q ? `This phone could not make ${SIZE_NAME[cfg.q]}, so it is ${SIZE_NAME[pv.q]}.` : '';
    const wantSound = o.snd.on;
    const pa = wantSound ? await pickAudio() : null;
    begin(pv.q); await wBusy;
    ui('perfect');
    const target = new ArrayBufferTarget();
    const muxer = new Muxer({ target, fastStart: 'in-memory', firstTimestampBehavior: 'offset', video: { codec: pv.mux, width: TW, height: TH, frameRate: fps }, ...(pa ? { audio: { codec: pa.mux, numberOfChannels: 2, sampleRate: 48000 } } : {}) });
    let encErr = null;
    const enc = new VideoEncoder({ output: (c, m) => muxer.addVideoChunk(c, m), error: (e) => { encErr = e; } });
    enc.configure(pv.config);
    // the sounds: only written down now (the time of each one); made at the end
    const log = [], orig = {}; let tNow = 0;
    for (const n of SOUNDS) { orig[n] = o.snd[n]; o.snd[n] = (...a) => { log.push([tNow, n, a]); }; }
    try {
      const dt = 1 / fps, lead = Math.round(LEAD * fps);
      vnow = performance.now(); popAt = vnow;   // the first text pops in at the start of the video
      let n = 0, stopAt = -1;
      while (true) {
        tNow = n / fps;
        if (n === lead) driver.go();
        driver.tick(vnow, n === 0 ? 0 : dt);
        if (wBusy) { await wBusy; driver.redraw(); }   // new text: wait for its picture (and draw the 3D picture again, it is gone by then)
        compose();
        const f = new VideoFrame(comp, { timestamp: Math.round(n * 1e6 / fps), duration: Math.round(1e6 / fps) });
        enc.encode(f, { keyFrame: n % (fps * 2) === 0 }); f.close();
        if (encErr) throw encErr;
        n++; vnow += 1000 / fps;
        if (stopAt < 0 && n > lead && driver.done()) stopAt = n + Math.round(TAIL * fps);
        if ((stopAt >= 0 && n >= stopAt) || n > fps * 90) break;
        setBar(n <= lead ? 0 : Math.min(0.97, driver.progress() * 0.97));
        while (enc.encodeQueueSize > 3) await new Promise((r) => setTimeout(r, 4));
        await new Promise((r) => requestAnimationFrame(r));   // show the picture, keep the phone responsive
      }
      await enc.flush(); enc.close();
      if (encErr) throw encErr;
      const secs = n / fps;
      if (pa && log.length) await addSound(muxer, pa, log, secs);
      muxer.finalize();
      setBar(1);
      const blob = new Blob([target.buffer], { type: 'video/mp4' });
      uiOff(); end();
      done(blob, `${SIZE_NAME[pv.q]} · ${fps} fps · ${Math.floor(secs / 60)}:${String(Math.round(secs % 60)).padStart(2, '0')}`, msg + (wantSound && !pa ? ' (No sound: this browser cannot add sound to the video.)' : ''));
    } finally {
      for (const nm of SOUNDS) o.snd[nm] = orig[nm];
      if (comp) { uiOff(); end(); }
      try { if (enc.state !== 'closed') enc.close(); } catch (e) { /* ignore */ }
    }
  };
  // make the same sounds again, off screen, exactly at their times, and put them in the video
  const addSound = async (muxer, pa, log, secs) => {
    const sr = 48000, len = Math.ceil((secs + 0.5) * sr);
    const off = new OfflineAudioContext(2, len, sr);
    const s = new o.Sound(); s.ctx = off; s.on = true; s.kv = o.snd.kv;
    s.out = off.createDynamicsCompressor(); s.out.threshold.value = -6; s.out.knee.value = 6; s.out.ratio.value = 12; s.out.attack.value = 0.002; s.out.release.value = 0.12; s.out.connect(off.destination);
    for (const [t, n, a] of log) { s.at = t; try { s[n](...a); } catch (e) { /* skip one sound */ } }
    const buf = await off.startRendering();
    const frames = Math.min(buf.length, Math.round(secs * sr));
    const aenc = new AudioEncoder({ output: (c, m) => muxer.addAudioChunk(c, m), error: (e) => { throw e; } });
    aenc.configure(pa.config);
    const L = buf.getChannelData(0), R = buf.getChannelData(1), step = 4800;
    for (let i = 0; i < frames; i += step) {
      const k = Math.min(step, frames - i), data = new Float32Array(k * 2);
      data.set(L.subarray(i, i + k), 0); data.set(R.subarray(i, i + k), k);
      const ad = new AudioData({ format: 'f32-planar', sampleRate: sr, numberOfFrames: k, numberOfChannels: 2, timestamp: Math.round(i * 1e6 / sr), data });
      aenc.encode(ad); ad.close();
    }
    await aenc.flush(); aenc.close();
  };

  /* ---------- 🔴 Live: records while it plays ---------- */
  let mr = null, chunks = [], liveEnd = 0, liveQ = 0, dest = null;
  const startLive = () => {
    begin(cfg.q); liveQ = cfg.q;
    const stream = comp.captureStream(cfg.fps);
    if (o.snd.on && o.snd.ctx && o.snd.out) { dest = o.snd.ctx.createMediaStreamDestination(); o.snd.out.connect(dest); dest.stream.getAudioTracks().forEach((t) => stream.addTrack(t)); }
    const types = ['video/mp4;codecs=avc1.640033,mp4a.40.2', 'video/mp4;codecs=avc1,mp4a.40.2', 'video/mp4', 'video/webm;codecs=vp9,opus', 'video/webm;codecs=vp8,opus', 'video/webm'];
    const mimeType = types.find((t) => MediaRecorder.isTypeSupported(t)) || '';
    mr = new MediaRecorder(stream, { mimeType, videoBitsPerSecond: RATE[cfg.q][cfg.fps === 60 ? 1 : 0] * 1e6, audioBitsPerSecond: 192000 });
    chunks = []; mr.ondataavailable = (e) => { if (e.data && e.data.size) chunks.push(e.data); };
    mr.start(1000);
    ui('live');
  };
  const stopLive = () => new Promise((res) => {
    if (!mr) return res();
    mr.onstop = () => {
      const type = (mr.mimeType || 'video/webm').split(';')[0];
      const blob = new Blob(chunks, { type }), secs = (performance.now() - t0) / 1000;
      if (dest) { try { o.snd.out.disconnect(dest); } catch (e) { /* ignore */ } dest = null; }
      mr = null; uiOff(); end();
      done(blob, `${SIZE_NAME[liveQ]} · ${cfg.fps} fps · ${Math.floor(secs / 60)}:${String(Math.round(secs % 60)).padStart(2, '0')}`, '');
      res();
    };
    mr.stop();
  });

  return {
    on: () => cfg.on && (canPerfect || canLive),
    mode: () => (cfg.mode === 'perfect' && canPerfect ? 'perfect' : canLive ? 'live' : 'perfect'),
    busy: false,
    active() { return this.busy || !!mr; },
    clock,
    // the page changed the two lines of text
    words() { if (!comp) return; popAt = clock(); wBusy = rasterWords().then(() => { wBusy = null; }); },
    // live: after each picture of the game
    frame() { if (mr && comp) compose(); if (mr && liveEnd && performance.now() > liveEnd) { liveEnd = 0; stopLive(); } },
    ended() { if (mr && !liveEnd) liveEnd = performance.now() + TAIL * 1000; },
    async start(driver) {
      if (this.mode() === 'perfect') {
        this.busy = true;
        try { await perfect(driver); } catch (e) { console.error(e); fail(e); } finally { this.busy = false; driver.finish && driver.finish(); }
      } else {
        try { startLive(); } catch (e) { console.error(e); end(); fail(e); return; }
        setTimeout(() => driver.go(), LEAD * 1000);
      }
    },
  };
}
