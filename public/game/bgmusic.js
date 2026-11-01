// 🎵 Background sound for the Shorts games (Cup Kick, Action Kick).
// The owner adds his own sounds (mp3, m4a, wav… or a video — its sound is used). They are kept on this phone
// (shared by both games), so he adds a sound once and picks it any time. He cuts the part he wants (start / end,
// by dragging on the wave or typing the seconds) and sets how loud it is. It plays under the game from the start
// and fades out at the end — also inside Auto record videos. When the part is shorter than the video it repeats.
const css = `
.bgm{margin-top:14px;padding:12px 14px;border:1px solid #22c55e99;border-radius:12px;background:linear-gradient(180deg,#0f2a22,#0d1a24)}
.bgm-h{display:flex;align-items:center;gap:8px;font-weight:800;color:#fff;font-size:15px;cursor:pointer}
.bgm-h input{position:absolute;opacity:0;width:1px;height:1px}
.bgm-sw{margin-left:auto;width:44px;height:24px;border-radius:99px;background:#3a4470;position:relative;transition:background .2s;flex:0 0 auto}
.bgm-sw:after{content:'';position:absolute;left:3px;top:3px;width:18px;height:18px;border-radius:50%;background:#fff;transition:left .2s}
.bgm-h input:checked+.bgm-sw,.bgm-row input:checked+.bgm-sw{background:#22c55e}.bgm-h input:checked+.bgm-sw:after,.bgm-row input:checked+.bgm-sw:after{left:23px}
.bgm-b[hidden]{display:none}
.bgm p{font-size:12px;color:#a9c9bb;margin-top:8px;line-height:1.4}
.bgm .l{display:block;font-size:12px;font-weight:700;color:#cdeedd;margin-top:10px}
.bgm select,.bgm input[type=number]{width:100%;margin-top:4px;padding:8px;border-radius:9px;border:1px solid #2f5a4a;background:#0b1a1a;color:#fff;font-size:14px;box-sizing:border-box}
.bgm .two{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.bgm .bt{display:flex;gap:6px;margin-top:8px}
.bgm .bt button{flex:1;width:auto;padding:9px 4px;border-radius:9px;background:#1d3a32;color:#dfffee;font-size:13px;font-weight:700}
.bgm .bt button.pr{background:linear-gradient(90deg,#16a34a,#22c55e);color:#04140b}
.bgm .bt button.dl{flex:0 0 auto;padding:9px 12px;background:#3a1d24;color:#ffb4b4}
.bgm .wave{position:relative;margin-top:10px;height:78px;border-radius:10px;background:#081414;border:1px solid #2f5a4a;touch-action:none;user-select:none;overflow:hidden}
.bgm .wave canvas{position:absolute;inset:0;width:100%;height:100%}
.bgm .hd{position:absolute;top:0;bottom:0;width:22px;margin-left:-11px;cursor:ew-resize;z-index:2}
.bgm .hd:before{content:'';position:absolute;left:10px;top:0;bottom:0;width:3px;background:#facc15;border-radius:2px}
.bgm .hd:after{content:'';position:absolute;left:3px;top:50%;width:16px;height:26px;margin-top:-13px;border-radius:6px;background:#facc15;box-shadow:0 0 6px #0008}
.bgm .ph{position:absolute;top:0;bottom:0;width:2px;background:#fff;z-index:1;pointer-events:none}
.bgm .info{display:flex;justify-content:space-between;font-size:12px;color:#9fd0b8;margin-top:6px}.bgm .info b{color:#facc15}
.bgm input[type=range]{width:100%;margin-top:6px}
.bgm .ends{display:flex;justify-content:space-between;font-size:11px;color:#7fa898}
.bgm-row{display:flex;align-items:center;gap:8px;margin-top:10px;font-size:13px;font-weight:700;color:#fff;cursor:pointer}
.bgm-row input{position:absolute;opacity:0;width:1px;height:1px}
.bgm .msg{font-size:12px;margin-top:6px;min-height:16px}.bgm .ok{color:#4ade80}.bgm .bad{color:#ff8a8a}
`;
const MAX_MB = 40;
const fmt = (s) => { s = Math.max(0, s); const m = Math.floor(s / 60); return m + ':' + (s % 60).toFixed(1).padStart(4, '0'); };
const store = (k, v) => { try { if (v === undefined) return JSON.parse(localStorage.getItem(k) || 'null'); localStorage.setItem(k, JSON.stringify(v)); } catch (e) { return null; } };

/* ---------- the sounds kept on this phone (IndexedDB, shared by both games) ---------- */
const db = () => new Promise((res, rej) => {
  const r = indexedDB.open('fft_bgsounds', 1);
  r.onupgradeneeded = () => r.result.createObjectStore('s', { keyPath: 'id' });
  r.onsuccess = () => res(r.result); r.onerror = () => rej(r.error);
});
const tx = async (mode, fn) => { const d = await db(); return new Promise((res, rej) => { const t = d.transaction('s', mode), st = t.objectStore('s'); const q = fn(st); t.oncomplete = () => res(q && q.result); t.onerror = () => rej(t.error); }); };
const listSounds = async () => { try { return ((await tx('readonly', (s) => s.getAll())) || []).sort((a, b) => b.t - a.t); } catch (e) { return []; } };
const getSound = async (id) => { try { return await tx('readonly', (s) => s.get(id)); } catch (e) { return null; } };
const putSound = (v) => tx('readwrite', (s) => s.put(v));
const delSound = (id) => tx('readwrite', (s) => s.delete(id));

/** o = { game, after (element to put the box after), snd (the game's Sound: needs ctx after start()) } */
export function setupBgMusic(o) {
  const st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);
  const K = 'fft_bgm_' + o.game;
  const S = Object.assign({ on: false, id: '', from: 0, to: 0, vol: 0.3, fade: true }, store(K) || {});
  const save = () => store(K, S);
  let buf = null, dur = 0, peaks = null, prev = null, prevT0 = 0, raf = 0;
  const decodeCtx = () => o.snd.ctx || (decodeCtx.c ||= new (window.AudioContext || window.webkitAudioContext)());

  const box = document.createElement('div'); box.className = 'bgm';
  box.innerHTML = `<label class="bgm-h">🎵 Background sound <input type="checkbox" id="bgm-on"><span class="bgm-sw"></span></label>
  <div class="bgm-b" hidden>
    <p>Add your own sound (mp3, m4a, wav… or a video — its sound is used). It is kept on this phone for both games. Cut the part you want and set how loud it is. It plays under the game and fades out at the end — also in the recorded video.</p>
    <label class="l">Your sounds<select id="bgm-pick"></select></label>
    <div class="bt"><button type="button" id="bgm-add">➕ Add a sound</button><button type="button" class="dl" id="bgm-del" title="Delete this sound">🗑</button></div>
    <input type="file" id="bgm-file" accept="audio/*,video/*" hidden>
    <div class="msg" id="bgm-msg"></div>
    <div id="bgm-edit" hidden>
      <div class="l">✂️ Cut: drag the yellow handles</div>
      <div class="wave" id="bgm-wave"><canvas></canvas><div class="ph" id="bgm-ph" hidden></div><div class="hd" id="bgm-h1"></div><div class="hd" id="bgm-h2"></div></div>
      <div class="info"><span>Start <b id="bgm-a"></b></span><span>Part: <b id="bgm-len"></b></span><span>End <b id="bgm-z"></b></span></div>
      <div class="two"><label class="l">Start (seconds)<input type="number" id="bgm-from" min="0" step="0.1"></label><label class="l">End (seconds)<input type="number" id="bgm-to" min="0" step="0.1"></label></div>
      <label class="l">🔊 Volume: <span id="bgm-volv"></span><input type="range" id="bgm-vol" min="0" max="1.5" step="0.05"></label>
      <div class="ends"><span>🔇 Off</span><span>100%</span><span>150%</span></div>
      <label class="bgm-row">Fade in at the start and fade out at the end <input type="checkbox" id="bgm-fade"><span class="bgm-sw"></span></label>
      <div class="bt"><button type="button" class="pr" id="bgm-play">▶ Listen to the part</button></div>
      <p>The video is usually 10–20 seconds. If the part is shorter, it starts again from the start of the part.</p>
    </div>
  </div>`;
  o.after.insertAdjacentElement('afterend', box);
  const $ = (id) => box.querySelector('#' + id), body = box.querySelector('.bgm-b');
  const msg = (t, cls = '') => { const m = $('bgm-msg'); m.textContent = t; m.className = 'msg ' + cls; };
  const wave = $('bgm-wave'), cv = wave.querySelector('canvas');

  /* ---------- the wave picture and the two handles ---------- */
  const draw = () => {
    const w = wave.clientWidth, h = wave.clientHeight; if (!w || !peaks) return;
    const r = Math.min(2, devicePixelRatio || 1); cv.width = w * r; cv.height = h * r;
    const g = cv.getContext('2d'); g.scale(r, r);
    const a = S.from / dur * w, z = S.to / dur * w;
    for (let x = 0; x < w; x++) {
      const p = peaks[Math.floor(x / w * peaks.length)] || 0, bh = Math.max(1, p * (h - 8));
      g.fillStyle = x >= a && x <= z ? '#4ade80' : '#2a4a40'; g.fillRect(x, (h - bh) / 2, 1, bh);
    }
    g.fillStyle = 'rgba(0,0,0,.45)'; g.fillRect(0, 0, a, h); g.fillRect(z, 0, w - z, h);
    $('bgm-h1').style.left = a + 'px'; $('bgm-h2').style.left = z + 'px';
    $('bgm-a').textContent = fmt(S.from); $('bgm-z').textContent = fmt(S.to); $('bgm-len').textContent = (S.to - S.from).toFixed(1) + ' s';
    if (document.activeElement !== $('bgm-from')) $('bgm-from').value = S.from.toFixed(1);
    if (document.activeElement !== $('bgm-to')) $('bgm-to').value = S.to.toFixed(1);
    $('bgm-from').max = $('bgm-to').max = dur.toFixed(1);
  };
  const setCut = (from, to) => {
    from = Math.max(0, Math.min(dur - 0.5, from)); to = Math.max(from + 0.5, Math.min(dur, to));
    S.from = Math.round(from * 10) / 10; S.to = Math.round(to * 10) / 10; save(); draw();
  };
  const drag = (el, which) => el.addEventListener('pointerdown', (e) => {
    e.preventDefault(); el.setPointerCapture(e.pointerId); stopPrev();
    const move = (ev) => { const r = wave.getBoundingClientRect(), t = (ev.clientX - r.left) / r.width * dur; which ? setCut(S.from, t) : setCut(t, S.to); };
    const up = () => { el.removeEventListener('pointermove', move); el.removeEventListener('pointerup', up); el.removeEventListener('pointercancel', up); };
    el.addEventListener('pointermove', move); el.addEventListener('pointerup', up); el.addEventListener('pointercancel', up);
  });
  drag($('bgm-h1'), 0); drag($('bgm-h2'), 1);
  $('bgm-from').addEventListener('change', () => setCut(Number($('bgm-from').value) || 0, S.to));
  $('bgm-to').addEventListener('change', () => setCut(S.from, Number($('bgm-to').value) || dur));
  addEventListener('resize', draw);

  /* ---------- volume, fade ---------- */
  const showVol = () => { $('bgm-vol').value = S.vol; $('bgm-volv').textContent = Math.round(S.vol * 100) + '%'; if (prev) prev.g.gain.value = S.vol; };
  $('bgm-vol').addEventListener('input', () => { S.vol = Number($('bgm-vol').value); save(); showVol(); });
  $('bgm-fade').addEventListener('change', () => { S.fade = $('bgm-fade').checked; save(); });

  /* ---------- load a sound ---------- */
  const load = async (id) => {
    stopPrev(); buf = null; peaks = null; $('bgm-edit').hidden = true;
    if (!id) return;
    const v = await getSound(id); if (!v) { msg('This sound is not on this phone any more.', 'bad'); return; }
    try {
      buf = await decodeCtx().decodeAudioData(await v.blob.arrayBuffer());
    } catch (e) { msg('Could not read this sound. Try an mp3 or m4a file.', 'bad'); return; }
    dur = buf.duration;
    const ch = buf.getChannelData(0), n = 600, stp = Math.max(1, Math.floor(ch.length / n)); peaks = new Float32Array(n); let mx = 0.0001;
    for (let i = 0; i < n; i++) { let p = 0; for (let j = i * stp, e = Math.min(ch.length, j + stp); j < e; j += 8) p = Math.max(p, Math.abs(ch[j])); peaks[i] = p; mx = Math.max(mx, p); }
    for (let i = 0; i < n; i++) peaks[i] /= mx;
    if (S.id !== id || !(S.to > S.from) || S.to > dur + 0.05) { S.from = 0; S.to = Math.min(dur, 30); }
    S.id = id; save();
    $('bgm-edit').hidden = false; msg(`${v.name} · ${fmt(dur)}`, 'ok');
    requestAnimationFrame(draw);
  };
  const fill = async (sel) => {
    const l = await listSounds();
    $('bgm-pick').innerHTML = l.length ? l.map((v) => `<option value="${v.id}">${v.name.replace(/</g, '&lt;')}</option>`).join('') : '<option value="">(no sounds yet — press ➕ Add a sound)</option>';
    $('bgm-del').hidden = !l.length;
    const id = sel && l.some((v) => v.id === sel) ? sel : (l[0] ? l[0].id : '');
    $('bgm-pick').value = id;
    await load(id);
  };
  $('bgm-pick').addEventListener('change', () => load($('bgm-pick').value));
  $('bgm-add').addEventListener('click', () => $('bgm-file').click());
  $('bgm-file').addEventListener('change', async () => {
    const f = $('bgm-file').files[0]; $('bgm-file').value = '';
    if (!f) return;
    if (f.size > MAX_MB * 1048576) { msg(`This file is too big (max ${MAX_MB} MB).`, 'bad'); return; }
    msg('Adding…');
    try { await decodeCtx().decodeAudioData(await f.arrayBuffer()); } catch (e) { msg('Could not read the sound in this file. Try an mp3 or m4a file.', 'bad'); return; }
    const id = 's' + Date.now().toString(36);
    try { await putSound({ id, name: f.name.replace(/\.[^.]+$/, '').slice(0, 40) || 'My sound', blob: f, t: Date.now() }); }
    catch (e) { msg('Could not keep the sound on this phone (storage full?).', 'bad'); return; }
    S.id = ''; await fill(id);
  });
  $('bgm-del').addEventListener('click', async () => {
    const id = $('bgm-pick').value; if (!id || !confirm('Delete this sound from this phone?')) return;
    await delSound(id); if (S.id === id) S.id = ''; save(); await fill('');
  });

  /* ---------- listen to the part (in the menu) ---------- */
  function stopPrev() { if (prev) { try { prev.s.stop(); } catch (e) { /* ignore */ } prev = null; } cancelAnimationFrame(raf); $('bgm-ph').hidden = true; $('bgm-play').textContent = '▶ Listen to the part'; }
  $('bgm-play').addEventListener('click', () => {
    if (prev) return stopPrev();
    if (!buf) return;
    const c = decodeCtx(); c.resume();
    const s = c.createBufferSource(), g = c.createGain(); s.buffer = buf; g.gain.value = S.vol;
    s.connect(g).connect(c.destination); s.start(0, S.from, S.to - S.from); prev = { s, g }; prevT0 = c.currentTime;
    s.onended = () => { if (prev && prev.s === s) stopPrev(); };
    $('bgm-play').textContent = '⏹ Stop';
    const ph = $('bgm-ph'); ph.hidden = false;
    const step = () => { if (!prev) return; ph.style.left = ((S.from + c.currentTime - prevT0) / dur * 100) + '%'; raf = requestAnimationFrame(step); };
    step();
  });

  /* ---------- on / off ---------- */
  const show = () => { $('bgm-on').checked = S.on; body.hidden = !S.on; $('bgm-fade').checked = S.fade; showVol(); if (S.on) requestAnimationFrame(draw); };
  $('bgm-on').addEventListener('change', () => { S.on = $('bgm-on').checked; save(); show(); if (!S.on) stopPrev(); });
  show();
  fill(S.id);

  return {
    ready: () => S.on && !!buf && S.to > S.from,
    /** START: the sound begins under the game (snd.bg is recorded by the recorder like the other sounds) */
    play() { stopPrev(); if (S.on && buf && S.to > S.from) o.snd.bg(buf, S.from, S.to, S.vol, S.fade); },
    /** the play is over: fade out over this many seconds */
    stop(secs) { o.snd.bgStop(S.fade ? secs : 0.08); },
  };
}
