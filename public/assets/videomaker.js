// Admin → Video Maker: a game video goes in, a finished Short comes out (made in this browser, frame by frame).
// Template "Moment Before Win" (from the owner's Alight Motion project New_Project_66.xml):
//   1080×1350 · 8.215 s · 60 fps · 0–6.7 s the game video (from 0.75 s, muted, graded) with a shaking neon
//   banner on top · 6.7 s on: the last frame freezes, "*WOULD YOU DRINK THIS?*" + big spectrum 3D "WIN YOUR TEAM???"
//   with a laser, particles and 📈📈📈 · the owner's own audio underneath.
import { Muxer, ArrayBufferTarget } from '/game/vendor/mp4-muxer.esm.js';

const W = 1080, H = 1350, FPS = 60, TOTAL = 8.215, CUT = 6.7;
const $ = (id) => document.getElementById(id);
const KEY = 'fft_vm';
const fields = ['t1', 't2', 't3', 'start', 'aStart', 'vol'];
const load = () => { try { const s = JSON.parse(localStorage.getItem(KEY) || '{}'); fields.forEach((f) => { if (s[f] !== undefined && $(f)) $(f).value = s[f]; }); } catch (e) { /* ignore */ } };
const save = () => { try { localStorage.setItem(KEY, JSON.stringify(Object.fromEntries(fields.map((f) => [f, $(f).value])))); } catch (e) { /* ignore */ } };

/* ---------------------------------------------------------------- files */
let videoFile = null, audioFile = null, img1 = null, img2 = null;
const pickImg = (file) => new Promise((res) => { const i = new Image(); i.onload = () => res(i); i.onerror = () => res(null); i.src = URL.createObjectURL(file); });
const showFile = (box, f, extra) => { $(box).innerHTML = f ? `<b>${f.name.replace(/</g, '&lt;')}</b><br><span class="muted">${(f.size / 1048576).toFixed(1)} MB${extra ? ' · ' + extra : ''}</span>` : ''; };

/* ---------------------------------------------------------------- drawing one frame */
const ease = (x) => 1 - Math.pow(1 - Math.min(1, Math.max(0, x)), 3);
const back = (x) => { x = Math.min(1, Math.max(0, x)); const c = 1.70158; return 1 + (c + 1) * Math.pow(x - 1, 3) + c * Math.pow(x - 1, 2); };
const noise = (t, f, seed) => Math.sin(t * f * 6.283 + seed) * 0.6 + Math.sin(t * f * 2.7 * 6.283 + seed * 3.1) * 0.4;

const fitText = (g, text, max, size, font) => { let s = size; do { g.font = `${font} ${s}px ${FONT}`; s -= 2; } while (g.measureText(text).width > max && s > 20); return s + 2; };
const FONT = '"Luckiest Guy", "Arial Black", Impact, sans-serif';

function drawVideo(g, v, dark) {
  // fill the 4:5 picture (the 9:16 game video is cut at the top and bottom, like in the template)
  const vw = v.videoWidth || 9, vh = v.videoHeight || 16, s = Math.max(W / vw, H / vh) * 1.0;
  g.save();
  g.filter = `saturate(1.25) contrast(1.1) brightness(${dark ? 0.62 : 1.04})` + (dark ? ' blur(3px)' : '');
  g.drawImage(v, (W - vw * s) / 2, (H - vh * s) / 2, vw * s, vh * s);
  g.restore();
  // soft vignette (the template's lift / shadow layers)
  const r = g.createRadialGradient(W / 2, H / 2, H * 0.35, W / 2, H / 2, H * 0.75);
  r.addColorStop(0, 'rgba(0,0,0,0)'); r.addColorStop(1, 'rgba(0,0,0,.35)');
  g.fillStyle = r; g.fillRect(0, 0, W, H);
}

function drawBanner(g, t, text) {
  // "MOMENT BEFORE WIN": dark plate, cyan trace, glare that sweeps across, shaking (vshake)
  const sx = noise(t, 6, 1) * 5 * 0.8, sy = noise(t, 8, 2) * 12 * 0.8;
  const inA = ease(t / 0.35);
  g.save();
  g.translate(W / 2 + sx, 150 + sy - (1 - inA) * 60); g.globalAlpha = inA;
  const bw = 920, bh = 150, rr = 34;
  const plate = () => { g.beginPath(); g.roundRect(-bw / 2, -bh / 2, bw, bh, rr); };
  g.shadowColor = 'rgba(0,0,0,.6)'; g.shadowBlur = 30; g.shadowOffsetY = 10;
  plate(); g.fillStyle = '#0a0f1e'; g.fill();
  g.shadowColor = 'transparent';
  // cyan glowing edge
  g.lineWidth = 5; g.strokeStyle = '#22d3ee'; g.shadowColor = '#22d3ee'; g.shadowBlur = 24; plate(); g.stroke(); g.shadowBlur = 0;
  // shimmer dots
  g.save(); plate(); g.clip();
  for (let i = 0; i < 26; i++) { const x = ((i * 97 + t * 40) % bw) - bw / 2, y = ((i * 53) % bh) - bh / 2; g.fillStyle = `rgba(160,240,255,${0.15 + 0.15 * Math.sin(t * 3 + i)})`; g.fillRect(x, y, 3, 3); }
  // the glare sweeps every 1.6 s
  const gx = ((t % 1.6) / 1.6) * (bw + 400) - bw / 2 - 200;
  const gr = g.createLinearGradient(gx - 120, 0, gx + 120, 0);
  gr.addColorStop(0, 'rgba(255,255,255,0)'); gr.addColorStop(0.5, 'rgba(255,255,255,.28)'); gr.addColorStop(1, 'rgba(255,255,255,0)');
  g.fillStyle = gr; g.fillRect(-bw / 2, -bh / 2, bw, bh);
  g.restore();
  // pictures on both sides (the template's Picsart PNGs) when added
  if (img1) { const s = 120 / Math.max(img1.width, img1.height); for (const side of [-1, 1]) { g.save(); g.translate(side * (bw / 2 - 30), -bh / 2 + 10); g.rotate(side * 0.18 + noise(t, 3, side) * 0.05); g.drawImage(img1, -img1.width * s / 2, -img1.height * s / 2, img1.width * s, img1.height * s); g.restore(); } }
  // the words
  const size = fitText(g, text, bw - 80, 74, '400');
  g.textAlign = 'center'; g.textBaseline = 'middle';
  g.lineJoin = 'round'; g.lineWidth = size * 0.14; g.strokeStyle = '#001018'; g.strokeText(text, 0, 4);
  g.shadowColor = '#22d3ee'; g.shadowBlur = 18; g.fillStyle = '#ffffff'; g.fillText(text, 0, 4); g.shadowBlur = 0;
  g.restore();
}

function drawEnd(g, t, small, big) {
  // the last 1.5 s: freeze frame + question in big spectrum 3D letters with a laser, particles and wings
  const k = t - CUT;
  // particles
  for (let i = 0; i < 70; i++) {
    const a = i * 2.39996, r = 120 + ((i * 37 + k * 260) % 620);
    const x = W / 2 + Math.cos(a) * r, y = 720 + Math.sin(a) * r * 0.7;
    g.fillStyle = `hsla(${(i * 23 + k * 200) % 360},100%,70%,${0.5 * Math.min(1, k * 3)})`; g.beginPath(); g.arc(x, y, 2 + (i % 3), 0, 6.283); g.fill();
  }
  // small text on top
  const ia = back(k / 0.3);
  g.save(); g.translate(W / 2, 420); g.scale(ia, ia); g.globalAlpha = Math.min(1, k * 4);
  const s1 = fitText(g, small, W - 120, 54, '400');
  g.textAlign = 'center'; g.textBaseline = 'middle'; g.lineJoin = 'round'; g.lineWidth = s1 * 0.16; g.strokeStyle = '#000'; g.strokeText(small, 0, 0);
  g.fillStyle = '#ffd23a'; g.fillText(small, 0, 0);
  g.restore();
  // the laser behind the big words
  const lw = W * ease((k - 0.1) / 0.35);
  if (lw > 0) {
    const lg = g.createLinearGradient(W / 2 - lw / 2, 0, W / 2 + lw / 2, 0);
    for (let i = 0; i <= 6; i++) lg.addColorStop(i / 6, `hsl(${(i * 60 + k * 300) % 360},100%,60%)`);
    g.save(); g.shadowColor = '#fff'; g.shadowBlur = 30; g.fillStyle = lg; g.fillRect(W / 2 - lw / 2, 712, lw, 16); g.fillStyle = 'rgba(255,255,255,.9)'; g.fillRect(W / 2 - lw / 2, 717, lw, 6); g.restore();
    // wings
    for (const side of [-1, 1]) {
      g.save(); g.translate(W / 2 + side * (lw / 2 - 20), 720); g.scale(side, 1); g.globalAlpha = 0.85;
      g.fillStyle = `hsl(${(190 + k * 200) % 360},100%,60%)`; g.shadowColor = g.fillStyle; g.shadowBlur = 20;
      g.beginPath(); g.moveTo(0, 0); g.lineTo(-90, -70); g.lineTo(-60, 0); g.lineTo(-90, 70); g.closePath(); g.fill(); g.restore();
    }
  }
  // big 3D words: Ice Platinum first, then the multi-colour spectrum
  const pop = back((k - 0.15) / 0.35);
  if (pop > 0) {
    g.save(); g.translate(W / 2, 720); g.rotate(Math.sin(k * 4) * 0.03); g.scale(pop, pop);
    const s2 = fitText(g, big, W - 100, 124, '400');
    g.textAlign = 'center'; g.textBaseline = 'middle'; g.lineJoin = 'round';
    for (let d = 14; d > 0; d -= 2) { g.fillStyle = `rgba(20,10,40,${0.9 - d * 0.03})`; g.fillText(big, d * 0.6, d); }   // depth
    g.lineWidth = s2 * 0.12; g.strokeStyle = '#05010f'; g.strokeText(big, 0, 0);
    const spec = Math.min(1, Math.max(0, (k - 0.6) / 0.4));
    const tw = g.measureText(big).width;
    const fg = g.createLinearGradient(-tw / 2, -s2 / 2, tw / 2, s2 / 2);
    if (spec < 1) { fg.addColorStop(0, '#ffffff'); fg.addColorStop(0.5, '#c7d2fe'); fg.addColorStop(1, '#e0f2fe'); }
    g.fillStyle = fg; g.shadowColor = '#a78bfa'; g.shadowBlur = 26; g.fillText(big, 0, 0);
    if (spec > 0) {
      const sg = g.createLinearGradient(-tw / 2, 0, tw / 2, 0);
      for (let i = 0; i <= 6; i++) sg.addColorStop(i / 6, `hsl(${(i * 55 + k * 240) % 360},100%,62%)`);
      g.globalAlpha = spec; g.fillStyle = sg; g.fillText(big, 0, 0);
    }
    g.restore();
  }
  // 📈📈📈
  const ea = back((k - 0.45) / 0.3);
  if (ea > 0) { g.save(); g.translate(W / 2, 900 + Math.sin(k * 8) * 6); g.scale(ea, ea); g.font = `86px "Noto Color Emoji", "Apple Color Emoji", sans-serif`; g.textAlign = 'center'; g.textBaseline = 'middle'; g.fillText('📈📈📈', 0, 0); g.restore(); }
  // picture of the end part (the template's second PNG) when added
  if (img2) { const s = 180 / Math.max(img2.width, img2.height), p = back((k - 0.3) / 0.3); if (p > 0) { g.save(); g.translate(W / 2, 1080); g.scale(p, p); g.drawImage(img2, -img2.width * s / 2, -img2.height * s / 2, img2.width * s, img2.height * s); g.restore(); } }
  // ring
  g.save(); g.globalAlpha = Math.max(0, 1 - k * 1.5); g.strokeStyle = '#fff'; g.lineWidth = 8; g.beginPath(); g.arc(W / 2, 720, 80 + k * 900, 0, 6.283); g.stroke(); g.restore();
}

function drawFrame(g, v, t, o) {
  g.fillStyle = '#000'; g.fillRect(0, 0, W, H);
  drawVideo(g, v, t >= CUT);
  if (t < CUT) drawBanner(g, t, o.t1);
  else drawEnd(g, t, o.t3, o.t2);
  // a quick white flash on the cut
  if (t >= CUT && t < CUT + 0.12) { g.fillStyle = `rgba(255,255,255,${0.7 * (1 - (t - CUT) / 0.12)})`; g.fillRect(0, 0, W, H); }
}

/* ---------------------------------------------------------------- making the video */
const seek = (v, time) => new Promise((res) => {
  if (Math.abs(v.currentTime - time) < 0.001) return res();
  const done = () => { v.removeEventListener('seeked', done); res(); };
  v.addEventListener('seeked', done); v.currentTime = time;
});
async function pickVideo() {
  const tries = ['avc1.640028', 'avc1.4d0028', 'avc1.42e028'].map((codec) => ({ mux: 'avc', c: { codec, avc: { format: 'avc' } } })).concat([{ mux: 'vp9', c: { codec: 'vp09.00.41.08' } }]);
  for (const t of tries) for (const hw of ['prefer-hardware', 'no-preference']) {
    const c = { ...t.c, width: W, height: H, bitrate: 14e6, framerate: FPS, hardwareAcceleration: hw, latencyMode: 'quality' };
    try { const s = await VideoEncoder.isConfigSupported(c); if (s.supported) return { config: s.config, mux: t.mux }; } catch (e) { /* next */ }
  }
  return null;
}
async function pickAudio() {
  if (typeof AudioEncoder !== 'function') return null;
  for (const [codec, mux] of [['mp4a.40.2', 'aac'], ['opus', 'opus']]) {
    const c = { codec, sampleRate: 48000, numberOfChannels: 2, bitrate: 192000 };
    try { const s = await AudioEncoder.isConfigSupported(c); if (s.supported) return { config: s.config, mux }; } catch (e) { /* next */ }
  }
  return null;
}
// the owner's audio, cut to the video's length, at the chosen volume, with a short fade in / out
async function makeAudio(file, from, vol) {
  const ctx = new AudioContext();
  const buf = await ctx.decodeAudioData(await file.arrayBuffer());
  ctx.close();
  const sr = 48000, len = Math.round(TOTAL * sr);
  const off = new OfflineAudioContext(2, len, sr);
  const src = off.createBufferSource(); src.buffer = buf;
  const gn = off.createGain(); gn.gain.setValueAtTime(0, 0); gn.gain.linearRampToValueAtTime(vol, 0.08); gn.gain.setValueAtTime(vol, TOTAL - 0.5); gn.gain.linearRampToValueAtTime(0, TOTAL);
  src.connect(gn).connect(off.destination); src.start(0, Math.max(0, Math.min(from, buf.duration - 0.1)));
  return off.startRendering();
}

async function make() {
  const o = { t1: $('t1').value.trim() || 'MOMENT BEFORE WIN', t2: $('t2').value.trim() || 'WIN YOUR TEAM???', t3: $('t3').value.trim(), start: Math.max(0, Number($('start').value) || 0) };
  const msg = $('vm-msg'), bar = $('vm-bar'), out = $('vm-out');
  const say = (t) => { msg.textContent = t; };
  if (!videoFile) return say('⚠️ Choose a video first.');
  if (typeof VideoEncoder !== 'function') return say('⚠️ This browser cannot make videos. Open the admin in Chrome.');
  save();
  $('vm-go').disabled = true; out.hidden = true; bar.parentElement.hidden = false; bar.style.width = '0%';
  let lock = null; try { lock = await navigator.wakeLock?.request('screen'); } catch (e) { /* ignore */ }
  try {
    say('Opening the video…');
    const v = document.createElement('video'); v.muted = true; v.playsInline = true; v.preload = 'auto'; v.src = URL.createObjectURL(videoFile);
    await new Promise((res, rej) => { v.onloadeddata = res; v.onerror = () => rej(new Error('This video cannot be opened here.')); });
    const need = o.start + CUT;
    if (v.duration && v.duration < need - 0.05) o.start = Math.max(0, v.duration - CUT);   // a short video: start earlier so it still fills 6.7 s
    const pv = await pickVideo(); if (!pv) throw new Error('This phone cannot make MP4 video here.');
    const pa = audioFile ? await pickAudio() : null;
    const target = new ArrayBufferTarget();
    const muxer = new Muxer({ target, fastStart: 'in-memory', firstTimestampBehavior: 'offset', video: { codec: pv.mux, width: W, height: H, frameRate: FPS }, ...(pa ? { audio: { codec: pa.mux, numberOfChannels: 2, sampleRate: 48000 } } : {}) });
    let err = null;
    const enc = new VideoEncoder({ output: (c, m) => muxer.addVideoChunk(c, m), error: (e) => { err = e; } });
    enc.configure(pv.config);
    const cv = document.createElement('canvas'); cv.width = W; cv.height = H;
    const g = cv.getContext('2d', { alpha: false });
    const prev = $('vm-prev'), pg = prev.getContext('2d');
    try { await document.fonts.load(`400 80px "Luckiest Guy"`); } catch (e) { /* the fallback font is fine */ }
    const frames = Math.round(TOTAL * FPS), freezeAt = o.start + CUT - 1 / FPS;
    for (let n = 0; n < frames; n++) {
      const t = n / FPS;
      await seek(v, Math.min(t < CUT ? o.start + t : freezeAt, (v.duration || 1e9) - 0.02));
      drawFrame(g, v, t, o);
      const f = new VideoFrame(cv, { timestamp: Math.round(n * 1e6 / FPS), duration: Math.round(1e6 / FPS) });
      enc.encode(f, { keyFrame: n % (FPS * 2) === 0 }); f.close();
      if (err) throw err;
      while (enc.encodeQueueSize > 4) await new Promise((r) => setTimeout(r, 4));
      if (n % 3 === 0) { pg.drawImage(cv, 0, 0, prev.width, prev.height); bar.style.width = (n / frames * 92).toFixed(1) + '%'; say(`⏳ Making the video… ${Math.round(n / frames * 92)}%`); await new Promise((r) => requestAnimationFrame(r)); }
    }
    await enc.flush(); enc.close();
    if (pa) {
      say('🎵 Adding the audio…');
      const ab = await makeAudio(audioFile, Math.max(0, Number($('aStart').value) || 0), Math.max(0, Math.min(2, (Number($('vol').value) || 100) / 100)));
      const aenc = new AudioEncoder({ output: (c, m) => muxer.addAudioChunk(c, m), error: (e) => { err = e; } });
      aenc.configure(pa.config);
      const L = ab.getChannelData(0), R = ab.numberOfChannels > 1 ? ab.getChannelData(1) : L, step = 4800;
      for (let i = 0; i < ab.length; i += step) {
        const k = Math.min(step, ab.length - i), d = new Float32Array(k * 2); d.set(L.subarray(i, i + k), 0); d.set(R.subarray(i, i + k), k);
        const a = new AudioData({ format: 'f32-planar', sampleRate: 48000, numberOfFrames: k, numberOfChannels: 2, timestamp: Math.round(i * 1e6 / 48000), data: d });
        aenc.encode(a); a.close();
      }
      await aenc.flush(); aenc.close();
      if (err) throw err;
    }
    muxer.finalize();
    const blob = new Blob([target.buffer], { type: 'video/mp4' });
    const url = URL.createObjectURL(blob), name = 'short-' + new Date().toISOString().slice(0, 16).replace(/[-:T]/g, '') + '.mp4';
    bar.style.width = '100%';
    say(`✅ Ready: 1080×1350 · ${TOTAL.toFixed(1)} s · ${FPS} fps · ${(blob.size / 1048576).toFixed(1)} MB${audioFile && !pa ? ' · (no audio: this browser cannot add it)' : ''}`);
    out.hidden = false;
    $('vm-video').src = url;
    $('vm-save').onclick = () => { const a = document.createElement('a'); a.href = url; a.download = name; document.body.appendChild(a); a.click(); a.remove(); };
    const file = new File([blob], name, { type: 'video/mp4' });
    $('vm-share').hidden = !(navigator.canShare && navigator.canShare({ files: [file] }));
    $('vm-share').onclick = () => navigator.share({ files: [file], title: name }).catch(() => {});
    URL.revokeObjectURL(v.src);
  } catch (e) {
    say('⚠️ ' + (e && e.message ? e.message : e));
  } finally {
    $('vm-go').disabled = false;
    if (lock) lock.release().catch(() => {});
  }
}

/* ---------------------------------------------------------------- page */
load();
fields.forEach((f) => $(f).addEventListener('input', save));
$('vm-video-in').addEventListener('change', (e) => {
  videoFile = e.target.files[0] || null;
  if (!videoFile) return showFile('vm-video-f', null);
  const v = document.createElement('video'); v.preload = 'metadata'; v.src = URL.createObjectURL(videoFile);
  v.onloadedmetadata = () => { showFile('vm-video-f', videoFile, `${v.videoWidth}×${v.videoHeight} · ${v.duration.toFixed(1)} s`); URL.revokeObjectURL(v.src); };
});
$('vm-audio-in').addEventListener('change', (e) => { audioFile = e.target.files[0] || null; showFile('vm-audio-f', audioFile, audioFile ? 'plays under the whole video' : ''); $('vm-audio-x').hidden = !audioFile; });
$('vm-audio-x').addEventListener('click', () => { audioFile = null; $('vm-audio-in').value = ''; showFile('vm-audio-f', null); $('vm-audio-x').hidden = true; });
$('vm-img1').addEventListener('change', async (e) => { img1 = e.target.files[0] ? await pickImg(e.target.files[0]) : null; });
$('vm-img2').addEventListener('change', async (e) => { img2 = e.target.files[0] ? await pickImg(e.target.files[0]) : null; });
$('vm-go').addEventListener('click', make);
// the preview shows the design before a video is chosen
(() => { const c = $('vm-prev'), g = c.getContext('2d'), big = document.createElement('canvas'); big.width = W; big.height = H; const bg = big.getContext('2d');
  const blank = document.createElement('canvas'); blank.width = 9; blank.height = 16; const b = blank.getContext('2d'); const gr = b.createLinearGradient(0, 0, 0, 16); gr.addColorStop(0, '#0b2a5c'); gr.addColorStop(0.55, '#1a7a3a'); gr.addColorStop(1, '#2fa84f'); b.fillStyle = gr; b.fillRect(0, 0, 9, 16);
  let t0 = performance.now();
  const loop = () => {
    if ($('vm-go').disabled) return requestAnimationFrame(loop);   // while making, the preview shows the real frames
    const t = ((performance.now() - t0) / 1000) % TOTAL;
    const o = { t1: $('t1').value.trim() || 'MOMENT BEFORE WIN', t2: $('t2').value.trim() || 'WIN YOUR TEAM???', t3: $('t3').value.trim() };
    bg.fillStyle = '#000'; bg.fillRect(0, 0, W, H); bg.drawImage(blank, 0, 0, W, H);
    if (t < CUT) drawBanner(bg, t, o.t1); else drawEnd(bg, t, o.t3, o.t2);
    g.drawImage(big, 0, 0, c.width, c.height); requestAnimationFrame(loop); };
  requestAnimationFrame(loop); })();
