// Shared sound effects and commentator for the FFTEdit games.

/* ------------------------------------------------------------------- sounds */
// All sound effects are made in code (no files needed).
export class Sfx {
  // Only clean tones — no white noise at all (noise sounds like TV static on YouTube).
  constructor() {
    const AC = window.AudioContext || window.webkitAudioContext;
    this.ok = !!AC;
    if (!this.ok) return;
    this.ctx = new AC();
    this.master = this.ctx.createGain(); this.master.gain.value = 0.9;
    const comp = this.ctx.createDynamicsCompressor();
    this.master.connect(comp); comp.connect(this.ctx.destination);
    this.last = {};
  }
  get running() { return this.ok && this.ctx.state === 'running'; }
  resume() { if (this.ok && this.ctx.state !== 'running') this.ctx.resume(); }
  limit(name, ms) { const now = performance.now(); if (now - (this.last[name] || 0) < ms) return false; this.last[name] = now; return true; }
  // kept for older callers: a short soft "tock" instead of a noise burst
  burst({ freq = 1000, dur = 0.1, vol = 0.5, delay = 0 }) {
    this.tone({ freq: Math.min(1400, Math.max(180, freq / 2)), type: 'sine', dur: Math.min(dur, 0.12), vol: vol * 0.3, delay });
  }
  tone({ freq = 440, to = 0, type = 'sine', dur = 0.2, vol = 0.3, delay = 0, attack = 0.005 }) {
    const t = this.ctx.currentTime + delay;
    const o = this.ctx.createOscillator(); o.type = type; o.frequency.setValueAtTime(freq, t);
    if (to) o.frequency.exponentialRampToValueAtTime(to, t + dur);
    const g = this.ctx.createGain(); g.gain.setValueAtTime(0.0001, t); g.gain.exponentialRampToValueAtTime(vol, t + attack); g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
    o.connect(g); g.connect(this.master); o.start(t); o.stop(t + dur + 0.05);
  }
  release() { if (!this.running) return; this.tone({ freq: 880, to: 660, dur: 0.08, vol: 0.12 }); }
  land(s = 1) { if (!this.running || !this.limit('land', 120)) return; this.tone({ freq: 150, to: 60, dur: 0.18, vol: 0.4 * s }); }
  rim(s = 1) { if (!this.running || !this.limit('rim', 150)) return; this.tone({ freq: 520, to: 420, dur: 0.1, vol: 0.12 * s, type: 'triangle' }); }
  clack(s = 1) { if (!this.running || !this.limit('clack', 120)) return; this.tone({ freq: 1180, dur: 0.06, vol: 0.18 * s }); this.tone({ freq: 1770, dur: 0.04, vol: 0.08 * s }); }
  fall() { if (!this.running) return; this.tone({ freq: 700, to: 140, dur: 0.55, vol: 0.2 }); }
  goal() {
    if (!this.running) return;
    // referee whistle + happy horn chord
    this.tone({ freq: 2600, dur: 0.16, vol: 0.08 }); this.tone({ freq: 2600, dur: 0.3, vol: 0.08, delay: 0.2 });
    [523, 659, 784].forEach((f, i) => this.tone({ freq: f, type: 'triangle', dur: 0.7, vol: 0.14, delay: 0.1 + i * 0.07, attack: 0.02 }));
    this.tone({ freq: 1047, type: 'triangle', dur: 0.9, vol: 0.12, delay: 0.35, attack: 0.02 });
  }
  win() {
    if (!this.running) return;
    [523, 659, 784, 1047].forEach((f, i) => this.tone({ freq: f, type: 'triangle', dur: 0.45, vol: 0.22, delay: i * 0.16 }));
    [523, 659, 784, 1047].forEach((f) => this.tone({ freq: f, type: 'triangle', dur: 1.8, vol: 0.13, delay: 0.7, attack: 0.03 }));
    [1319, 1568].forEach((f, i) => this.tone({ freq: f, dur: 0.25, vol: 0.06, delay: 1.0 + i * 0.25 }));
  }
  // Rolling: a soft, clean low rumble (no noise). Louder and a little higher while the balls roll faster;
  // a slow wobble makes it sound like a ball turning over on the bowl.
  setRoll(speed) {
    if (!this.ok) return;
    if (!this.roll) {
      const out = this.ctx.createGain(); out.gain.value = 0;
      const lp = this.ctx.createBiquadFilter(); lp.type = 'lowpass'; lp.frequency.value = 420; lp.Q.value = 0.5;
      const o1 = this.ctx.createOscillator(); o1.type = 'sine'; o1.frequency.value = 85;
      const o2 = this.ctx.createOscillator(); o2.type = 'triangle'; o2.frequency.value = 171;
      const g2 = this.ctx.createGain(); g2.gain.value = 0.35;
      const wob = this.ctx.createGain(); wob.gain.value = 0.7;          // wobble around 0.7
      const lfo = this.ctx.createOscillator(); lfo.frequency.value = 6;
      const lfoAmt = this.ctx.createGain(); lfoAmt.gain.value = 0.3;
      lfo.connect(lfoAmt); lfoAmt.connect(wob.gain);
      o1.connect(wob); o2.connect(g2); g2.connect(wob); wob.connect(lp); lp.connect(out); out.connect(this.master);
      [o1, o2, lfo].forEach((o) => o.start());
      this.roll = { out, o1, o2, lfo };
    }
    const t = this.ctx.currentTime, r = this.roll;
    const k = Math.min(1, Math.max(0, (speed - 0.2) / 7));               // 0 = still, 1 = fast
    r.out.gain.setTargetAtTime(k > 0 ? 0.04 + 0.16 * k : 0, t, 0.12);
    r.o1.frequency.setTargetAtTime(75 + 55 * k, t, 0.15);
    r.o2.frequency.setTargetAtTime(150 + 110 * k, t, 0.15);
    r.lfo.frequency.setTargetAtTime(4 + 14 * k, t, 0.15);
  }
  duck(on) { if (this.ok) this.master.gain.setTargetAtTime(on ? 0.6 : 0.9, this.ctx.currentTime, 0.3); } // effects step back while the commentator talks
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

/* ------------------------------------------------------------ fresh start */
// The panel adds &run=<number> to the address; the number changes every time the admin presses
// Start (or Restart). A new number = the game begins from zero. A crash restart keeps the number,
// so the game simply continues.
export function freshRun(game, keys) {
  const run = new URLSearchParams(location.search).get('run');
  if (!run || run === '0') return false;
  try {
    if (localStorage.getItem('fft_run_' + game) === run) return false;
    keys.forEach((k) => localStorage.removeItem(k));
    localStorage.setItem('fft_run_' + game, run);
    return true;
  } catch (e) { return false; }
}
