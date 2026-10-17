// Shared sound effects and commentator for the FFTEdit games.

/* ------------------------------------------------------------------- sounds */
// All sound effects are made in code (no files needed).
export class Sfx {
  constructor() {
    const AC = window.AudioContext || window.webkitAudioContext;
    this.ok = !!AC;
    if (!this.ok) return;
    this.ctx = new AC();
    this.master = this.ctx.createGain(); this.master.gain.value = 0.9;
    const comp = this.ctx.createDynamicsCompressor();
    this.master.connect(comp); comp.connect(this.ctx.destination);
    const len = this.ctx.sampleRate * 2;
    this.noise = this.ctx.createBuffer(1, len, this.ctx.sampleRate);
    const d = this.noise.getChannelData(0);
    for (let i = 0; i < len; i++) d[i] = Math.random() * 2 - 1;
    // continuous layer
    // only a soft low rumble while balls roll (no always-on background hiss)
    this.rollGain = this.loop(160, 0.7, 0, 'lowpass');
    this.last = {};
  }
  get running() { return this.ok && this.ctx.state === 'running'; }
  resume() { if (this.ok && this.ctx.state !== 'running') this.ctx.resume(); }
  loop(freq, q, gain, type, lfoRate) {
    const src = this.ctx.createBufferSource(); src.buffer = this.noise; src.loop = true;
    const f = this.ctx.createBiquadFilter(); f.type = type; f.frequency.value = freq; f.Q.value = q;
    const g = this.ctx.createGain(); g.gain.value = gain;
    src.connect(f); f.connect(g); g.connect(this.master); src.start();
    if (lfoRate) {
      const lfo = this.ctx.createOscillator(); lfo.frequency.value = lfoRate;
      const lg = this.ctx.createGain(); lg.gain.value = gain * 0.5;
      lfo.connect(lg); lg.connect(g.gain); lfo.start();
    }
    return g;
  }
  limit(name, ms) { const now = performance.now(); if (now - (this.last[name] || 0) < ms) return false; this.last[name] = now; return true; }
  burst({ freq = 1000, q = 1, type = 'bandpass', dur = 0.1, vol = 0.5, sweepTo = 0, delay = 0 }) {
    const t = this.ctx.currentTime + delay;
    const src = this.ctx.createBufferSource(); src.buffer = this.noise;
    const f = this.ctx.createBiquadFilter(); f.type = type; f.frequency.setValueAtTime(freq, t); f.Q.value = q;
    if (sweepTo) f.frequency.exponentialRampToValueAtTime(sweepTo, t + dur);
    const g = this.ctx.createGain(); g.gain.setValueAtTime(vol, t); g.gain.exponentialRampToValueAtTime(0.001, t + dur);
    src.connect(f); f.connect(g); g.connect(this.master); src.start(t, Math.random()); src.stop(t + dur + 0.05);
  }
  tone({ freq = 440, to = 0, type = 'sine', dur = 0.2, vol = 0.3, delay = 0, attack = 0.005 }) {
    const t = this.ctx.currentTime + delay;
    const o = this.ctx.createOscillator(); o.type = type; o.frequency.setValueAtTime(freq, t);
    if (to) o.frequency.exponentialRampToValueAtTime(to, t + dur);
    const g = this.ctx.createGain(); g.gain.setValueAtTime(0.0001, t); g.gain.exponentialRampToValueAtTime(vol, t + attack); g.gain.exponentialRampToValueAtTime(0.001, t + dur);
    o.connect(g); g.connect(this.master); o.start(t); o.stop(t + dur + 0.05);
  }
  release() { if (!this.running) return; this.tone({ freq: 1500, to: 900, type: 'square', dur: 0.05, vol: 0.08 }); this.burst({ freq: 3000, q: 2, dur: 0.05, vol: 0.25 }); }
  land(s = 1) { if (!this.running || !this.limit('land', 60)) return; this.tone({ freq: 140, to: 55, dur: 0.18, vol: 0.5 * s }); this.burst({ freq: 900, q: 1, dur: 0.08, vol: 0.3 * s }); }
  rim(s = 1) { if (!this.running || !this.limit('rim', 90)) return; this.tone({ freq: 220, to: 120, dur: 0.12, vol: 0.25 * s, type: 'triangle' }); this.burst({ freq: 1600, q: 2, dur: 0.06, vol: 0.25 * s }); }
  clack(s = 1) { if (!this.running || !this.limit('clack', 80)) return; this.burst({ freq: 2600, q: 4, dur: 0.06, vol: 0.6 * s }); this.tone({ freq: 950, type: 'triangle', dur: 0.07, vol: 0.2 * s }); }
  fall() { if (!this.running) return; this.burst({ freq: 2200, sweepTo: 250, q: 1.5, dur: 0.6, vol: 0.45 }); this.tone({ freq: 600, to: 120, dur: 0.5, vol: 0.18, type: 'sine' }); }
  goal() {
    if (!this.running) return;
    // whistle + cheering crowd + short horn chord
    this.tone({ freq: 2900, dur: 0.18, vol: 0.12, type: 'sine' }); this.tone({ freq: 2900, dur: 0.35, vol: 0.12, type: 'sine', delay: 0.22 });
    for (let i = 0; i < 6; i++) this.burst({ freq: 700 + Math.random() * 900, q: 0.8, dur: 2.2, vol: 0.22, delay: 0.05 * i });
    [523, 659, 784].forEach((f) => this.tone({ freq: f, type: 'sawtooth', dur: 0.6, vol: 0.07, delay: 0.1, attack: 0.02 }));
  }
  win() {
    if (!this.running) return;
    [523, 659, 784, 1047].forEach((f, i) => { this.tone({ freq: f, type: 'triangle', dur: 0.5, vol: 0.25, delay: i * 0.16 }); this.tone({ freq: f / 2, type: 'sawtooth', dur: 0.5, vol: 0.06, delay: i * 0.16 }); });
    [523, 659, 784, 1047].forEach((f) => this.tone({ freq: f, type: 'triangle', dur: 1.6, vol: 0.16, delay: 0.7, attack: 0.03 }));
    for (let i = 0; i < 10; i++) this.burst({ freq: 600 + Math.random() * 1200, q: 0.7, dur: 3.2, vol: 0.2, delay: 0.1 * i });
  }
  setRoll(speed) { if (this.ok) this.rollGain.gain.setTargetAtTime(speed > 0.3 ? Math.min(0.12, speed * 0.03) : 0, this.ctx.currentTime, 0.08); }
  duck(on) { if (this.ok) this.master.gain.setTargetAtTime(on ? 0.6 : 0.9, this.ctx.currentTime, 0.3); } // sound effects step back while the commentator talks
}

/* -------------------------------------------------------------- commentator */
// Plays English voice lines with an on-screen caption. Lines and audio come from the admin panel.
export class Commentator {
  constructor(voice, sfx) {
    this.enabled = !!(voice && voice.enabled);
    this.volume = voice && voice.volume != null ? voice.volume : 0.95;
    this.sfx = sfx;
    this.byCat = {};
    this.queue = [];
    this.busy = false;
    this.lastId = '';
    this.audio = {};
    Object.entries((voice && voice.lines) || {}).forEach(([id, l]) => {
      const cat = id.replace(/_\d+$/, '');
      (this.byCat[cat] = this.byCat[cat] || []).push({ id, text: l.text, url: l.url });
      if (l.url) { const a = new Audio(l.url); a.preload = 'auto'; this.audio[id] = a; }
    });
    this.box = document.getElementById('caption'); this.textEl = document.getElementById('caption-text');
  }
  say(cat, { urgent = false, chance = 1, prefix = '' } = {}) {
    if (!this.enabled || Math.random() > chance) return;
    const list = (this.byCat[cat] || []).filter((l) => l.id !== this.lastId);
    const pool = list.length ? list : this.byCat[cat] || [];
    if (!pool.length) return;
    const line = { ...pool[Math.floor(Math.random() * pool.length)], prefix };
    if (urgent) this.queue = [line]; else if (this.queue.length < 2) this.queue.push(line); else return;
    if (urgent) this.stopCurrent(); // cut off whatever is playing
    this.next();
  }
  get idle() { return !this.busy && !this.queue.length; }
  stopCurrent() {
    if (this.current) { this.current.onended = this.current.onerror = null; this.current.pause(); this.current = null; }
    clearTimeout(this.timer); this.seq = (this.seq || 0) + 1; this.busy = false;
  }
  next() {
    if (this.busy || !this.queue.length) return;
    const line = this.queue.shift();
    this.lastId = line.id; this.busy = true;
    const seq = this.seq = (this.seq || 0) + 1; // older timers for a cut-off line do nothing
    const shown = line.prefix ? line.prefix + ' ' + line.text : line.text;
    this.textEl.textContent = shown;
    this.box.hidden = false; this.box.classList.add('talking');
    this.sfx.duck(true);
    const shownAt = performance.now();
    const minShow = Math.max(3200, shown.length * 55);
    let finished = false;
    const done = () => {
      if (finished || seq !== this.seq) return;
      const left = minShow - (performance.now() - shownAt);
      if (left > 0) { clearTimeout(this.timer); this.timer = setTimeout(done, left); this.box.classList.remove('talking'); this.sfx.duck(false); return; }
      finished = true;
      clearTimeout(this.timer);
      this.box.classList.remove('talking');
      this.sfx.duck(false);
      this.current = null;
      setTimeout(() => { if (seq !== this.seq) return; this.busy = false; if (this.queue.length) this.next(); else this.box.hidden = true; }, 900);
    };
    const a = this.audio[line.id];
    const canPlay = a && this.sfx.running;
    if (canPlay) {
      this.current = a; a.currentTime = 0; a.volume = Math.max(0, Math.min(1, this.volume));
      a.onended = done; a.onerror = done;
      a.play().catch(() => { this.timer = setTimeout(done, 4000); });
      this.timer = setTimeout(done, 15000); // safety
    } else {
      this.timer = setTimeout(done, Math.max(3000, line.text.length * 65));
    }
  }
}

/* ------------------------------------------------------------ pause screen */
export const PACE = { slow: 0.8, normal: 1, fast: 1.3 };

// Shown instead of the game when Auto Play is off in the admin panel.
// Checks the settings every 15 s and reloads when the game is switched back on.
export function holdScreen(api, rev) {
  const el = document.createElement('div');
  el.style.cssText = 'position:fixed;inset:0;z-index:99;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2vh;' +
    'background:radial-gradient(60% 40% at 50% 45%,#1b2a55,#05060d);color:#fff;font-family:Oswald,Impact,Arial Narrow,sans-serif;text-align:center';
  el.innerHTML = '<div style="font-size:9vmin;font-weight:700;letter-spacing:.06em">BE RIGHT BACK</div>' +
    '<div style="font-size:4vmin;color:#ffd21a;letter-spacing:.2em">THE GAME CONTINUES SOON</div>' +
    '<div style="font-size:3vmin;color:#c9d4f2;margin-top:2vh">Comment your country while you wait!</div>';
  document.body.appendChild(el);
  setInterval(async () => {
    try {
      const r = await fetch(api, { cache: 'no-store' });
      const c = r.ok ? await r.json() : null;
      if (c && (c.autoplay !== false || c.rev !== rev)) location.reload();
    } catch (e) { /* try again later */ }
  }, 15000);
}

/* ------------------------------------------------------------ live results */
// The stream browser opens the game with ?stream=ID&sig=…; only then are scores sent to the website.
export function reporter(game) {
  const q = new URLSearchParams(location.search);
  const stream = q.get('stream'), sig = q.get('sig');
  if (!stream || !sig) return () => {};
  return (data) => {
    fetch('/api/results.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, keepalive: true,
      body: JSON.stringify({ stream: Number(stream), sig, game, ...data }),
    }).catch(() => {});
  };
}
