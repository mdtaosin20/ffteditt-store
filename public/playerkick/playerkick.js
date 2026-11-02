// FFTEdit · Player Kick (a copy of Cup Kick: football players with photos and names instead of flags) — a 3D scene for making Shorts (not for the 24/7 lives).
// Six countries line up; each player kicks one ball toward the cup, one after another; exactly ONE ball
// touches the cup, in a crowd of balls, shown a little slower; the viewers say in the comments whose ball it was.
// The owner picks the countries in the menu, presses START and records the screen. Tap the screen = menu again.
import * as THREE from 'three';
import { RoomEnvironment } from '/game/vendor/RoomEnvironment.js';
import { setupRecorder } from '/game/recorder.js?v=4';
import { setupBgMusic } from '/game/bgmusic.js?v=1';
import { setupMyVoice, voiceRun } from '/game/myvoice-game.js?v=2';
import { genStadium, genBall, newSeed, BALL_GROUPS } from '/game/variety.js?v=1';
import { makeGrass } from '/game/grass.js?v=1';
import { PLAYERS, DEFAULT_SIX } from '/playerkick/players.js?v=2';

const $ = (id) => document.getElementById(id);
const DT = 1 / 120;
const CLOSE = new URLSearchParams(location.search).has('closeup');
let R = 0.45;   // ball size (menu → CONTROL → Ball size): 0.38 = small, 0.45 = a little bigger, 0.52 = big
const CUP = { x: 0, z: -1.5, r: 0.32, s: 1 }, PZ = -19;   // the trophy: where it stands and how big (s) — menu → 🏆 Trophy
let PS = 1;   // player size (menu → CONTROL): 1 = normal, 1.4 = bigger, 1.7 = biggest; bigger players stand further apart
let NP = 6;   // how many players (menu: 2 … 6)
let GAP = 0.2;   // space between two players (menu → CONTROL → 📷 Camera & field)
const CAM = { fov: 50, z: 0, y: 0 };   // camera: wider view, forward / back, up / down (same menu)
const PX = (i) => (i - (NP - 1) / 2) * (1.58 * PS + GAP);   // a player is 1.58 wide (with arms): keep a small gap so they never touch
const WALL = () => Math.max(6.2, PX(NP - 1) + 0.9);   // the side lines the balls bounce off
const UP = 1.0, DOWN = 1.1;              // 🔁 loop: seconds the camera looks up at the sky at the end / comes down at the start
const SLOW = 1;                         // speed of the moment the cup is touched (1 = no slow motion)
const KITS = {   // known shirts; other countries get colours from their flag
  fr: ['#1e3a8a', '#ffffff', '#ffffff', '#b91c1c'], br: ['#facc15', '#16a34a', '#1d4ed8', '#ffffff'], tr: ['#e30a17', '#ffffff', '#ffffff', '#e30a17'],
  ar: ['stripes', '#ffffff', '#111827', '#ffffff'], pl: ['#ffffff', '#dc2626', '#dc2626', '#ffffff'], ma: ['#c1121f', '#006233', '#006233', '#c1121f'],
  pt: ['#b91c1c', '#15803d', '#15803d', '#b91c1c'], es: ['#c1121f', '#facc15', '#1e3a8a', '#1e3a8a'], de: ['#ffffff', '#111111', '#111111', '#ffffff'],
  us: ['#ffffff', '#1e3a8a', '#1e3a8a', '#ffffff'], gb: ['#ffffff', '#1e3a8a', '#1e3a8a', '#ffffff'], in: ['#2563eb', '#f97316', '#2563eb', '#2563eb'],
  pk: ['#15803d', '#ffffff', '#15803d', '#15803d'], bd: ['#15803d', '#dc2626', '#15803d', '#dc2626'], dz: ['#ffffff', '#15803d', '#15803d', '#ffffff'],
  eg: ['#dc2626', '#111111', '#ffffff', '#111111'], sa: ['#ffffff', '#15803d', '#15803d', '#ffffff'], id: ['#dc2626', '#ffffff', '#ffffff', '#dc2626'],
  it: ['#1d4ed8', '#ffffff', '#ffffff', '#1d4ed8'], nl: ['#f97316', '#ffffff', '#f97316', '#f97316'], jp: ['#1e3a8a', '#ffffff', '#1e3a8a', '#1e3a8a'],
  mx: ['#15803d', '#ffffff', '#ffffff', '#dc2626'], ng: ['#16a34a', '#ffffff', '#16a34a', '#16a34a'], kr: ['#dc2626', '#111111', '#111111', '#dc2626'],
};

const rng = (s) => () => { s |= 0; s = s + 0x6D2B79F5 | 0; let t = Math.imul(s ^ s >>> 15, 1 | s); t = t + Math.imul(t ^ t >>> 7, 61 | t) ^ t; return ((t ^ t >>> 14) >>> 0) / 4294967296; };

/* ---------------------------------------------------------------- the play: all balls are kicked within a few seconds and roll on the grass,
   curling a little and knocking each other around; the first ball that touches the cup knocks it over, later balls bounce off the fallen cup */
const G = 9.8, CUP_H = 1.35;   // the cup is this high
const PLAY = { speed: 1, within: 2, mess: 1 };   // ball speed (1 = normal), all kicks within N seconds, how messy (0 = calm, 1 = normal, 2 = wild)
function simulate(seed, n, o = PLAY) {
  const rnd = rng(seed), balls = [], kicks = [], mess = o.mess, W = WALL();
  const order = []; for (let i = 0; i < n; i++) order.push(i);   // one ball for each player
  for (let a = order.length - 1; a > 0; a--) { const c = Math.floor(rnd() * (a + 1)); [order[a], order[c]] = [order[c], order[a]]; }
  let t = 0.8;
  const aimX = 2.4 - Math.min(mess, 2.6) * 0.5, aimZ = 1.1 - Math.min(mess, 2.6) * 0.2;   // messier = the balls are aimed closer together, so they crash more
  for (const i of order) {
    const sx = PX(i) + 0.25 * PS, sz = PZ + 0.3 * PS + R + 0.06;   // in front of his right (kicking) foot
    // messier = more balls are kicked across to the other side, so their paths cross and they swap places on the way
    const across = mess > 0 && rnd() < 0.35 + mess * 0.25;
    let tx = CUP.x + (across ? -Math.sign(sx || 1) * (0.3 + rnd() * aimX) : (rnd() * 2 - 1) * aimX); const tz = CUP.z + (rnd() * 2 - 1) * aimZ;
    const d = Math.hypot(tx - sx, tz - sz), sp = (6.6 + rnd() * 2.0) * o.speed;
    const curl = (rnd() * 2 - 1) * 0.9 * Math.min(mess, 2.5) * Math.min(1, o.speed);   // the ball bends left or right a little while it rolls
    balls.push({ team: i, t, x: sx, y: R, z: sz, vx: (tx - sx) / d * sp, vy: 0, vz: (tz - sz) / d * sp, curl, on: false, id: balls.length });
    kicks.push({ team: i, t });
    t += o.within / (n - 1) * (0.4 + rnd() * 0.9);
  }
  const steps = Math.ceil((t + 3 + 22 / (7.6 * o.speed)) / DT), track = balls.map(() => new Float32Array(steps * 3));
  const touches = [], near = [], bumps = [], lands = [];
  let simT = 0, cupUp = true, fall = null;
  const lying = { ax: 0, az: 0, bx: 0, bz: 0, r: 0.3 * CUP.s };   // the fallen cup, as a fat stick on the grass
  for (let s = 0; s < steps; s++, simT += DT) {
    if (cupUp && o.maxT && simT > o.maxT) return null;   // (searching) nobody touched the cup in time: try the next play
    if (!cupUp && o.want >= 0 && balls[touches[0].id].team !== o.want) return null;   // (searching) the wrong country touched it
    for (const b of balls) {
      if (!b.on && simT >= b.t) b.on = true;
      if (!b.on) continue;
      const v = Math.hypot(b.vx, b.vz);
      if (v > 0.5) { const c = b.curl * DT / v; b.vx += -b.vz * c; b.vz += b.vx * c; b.curl *= Math.exp(-0.5 * DT); }
      b.x += b.vx * DT; b.z += b.vz * DT;
      b.y = R; b.vy = 0;   // always on the grass, never goes up
      const f = Math.exp(-0.22 * DT); b.vx *= f; b.vz *= f;
      if (b.x < -W) { b.x = -W; b.vx = Math.abs(b.vx) * 0.6; } if (b.x > W) { b.x = W; b.vx = -Math.abs(b.vx) * 0.6; }
      // the cup: standing (round post), falling (still a post for a moment), or lying on the grass
      let px = CUP.x, pz = CUP.z, pr = CUP.r;
      if (fall && simT > fall.t + 0.35) {
        const L = lying, ex = L.bx - L.ax, ez = L.bz - L.az;
        const u = Math.max(0, Math.min(1, ((b.x - L.ax) * ex + (b.z - L.az) * ez) / (ex * ex + ez * ez)));
        px = L.ax + ex * u; pz = L.az + ez * u; pr = L.r;
      }
      const dx = b.x - px, dz = b.z - pz, dd = Math.hypot(dx, dz) || 1e-6;
      if (dd < R + pr) {
        const nx = dx / dd, nz = dz / dd, vn = b.vx * nx + b.vz * nz;
        if (vn < 0) {
          if (cupUp) {
            // the first touch knocks the cup over (it falls the way the ball was going)
            const sp = Math.hypot(b.vx, b.vz) || 1;
            fall = { t: simT, dx: b.vx / sp, dz: b.vz / sp }; cupUp = false;
            lying.ax = CUP.x + fall.dx * 0.3 * CUP.s; lying.az = CUP.z + fall.dz * 0.3 * CUP.s;
            lying.bx = CUP.x + fall.dx * (0.3 + CUP_H - 0.15) * CUP.s; lying.bz = CUP.z + fall.dz * (0.3 + CUP_H - 0.15) * CUP.s;
            touches.push({ id: b.id, t: simT });
          } else bumps.push(simT);
          // bounce off it (a hard bounce, a bit to the side)
          b.vx -= 1.75 * vn * nx; b.vz -= 1.75 * vn * nz;
          const kick = (rnd() * 2 - 1) * 0.35 * Math.abs(vn) * (0.5 + mess * 0.5); b.vx += -nz * kick; b.vz += nx * kick;
        }
        b.x = px + nx * (R + pr); b.z = pz + nz * (R + pr);
      } else if (cupUp && dd < R + CUP.r + 0.6) near.push({ id: b.id, t: simT });
    }
    for (let a = 0; a < balls.length; a++) for (let c = a + 1; c < balls.length; c++) {
      const A = balls[a], B = balls[c]; if (!A.on || !B.on) continue;
      const dx = B.x - A.x, dz = B.z - A.z, dd = Math.hypot(dx, dz);
      if (dd > 0 && dd < 2 * R) {
        const nx = dx / dd, nz = dz / dd, rel = (A.vx - B.vx) * nx + (A.vz - B.vz) * nz;
        if (rel > 0) {
          const j = rel * 0.95; A.vx -= j * nx; A.vz -= j * nz; B.vx += j * nx; B.vz += j * nz;
          // the balls fly apart a little to the side too, so a crash sends them here and there
          const side = (rnd() * 2 - 1) * rel * 0.3 * mess; A.vx -= -nz * side; A.vz -= nx * side; B.vx += -nz * side; B.vz += nx * side;
          if (rel > 1.5) bumps.push(simT);
        }
        const o2 = (2 * R - dd) / 2; A.x -= nx * o2; A.z -= nz * o2; B.x += nx * o2; B.z += nz * o2;
      }
    }
    balls.forEach((b, k) => { track[k][s * 3] = b.x; track[k][s * 3 + 1] = b.y; track[k][s * 3 + 2] = b.z; });
  }
  return { balls, kicks, track, touches, near, bumps, lands, steps, fall };
}
// exactly ONE ball knocks the cup over, a little after the kicks, with other balls close by at that moment
function findPlay(n, want, o = PLAY) {
  const start = (Math.random() * 1e9) | 0, travel = Math.abs(CUP.z - PZ) / (7.6 * o.speed);
  const minT = 0.8 + travel + Math.min(1.5, o.within * 0.5), maxT = 0.8 + o.within + travel * 1.8 + 1;
  // looks for a good play; if it takes long (rare settings), it asks for a little less mixing, so START never hangs
  const t0 = Date.now(); let easy = 0;
  for (let s = start; s < start + 60000; s++) {
    if ((s & 63) === 0) { const ms = Date.now() - t0, k = o.mess > 3 ? 3 : 1; easy = ms > 3000 * k ? 2 : ms > 1500 * k ? 1 : 0; if (ms > 9000 + 3000 * (k - 1)) break; }   // Extreme may look a little longer
    const p = simulate(s, n, { ...o, maxT, want });
    if (!p || p.touches.length !== 1) continue;
    const T = p.touches[0].t;
    if (T < minT || T > maxT) continue;
    const w = p.balls[p.touches[0].id];
    if (want >= 0 && w.team !== want) continue;
    const close = new Set(p.near.filter((x) => x.id !== w.id && Math.abs(x.t - T) < 1.0).map((x) => x.id));
    if (close.size < 1 && easy < 2) continue;   // at least one other ball close by, so it is not easy
    // the balls must get mixed up on the way: count how often two balls swap left/right (while near each other)
    if (o.mess > 0 && easy < 1) {
      let swaps = 0, mine = 0; const every = Math.round(0.05 / DT), last = Math.floor(T / DT);
      for (let a = 0; a < p.balls.length; a++) for (let c = a + 1; c < p.balls.length; c++) {
        let side = 0;
        for (let st = 0; st <= last; st += every) {
          const A = p.track[a], C = p.track[c]; if (st * DT < p.balls[a].t || st * DT < p.balls[c].t) continue;
          if (Math.abs(A[st * 3 + 2] - C[st * 3 + 2]) > 4) continue;
          const sd = Math.sign(A[st * 3] - C[st * 3]); if (side && sd && sd !== side) { swaps++; if (a === w.id || c === w.id) mine++; } if (sd) side = sd;
        }
      }
      if (swaps < Math.min(o.mess + 1, p.balls.length - 1) || (o.mess > 1 && mine < 1) || (o.mess > 2 && mine < 2)) continue;
    }
    // Very hard: a crowd of balls at the cup when it is hit, and a moment before, another ball looks like the one that will hit it
    if (o.mess > 2 && easy < 2) {
      const at = (k, tt) => { const st = Math.max(0, Math.floor(tt / DT)); return Math.hypot(p.track[k][st * 3] - CUP.x, p.track[k][st * 3 + 2] - CUP.z); };
      let crowd = 0; for (let k = 0; k < p.balls.length; k++) if (k !== w.id && p.balls[k].t < T && at(k, T) < 2.0) crowd++;
      if (crowd < Math.min(2, p.balls.length - 2)) continue;   // (with few players there are fewer balls to crowd the cup)
      let decoy = false; for (let k = 0; k < p.balls.length; k++) if (k !== w.id && p.balls[k].t < T - 0.7 && at(k, T - 0.7) < at(w.id, T - 0.7)) decoy = true;
      if (!decoy) continue;
    }
    // Extreme: two other balls are right at the cup when it is hit, two others look closer just before the hit,
    // another ball arrives at almost the same moment, the winner swaps places with others and was not kicked first
    if (o.mess > 3 && easy < 1 && p.balls.length >= 4) {
      const at = (k, tt) => { const st = Math.max(0, Math.floor(tt / DT)); return Math.hypot(p.track[k][st * 3] - CUP.x, p.track[k][st * 3 + 2] - CUP.z); };
      let crowd = 0; for (let k = 0; k < p.balls.length; k++) if (k !== w.id && p.balls[k].t < T && at(k, T) < 1.7) crowd++;
      if (crowd < 2) continue;
      let closer = 0; for (let k = 0; k < p.balls.length; k++) if (k !== w.id && p.balls[k].t < T - 0.4 && at(k, T - 0.4) < at(w.id, T - 0.4)) closer++;
      if (closer < 2) continue;
      if (new Set(p.near.filter((x) => x.id !== w.id && Math.abs(x.t - T) < 0.45).map((x) => x.id)).size < 1) continue;
      if (Math.min(...p.balls.map((b) => b.t)) === w.t) continue;
      let mine = 0; const every = Math.round(0.05 / DT), last = Math.floor(T / DT);
      for (let c = 0; c < p.balls.length; c++) {
        if (c === w.id) continue; let side = 0;
        for (let st = 0; st <= last; st += every) {
          const A = p.track[w.id], C = p.track[c]; if (st * DT < w.t || st * DT < p.balls[c].t) continue;
          if (Math.abs(A[st * 3 + 2] - C[st * 3 + 2]) > 4) continue;
          const sd = Math.sign(A[st * 3] - C[st * 3]); if (side && sd && sd !== side) mine++; if (sd) side = sd;
        }
      }
      if (mine < 2) continue;
    }
    return { ...p, touchT: T, winner: w.team };
  }
  return null;
}

/* ---------------------------------------------------------------- sound (made in the browser) */
class Sound {
  constructor() { this.ctx = null; this.on = true; this.kv = 1; }   // kv = kick sound loudness (0 … 3)
  start() {
    if (!this.on) return;
    if (!this.ctx) {
      this.ctx = new (window.AudioContext || window.webkitAudioContext)();
      // a soft limiter at the end, so a very loud kick setting does not crackle
      this.out = this.ctx.createDynamicsCompressor(); this.out.threshold.value = -6; this.out.knee.value = 6; this.out.ratio.value = 12; this.out.attack.value = 0.002; this.out.release.value = 0.12;
      this.out.connect(this.ctx.destination);
    }
    this.ctx.resume();   // (no crowd noise in the background any more: only kicks, bumps and the cup sounds)
  }
  swell(v, secs) { if (!this.crowd) return; const g = this.crowd.gain, t = this.ctx.currentTime; g.cancelScheduledValues(t); g.setValueAtTime(g.value, t); g.linearRampToValueAtTime(v, t + 0.15); g.linearRampToValueAtTime(0.08, t + secs); }
  tone(f, f2, dur, vol, type = 'sine') {
    if (!this.ctx || !this.on) return;
    const c = this.ctx, o = c.createOscillator(), g = c.createGain(), t = this.at ?? c.currentTime;   // at: a set time (used when the recorder makes the sounds again)
    o.type = type; o.frequency.setValueAtTime(f, t); if (f2) o.frequency.exponentialRampToValueAtTime(f2, t + dur);
    g.gain.setValueAtTime(vol, t); g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
    o.connect(g).connect(this.out || c.destination); o.start(t); o.stop(t + dur + 0.02);
  }
  // 🎵 the owner's background sound: the part from…to repeats under the game until bgStop (fades in / out)
  bg(buf, from, to, vol, fade) {
    if (!this.ctx || !this.on || !buf) return;
    this.bgStop(0);
    const c = this.ctx, t = this.at ?? c.currentTime, s = c.createBufferSource(), g = c.createGain();
    s.buffer = buf; s.loop = true; s.loopStart = from; s.loopEnd = to;
    g.gain.setValueAtTime(fade ? 0 : vol, t); if (fade) g.gain.linearRampToValueAtTime(vol, t + 0.6);
    s.connect(g).connect(this.out || c.destination); s.start(t, from);
    this.bgN = { s, g, vol };
  }
  bgStop(secs = 0.8) {
    const b = this.bgN; if (!b || !this.ctx) return; this.bgN = null;
    const t = this.at ?? this.ctx.currentTime, f = Math.max(0.03, secs), gn = b.g.gain;
    try {
      if (gn.cancelAndHoldAtTime) gn.cancelAndHoldAtTime(t); else { gn.cancelScheduledValues(t); gn.setValueAtTime(b.vol, t); }
      gn.linearRampToValueAtTime(0, t + f); b.s.stop(t + f + 0.02);
    } catch (e) { /* already stopped */ }
  }
  clip(buf) { if (!this.ctx || !this.on || !buf) return; const c = this.ctx, s = c.createBufferSource(); s.buffer = buf; s.connect(this.out || c.destination); s.start(this.at ?? c.currentTime); }   // 🗣 the owner's voice line
  kick() { if (this.kv <= 0) return; this.tone(140, 50, 0.18, 0.6 * this.kv); this.tone(900, 300, 0.05, 0.08 * this.kv, 'triangle'); }
  clank() { this.tone(520, 300, 0.25, 0.25, 'triangle'); this.tone(1650, 900, 0.3, 0.1, 'square'); this.tone(80, 40, 0.2, 0.4); }
  land(v) { if (this.kv > 0) this.tone(90, 40, 0.14, Math.min(0.5, v * 0.07) * this.kv); }
  bump() { if (this.kv > 0) this.tone(320, 200, 0.07, 0.12 * this.kv, 'triangle'); }
  ding() { this.tone(1320, 0, 1.4, 0.25); this.tone(1980, 0, 1.0, 0.12); this.tone(2640, 0, 0.7, 0.06); this.swell(0.32, 2.5); }
  // everybody shouts together "HEYYY!": many voices (buzzing tones through mouth-like filters)
  win() {
    if (!this.ctx || !this.on) return;
    const c = this.ctx, t0 = this.at ?? c.currentTime, mix = c.createGain(); mix.gain.value = 1;
    const out = c.createGain(); out.gain.value = 0.9; out.connect(this.out || c.destination);
    for (const [f, q, v] of [[650, 4, 1], [1750, 7, 0.55], [2600, 9, 0.3]]) {   // the "eh / ay" sound of a mouth
      const bp = c.createBiquadFilter(); bp.type = 'bandpass'; bp.frequency.value = f; bp.Q.value = q;
      const g = c.createGain(); g.gain.value = v; mix.connect(bp).connect(g).connect(out);
    }
    for (let k = 0; k < 14; k++) {   // 14 people (men and women), each a little different
      const t = t0 + Math.random() * 0.08, f0 = k % 3 === 0 ? 260 + Math.random() * 90 : 130 + Math.random() * 70, len = 0.75 + Math.random() * 0.35;
      const o = c.createOscillator(), g = c.createGain(), vib = c.createOscillator(), vg = c.createGain();
      o.type = 'sawtooth'; o.frequency.setValueAtTime(f0 * 0.85, t); o.frequency.linearRampToValueAtTime(f0 * 1.15, t + 0.12); o.frequency.linearRampToValueAtTime(f0 * 0.95, t + len);
      vib.frequency.value = 5 + Math.random() * 2; vg.gain.value = f0 * 0.025; vib.connect(vg).connect(o.frequency);
      g.gain.setValueAtTime(0.0001, t); g.gain.exponentialRampToValueAtTime(0.06, t + 0.05); g.gain.setValueAtTime(0.06, t + len * 0.6); g.gain.exponentialRampToValueAtTime(0.0001, t + len);
      o.connect(g).connect(mix); o.start(t); o.stop(t + len + 0.05); vib.start(t); vib.stop(t + len + 0.05);
    }
    // the breathy "H" at the start
    const len = c.sampleRate * 0.25, buf = c.createBuffer(1, len, c.sampleRate), d = buf.getChannelData(0);
    for (let i = 0; i < len; i++) d[i] = (Math.random() * 2 - 1) * (1 - i / len);
    const n = c.createBufferSource(), hp = c.createBiquadFilter(), ng = c.createGain(); n.buffer = buf; hp.type = 'bandpass'; hp.frequency.value = 1800; hp.Q.value = 0.8; ng.gain.value = 0.35;
    n.connect(hp).connect(ng).connect(out); n.start(t0);
  }
}

/* ---------------------------------------------------------------- scene */
async function main() {
  const stage = $('stage'), canvas = $('c');
  const renderer = new THREE.WebGLRenderer({ canvas, antialias: true });
  renderer.shadowMap.enabled = true; renderer.shadowMap.type = THREE.PCFSoftShadowMap;
  renderer.toneMapping = THREE.ACESFilmicToneMapping; renderer.outputColorSpace = THREE.SRGBColorSpace; renderer.toneMappingExposure = 0.9;
  const scene = new THREE.Scene();
  scene.background = new THREE.Color('#0b1024'); scene.fog = new THREE.Fog('#0b1024', 30, 60);
  scene.environment = new THREE.PMREMGenerator(renderer).fromScene(new RoomEnvironment(), 0.04).texture;
  const camera = new THREE.PerspectiveCamera(50, 9 / 16, 0.1, 400);
  let sharp = false;   // menu → CONTROL → Picture quality: Sharp = the phone's full resolution (for recording in high quality)
  const fit = () => { const w = stage.clientWidth, h = stage.clientHeight; renderer.setPixelRatio(sharp ? Math.min(4, devicePixelRatio || 1) : Math.min(2, devicePixelRatio || 1)); renderer.setSize(w, h, false); };
  fit(); addEventListener('resize', fit);

  const hemi = new THREE.HemisphereLight('#dfe8ff', '#2c5a2c', 0.7); scene.add(hemi);
  const sun = new THREE.DirectionalLight('#fff4e0', 2.0);
  sun.position.set(6, 14, 8); sun.castShadow = true; sun.shadow.mapSize.set(2048, 2048);
  sun.shadow.bias = -0.0008; sun.shadow.normalBias = 0.05;   // no flickering stripes of shadow on the players' arms and bodies
  Object.assign(sun.shadow.camera, { left: -14, right: 14, top: 14, bottom: -14, near: 1, far: 50 }); sun.target.position.set(0, 0, -8); scene.add(sun, sun.target);
  const tex = (w, h, draw) => { const c = document.createElement('canvas'); c.width = w; c.height = h; draw(c.getContext('2d'), w, h); const t = new THREE.CanvasTexture(c); t.colorSpace = THREE.SRGBColorSpace; t.anisotropy = 8; return t; };
  const R2 = rng(7);

  // the ground of each place: stripes in two colours, painted lines, and some speckles (sand, snow, concrete)
  let WM = '';   // the channel name (menu → CONTROL → Channel name): on the grass, on the LED board and floating; empty = none
  const groundTex = (a, b, line, dots, wmc) => tex(1024, 1024, (g, w, h) => {
    for (let i = 0; i < 16; i++) { g.fillStyle = i % 2 ? a : b; g.fillRect(0, i * h / 16, w, h / 16); }
    if (dots) { const r = rng(3); for (let i = 0; i < 9000; i++) { g.fillStyle = dots[i % dots.length]; g.fillRect(r() * w, r() * h, 2 + r() * 3, 2 + r() * 3); } }
    g.strokeStyle = line; g.lineWidth = 8; g.beginPath(); g.moveTo(0, 300); g.lineTo(w, 300); g.stroke();
    g.beginPath(); g.ellipse(512, 700, 300, 300, 0, 0, 7); g.stroke();
    if (WM) {   // the channel name painted big on the grass, where the balls roll over it
      g.save(); g.translate(512, 520); g.scale(1, 2.2); g.font = 'bold 64px Arial'; const fit = Math.min(1, 210 / g.measureText(WM).width); g.font = `bold ${Math.round(64 * fit)}px Arial`; g.textAlign = 'center'; g.textBaseline = 'middle';
      g.fillStyle = wmc || 'rgba(255,255,255,.28)'; g.fillText(WM, 0, 0); g.restore();
    }
  });
  const fieldTex = groundTex('#2f8f34', '#287d2d', 'rgba(255,255,255,.9)');
  const field = new THREE.Mesh(new THREE.PlaneGeometry(40, 46), new THREE.MeshStandardMaterial({ map: fieldTex, roughness: 1 }));
  field.rotation.x = -Math.PI / 2; field.position.z = -8; field.receiveShadow = true; scene.add(field);
// 🌱 3D grass blades on the field — only when "3D grass" is on in the menu (made the first time it is turned on).
  // The white lines stay free: the half-way line and the big circle.
  let grass = null, grassOn = false, lastTh = null;
  const paintGrass = () => {
    if (grassOn && !grass) grass = makeGrass({ scene, count: 55000, field: { z0: -31, depth: 46, stripes: 16 }, lines: (x, z) => {
      if (z > -17.75 && z < -17.0) return true;   // (a little more space on the camera side, so the blades do not hide the line)
      const d = Math.hypot(x / 11.72, (z - 0.45) / 13.48); return Math.abs(d - 1) < 0.03;
    } });
    if (grass && lastTh) grass.paint(lastTh.ground[0], lastTh.ground[1], grassOn && !['beach', 'street', 'snow'].includes(lastTh.extra));   // no blades on sand, snow or the street
  };
  const standMat = new THREE.MeshStandardMaterial({ color: '#3a4050', roughness: 0.9 });
  const crowdCols = ['#f5c518', '#2563eb', '#e11d48', '#f8fafc', '#16a34a', '#f97316'];
  const stadium = new THREE.Group(); scene.add(stadium);   // stands, crowd, roof and lights (hidden on the beach and in the street)
  const crowd = new THREE.InstancedMesh(new THREE.BoxGeometry(0.34, 0.5, 0.3), new THREE.MeshStandardMaterial({ roughness: 0.8 }), 3200);
  let ci = 0; const m4 = new THREE.Matrix4(), col = new THREE.Color(), crowdPos = [], crowdSlot = [];
  for (let r = 0; r < 16; r++) {
    const z = -22 - r * 1.1, y = 0.6 + r * 0.9;
    const step = new THREE.Mesh(new THREE.BoxGeometry(40, 0.9, 1.1), standMat); step.position.set(0, y - 0.45, z); step.receiveShadow = true; stadium.add(step);
    for (let k = 0; k < 96 && ci < 3200; k++) {
      if (R2() < 0.12) continue;
      const x = -19 + k * 0.42 + (r % 2) * 0.2; crowdPos.push([x, y + 0.25, z, R2() * 6]);
      m4.makeTranslation(x, y + 0.25, z); crowd.setMatrixAt(ci, m4); { const sl = (k * 7 + r * 3 + (R2() * 3 | 0)) % 6; crowdSlot.push(sl); crowd.setColorAt(ci, col.set(crowdCols[sl])); } ci++;
    }
  }
  crowd.count = ci; stadium.add(crowd);
  const roof = new THREE.Mesh(new THREE.BoxGeometry(44, 0.8, 14), new THREE.MeshStandardMaterial({ color: '#141a2e' })); roof.position.set(0, 16.2, -32); stadium.add(roof);
  const lamps = new THREE.Group(); stadium.add(lamps);
  for (let k = 0; k < 9; k++) { const l = new THREE.Mesh(new THREE.BoxGeometry(2.2, 0.5, 0.3), new THREE.MeshBasicMaterial({ color: '#fffbe6' })); l.position.set(-16 + k * 4, 15.6, -25.2); lamps.add(l); }
  const makeLed = () => { const t = tex(2048, 128, (g, w, h) => { g.fillStyle = '#0b1230'; g.fillRect(0, 0, w, h); g.fillStyle = '#ffd23a'; g.font = 'bold 74px Arial'; g.textBaseline = 'middle'; const lt = WM ? '★ ' + WM + ' ★' : '★ STAR CUP ★', step = g.measureText(lt).width + 90; for (let x = 30; x < w; x += step) g.fillText(lt, x, h / 2); });

  t.wrapS = THREE.RepeatWrapping; t.repeat.set(2, 1); return t; };
  const ledTex = makeLed();
  const led = new THREE.Mesh(new THREE.BoxGeometry(40, 0.9, 0.2), new THREE.MeshStandardMaterial({ map: ledTex, emissive: '#fff', emissiveMap: ledTex, emissiveIntensity: 0.5 })); led.position.set(0, 0.45, -21); scene.add(led);

  /* ---------- places: night stadium, day stadium, sunset, beach, snow, street ---------- */
  const sky = (top, mid, low) => { const t = tex(16, 512, (g, w, h) => { const gr = g.createLinearGradient(0, 0, 0, h); gr.addColorStop(0, top); gr.addColorStop(0.55, mid); gr.addColorStop(1, low); g.fillStyle = gr; g.fillRect(0, 0, w, h); }); return t; };
  const extras = new THREE.Group(); scene.add(extras);
  const lam = (c) => new THREE.MeshLambertMaterial({ color: c });
  const props = {
    beach() {
      const sea = new THREE.Mesh(new THREE.PlaneGeometry(300, 120), new THREE.MeshStandardMaterial({ color: '#1e88c7', roughness: 0.25, metalness: 0.1 }));
      sea.rotation.x = -Math.PI / 2; sea.position.set(0, -0.05, -90); extras.add(sea);
      const foam = new THREE.Mesh(new THREE.PlaneGeometry(300, 1.2), new THREE.MeshBasicMaterial({ color: '#e8fbff' })); foam.rotation.x = -Math.PI / 2; foam.position.set(0, 0.0, -31); extras.add(foam);
      const sand = new THREE.Mesh(new THREE.PlaneGeometry(300, 30), lam('#e9cf98')); sand.rotation.x = -Math.PI / 2; sand.position.set(0, -0.02, -16); extras.add(sand);
      for (const [x, z, h] of [[-13, -25, 7], [-8.5, -28, 8.5], [9, -26, 7.5], [14, -23, 6.5], [-17, -21, 6]]) {   // palm trees
        const p = new THREE.Group(); p.position.set(x, 0, z);
        for (let k = 0; k < 8; k++) { const seg = new THREE.Mesh(new THREE.CylinderGeometry(0.22, 0.28, h / 8 + 0.05, 8), lam(k % 2 ? '#8a5a2b' : '#9b6a35')); seg.position.set(Math.sin(k / 8) * 0.8, (k + 0.5) * h / 8, 0); p.add(seg); }
        for (let k = 0; k < 7; k++) { const leaf = new THREE.Mesh(new THREE.BoxGeometry(3.2, 0.08, 0.7), lam(k % 2 ? '#2f9e44' : '#37b24d')); leaf.position.set(0.8, h, 0); leaf.rotation.set(0, k / 7 * Math.PI * 2, -0.45); leaf.translateX(1.4); p.add(leaf); }
        extras.add(p);
      }
      const sunD = new THREE.Mesh(new THREE.CircleGeometry(3, 32), new THREE.MeshBasicMaterial({ color: '#fff6c8', fog: false })); sunD.position.set(14, 26, -120); extras.add(sunD);
    },
    snow() {
      const n = 900, g = new THREE.BufferGeometry(), pos = new Float32Array(n * 3), r = rng(11);
      for (let i = 0; i < n; i++) { pos[i * 3] = (r() * 2 - 1) * 20; pos[i * 3 + 1] = r() * 18; pos[i * 3 + 2] = -30 + r() * 38; }
      g.setAttribute('position', new THREE.BufferAttribute(pos, 3));
      const flakes = new THREE.Points(g, new THREE.PointsMaterial({ color: '#ffffff', size: 0.12, transparent: true, opacity: 0.9 }));
      flakes.userData.fall = true; extras.add(flakes);
      const snowRoof = new THREE.Mesh(new THREE.BoxGeometry(44.2, 0.3, 14.2), lam('#f4f8ff')); snowRoof.position.set(0, 16.7, -32); extras.add(snowRoof);
    },
    street() {
      const r = rng(5);
      for (let k = 0; k < 9; k++) {   // city blocks with lit windows
        const w = 4 + r() * 3, h = 8 + r() * 14, x = -22 + k * 5.4, z = -27 - r() * 6;
        const winTex = tex(128, 256, (g, W, H) => { g.fillStyle = ['#3b3f58', '#4a3f55', '#2f4858', '#55463a'][k % 4]; g.fillRect(0, 0, W, H); for (let yy = 10; yy < H - 10; yy += 22) for (let xx = 10; xx < W - 10; xx += 22) { g.fillStyle = r() < 0.55 ? '#ffd98a' : '#1b1e2c'; g.fillRect(xx, yy, 12, 14); } });
        winTex.repeat.set(Math.round(w / 2.5), Math.round(h / 5)); winTex.wrapS = winTex.wrapT = THREE.RepeatWrapping;
        const b = new THREE.Mesh(new THREE.BoxGeometry(w, h, 4), new THREE.MeshStandardMaterial({ map: winTex, emissive: '#ffffff', emissiveMap: winTex, emissiveIntensity: 0.35, roughness: 0.9 }));
        b.position.set(x, h / 2, z); extras.add(b);
      }
      const fence = tex(512, 128, (g, W, H) => { g.clearRect(0, 0, W, H); g.strokeStyle = '#c9ced6'; g.lineWidth = 3; for (let x = -H; x < W; x += 24) { g.beginPath(); g.moveTo(x, 0); g.lineTo(x + H, H); g.stroke(); g.beginPath(); g.moveTo(x + H, 0); g.lineTo(x, H); g.stroke(); } });
      fence.wrapS = THREE.RepeatWrapping; fence.repeat.set(10, 1);
      const f = new THREE.Mesh(new THREE.PlaneGeometry(44, 3.2), new THREE.MeshBasicMaterial({ map: fence, transparent: true })); f.position.set(0, 1.6, -23); extras.add(f);
      for (const x of [-12, 0, 12]) { const pole = new THREE.Mesh(new THREE.CylinderGeometry(0.12, 0.12, 9, 8), lam('#59606e')); pole.position.set(x, 4.5, -23.5); extras.add(pole); const lamp = new THREE.Mesh(new THREE.BoxGeometry(1.4, 0.35, 0.6), new THREE.MeshBasicMaterial({ color: '#fff3c4' })); lamp.position.set(x, 9, -23.2); extras.add(lamp); }
    },
    sunset() { const sunD = new THREE.Mesh(new THREE.CircleGeometry(5, 40), new THREE.MeshBasicMaterial({ color: '#ffcf7a', fog: false })); sunD.position.set(-12, 14, -110); extras.add(sunD); },
  };
  const THEMES = {
    night: { name: '🌙 Night stadium', bg: '#0b1024', fog: [30, 60], sun: ['#fff4e0', 2.0], hemi: ['#dfe8ff', '#2c5a2c', 0.7], exp: 0.9, ground: ['#2f8f34', '#287d2d', 'rgba(255,255,255,.9)'], stand: '#3a4050', roof: '#141a2e', stadium: true, lamps: true },
    day: { name: '☀️ Day stadium', sky: ['#3d8fe0', '#8cc4f5', '#d9eefc'], fog: [45, 120], fogC: '#cfe6fa', sun: ['#fffaf0', 2.6], hemi: ['#ffffff', '#3c7a3c', 1.0], exp: 1.0, ground: ['#46a83c', '#3d9634', 'rgba(255,255,255,.95)'], stand: '#9aa3b5', roof: '#cfd6e2', stadium: true, lamps: false },
    sunset: { name: '🌅 Sunset stadium', sky: ['#2b1d5c', '#e0567a', '#ffb067'], fog: [40, 110], fogC: '#e78a7a', sun: ['#ffb36b', 2.2], hemi: ['#ffd2b0', '#3a4a2a', 0.8], exp: 0.95, ground: ['#3f8d36', '#367d2e', 'rgba(255,240,220,.9)'], stand: '#5a4560', roof: '#2a1d3a', stadium: true, lamps: true, extra: 'sunset' },
    beach: { name: '🏖 Beach', sky: ['#1f7fd6', '#7cc3f2', '#e6f6ff'], fog: [60, 160], fogC: '#cdeaf9', sun: ['#fffaf0', 2.7], hemi: ['#ffffff', '#c9a56a', 1.0], exp: 1.0, ground: ['#ecd29c', '#e4c88f', 'rgba(30,110,200,.85)', ['#d8bb80', '#f6e2b4']], stadium: false, extra: 'beach', wmc: 'rgba(90,60,20,.22)' },
    snow: { name: '❄️ Snow', sky: ['#7d8fa8', '#b9c6d6', '#e8eef5'], fog: [30, 80], fogC: '#dfe7f0', sun: ['#f2f6ff', 1.8], hemi: ['#f4f8ff', '#b0bccb', 1.1], exp: 1.0, ground: ['#f3f7fb', '#e6edf5', 'rgba(220,40,60,.85)', ['#ffffff', '#d4dee9', '#c5d9c0']], stand: '#7d8796', roof: '#5d6675', stadium: true, lamps: true, extra: 'snow', wmc: 'rgba(40,60,90,.18)' },
    street: { name: '🏙 Street court', sky: ['#141a3a', '#3b3f75', '#c06a6a'], fog: [40, 90], fogC: '#3b3f75', sun: ['#ffe6c2', 1.7], hemi: ['#c8d2ff', '#3d3d48', 0.8], exp: 0.95, ground: ['#7c818c', '#747984', 'rgba(255,214,58,.95)', ['#6b707a', '#8a8f99']], stadium: false, extra: 'street' },
  };
  let randTh = null;   // 🎲 the made-up stadium (a new one at every START when "New stadium every START" is chosen)
  const applyTheme = (key) => {
    const th = key === 'rand' ? (randTh || (randTh = genStadium(newSeed()))) : (THEMES[key] || THEMES.night);
    extras.clear();
    if (th.sky) { scene.background = sky(...th.sky); scene.fog = new THREE.Fog(th.fogC, th.fog[0], th.fog[1]); }
    else { scene.background = new THREE.Color(th.bg); scene.fog = new THREE.Fog(th.bg, th.fog[0], th.fog[1]); }
    sun.color.set(th.sun[0]); sun.intensity = th.sun[1];
    hemi.color.set(th.hemi[0]); hemi.groundColor.set(th.hemi[1]); hemi.intensity = th.hemi[2];
    renderer.toneMappingExposure = th.exp;
    if (field.material.map) field.material.map.dispose();
    field.material.map = groundTex(th.ground[0], th.ground[1], th.ground[2], th.ground[3], th.wmc); field.material.needsUpdate = true;
    stadium.visible = th.stadium; lamps.visible = !!th.lamps;
    if (th.stand) standMat.color.set(th.stand);
    if (th.roof) roof.material.color.set(th.roof);
    if (th.extra) props[th.extra]();
    lastTh = th; paintGrass();
    const cc = th.crowd || crowdCols;   // the crowd's shirt colours
    for (let k = 0; k < ci; k++) crowd.setColorAt(k, col.set(cc[crowdSlot[k]]));
    if (crowd.instanceColor) crowd.instanceColor.needsUpdate = true;
  };
  applyTheme('night');

  // flags
  const atlas = await new Promise((res) => { const im = new Image(); im.onload = () => res(im); im.src = '/flags/flags.png?v=2'; });
  const info = await (await fetch('/flags/flags.json?v=2')).json();
  const C = info.countries, idx = new Map(C.map((c, i) => [c.code, i]));
  const CNAME = (code) => C[idx.get(code)].name;
  const drawFlag = (g, code, x, y, w, h) => { const i = idx.get(code); g.drawImage(atlas, (i % info.cols) * info.w, Math.floor(i / info.cols) * info.h, info.w, info.h, x, y, w, h); };
  // a shirt for any country: the two main colours of its flag
  const flagKit = (code) => {
    if (KITS[code]) return KITS[code];
    const c = document.createElement('canvas'); c.width = 40; c.height = 30; const g = c.getContext('2d'); drawFlag(g, code, 0, 0, 40, 30);
    const d = g.getImageData(0, 0, 40, 30).data, count = new Map();
    for (let i = 0; i < d.length; i += 4) { const k = [d[i] >> 5, d[i + 1] >> 5, d[i + 2] >> 5].join(','); count.set(k, (count.get(k) || 0) + 1); }
    const top = [...count.entries()].sort((a, b) => b[1] - a[1]).map(([k]) => '#' + k.split(',').map((v) => (v * 32 + 16).toString(16).padStart(2, '0')).join(''));
    const a = top[0] || '#ffffff', b = top[1] || '#111111';
    return [a, b, b, a];
  };

  /* ---------- 👥 the players (names, national team for the shirt) and their photos (kept on this phone) ---------- */
  let roster = PLAYERS.map((p) => ({ ...p }));
  try { const r = JSON.parse(localStorage.getItem('fft_pk_roster') || 'null'); if (Array.isArray(r) && r.length) roster = r.filter((p) => p && p.id && p.name && idx.has(p.c)); } catch (e) { /* ignore */ }
  roster.forEach((p) => { if (!(p.n >= 1 && p.n <= 99)) p.n = (PLAYERS.find((q) => q.id === p.id) || {}).n || 10; });
  const saveRoster = () => { try { localStorage.setItem('fft_pk_roster', JSON.stringify(roster)); } catch (e) { /* ignore */ } };
  const PL = (id) => roster.find((p) => p.id === id) || { id, name: '?', c: 'fr' };
  const NAME = (id) => PL(id).name;
  const photoDb = () => new Promise((res, rej) => { const r = indexedDB.open('fft_pk_photos', 1); r.onupgradeneeded = () => r.result.createObjectStore('p'); r.onsuccess = () => res(r.result); r.onerror = () => rej(r.error); });
  const photoTx = async (mode, fn) => { const d = await photoDb(); return new Promise((res, rej) => { const t = d.transaction('p', mode), q = fn(t.objectStore('p')); t.oncomplete = () => res(q && q.result); t.onerror = () => rej(t.error); }); };
  const photos = new Map(), photoUrl = new Map();   // id → picture (for the 3D board) / small url (for the menu)
  const loadPhoto = async (id, blob) => {
    try { const img = await createImageBitmap(blob); photos.set(id, img); if (photoUrl.has(id)) URL.revokeObjectURL(photoUrl.get(id)); photoUrl.set(id, URL.createObjectURL(blob)); } catch (e) { /* not a picture */ }
  };
  try {
    const keys = await photoTx('readonly', (st) => st.getAllKeys());
    await Promise.all((keys || []).map(async (k) => { const b = await photoTx('readonly', (st) => st.get(k)); if (b) await loadPhoto(k, b); }));
  } catch (e) { /* no photos yet */ }
  // the board above a player: his photo — or, without a photo, our own 3D-style player in his national shirt with his
  // number — and his name under it
  const shade = (hex, f) => { const n = parseInt(hex.slice(1), 16), c = (v) => Math.max(0, Math.min(255, Math.round(v * f))); return `rgb(${c(n >> 16)},${c((n >> 8) & 255)},${c(n & 255)})`; };
  const drawAvatar = (g, x0, y0, w, h, p, sk, hair) => {
    const [a, trim, shorts] = flagKit(p.c), shirt = a === 'stripes' ? '#ffffff' : a;
    const bg = a === 'stripes' ? '#74acdf' : a, gr = g.createLinearGradient(0, y0, 0, y0 + h);
    gr.addColorStop(0, shade(bg, 1.05)); gr.addColorStop(1, shade(bg, 0.35)); g.fillStyle = gr; g.fillRect(x0, y0, w, h);
    const rg = g.createRadialGradient(x0 + w / 2, y0 + h * 0.35, 4, x0 + w / 2, y0 + h * 0.35, w * 0.7);
    rg.addColorStop(0, 'rgba(255,255,255,.35)'); rg.addColorStop(1, 'rgba(255,255,255,0)'); g.fillStyle = rg; g.fillRect(x0, y0, w, h);
    const hs = h * 0.38, cx = x0 + w / 2, hy = y0 + h * 0.1, ty = hy + hs + h * 0.02, tw = hs * 1.3, aw = hs * 0.38;
    // arms and body (shirt), collar, number
    const arm = (ax) => { g.fillStyle = shirt; g.fillRect(ax, ty + 4, aw, h - (ty - y0) - 4); if (a === 'stripes') { g.fillStyle = '#7dd3fc'; g.fillRect(ax + aw * 0.35, ty + 4, aw * 0.3, h - (ty - y0) - 4); } };
    arm(cx - tw / 2 - aw - 2); arm(cx + tw / 2 + 2);
    g.fillStyle = shirt; g.fillRect(cx - tw / 2, ty, tw, h - (ty - y0));
    if (a === 'stripes') { g.fillStyle = '#7dd3fc'; for (let k = 0; k < 5; k++) if (k % 2) g.fillRect(cx - tw / 2 + k * tw / 5, ty, tw / 5, h - (ty - y0)); }
    g.fillStyle = trim === '#ffffff' && shirt === '#ffffff' ? '#1e3a8a' : trim; g.fillRect(cx - tw / 2, ty, tw, h * 0.03);
    g.fillStyle = 'rgba(0,0,0,.14)'; g.fillRect(cx + tw * 0.18, ty, tw * 0.32, h - (ty - y0));   // a little shade on one side (3D look)
    g.font = `bold ${Math.round(hs * 0.62)}px Arial`; g.textAlign = 'center'; g.textBaseline = 'middle';
    g.fillStyle = a === 'stripes' ? '#111827' : (trim === shirt ? (shirt === '#ffffff' ? '#111' : '#fff') : trim); g.fillText(String(p.n), cx, ty + (h - (ty - y0)) * 0.48);
    // head: skin, hair, eyes, smile
    g.fillStyle = sk; g.fillRect(cx - hs / 2, hy, hs, hs);
    g.fillStyle = 'rgba(0,0,0,.12)'; g.fillRect(cx + hs * 0.28, hy, hs * 0.22, hs);
    g.fillStyle = hair; g.fillRect(cx - hs / 2 - 2, hy - hs * 0.06, hs + 4, hs * 0.26);
    g.fillStyle = '#1b1b1b'; for (const ex of [-0.17, 0.17]) { g.beginPath(); g.ellipse(cx + ex * hs, hy + hs * 0.5, hs * 0.07, hs * 0.095, 0, 0, 7); g.fill(); }
    g.fillStyle = '#fff'; for (const ex of [-0.17, 0.17]) { g.beginPath(); g.arc(cx + ex * hs + hs * 0.025, hy + hs * 0.46, hs * 0.025, 0, 7); g.fill(); }
    g.strokeStyle = '#1b1b1b'; g.lineWidth = hs * 0.04; g.lineCap = 'round'; g.beginPath(); g.arc(cx, hy + hs * 0.62, hs * 0.13, 0.35, Math.PI - 0.35); g.stroke();
    g.fillStyle = 'rgba(255,90,90,.25)'; for (const ex of [-0.3, 0.3]) { g.beginPath(); g.arc(cx + ex * hs, hy + hs * 0.64, hs * 0.07, 0, 7); g.fill(); }
  };
  const drawCard = (g, id, w, h, sk = '#f1c7a3', hair = '#2b1d14') => {
    const p = PL(id), ph = photos.get(id), PH = h - 70;
    g.fillStyle = '#ffffff'; g.fillRect(0, 0, w, h);
    if (ph) {   // fill the square, keep the top (faces are usually at the top)
      const s = Math.max((w - 16) / ph.width, (PH - 8) / ph.height), sw = (w - 16) / s, sh = (PH - 8) / s;
      g.drawImage(ph, (ph.width - sw) / 2, Math.max(0, Math.min(ph.height - sh, (ph.height - sh) * 0.15)), sw, sh, 8, 8, w - 16, PH - 8);
    } else {
      g.save(); g.beginPath(); g.rect(8, 8, w - 16, PH - 8); g.clip(); drawAvatar(g, 8, 8, w - 16, PH - 8, p, sk, hair); g.restore();
    }
    g.fillStyle = '#0a0e1e'; g.fillRect(8, PH + 4, w - 16, h - PH - 12);
    let fs = 40; g.font = `bold ${fs}px Arial`; while (g.measureText(p.name).width > w - 28 && fs > 18) { fs -= 2; g.font = `bold ${fs}px Arial`; }
    g.fillStyle = '#ffffff'; g.textAlign = 'center'; g.textBaseline = 'middle'; g.fillText(p.name, w / 2, PH + 4 + (h - PH - 12) / 2 + 1);
  };

  const makeBallTex = (base, patch) => tex(1024, 512, (g, w, h) => {
    g.fillStyle = base; g.fillRect(0, 0, w, h);
    const ph = (1 + Math.sqrt(5)) / 2, v = [[-1, ph, 0], [1, ph, 0], [-1, -ph, 0], [1, -ph, 0], [0, -1, ph], [0, 1, ph], [0, -1, -ph], [0, 1, -ph], [ph, 0, -1], [ph, 0, 1], [-ph, 0, -1], [-ph, 0, 1]];
    g.fillStyle = patch;
    for (const [x, y, z] of v) {
      const l = Math.hypot(x, y, z), lat = Math.asin(y / l), lon = Math.atan2(z, x);
      const cx = (lon / (2 * Math.PI) + 0.5) * w, cy = (0.5 - lat / Math.PI) * h, r = 46, sx = 1 / Math.max(0.2, Math.cos(lat));
      for (const off of [-w, 0, w]) { g.beginPath(); for (let k = 0; k < 5; k++) { const a = -Math.PI / 2 + k * 2 * Math.PI / 5; g.lineTo(cx + off + Math.cos(a) * r * sx, cy + Math.sin(a) * r); } g.closePath(); g.fill(); }
    }
  });
  const ballMat = new THREE.MeshStandardMaterial({ map: makeBallTex('#fafafa', '#151515'), roughness: 0.45 });
  const paintBalls = (c) => { ballMat.map.dispose(); ballMat.map = makeBallTex(c.base, c.patch); ballMat.needsUpdate = true; };
  let ballGeo = new THREE.SphereGeometry(R, 40, 28);

  // the trophy (our own design): a black-and-gold base with laurel-and-ball badges, a fluted golden stem,
  // golden prongs holding a golden football on top; it stands on a glowing golden stage
  const gold = new THREE.MeshStandardMaterial({ color: '#f7c948', metalness: 1, roughness: 0.18 });
  const darkGold = new THREE.MeshStandardMaterial({ color: '#c8921a', metalness: 1, roughness: 0.3 });
  const cup = new THREE.Group();
  const add = (geo, mat, y, rx = 0) => { const m = new THREE.Mesh(geo, mat); m.position.y = y; m.rotation.x = rx; m.castShadow = true; cup.add(m); return m; };
  const badgeTex = tex(1024, 128, (g, w, h) => {
    g.fillStyle = '#111318'; g.fillRect(0, 0, w, h);
    for (let k = 0; k < 4; k++) {
      const cx = (k + 0.5) * w / 4, cy = h / 2;
      g.strokeStyle = '#f7c948'; g.fillStyle = '#f7c948'; g.lineWidth = 5;
      g.beginPath(); g.arc(cx, cy, 30, 0, 7); g.stroke();                                   // a ball…
      g.beginPath(); for (let q = 0; q < 5; q++) { const a = -Math.PI / 2 + q * 2 * Math.PI / 5; g.lineTo(cx + Math.cos(a) * 11, cy + Math.sin(a) * 11); } g.closePath(); g.fill();
      for (const sd of [-1, 1]) for (let q = 0; q < 5; q++) {                                // …in two laurel branches
        const a = Math.PI / 2 + sd * (0.5 + q * 0.42), lx = cx + sd * 0 + Math.cos(a) * 46 * -sd, ly = cy + Math.sin(a) * 46 * 0.75;
        g.beginPath(); g.ellipse(cx - sd * Math.abs(cx - lx) - sd * 0, ly, 10, 5, sd * (0.9 - q * 0.35), 0, 7); g.fill();
      }
    }
    g.fillStyle = '#f7c948'; g.fillRect(0, 0, w, 6); g.fillRect(0, h - 6, w, 6);
  });
  badgeTex.wrapS = THREE.RepeatWrapping;
  add(new THREE.CylinderGeometry(0.62, 0.68, 0.14, 8), darkGold, 0.07);                                        // foot
  add(new THREE.CylinderGeometry(0.56, 0.6, 0.34, 8), [new THREE.MeshStandardMaterial({ map: badgeTex, metalness: 0.6, roughness: 0.35 }), darkGold, darkGold], 0.31);   // black band with badges
  add(new THREE.CylinderGeometry(0.5, 0.56, 0.1, 8), gold, 0.53);
  const stem = [[0.42, 0], [0.3, 0.08], [0.2, 0.3], [0.15, 0.62], [0.17, 0.82], [0.26, 0.95], [0.3, 1.0]].map(([r, y]) => new THREE.Vector2(r, y));
  add(new THREE.LatheGeometry(stem, 24), gold, 0.58);                                                         // fluted stem (24 sides)
  add(new THREE.TorusGeometry(0.2, 0.035, 12, 40), darkGold, 1.18, Math.PI / 2);
  // a crown-shaped holder for the ball: a bowl with six points
  const bowl = [[0.12, 0], [0.22, 0.04], [0.32, 0.14], [0.38, 0.28], [0.36, 0.3], [0.3, 0.17], [0.2, 0.08], [0.1, 0.05]].map(([r, y]) => new THREE.Vector2(r, y));
  add(new THREE.LatheGeometry(bowl, 48), gold, 1.58);
  for (let q = 0; q < 6; q++) {
    const a = q * Math.PI / 3, pt = new THREE.Mesh(new THREE.ConeGeometry(0.06, 0.2, 12), gold);
    pt.position.set(Math.cos(a) * 0.37, 1.95, Math.sin(a) * 0.37); pt.castShadow = true; cup.add(pt);
  }
  const goldBallTex = tex(1024, 512, (g, w, h) => {
    g.fillStyle = '#f7c948'; g.fillRect(0, 0, w, h);
    const ph = (1 + Math.sqrt(5)) / 2, v = [[-1, ph, 0], [1, ph, 0], [-1, -ph, 0], [1, -ph, 0], [0, -1, ph], [0, 1, ph], [0, -1, -ph], [0, 1, -ph], [ph, 0, -1], [ph, 0, 1], [-ph, 0, -1], [-ph, 0, 1]];
    g.fillStyle = '#b07a12';
    for (const [x, y, z] of v) { const l = Math.hypot(x, y, z), lat = Math.asin(y / l), lon = Math.atan2(z, x), cx = (lon / (2 * Math.PI) + 0.5) * w, cy = (0.5 - lat / Math.PI) * h, sx = 1 / Math.max(0.2, Math.cos(lat));
      for (const off of [-w, 0, w]) { g.beginPath(); for (let k = 0; k < 5; k++) { const a = -Math.PI / 2 + k * 2 * Math.PI / 5; g.lineTo(cx + off + Math.cos(a) * 44 * sx, cy + Math.sin(a) * 44); } g.closePath(); g.fill(); } }
  });
  // the join between the stem and the ball holder (it was open: the grass showed through) — closed, with a design (menu → Trophy design)
  add(new THREE.CylinderGeometry(0.3, 0.27, 0.05, 48), gold, 1.585);
  add(new THREE.CylinderGeometry(0.14, 0.2, 0.08, 32), darkGold, 1.62);
  const necks = { A: new THREE.Group(), B: new THREE.Group(), C: new THREE.Group() };
  {   // A: a golden ring with red gems
    const gem = new THREE.MeshStandardMaterial({ color: '#e11d48', metalness: 0.2, roughness: 0.05, emissive: '#7f0b24', emissiveIntensity: 0.6 });
    for (let q = 0; q < 10; q++) { const a = q * Math.PI / 5, m = new THREE.Mesh(new THREE.SphereGeometry(0.045, 16, 12), gem); m.position.set(Math.cos(a) * 0.27, 1.6, Math.sin(a) * 0.27); necks.A.add(m); }
    const r = new THREE.Mesh(new THREE.TorusGeometry(0.29, 0.02, 10, 48), darkGold); r.rotation.x = Math.PI / 2; r.position.y = 1.585; necks.A.add(r);
  }
  {   // B: two golden laurel wings
    const leaf = new THREE.SphereGeometry(1, 16, 10);
    for (const sd of [-1, 1]) for (let q = 0; q < 5; q++) {
      const m = new THREE.Mesh(leaf, q % 2 ? darkGold : gold); m.scale.set(0.12, 0.035, 0.05);
      m.position.set(sd * (0.2 + q * 0.07), 1.6 + q * 0.045, 0); m.rotation.z = sd * (0.35 + q * 0.12); m.castShadow = true; necks.B.add(m);
    }
  }
  {   // C: an eight-pointed golden star with pearls on its points
    const sh = new THREE.Shape(); for (let q = 0; q < 16; q++) { const a = q * Math.PI / 8, r = q % 2 ? 0.24 : 0.42; if (q) sh.lineTo(Math.cos(a) * r, Math.sin(a) * r); else sh.moveTo(r, 0); }
    const star = new THREE.Mesh(new THREE.ExtrudeGeometry(sh, { depth: 0.04, bevelEnabled: true, bevelSize: 0.01, bevelThickness: 0.01 }), gold);
    star.rotation.x = -Math.PI / 2; star.position.y = 1.58; star.castShadow = true; necks.C.add(star);
    const pearl = new THREE.MeshStandardMaterial({ color: '#fff7e6', metalness: 0.1, roughness: 0.15 });
    for (let q = 0; q < 8; q++) { const a = q * Math.PI / 4, m = new THREE.Mesh(new THREE.SphereGeometry(0.04, 16, 12), pearl); m.position.set(Math.cos(a) * 0.42, 1.63, -Math.sin(a) * 0.42); necks.C.add(m); }
  }
  Object.values(necks).forEach((g) => cup.add(g));
  const setNeck = (k) => { for (const [n, g] of Object.entries(necks)) g.visible = n === k; };
  setNeck('A');
  const topBall = add(new THREE.SphereGeometry(0.4, 48, 32), new THREE.MeshStandardMaterial({ map: goldBallTex, metalness: 1, roughness: 0.2 }), 2.08);
  topBall.rotation.set(0.4, 0.6, 0);
  // the glowing stage on the grass (it stays when the trophy falls off it)
  const stage3 = new THREE.Mesh(new THREE.CylinderGeometry(0.38, 0.41, 0.06, 64), new THREE.MeshStandardMaterial({ color: '#f7c948', metalness: 1, roughness: 0.15, emissive: '#ffb300', emissiveIntensity: 0.35 }));
  stage3.position.set(CUP.x, 0.03, CUP.z); stage3.receiveShadow = true; scene.add(stage3);
  const glow = new THREE.Mesh(new THREE.RingGeometry(0.4, 0.55, 64), new THREE.MeshBasicMaterial({ color: '#ffd23a', transparent: true, opacity: 0.35 }));
  glow.rotation.x = -Math.PI / 2; glow.position.set(CUP.x, 0.04, CUP.z); scene.add(glow);
  cup.scale.setScalar(0.5);
  const cupPivot = new THREE.Group(); cupPivot.position.set(CUP.x, 0.06, CUP.z); cupPivot.add(cup); scene.add(cupPivot);
  const fallAxis = new THREE.Vector3(), cupBase = 0.3;   // the cup tips over its base edge
  const ring = new THREE.Mesh(new THREE.TorusGeometry(0.6, 0.05, 12, 64), new THREE.MeshBasicMaterial({ color: '#fff6b0', transparent: true, opacity: 0 }));
  ring.rotation.x = -Math.PI / 2; ring.position.set(CUP.x, 0.45, CUP.z); scene.add(ring);
  const placeCup = () => {   // 🏆 the trophy moved or made bigger (menu): its stage, glow and ring go with it
    stage3.position.set(CUP.x, 0.03, CUP.z); stage3.scale.set(CUP.s, 1, CUP.s);
    glow.position.set(CUP.x, 0.04, CUP.z); glow.scale.setScalar(CUP.s);
    ring.position.set(CUP.x, 0.45 * CUP.s, CUP.z); cupPivot.position.set(CUP.x, 0.06, CUP.z); cupPivot.scale.setScalar(CUP.s);
  };

  /* ---------- players (our own blocky style), made again for each set of countries ---------- */
  const skin = ['#f1c7a3', '#c68a5c', '#e8b48a', '#f3d1b5', '#8d5a3b', '#d9a77c'];
  const faceTex = (sk) => tex(256, 256, (g, w, h) => {
    g.fillStyle = sk; g.fillRect(0, 0, w, h);   // (the eyes are separate, so they can look around and blink)
    g.strokeStyle = '#1b1b1b'; g.lineWidth = 10; g.lineCap = 'round'; g.beginPath(); g.arc(128, 150, 34, 0.35, Math.PI - 0.35); g.stroke();
    g.fillStyle = 'rgba(255,90,90,.25)'; g.beginPath(); g.arc(60, 150, 18, 0, 7); g.fill(); g.beginPath(); g.arc(196, 150, 18, 0, 7); g.fill();
  });
  const eyeGeo = new THREE.CircleGeometry(1, 24), eyeMat = new THREE.MeshBasicMaterial({ color: '#1b1b1b' }), shineMat = new THREE.MeshBasicMaterial({ color: '#ffffff' });
  const std = (c) => new THREE.MeshStandardMaterial({ color: c, roughness: 0.85 });   // matt, so arms do not shine
  const box = (w, h, d, mat) => { const m = new THREE.Mesh(new THREE.BoxGeometry(w, h, d), mat); m.castShadow = true; m.receiveShadow = true; return m; };
  let players = [];
  const team = new THREE.Group(); scene.add(team);
  const buildTeams = (codes) => {
    team.clear();
    players = codes.map((code, i) => {
      const [sh, trim, shorts, socks] = flagKit(PL(code).c), sk = skin[i % 6], p = new THREE.Group();
      p.position.set(PX(i), 0, PZ); p.scale.setScalar(PS);
      const shirt = std(sh === 'stripes' ? '#bae6fd' : sh);
      const shirtM = new THREE.MeshStandardMaterial({ roughness: 0.85, map: tex(256, 256, (g, w, h) => {
        if (sh === 'stripes') { for (let k = 0; k < 8; k++) { g.fillStyle = k % 2 ? '#7dd3fc' : '#ffffff'; g.fillRect(k * 32, 0, 32, h); } } else { g.fillStyle = sh; g.fillRect(0, 0, w, h); }
        g.fillStyle = trim; g.fillRect(0, 0, w, 22);
        g.fillStyle = sh === 'stripes' ? '#111827' : trim; g.font = 'bold 120px Arial'; g.textAlign = 'center'; g.textBaseline = 'middle'; g.fillText(String(PL(code).n), w / 2, h / 2 + 14);
      }) });
      const leg = (sx) => { const g = new THREE.Group(); g.position.set(sx, 1.0, 0);
        const a = box(0.36, 0.62, 0.38, std(sk)); a.position.y = -0.31; g.add(a);
        const s = box(0.38, 0.3, 0.4, std(socks)); s.position.y = -0.72; g.add(s);
        const so = box(0.4, 0.16, 0.5, std('#111')); so.position.set(0, -0.92, 0.05); g.add(so); p.add(g); return g; };
      const legL = leg(-0.22), legR = leg(0.22);
      const sht = box(0.92, 0.42, 0.48, std(shorts)); sht.position.y = 1.15; p.add(sht);
      const body = box(0.96, 0.95, 0.5, [shirt, shirt, shirt, shirt, shirtM, shirt]); body.position.y = 1.82; p.add(body);
      const arm = (sx) => { const g = new THREE.Group(); g.position.set(sx, 2.2, 0); const a = box(0.3, 0.9, 0.32, shirt); a.position.y = -0.4; g.add(a); const hd = box(0.32, 0.24, 0.34, std(sk)); hd.position.y = -0.96; /* a hair bigger than the arm, so the two never flicker where they meet */ g.add(hd); p.add(g); return g; };
      const armL = arm(-0.64), armR = arm(0.64);
      const head = box(0.78, 0.78, 0.74, [std(sk), std(sk), std(sk), std(sk), new THREE.MeshStandardMaterial({ map: faceTex(sk) }), std(sk)]); head.position.y = 2.72; p.add(head);
      // eyes: a black oval with a white shine; they move together (look at the ball), blink, and open wide when the cup is hit
      const eyes = [-0.124, 0.124].map((ex) => {
        const e = new THREE.Group(); e.position.set(ex, 2.775, 0.372);
        const pupil = new THREE.Mesh(eyeGeo, eyeMat); pupil.scale.set(0.05, 0.068, 1); e.add(pupil);
        const shine = new THREE.Mesh(eyeGeo, shineMat); shine.scale.set(0.019, 0.019, 1); shine.position.set(0.016, 0.026, 0.002); e.add(shine);
        p.add(e); return e;
      });
      const hairC = ['#2b1d14', '#111', '#5b3a1e', '#111', '#c9a26b', '#111'][i];
      const hair = box(0.82, 0.2, 0.78, std(hairC)); hair.position.y = 3.15; p.add(hair);
      const fl = new THREE.Mesh(new THREE.PlaneGeometry(1.5, 1.95), new THREE.MeshBasicMaterial({ map: tex(260, 338, (g, w, h) => drawCard(g, code, w, h, sk, hairC)) })); fl.position.set(0, 4.55, 0); p.add(fl);   // 📷 photo + name
      team.add(p);
      return { p, legL, legR, armL, armR, eyes, kicks: [], blinkAt: 1 + Math.random() * 3, look: new THREE.Vector2() };
    });
  };
  const ballGroup = new THREE.Group(); scene.add(ballGroup);
  let meshes = [], quats = [];

  /* ---------- play ---------- */
  const snd = new Sound();
  let vr = null;   // 🗣 My Voice lines (made below)
  let bgm = { play() {}, stop() {} };   // 🎵 background sound (made below)
  let LOOP = false, endT0 = 0, backWords = false, vt = 0;   // 🔁 loop video (menu → Loop); vt = seconds since START
  let play = null, simT = 0, last = 0, playing = false, lastStep = 0, doneKick = new Set(), doneBump = 0, doneLand = 0, clanked = false, dinged = false, endT = 0;
  const axis = new THREE.Vector3(), dq = new THREE.Quaternion();
  const w1 = $('w1'), w2 = $('w2'), words = $('words');
  // a line = the words + the emojis at the end in their own box (so gold / glossy letters do not colour the emojis)
  const fillLine = (el, text) => {
    el.textContent = '';
    text = String(text || '').trim();
    if (!text) return;
    const m = text.match(/^(.*?)\s*((?:[\p{Extended_Pictographic}\u{1F1E6}-\u{1F1FF}\uFE0F\u200D]\s*)+)$/u);
    const tx = document.createElement('span'); tx.className = 'tx'; tx.textContent = m && m[1] ? m[1] : (m ? '' : text);
    el.appendChild(tx);
    if (m) { if (m[1]) el.appendChild(document.createTextNode(' ')); const em = document.createElement('span'); em.className = 'em'; em.textContent = m[2].trim(); el.appendChild(em); }
  };
  const setStyle = (st) => {
    words.className = 'words' + (words.classList.contains('end') ? ' end' : '') + (st === 'off' ? '' : ' st-' + st);
    words.hidden = st === 'off';
  };
  // a long text in a one-line style: shrink that line so it fits the picture
  const fitLines = () => {
    const box = words.firstElementChild;
    w1.style.zoom = w2.style.zoom = '';
    if (words.classList.contains('st-classic')) return;
    const cs = getComputedStyle(box);
    const room = words.clientWidth * 0.94 - (parseFloat(cs.paddingLeft) + parseFloat(cs.paddingRight) + parseFloat(cs.borderLeftWidth) + parseFloat(cs.borderRightWidth));
    if (room <= 0) return;
    for (const el of [w1, w2]) {
      const w = Math.max(el.scrollWidth, el.getBoundingClientRect().width) * 1.1;   // a little room for glows and tilts
      if (w > room) el.style.zoom = String(Math.max(0.45, room / w));
    }
  };
  // the recorder (menu → 🎥 Auto record); a stand-in until the menu is ready
  let rec = { busy: false, on: () => false, active: () => false, words() {}, frame() {}, ended() {} };
  let wmX = 0, wmY = 0;
  const setWords = (a, b, end) => { fillLine(w1, a); fillLine(w2, b); requestAnimationFrame(fitLines); if (document.fonts) document.fonts.ready.then(() => requestAnimationFrame(fitLines)); words.classList.toggle('end', end); w1.classList.remove('pop'); w2.classList.remove('pop'); void w1.offsetWidth; w1.classList.add('pop'); w2.classList.add('pop'); rec.words(); };

  const prepare = (codes, want) => {
    play = findPlay(codes.length, -1, ctl());
    if (!play) return false;
    // the country picked in the menu simply stands where the winning ball is kicked from (the line-up order changes, the play stays fair)
    if (want >= 0 && play.winner !== want) { const c = codes[want]; codes[want] = codes[play.winner]; codes[play.winner] = c; }
    buildTeams(codes);
    players.forEach((pl, i) => { pl.kicks = play.kicks.filter((k) => k.team === i).map((k) => k.t); pl.gseed = Math.random() * 1000; });
    ballGroup.clear();
    meshes = play.balls.map(() => { const m = new THREE.Mesh(ballGeo, ballMat); m.castShadow = true; ballGroup.add(m); return m; });
    quats = play.balls.map(() => new THREE.Quaternion().setFromEuler(new THREE.Euler(Math.random() * 6, Math.random() * 6, 0)));
    simT = 0; lastStep = 0; doneKick = new Set(); doneBump = 0; doneLand = 0; clanked = false; dinged = false;
    vr.reset();
    endT = Math.max(play.touchT + 3.5, vr.minEnd(play.touchT));   // more time when My Voice still has something to say
    endT0 = endT; backWords = false; vt = 0;
    // 🔁 loop: at the end the camera turns up to the sky; while nobody sees the field everything is put back as at the
    // start; the video starts in that same sky picture and comes down to the field — no jump, it never looks like an end
    if (LOOP) endT += UP;
    play.q0 = quats.map((q) => q.clone());
    place(0);
    return true;
  };
  const place = (step) => {
    for (let s = lastStep; s < step; s++) {
      play.balls.forEach((b, k) => {
        const T = play.track[k], x0 = T[s * 3], z0 = T[s * 3 + 2], x1 = T[s * 3 + 3], z1 = T[s * 3 + 5];
        const dx = x1 - x0, dz = z1 - z0, d = Math.hypot(dx, dz); if (d < 1e-6) return;   // (it keeps spinning in the air)
        axis.set(dz / d, 0, -dx / d); dq.setFromAxisAngle(axis, d / R); quats[k].premultiply(dq);
      });
    }
    lastStep = step;
    play.balls.forEach((b, k) => { meshes[k].position.set(play.track[k][step * 3], play.track[k][step * 3 + 1], play.track[k][step * 3 + 2]); meshes[k].quaternion.copy(quats[k]); });
  };

  // small moves while the balls roll (arm angles: x = forward/up, z = out to the side / in over the head)
  const GESTURES = 7;
  const pose = (kind, t, i) => {
    const q = t * 6 + i, s1 = Math.sin(q), pump = 0.5 + 0.5 * Math.sin(q * 1.6);
    switch (kind) {
      case 0: return { lx: -0.35, lz: 2.7, rx: -0.35, rz: -2.7, y: 0, ry: Math.sin(t * 3 + i) * 0.08 };   // hands on the head (nervous)
      case 1: return { lx: -0.2 - 0.2 * s1, lz: 0, rx: -1.3 - pump * 0.8, rz: 0, y: pump * 0.05, ry: 0 };   // right fist pumping "go go!"
      case 2: return { lx: 0, lz: -0.15, rx: -1.45, rz: 0.12 + Math.sin(q * 0.8) * 0.06, y: 0, ry: 0 };   // pointing at his ball
      case 3: { const c = 0.5 + 0.5 * Math.sin(q * 2.4); return { lx: -1.25, lz: -0.05 + c * 0.45, rx: -1.25, rz: 0.05 - c * 0.45, y: 0, ry: 0 }; }   // clapping
      case 4: return { lx: -1.3 - pump * 0.8, lz: 0, rx: -0.2 - 0.2 * s1, rz: 0, y: pump * 0.05, ry: 0 };   // left fist pumping
      case 5: return { lx: 0.35, lz: -0.55, rx: 0.35, rz: 0.55, y: -0.06 + Math.abs(Math.sin(q * 0.9)) * 0.04, ry: Math.sin(t * 2 + i) * 0.12 };   // hands on the hips, bouncing (waiting)
      default: return { lx: -1.45, lz: 0.1, rx: -0.3, rz: 0.15 * s1, y: 0, ry: -0.1 };   // pointing with the other hand
    }
  };
  // which move a player does in each second: random per play, never the same twice in a row
  const gestureOf = (pl, seg) => {
    const h = (n) => { const x = Math.sin(n * 12.9898 + pl.gseed * 78.233) * 43758.5453; return x - Math.floor(x); };
    let k = Math.floor(h(seg) * GESTURES); const prev = Math.floor(h(seg - 1) * GESTURES);
    if (k === prev) k = (k + 1 + Math.floor(h(seg + 0.5) * (GESTURES - 1))) % GESTURES;
    return k;
  };
  // eyes: look at your newest ball (or the cup), blink now and then, open wide when the cup is hit
  const tmpV = new THREE.Vector3(), headV = new THREE.Vector3();
  const updateEyes = (now) => {
    const t = simT, sec = now / 1000;
    players.forEach((pl, i) => {
      let tx = CUP.x, ty = 0.4, tz = CUP.z;
      if (play) {
        let best = -1, bt = -1; play.balls.forEach((b, k) => { if (b.team === i && b.t <= t && b.t > bt) { bt = b.t; best = k; } });
        if (best >= 0 && meshes[best]) { tx = meshes[best].position.x; tz = meshes[best].position.z; }
        else { tx = pl.p.position.x; tz = PZ + 0.3 * PS + R; ty = 0.2; }   // before kicking: looks down at his ball
      }
      pl.p.getWorldPosition(headV); headV.y += 2.75 * PS;
      tmpV.set(tx - headV.x, ty - headV.y, tz - headV.z).normalize();
      const gx = THREE.MathUtils.clamp(tmpV.x * 0.09, -0.05, 0.05), gy = THREE.MathUtils.clamp(tmpV.y * 0.07, -0.035, 0.02);
      pl.look.x += (gx - pl.look.x) * 0.2; pl.look.y += (gy - pl.look.y) * 0.2;
      if (sec > pl.blinkAt + 0.14) pl.blinkAt = sec + 1.5 + Math.random() * 3;
      const blink = sec > pl.blinkAt && sec < pl.blinkAt + 0.14 ? 0.12 : 1;
      const since = play ? t - play.touchT : -1, wow = since > 0 && since < 0.9 ? 1.35 - Math.max(0, since - 0.6) : 1;
      pl.eyes.forEach((e, k) => { e.position.x = (k ? 0.124 : -0.124) + pl.look.x; e.position.y = 2.775 + pl.look.y; e.scale.set(wow, blink * wow, 1); });
    });
  };
  const frame = (now) => {
    requestAnimationFrame(frame);
    if (rec.busy) { last = 0; return; }   // ⚡ Perfect recording moves the game itself, picture by picture
    const real = Math.min(0.05, (now - (last || now)) / 1000); last = now;
    tick(now, real);
  };
  const tick = (now, real) => {
    if (play) vt += real;
    if (play && playing) {
      const near = simT > play.touchT - 0.6 && simT < play.touchT + 0.4;
      simT += real * (near ? SLOW : 1);
      if (simT >= endT) { simT = endT; if (vr.done()) { playing = false; rec.ended(); if (LOOP) bgm.stop(0.03); else bgm.stop(rec.active() ? 0.7 : 1.2); } }
      // sounds at the right moments
      play.kicks.forEach((k, i) => { if (simT >= k.t && !doneKick.has(i)) { doneKick.add(i); snd.kick(); } });
      while (doneBump < play.bumps.length && play.bumps[doneBump] <= simT) { if (doneBump % 2 === 0) snd.bump(); doneBump++; }
      while (doneLand < play.lands.length && play.lands[doneLand].t <= simT) { snd.land(play.lands[doneLand].v); doneLand++; }
      if (!dinged && simT >= play.touchT) { dinged = true; snd.ding(); setWords($('t2').value.replace(/touches/i, 'touched').replace(/will touch/i, 'touched'), $('t3').value, true); }
      vr.tick(real, dinged);
    }
    const t = simT;
    if (play) {
      place(Math.min(play.steps - 2, Math.floor(Math.min(t, endT0) / DT)));
      // 🔁 loop: in the last picture (camera up at the sky) everything is back as at the start
      var back = LOOP && t >= endT - 1e-6 ? 1 : 0;
      if (LOOP && !backWords && t > endT0 + UP * 0.6) { backWords = true; setWords($('t1').value, $('t2').value, false); }
      if (back) meshes.forEach((m, k) => { const T = play.track[k]; m.position.set(T[0], T[1], T[2]); m.quaternion.copy(play.q0[k]); });
      players.forEach((pl, i) => {
        let a = 0;
        for (const k of pl.kicks) { const d = t - k; if (d > -0.3 && d < 0) a = -0.9 * Math.sin((d + 0.3) / 0.3 * Math.PI / 2); if (d >= 0 && d < 0.25) a = -0.9 * (1 - d / 0.25) + 1.1 * Math.sin(d / 0.25 * Math.PI); }
        const since = t - play.touchT;
        if (since > 0) {
          // the cup was touched: everybody jumps and dances, arms up in the air
          const go = Math.min(1, since / 0.25) * (1 - back), ph = since * 9 + i * 1.3, hop = Math.abs(Math.sin(ph));
          pl.p.position.y = hop * 0.55 * go;
          pl.p.rotation.y = Math.sin(since * 5 + i) * 0.35 * go;
          pl.armL.rotation.set(0, 0, -(2.5 + Math.sin(ph * 2) * 0.35) * go); pl.armR.rotation.set(0, 0, (2.5 + Math.sin(ph * 2 + 1) * 0.35) * go);
          pl.legL.rotation.set(-(1 - hop) * 0.5 * go, 0, 0); pl.legR.rotation.set(-(1 - hop) * 0.5 * go, 0, 0);
          return;
        }
        pl.p.rotation.y = 0; pl.armL.rotation.z = 0; pl.armR.rotation.z = 0;
        pl.legR.rotation.x = -a; pl.legL.rotation.x = a * 0.2; pl.armL.rotation.x = a * 0.5; pl.armR.rotation.x = -a * 0.5;
        pl.p.position.y = Math.abs(Math.sin(now / 330 + i)) * 0.04;
        // after kicking, while the balls roll: each player does his own small thing (not too much, the balls stay the show)
        const first = pl.kicks.length ? Math.min(...pl.kicks) : 99;
        let w = 0;
        if (t > first + 0.25) { w = 1; for (const k of pl.kicks) { const d = t - k; if (d > -0.45 && d < 0.55) w = Math.min(w, d < 0 ? Math.max(0, -d - 0.3) / 0.15 : Math.max(0, d - 0.25) / 0.3); } }
        w = Math.min(w, Math.max(0, (t - first - 0.25) / 0.3));
        if (w > 0) {
          // the move changes every second or so, and is different in every play
          const segLen = 1.1, u = (t - first - 0.25) / segLen + (pl.gseed % 1), seg = Math.floor(u), f = u - seg;
          const A = pose(gestureOf(pl, seg), t, i), B = pose(gestureOf(pl, seg - 1), t, i);
          const m = f < 0.25 ? f / 0.25 : 1, mm = m * m * (3 - 2 * m);   // a quick smooth change from the last move
          const P = {}; for (const key in A) P[key] = B[key] + (A[key] - B[key]) * mm;
          const mix = (v, to) => v + (to - v) * w;
          pl.armL.rotation.x = mix(pl.armL.rotation.x, P.lx); pl.armL.rotation.z = mix(pl.armL.rotation.z, P.lz);
          pl.armR.rotation.x = mix(pl.armR.rotation.x, P.rx); pl.armR.rotation.z = mix(pl.armR.rotation.z, P.rz);
          pl.p.position.y += P.y * w; pl.p.rotation.y = mix(pl.p.rotation.y, P.ry);
        }
      });
      const since = t - play.touchT;
      if (since > 0 && play.fall) {
        // falls over in ~0.5 s (faster and faster), a small bounce, then lies on the grass
        const f = play.fall, k = Math.min(1, since / 0.5), bounce = since > 0.5 && since < 0.75 ? Math.sin((since - 0.5) / 0.25 * Math.PI) * 0.12 : 0;
        cupPivot.position.set(CUP.x + f.dx * cupBase * CUP.s, 0.06, CUP.z + f.dz * cupBase * CUP.s); cup.position.set(-f.dx * cupBase, 0, -f.dz * cupBase);
        fallAxis.set(f.dz, 0, -f.dx); cupPivot.quaternion.setFromAxisAngle(fallAxis, (k * k * (Math.PI / 2 - 0.12) - bounce) * (1 - back));
        if (since > 0.5 && !clanked) { clanked = true; snd.clank(); snd.win(); }
      } else { cupPivot.position.set(CUP.x, 0.06, CUP.z); cup.position.set(0, 0, 0); cupPivot.quaternion.identity(); }
      ring.material.opacity = since > 0 && since < 0.6 ? 0.9 * (1 - since / 0.6) : 0; ring.scale.setScalar((since > 0 ? 1 + since * 3 : 1) * CUP.s);
      const jump = since > 0 && since < 3 ? 0.25 : 0.06;
      for (let k = 0; k < ci; k++) { const [x, y, z, ph] = crowdPos[k]; m4.makeTranslation(x, y + Math.max(0, Math.sin(now / 140 + ph)) * jump, z); crowd.setMatrixAt(k, m4); }
      crowd.instanceMatrix.needsUpdate = true;
    }
    const k = play ? Math.min(1, simT / endT0) : 0;
    const sm = (u) => { u = Math.max(0, Math.min(1, u)); return u * u * (3 - 2 * u); };
    const sky = LOOP && play ? Math.max(1 - sm(vt / DOWN), sm((simT - endT0) / UP)) : 0;   // 🔁 0 = the field, 1 = up at the sky
    const cx = Math.sin(now / 3300) * 0.25 * (1 - sky), cy = 3.2 - k * 0.5 + CAM.y, cz = 9.5 - k * 2.2 + CAM.z, ly = 2.6 - k * 0.6 + CAM.y * 0.6;
    if (camera.fov !== CAM.fov) { camera.fov = CAM.fov; camera.updateProjectionMatrix(); }
    camera.position.set(cx, cy + (4.2 - cy) * sky, cz + (8.6 - cz) * sky); camera.lookAt(0, ly + (24 - ly) * sky, -10 + 4 * sky);
    if (CLOSE) { camera.position.set(0.2, 0.9, 1.4); camera.lookAt(0, 0.62, -1.5); }   // ?closeup=1: a close look at the trophy
    for (const o of extras.children) if (o.userData.fall) { const p = o.geometry.attributes.position; for (let i = 0; i < p.count; i++) { let y = p.getY(i) - 0.03; if (y < 0) y = 18; p.setY(i, y); p.setX(i, p.getX(i) + Math.sin(now / 900 + i) * 0.004); } p.needsUpdate = true; }
    updateEyes(now);
    if (WM) {   // the floating name drifts slowly around the middle of the picture (so it cannot just be cut off)
      const el = $('wm'), W = stage.clientWidth, H = stage.clientHeight, u = now / 1000;
      if (el.textContent !== WM) el.textContent = WM;
      const x = (0.5 + 0.32 * Math.sin(u * 0.23)) * W - el.offsetWidth / 2, y = (0.52 + 0.3 * Math.sin(u * 0.17 + 1.3)) * H;
      el.style.transform = `translate(${x}px, ${y}px) rotate(-8deg)`; wmX = x; wmY = y;
    }
    if (grass) grass.tick(now / 1000);
    renderer.render(scene, camera);
    rec.frame();
  };
  requestAnimationFrame(frame);

  /* ---------- the menu ---------- */
  const menu = $('menu'), teamsEl = $('teams'), winEl = $('win');
  const esc = (t) => String(t).replace(/[&<>"]/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[m]);
  const thumbCss = (id) => (photoUrl.has(id) ? `background-image:url("${photoUrl.get(id)}")` : 'background-image:none');
  let saved = DEFAULT_SIX;
  try { const s = JSON.parse(localStorage.getItem('fft_playerkick') || 'null'); if (Array.isArray(s) && s.length === 6) saved = s; } catch (e) { /* ignore */ }
  saved = saved.map((id, i) => (roster.some((p) => p.id === id) ? id : (roster[i] || roster[0]).id));
  const selects = [];
  const fillSelect = (s) => {   // players with a photo first
    const v = s.value, list = [...roster].sort((a, b) => (photos.has(b.id) - photos.has(a.id)));
    s.innerHTML = list.map((p) => `<option value="${esc(p.id)}">${photos.has(p.id) ? '📷 ' : ''}${esc(p.name)}</option>`).join('');
    if (v && roster.some((p) => p.id === v)) s.value = v;
  };
  for (let i = 0; i < 6; i++) {
    const l = document.createElement('label'); const f = document.createElement('span'); f.className = 'fl ph';
    const s = document.createElement('select'); fillSelect(s);
    s.value = saved[i]; f.style.cssText = thumbCss(s.value);
    s.addEventListener('change', () => { f.style.cssText = thumbCss(s.value); fillWin(); });
    l.append(f, s); teamsEl.appendChild(l); selects.push(s);
  }
  const refreshPicks = () => { selects.forEach((s) => { fillSelect(s); s.previousElementSibling.style.cssText = thumbCss(s.value); }); fillWin(); if (!playing) buildTeams(selects.slice(0, NP).map((x) => x.value)); };

  /* ---------- 👥 Players & photos: add a photo, rename, delete, add a new player ---------- */
  const pm = document.createElement('div'); pm.className = 'pm';
  pm.innerHTML = `<button type="button" class="pm-h" id="pmOpen">👥 Players &amp; photos <span id="pmCount"></span><b>▾</b></button>
  <div class="pm-b" hidden><p class="small muted">Tap 📷 to add a photo from your phone (it stays on this phone). Only players with a photo are picked by 🎲 Random (when there are 6 or more).</p>
  <input id="pmFind" placeholder="🔎 Find a player…">
  <div class="pm-list" id="pmList"></div>
  <div class="pm-add"><input id="pmName" maxlength="20" placeholder="New player name"><select id="pmC"></select><button type="button" id="pmAdd">➕ Add</button></div>
  <input type="file" id="pmFile" accept="image/*" hidden></div>`;
  $('win').closest('label').insertAdjacentElement('afterend', pm);
  const countries = C.map((c) => c.code).sort((a, b) => CNAME(a).localeCompare(CNAME(b)));
  pm.querySelector('#pmC').innerHTML = countries.map((c) => `<option value="${c}">${esc(CNAME(c))}</option>`).join('');
  pm.querySelector('#pmC').value = 'ar';
  let fileFor = null;
  const drawList = () => {
    const q = pm.querySelector('#pmFind').value.trim().toLowerCase();
    pm.querySelector('#pmCount').textContent = `(${photos.size} / ${roster.length} with photo)`;
    pm.querySelector('#pmList').innerHTML = roster.filter((p) => !q || p.name.toLowerCase().includes(q)).map((p) => `<div class="pm-r" data-id="${esc(p.id)}">
      <span class="pm-t" style='${thumbCss(p.id)}'>${photos.has(p.id) ? '' : '?'}</span>
      <input class="pm-n" maxlength="20" value="${esc(p.name)}"><input class="pm-num" type="number" min="1" max="99" value="${p.n}" title="Shirt number"><span class="pm-c">${esc(p.c.toUpperCase())}</span>
      <button type="button" data-a="photo">📷</button><button type="button" data-a="del" class="pm-x">🗑</button></div>`).join('');
  };
  pm.querySelector('#pmOpen').addEventListener('click', () => { const b = pm.querySelector('.pm-b'); b.hidden = !b.hidden; if (!b.hidden) drawList(); });
  pm.querySelector('#pmFind').addEventListener('input', drawList);
  pm.querySelector('#pmList').addEventListener('click', async (e) => {
    const b = e.target.closest('button'); if (!b) return;
    const id = b.closest('.pm-r').dataset.id;
    if (b.dataset.a === 'photo') { fileFor = id; pm.querySelector('#pmFile').click(); }
    if (b.dataset.a === 'del') {
      if (roster.length <= 6) { alert('Keep at least 6 players.'); return; }
      if (!confirm(`Delete ${NAME(id)}?`)) return;
      roster = roster.filter((p) => p.id !== id); saveRoster(); photos.delete(id);
      try { await photoTx('readwrite', (st) => st.delete(id)); } catch (err) { /* ignore */ }
      selects.forEach((s, i) => { if (s.value === id) s.value = roster.find((p) => !selects.some((x) => x.value === p.id)).id; });
      drawList(); refreshPicks();
    }
  });
  pm.querySelector('#pmList').addEventListener('change', (e) => {
    const nu = e.target.closest('.pm-num');
    if (nu) { const p = PL(nu.closest('.pm-r').dataset.id), v = Math.round(Number(nu.value)); if (v >= 1 && v <= 99) { p.n = v; saveRoster(); refreshPicks(); } nu.value = p.n; return; }
    const inp = e.target.closest('.pm-n'); if (!inp) return;
    const p = PL(inp.closest('.pm-r').dataset.id), v = inp.value.trim().toUpperCase().slice(0, 20);
    if (v) { p.name = v; saveRoster(); refreshPicks(); } inp.value = p.name;
  });
  pm.querySelector('#pmFile').addEventListener('change', async () => {
    const f = pm.querySelector('#pmFile').files[0], id = fileFor; pm.querySelector('#pmFile').value = '';
    if (!f || !id) return;
    let blob = f;
    try {   // keep a smaller copy (enough for the board): quicker and saves space on the phone
      const im = await createImageBitmap(f), s = Math.min(1, 640 / Math.max(im.width, im.height));
      const c = document.createElement('canvas'); c.width = Math.round(im.width * s); c.height = Math.round(im.height * s); c.getContext('2d').drawImage(im, 0, 0, c.width, c.height);
      blob = await new Promise((r) => c.toBlob(r, 'image/jpeg', 0.88)) || f;
    } catch (err) { alert('This file is not a picture.'); return; }
    try { await photoTx('readwrite', (st) => st.put(blob, id)); } catch (err) { alert('Could not keep the photo on this phone (storage full?).'); return; }
    await loadPhoto(id, blob); drawList(); refreshPicks();
  });
  pm.querySelector('#pmAdd').addEventListener('click', () => {
    const name = pm.querySelector('#pmName').value.trim().toUpperCase().slice(0, 20); if (!name) return;
    const id = 'u' + Date.now().toString(36);
    roster.unshift({ id, name, c: pm.querySelector('#pmC').value, n: 10, mine: true }); saveRoster();
    pm.querySelector('#pmName').value = ''; pm.querySelector('#pmFind').value = ''; drawList(); refreshPicks();
  });
  // control: ball speed, all kicks within N seconds, how messy (remembered on this phone)
  const setQuality = (q) => {
    sharp = q === 'sharp'; fit();
    const size = sharp ? 4096 : 2048;
    if (sun.shadow.mapSize.x !== size) { sun.shadow.mapSize.set(size, size); if (sun.shadow.map) { sun.shadow.map.dispose(); sun.shadow.map = null; } }
  };
  const ctl = () => ({ speed: Number($('speed').value) || 1, within: Number($('within').value) || 2, mess: Number($('mess').value), size: Number($('size').value) || 1, np: Number($('np').value) || 6, ball: Number($('ball').value) || 0.45, place: $('place').value, bcol: $('bcol').value, quality: $('quality').value, grass: $('grass3d').checked, chan: $('chan').value, kvol: Number($('kvol').value), tstyle: $('tstyle').value });
  const showCtl = () => {
    const sp = Number($('speed').value);
    $('speedV').textContent = (sp < 0.95 ? 'Slow' : sp > 1.05 ? 'Fast' : 'Normal') + ` (${sp.toFixed(1)}×)`;
    const w = Number($('within').value); $('withinV').textContent = `${w} second${w === 1 ? '' : 's'}`;
    const kv = Number($('kvol').value); $('kvolV').textContent = kv === 0 ? 'Off' : Math.round(kv * 100) + '%'; snd.kv = kv;
  };
  $('bcol').innerHTML = Object.entries(BALL_GROUPS).map(([k, v]) => `<option value="${k}">${v[0]}</option>`).join('') + '<option value="rand">🎲 New colours every START (unlimited)</option>';
  try {
    const c = JSON.parse(localStorage.getItem('fft_playerkick_ctl') || 'null');
    if (c) { if (c.speed) $('speed').value = c.speed; if (c.within) $('within').value = c.within; if (c.mess !== undefined) $('mess').value = String(c.mess); if (c.size) $('size').value = String(c.size); if (c.np >= 2 && c.np <= 6) $('np').value = String(c.np); if (c.ball) $('ball').value = String(c.ball); if (c.place && (THEMES[c.place] || c.place === 'rand')) $('place').value = c.place; if (c.bcol && (BALL_GROUPS[c.bcol] || c.bcol === 'rand')) $('bcol').value = c.bcol; if (c.quality) $('quality').value = c.quality; if (typeof c.grass === 'boolean') $('grass3d').checked = c.grass; if (typeof c.chan === 'string') $('chan').value = c.chan; if (typeof c.kvol === 'number' && c.kvol >= 0 && c.kvol <= 3) $('kvol').value = String(c.kvol); if (c.tstyle && [...$('tstyle').options].some((o) => o.value === c.tstyle)) $('tstyle').value = c.tstyle; }
  } catch (e) { /* ignore */ }
  const setBall = (r) => { if (r === R) return; R = r; ballGeo.dispose(); ballGeo = new THREE.SphereGeometry(R, 40, 28); play = null; ballGroup.clear(); };
  // the channel name: redraw the grass and the LED board with it
  let themeNow = 'night';
  const setChannel = (name) => {
    name = name.trim().slice(0, 30); if (name === WM) return; WM = name;
    applyTheme(themeNow);
    const t = makeLed(); led.material.map.dispose(); led.material.map = t; led.material.emissiveMap = t; led.material.needsUpdate = true;
    if (!WM) { $('wm').textContent = ''; }
  };
  // 📷 Camera & field and 🏆 Trophy: each has its own switch; switched off = the normal picture
  const camCtl = () => ({ on: $('camOn').checked, fov: Number($('camfov').value) || 50, z: Number($('camz').value) || 0, y: Number($('camy').value) || 0, gap: Number($('gap').value) || 0.2,
    cup: $('cupOn').checked, cz: Number($('cupz').value) || 0, cx: Number($('cupx').value) || 0, cs: Number($('cups').value) || 1 });
  const rebuild = () => { play = null; ballGroup.clear(); buildTeams(selects.slice(0, NP).map((x) => x.value)); };
  const showCam = () => {
    const c = camCtl();
    CAM.fov = c.on ? c.fov : 50; CAM.z = c.on ? c.z : 0; CAM.y = c.on ? c.y : 0;
    const gap = c.on ? c.gap : 0.2, cz = -1.5 + (c.cup ? c.cz : 0), cx = c.cup ? c.cx : 0, cs = c.cup ? c.cs : 1;
    const moved = gap !== GAP || cz !== CUP.z || cx !== CUP.x || cs !== CUP.s;
    GAP = gap; CUP.z = cz; CUP.x = cx; CUP.s = cs; CUP.r = 0.32 * cs;
    $('camBody').hidden = !c.on; $('cupBody').hidden = !c.cup;
    $('camfovV').textContent = c.fov <= 50 ? 'Normal' : `+${Math.round((c.fov - 50) / 22 * 100)}% wider`;
    $('camzV').textContent = c.z === 0 ? 'Normal' : c.z < 0 ? `${-c.z} closer` : `${c.z} further back`;
    $('camyV').textContent = c.y === 0 ? 'Normal' : c.y < 0 ? `${-c.y} lower` : `${c.y} higher`;
    $('gapV').textContent = c.gap <= 0.2 ? 'Normal' : `+${Math.round((c.gap - 0.2) * 100)}%`;
    $('cupzV').textContent = c.cz === 0 ? 'Normal' : c.cz > 0 ? `${c.cz} nearer to you` : `${-c.cz} nearer to the players`;
    $('cupxV').textContent = c.cx === 0 ? 'Middle' : c.cx < 0 ? `${-c.cx} left` : `${c.cx} right`;
    $('cupsV').textContent = c.cs === 1 ? 'Normal' : `${c.cs.toFixed(1)}×`;
    placeCup(); if (moved) rebuild();
    try { localStorage.setItem('fft_playerkick_cam', JSON.stringify(c)); } catch (e) { /* ignore */ }
  };
  try {
    const c = JSON.parse(localStorage.getItem('fft_playerkick_cam') || 'null');
    if (c) { $('camOn').checked = c.on !== false; $('camfov').value = c.fov; $('camz').value = c.z; $('camy').value = c.y; $('gap').value = c.gap; $('cupOn').checked = !!c.cup; if (c.cz !== undefined) { $('cupz').value = c.cz; $('cupx').value = c.cx; $('cups').value = c.cs; } }
  } catch (e) { /* ignore */ }
  showCam();
  ['camOn', 'camfov', 'camz', 'camy', 'gap', 'cupOn', 'cupz', 'cupx', 'cups'].forEach((id) => $(id).addEventListener(id.endsWith('On') ? 'change' : 'input', showCam));
  $('camReset').addEventListener('click', () => { $('camfov').value = 50; $('camz').value = 0; $('camy').value = 0; $('gap').value = 0.2; showCam(); });
  // 🏆 the design in the middle of the trophy (A gems, B wings, C star, plain)
  try { const v = localStorage.getItem('fft_playerkick_neck'); if (v && [...$('neck').options].some((o) => o.value === v)) $('neck').value = v; } catch (e) { /* ignore */ }
  setNeck($('neck').value);
  $('neck').addEventListener('change', () => { setNeck($('neck').value); try { localStorage.setItem('fft_playerkick_neck', $('neck').value); } catch (e) { /* ignore */ } });
  $('cupReset').addEventListener('click', () => { $('cupz').value = 0; $('cupx').value = 0; $('cups').value = 1; showCam(); });
  showCtl(); PS = ctl().size; setBall(ctl().ball);
  $('ball').addEventListener('change', () => setBall(ctl().ball)); themeNow = ctl().place; setChannel(ctl().chan); applyTheme(themeNow); setQuality(ctl().quality);
  // 🎲 unlimited looks: a new stadium and/or new ball colours (shown under the place)
  let randBall = null;
  const lookInfo = () => {
    const a = themeNow === 'rand' && randTh ? randTh.name : '', b = $('bcol').value === 'rand' && randBall ? randBall.name : '';
    $('lookInfo').textContent = [a, b].filter(Boolean).join(' · ');
  };
  const applyBall = (fresh) => {
    const v = $('bcol').value;
    if (v === 'rand') { if (fresh || !randBall) randBall = genBall(newSeed()); paintBalls(randBall); } else { const g = BALL_GROUPS[v] || BALL_GROUPS.classic; paintBalls({ base: g[1], patch: g[2] }); }
    lookInfo();
  };
  const newLook = () => {   // at START
    if (themeNow === 'rand') { randTh = genStadium(newSeed()); applyTheme('rand'); }
    if ($('bcol').value === 'rand') applyBall(true);
    lookInfo();
  };
  applyBall(false);
  grassOn = $('grass3d').checked; paintGrass();
  $('grass3d').addEventListener('change', () => { grassOn = $('grass3d').checked; paintGrass(); });
  $('bcol').addEventListener('change', () => applyBall(true));
  $('place').addEventListener('change', () => { themeNow = ctl().place; if (themeNow === 'rand') randTh = genStadium(newSeed()); applyTheme(themeNow); lookInfo(); });
  $('chan').addEventListener('change', () => setChannel(ctl().chan));
  $('quality').addEventListener('change', () => setQuality(ctl().quality));
  $('size').addEventListener('change', () => { PS = ctl().size; play = null; ballGroup.clear(); buildTeams(selects.slice(0, NP).map((x) => x.value)); });   // see the new size at once
  setStyle(ctl().tstyle); setWords($('t1').value, $('t2').value, false);
  $('tstyle').addEventListener('change', () => { setStyle(ctl().tstyle); setWords($('t1').value, $('t2').value, false); });
  $('kvol').addEventListener('change', () => { if (!$('snd').checked) return; snd.on = true; snd.start(); snd.kick(); });   // hear the new loudness at once
  ['speed', 'within', 'mess', 'size', 'np', 'ball', 'place', 'quality', 'chan', 'kvol', 'tstyle', 'bcol', 'grass3d'].forEach((id) => $(id).addEventListener('input', () => {
    showCtl(); try { localStorage.setItem('fft_playerkick_ctl', JSON.stringify(ctl())); } catch (e) { /* ignore */ }
  }));
  const fillWin = () => { const v = winEl.value; winEl.innerHTML = '<option value="-1">🎲 Random</option>' + selects.slice(0, NP).map((s, i) => `<option value="${i}">${NAME(s.value)}</option>`).join(''); winEl.value = [...winEl.options].some((o) => o.value === v) ? v : '-1'; };
  fillWin();
  // 👥 how many players: the boxes for the others are hidden, the line-up is built again at once
  const setNP = () => {
    NP = Math.max(2, Math.min(6, Number($('np').value) || 6));
    selects.forEach((s, i) => { s.closest('label').hidden = i >= NP; });
    fillWin(); play = null; ballGroup.clear(); buildTeams(selects.slice(0, NP).map((x) => x.value));
  };
  $('np').addEventListener('change', setNP);
  setNP();
  $('shuffle').addEventListener('click', () => {
    const withPhoto = roster.filter((p) => photos.has(p.id)).map((p) => p.id);
    const pool = (withPhoto.length >= 6 ? withPhoto : roster.map((p) => p.id)).sort(() => Math.random() - 0.5), pick = [];
    for (const c of pool) { if (pick.length >= 6) break; if (!pick.includes(c)) pick.push(c); }
    selects.forEach((s, i) => { s.value = pick[i]; s.dispatchEvent(new Event('change')); });
  });
  const mv = setupMyVoice({ game: 'playerkick', after: $('snd').closest('label'), stage });
  vr = voiceRun(mv, snd);
  rec = setupRecorder({
    game: 'playerkick', renderer, canvas, stage, words, sub: () => mv.subNow(), Sound, snd, fit, after: $('snd').closest('label'),
    cssUrl: document.querySelector('link[href*="playerkick.css"]').href,
    loop: () => LOOP,
    setSize: (w, h) => { renderer.setPixelRatio(1); renderer.setSize(w, h, false); },
    wm: () => { const el = $('wm'); return WM ? { text: WM, x: wmX, y: wmY, w: el.offsetWidth, h: el.offsetHeight, fs: parseFloat(getComputedStyle(el).fontSize) } : null; },
    onOpenMenu: () => { menu.hidden = false; },
  });
  bgm = setupBgMusic({ game: 'playerkick', after: $('snd').closest('label'), snd });
  // START: first a word on the screen (a hard play can take a few seconds to find), then the play
  $('go').addEventListener('click', () => { snd.on = $('snd').checked; snd.start(); $('answer').textContent = '⏳ Making the play…'; setTimeout(goNow, 30); });
  const goNow = () => {
    const codes = selects.slice(0, NP).map((s) => s.value);
    if (new Set(codes).size !== codes.length) { $('answer').textContent = `⚠️ Pick ${NP} different players.`; return; }
    try { localStorage.setItem('fft_playerkick', JSON.stringify(selects.map((s) => s.value))); } catch (e) { /* ignore */ }
    snd.on = $('snd').checked; snd.start();
    LOOP = $('loop').checked; vt = 0;
    newLook();   // 🎲 a new stadium / new ball colours when chosen
    if (!prepare(codes, Number(winEl.value))) { $('answer').textContent = 'Could not make a play — press START again.'; return; }
    $('answer').textContent = `Answer for this play (only you see it here): ${NAME(codes[play.winner])}`;
    setWords($('t1').value, $('t2').value, false);
    menu.hidden = true; $('tap').hidden = true;
    simT = 0; playing = false;
    if (rec.on()) {   // 🎥 Auto record: starts now, stops by itself at the end
      let went = false, bgOn = false;
      rec.start({
        go: () => { went = true; playing = true; },
        tick: (now, dt) => { if (!bgOn) { bgOn = true; bgm.play(); } tick(now, dt); },   // 🎵 from the first picture of the video
        redraw: () => renderer.render(scene, camera),
        done: () => went && !playing,
        progress: () => (play ? Math.min(1, simT / endT) : 0),
        finish: () => { last = 0; },
      });
      if (rec.mode() === 'live') bgm.play();
    } else { bgm.play(); setTimeout(() => { playing = true; }, 1200); }   // a moment to start the screen recording
  };
  stage.addEventListener('click', () => { if (!playing && !rec.active()) menu.hidden = false; });
  // a still picture behind the menu
  buildTeams(saved);
}
main();

/* ---------------------------------------------------------------- the phone app (install on the home screen, opens full screen) */
(() => {
  const btn = $('install'), help = $('apphelp');
  if ('serviceWorker' in navigator) navigator.serviceWorker.register('/playerkick/sw.js', { scope: '/playerkick/' }).catch(() => {});
  const inApp = matchMedia('(display-mode: fullscreen)').matches || matchMedia('(display-mode: standalone)').matches || navigator.standalone;
  if (inApp) { help.textContent = '✅ Running as the Player Kick app (full screen).'; return; }
  let ask = null;
  addEventListener('beforeinstallprompt', (e) => { e.preventDefault(); ask = e; btn.hidden = false; help.textContent = 'Install it once: then open Player Kick from your home screen — full screen, no browser bar in your recordings.'; });
  btn.addEventListener('click', async () => { if (!ask) return; ask.prompt(); const r = await ask.userChoice.catch(() => null); ask = null; btn.hidden = true; if (r && r.outcome === 'accepted') help.textContent = '✅ Installed. Open Player Kick from your home screen.'; });
  addEventListener('appinstalled', () => { btn.hidden = true; help.textContent = '✅ Installed. Open Player Kick from your home screen.'; });
  setTimeout(() => {
    if (ask) return;
    const ios = /iphone|ipad|ipod/i.test(navigator.userAgent);
    help.textContent = ios ? '📲 To get the app: in Safari tap Share ⬆ → "Add to Home Screen".' : '📲 To get the app: Chrome menu ⋮ → "Install app" (or "Add to Home screen").';
  }, 2500);
})();
