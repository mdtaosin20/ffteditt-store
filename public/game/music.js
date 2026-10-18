// FFTEdit · background music made in code while the game runs.
// Nothing is downloaded or copied: every note is generated here, so the music is our own and
// YouTube has nothing to match it against (no copyright claims). Each 8-bar part picks a new
// melody, so it does not sound like the same short loop forever.
//
//   const m = new Music(audioContext, outputNode, { style: 'upbeat' | 'chill' | 'epic', volume: 0..1 });
//   m.start();  m.stop();  m.setVolume(0.4);

const NOTE = (n) => 440 * Math.pow(2, (n - 69) / 12);   // MIDI number -> Hz

const STYLES = {
  // bright pop / EDM feel: Am F C G
  upbeat: { bpm: 118, chords: [[57, 60, 64], [53, 57, 60], [48, 52, 55], [55, 59, 62]], scale: [0, 2, 3, 5, 7, 8, 10], root: 57,
    kick: [0, 4, 8, 12], snare: [4, 12], hat: [2, 6, 10, 14], arp: true, pad: 0.10, lead: 'triangle', leadOct: 12 },
  // relaxed lo-fi: Dm7 G7 Cmaj7 Am7
  chill: { bpm: 86, chords: [[50, 53, 57, 60], [55, 59, 62, 65], [48, 52, 55, 59], [57, 60, 64, 67]], scale: [0, 2, 4, 5, 7, 9, 11], root: 48,
    kick: [0, 7, 10], snare: [4, 12], hat: [0, 2, 4, 6, 8, 10, 12, 14], arp: false, pad: 0.13, lead: 'sine', leadOct: 12 },
  // big and tense, for battles: Dm Bb F C
  epic: { bpm: 100, chords: [[50, 53, 57], [46, 50, 53], [53, 57, 60], [48, 52, 55]], scale: [0, 2, 3, 5, 7, 8, 10], root: 50,
    kick: [0, 3, 8, 11], snare: [4, 12], hat: [2, 6, 10, 14], arp: true, pad: 0.14, lead: 'sawtooth', leadOct: 12 },
};

export class Music {
  constructor(ctx, out, opt = {}) {
    this.ctx = ctx;
    this.style = STYLES[opt.style] || STYLES.upbeat;
    this.gain = ctx.createGain(); this.gain.gain.value = 0;
    this.vol = Math.max(0, Math.min(1, opt.volume ?? 0.4));
    const lp = ctx.createBiquadFilter(); lp.type = 'lowpass'; lp.frequency.value = 5200;
    this.gain.connect(lp); lp.connect(out);
    this.step = 0; this.bar = 0; this.next = 0; this.timer = null; this.melody = [];
  }

  start() {
    if (this.timer || !this.ctx) return;
    this.next = this.ctx.currentTime + 0.1;
    this.gain.gain.setTargetAtTime(this.vol * 0.5, this.ctx.currentTime, 1.5);   // fade in
    this.timer = setInterval(() => this.tick(), 60);
  }
  stop() { clearInterval(this.timer); this.timer = null; if (this.ctx) this.gain.gain.setTargetAtTime(0, this.ctx.currentTime, 0.4); }
  setVolume(v) { this.vol = Math.max(0, Math.min(1, v)); if (this.ctx) this.gain.gain.setTargetAtTime(this.vol * 0.5, this.ctx.currentTime, 0.3); }

  tick() {
    if (this.ctx.state !== 'running') { this.next = this.ctx.currentTime + 0.1; return; }
    const sixteenth = 60 / this.style.bpm / 4;
    while (this.next < this.ctx.currentTime + 0.25) {
      this.play(this.step % 16, this.next);
      this.next += sixteenth;
      this.step++;
      if (this.step % 16 === 0) { this.bar++; if (this.bar % 8 === 0) this.melody = []; }
    }
  }

  newMelody() { // a short random phrase from the scale, used for 8 bars
    const st = this.style, m = [];
    let deg = 2 + Math.floor(Math.random() * 3);
    for (let i = 0; i < 16; i++) {
      if (Math.random() < 0.35) { m.push(null); continue; }
      deg = Math.max(0, Math.min(9, deg + Math.floor(Math.random() * 5) - 2));
      const oct = Math.floor(deg / 7), s = st.scale[deg % 7];
      m.push(st.root + st.leadOct + oct * 12 + s);
    }
    this.melody = m;
  }

  play(s, t) {
    const st = this.style, chord = st.chords[Math.floor(this.bar / 1) % st.chords.length];
    const part = Math.floor(this.bar / 8) % 4;           // 0 calm, 1 build, 2 full, 3 full + lead
    if (!this.melody.length) this.newMelody();
    const beat = 60 / st.bpm;
    // pad: one long chord per bar
    if (s === 0) chord.forEach((n) => this.pad(NOTE(n), t, beat * 4, st.pad));
    // bass on every beat
    if (s % 4 === 0) this.bass(NOTE(chord[0] - 12), t, beat * 0.9);
    // drums (clean tones only — no noise)
    if (part > 0 && st.kick.includes(s)) this.kick(t);
    if (part > 1 && st.snare.includes(s)) this.snare(t);
    if (part > 0 && st.hat.includes(s)) this.hat(t);
    // arpeggio
    if (st.arp && part > 0 && s % 2 === 0) this.blip(NOTE(chord[(s / 2) % chord.length] + 12), t, beat / 2, 0.035);
    // melody
    if (part >= 2 && s % 2 === 0) { const n = this.melody[(s / 2) + (this.bar % 2) * 8]; if (n) this.lead(NOTE(n), t, beat * 0.45); }
  }

  osc(type, f, t, dur, vol, attack = 0.01, release = 0.08, filter = 0) {
    const c = this.ctx, o = c.createOscillator(), g = c.createGain();
    o.type = type; o.frequency.setValueAtTime(f, t);
    g.gain.setValueAtTime(0.0001, t); g.gain.exponentialRampToValueAtTime(vol, t + attack);
    g.gain.setValueAtTime(vol, t + Math.max(attack, dur - release)); g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
    if (filter) { const lp = c.createBiquadFilter(); lp.type = 'lowpass'; lp.frequency.value = filter; o.connect(lp); lp.connect(g); } else o.connect(g);
    g.connect(this.gain); o.start(t); o.stop(t + dur + 0.05);
    return o;
  }
  pad(f, t, dur, vol) { this.osc('sawtooth', f, t, dur, vol * 0.35, 0.35, 0.6, 900); const o = this.osc('sawtooth', f * 1.004, t, dur, vol * 0.35, 0.35, 0.6, 900); void o; }
  bass(f, t, dur) { this.osc('triangle', f, t, dur, 0.22, 0.01, 0.1, 600); }
  blip(f, t, dur, vol) { this.osc('square', f, t, dur, vol, 0.005, 0.06, 2400); }
  lead(f, t, dur) { this.osc(this.style.lead, f, t, dur, this.style.lead === 'sawtooth' ? 0.04 : 0.07, 0.02, 0.12, 3000); }
  kick(t) {
    const c = this.ctx, o = c.createOscillator(), g = c.createGain();
    o.frequency.setValueAtTime(130, t); o.frequency.exponentialRampToValueAtTime(42, t + 0.14);
    g.gain.setValueAtTime(0.0001, t); g.gain.exponentialRampToValueAtTime(0.55, t + 0.004); g.gain.exponentialRampToValueAtTime(0.0001, t + 0.28);
    o.connect(g); g.connect(this.gain); o.start(t); o.stop(t + 0.3);
  }
  snare(t) { this.osc('triangle', 210, t, 0.12, 0.12, 0.002, 0.08); this.osc('sine', 330, t, 0.08, 0.06, 0.002, 0.06); }
  hat(t) { this.osc('sine', 7400, t, 0.03, 0.012, 0.001, 0.02); }
}

/* ---------------------------------------------------------------- song playlist with crossfade */
// Plays song files one after another in a shuffled order. The next song fades in while the
// current one fades out (about 7 s), so there is never a gap and you can't hear where one ends.
export class SongPlayer {
  constructor(ctx, out, { songs, url, volume = 0.4, fade = 7 }) {
    this.ctx = ctx; this.songs = songs; this.url = url; this.fade = fade;
    this.vol = Math.max(0, Math.min(1, volume));
    this.out = ctx.createGain(); this.out.gain.value = this.vol; this.out.connect(out);
    this.decks = [0, 1].map(() => {
      const el = new Audio(); el.preload = 'auto'; el.crossOrigin = 'anonymous';
      const src = ctx.createMediaElementSource(el), g = ctx.createGain(); g.gain.value = 0;
      src.connect(g); g.connect(this.out);
      return { el, g };
    });
    this.cur = 0; this.order = []; this.last = '';
    this.timer = null;
  }
  nextSong() {
    if (!this.order.length) {
      this.order = this.songs.slice().sort(() => Math.random() - 0.5);
      if (this.order.length > 1 && this.order[0].f === this.last) this.order.push(this.order.shift());
    }
    const s = this.order.shift(); this.last = s.f; return s;
  }
  playOn(i, fadeIn) {
    const d = this.decks[i], s = this.nextSong(), t = this.ctx.currentTime;
    d.el.src = this.url(s.f); d.el.currentTime = 0;
    d.g.gain.cancelScheduledValues(t); d.g.gain.setValueAtTime(0.0001, t); d.g.gain.linearRampToValueAtTime(1, t + fadeIn);
    d.el.play().catch(() => {});
    d.started = performance.now();
  }
  start() {
    if (this.timer) return;
    this.playOn(this.cur, 2);
    this.timer = setInterval(() => this.tick(), 500);
  }
  tick() {
    if (this.ctx.state !== 'running') return;
    const d = this.decks[this.cur];
    const left = (d.el.duration || 0) - d.el.currentTime;
    const stuck = d.el.error || (d.el.paused && performance.now() - d.started > 8000);
    if ((d.el.duration && left < this.fade) || d.el.ended || stuck) {
      const t = this.ctx.currentTime;
      d.g.gain.cancelScheduledValues(t); d.g.gain.setValueAtTime(d.g.gain.value, t); d.g.gain.linearRampToValueAtTime(0.0001, t + this.fade);
      const old = d.el; setTimeout(() => old.pause(), this.fade * 1000 + 200);
      this.cur = 1 - this.cur;
      this.playOn(this.cur, stuck ? 1 : this.fade);
    } else if (d.el.paused && !d.el.ended) d.el.play().catch(() => {});
  }
  setVolume(v) { this.vol = Math.max(0, Math.min(1, v)); this.out.gain.setTargetAtTime(this.vol, this.ctx.currentTime, 0.3); }
}
