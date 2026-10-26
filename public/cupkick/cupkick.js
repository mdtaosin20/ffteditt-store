// FFTEdit · Cup Kick — a 3D scene for making Shorts (not for the 24/7 lives).
// Six countries line up; each player kicks two (identical) balls toward the cup at random moments; exactly ONE ball
// touches the cup, in a crowd of balls, shown a little slower; the viewers say in the comments whose ball it was.
// The owner picks the countries in the menu, presses START and records the screen. Tap the screen = menu again.
import * as THREE from 'three';
import { RoomEnvironment } from '/game/vendor/RoomEnvironment.js';

const $ = (id) => document.getElementById(id);
const DT = 1 / 120;
const CLOSE = new URLSearchParams(location.search).has('closeup');
const R = 0.38, CUP = { x: 0, z: -1.5, r: 0.32 }, PZ = -19;
let PS = 1;   // player size (menu → CONTROL): 1 = normal, 1.4 = bigger, 1.7 = biggest; bigger players stand further apart
const PX = (i) => (i - 2.5) * (1.58 * PS + 0.2);   // a player is 1.58 wide (with arms): keep a small gap so they never touch
const WALL = () => Math.max(6.2, PX(5) + 0.9);   // the side lines the balls bounce off
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
const DEFAULT = ['fr', 'br', 'tr', 'ar', 'pl', 'ma'];
const POPULAR = ['tr', 'br', 'fr', 'ar', 'pl', 'ma', 'us', 'gb', 'de', 'es', 'pt', 'it', 'in', 'pk', 'bd', 'dz', 'eg', 'sa', 'id', 'jp', 'kr', 'mx', 'nl', 'ng', 'ca'];

const rng = (s) => () => { s |= 0; s = s + 0x6D2B79F5 | 0; let t = Math.imul(s ^ s >>> 15, 1 | s); t = t + Math.imul(t ^ t >>> 7, 61 | t) ^ t; return ((t ^ t >>> 14) >>> 0) / 4294967296; };

/* ---------------------------------------------------------------- the play: all balls are kicked within a few seconds and roll on the grass,
   curling a little and knocking each other around; the first ball that touches the cup knocks it over, later balls bounce off the fallen cup */
const G = 9.8, CUP_H = 1.35;   // the cup is this high
const PLAY = { speed: 1, within: 2, mess: 1 };   // ball speed (1 = normal), all kicks within N seconds, how messy (0 = calm, 1 = normal, 2 = wild)
function simulate(seed, n, o = PLAY) {
  const rnd = rng(seed), balls = [], kicks = [], mess = o.mess, W = WALL();
  const order = []; for (let i = 0; i < n; i++) for (let k = 0; k < 2; k++) order.push([i, k]);
  for (let a = order.length - 1; a > 0; a--) { const c = Math.floor(rnd() * (a + 1)); [order[a], order[c]] = [order[c], order[a]]; }
  let t = 0.8;
  const aimX = 2.4 - mess * 0.5, aimZ = 1.1 - mess * 0.2;   // messier = the balls are aimed closer together, so they crash more
  for (const [i, k] of order) {
    const tx = (rnd() * 2 - 1) * aimX, tz = CUP.z + (rnd() * 2 - 1) * aimZ;
    const sx = PX(i) + (k ? -0.42 : 0.42) * PS, sz = PZ + 0.75 * PS;
    const d = Math.hypot(tx - sx, tz - sz), sp = (6.6 + rnd() * 2.0) * o.speed;
    const curl = (rnd() * 2 - 1) * 0.9 * mess * o.speed;   // the ball bends left or right a little while it rolls
    balls.push({ team: i, t, x: sx, y: R, z: sz, vx: (tx - sx) / d * sp, vy: 0, vz: (tz - sz) / d * sp, curl, on: false, id: balls.length });
    kicks.push({ team: i, t });
    t += o.within / (n * 2 - 1) * (0.4 + rnd() * 0.9);
  }
  const steps = Math.ceil((t + 3 + 22 / (7.6 * o.speed)) / DT), track = balls.map(() => new Float32Array(steps * 3));
  const touches = [], near = [], bumps = [], lands = [];
  let simT = 0, cupUp = true, fall = null;
  const lying = { ax: 0, az: 0, bx: 0, bz: 0, r: 0.3 };   // the fallen cup, as a fat stick on the grass
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
            lying.ax = CUP.x + fall.dx * 0.3; lying.az = CUP.z + fall.dz * 0.3;
            lying.bx = CUP.x + fall.dx * (0.3 + CUP_H - 0.15); lying.bz = CUP.z + fall.dz * (0.3 + CUP_H - 0.15);
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
  const start = (Math.random() * 1e9) | 0, travel = 17.5 / (7.6 * o.speed);
  const minT = 0.8 + travel + Math.min(1.5, o.within * 0.5), maxT = 0.8 + o.within + travel * 1.8 + 1;
  for (let s = start; s < start + 30000; s++) {
    const p = simulate(s, n, { ...o, maxT, want });
    if (!p || p.touches.length !== 1) continue;
    const T = p.touches[0].t;
    if (T < minT || T > maxT) continue;
    const w = p.balls[p.touches[0].id];
    if (want >= 0 && w.team !== want) continue;
    const close = new Set(p.near.filter((x) => x.id !== w.id && Math.abs(x.t - T) < 1.0).map((x) => x.id));
    if (close.size < 2) continue;
    return { ...p, touchT: T, winner: w.team };
  }
  return null;
}

/* ---------------------------------------------------------------- sound (made in the browser) */
class Sound {
  constructor() { this.ctx = null; this.on = true; }
  start() {
    if (!this.on) return;
    if (!this.ctx) this.ctx = new (window.AudioContext || window.webkitAudioContext)();
    this.ctx.resume();   // (no crowd noise in the background any more: only kicks, bumps and the cup sounds)
  }
  swell(v, secs) { if (!this.crowd) return; const g = this.crowd.gain, t = this.ctx.currentTime; g.cancelScheduledValues(t); g.setValueAtTime(g.value, t); g.linearRampToValueAtTime(v, t + 0.15); g.linearRampToValueAtTime(0.08, t + secs); }
  tone(f, f2, dur, vol, type = 'sine') {
    if (!this.ctx || !this.on) return;
    const c = this.ctx, o = c.createOscillator(), g = c.createGain(), t = c.currentTime;
    o.type = type; o.frequency.setValueAtTime(f, t); if (f2) o.frequency.exponentialRampToValueAtTime(f2, t + dur);
    g.gain.setValueAtTime(vol, t); g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
    o.connect(g).connect(c.destination); o.start(t); o.stop(t + dur + 0.02);
  }
  kick() { this.tone(140, 50, 0.18, 0.6); this.tone(900, 300, 0.05, 0.08, 'triangle'); }
  clank() { this.tone(520, 300, 0.25, 0.25, 'triangle'); this.tone(1650, 900, 0.3, 0.1, 'square'); this.tone(80, 40, 0.2, 0.4); }
  land(v) { this.tone(90, 40, 0.14, Math.min(0.5, v * 0.07)); }
  bump() { this.tone(320, 200, 0.07, 0.12, 'triangle'); }
  ding() { this.tone(1320, 0, 1.4, 0.25); this.tone(1980, 0, 1.0, 0.12); this.tone(2640, 0, 0.7, 0.06); this.swell(0.32, 2.5); }
  // everybody shouts together "HEYYY!": many voices (buzzing tones through mouth-like filters)
  win() {
    if (!this.ctx || !this.on) return;
    const c = this.ctx, t0 = c.currentTime, mix = c.createGain(); mix.gain.value = 1;
    const out = c.createGain(); out.gain.value = 0.9; out.connect(c.destination);
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
  const fit = () => { const w = stage.clientWidth, h = stage.clientHeight; renderer.setPixelRatio(Math.min(2, devicePixelRatio || 1)); renderer.setSize(w, h, false); };
  fit(); addEventListener('resize', fit);

  scene.add(new THREE.HemisphereLight('#dfe8ff', '#2c5a2c', 0.7));
  const sun = new THREE.DirectionalLight('#fff4e0', 2.0);
  sun.position.set(6, 14, 8); sun.castShadow = true; sun.shadow.mapSize.set(2048, 2048);
  sun.shadow.bias = -0.0008; sun.shadow.normalBias = 0.05;   // no flickering stripes of shadow on the players' arms and bodies
  Object.assign(sun.shadow.camera, { left: -14, right: 14, top: 14, bottom: -14, near: 1, far: 50 }); sun.target.position.set(0, 0, -8); scene.add(sun, sun.target);
  const tex = (w, h, draw) => { const c = document.createElement('canvas'); c.width = w; c.height = h; draw(c.getContext('2d'), w, h); const t = new THREE.CanvasTexture(c); t.colorSpace = THREE.SRGBColorSpace; t.anisotropy = 8; return t; };
  const R2 = rng(7);

  const fieldTex = tex(1024, 1024, (g, w, h) => {
    for (let i = 0; i < 16; i++) { g.fillStyle = i % 2 ? '#2f8f34' : '#287d2d'; g.fillRect(0, i * h / 16, w, h / 16); }
    g.strokeStyle = 'rgba(255,255,255,.9)'; g.lineWidth = 8; g.beginPath(); g.moveTo(0, 300); g.lineTo(w, 300); g.stroke();
    g.beginPath(); g.ellipse(512, 700, 300, 300, 0, 0, 7); g.stroke();
  });
  const field = new THREE.Mesh(new THREE.PlaneGeometry(40, 46), new THREE.MeshStandardMaterial({ map: fieldTex, roughness: 1 }));
  field.rotation.x = -Math.PI / 2; field.position.z = -8; field.receiveShadow = true; scene.add(field);
  const standMat = new THREE.MeshStandardMaterial({ color: '#3a4050', roughness: 0.9 });
  const crowdCols = ['#f5c518', '#2563eb', '#e11d48', '#f8fafc', '#16a34a', '#f97316'];
  const crowd = new THREE.InstancedMesh(new THREE.BoxGeometry(0.34, 0.5, 0.3), new THREE.MeshStandardMaterial({ roughness: 0.8 }), 3200);
  let ci = 0; const m4 = new THREE.Matrix4(), col = new THREE.Color(), crowdPos = [];
  for (let r = 0; r < 16; r++) {
    const z = -22 - r * 1.1, y = 0.6 + r * 0.9;
    const step = new THREE.Mesh(new THREE.BoxGeometry(40, 0.9, 1.1), standMat); step.position.set(0, y - 0.45, z); step.receiveShadow = true; scene.add(step);
    for (let k = 0; k < 96 && ci < 3200; k++) {
      if (R2() < 0.12) continue;
      const x = -19 + k * 0.42 + (r % 2) * 0.2; crowdPos.push([x, y + 0.25, z, R2() * 6]);
      m4.makeTranslation(x, y + 0.25, z); crowd.setMatrixAt(ci, m4); crowd.setColorAt(ci, col.set(crowdCols[(k * 7 + r * 3 + (R2() * 3 | 0)) % 6])); ci++;
    }
  }
  crowd.count = ci; scene.add(crowd);
  const roof = new THREE.Mesh(new THREE.BoxGeometry(44, 0.8, 14), new THREE.MeshStandardMaterial({ color: '#141a2e' })); roof.position.set(0, 16.2, -32); scene.add(roof);
  for (let k = 0; k < 9; k++) { const l = new THREE.Mesh(new THREE.BoxGeometry(2.2, 0.5, 0.3), new THREE.MeshBasicMaterial({ color: '#fffbe6' })); l.position.set(-16 + k * 4, 15.6, -25.2); scene.add(l); }
  const ledTex = tex(2048, 128, (g, w, h) => { g.fillStyle = '#0b1230'; g.fillRect(0, 0, w, h); g.fillStyle = '#ffd23a'; g.font = 'bold 74px Arial'; g.textBaseline = 'middle'; for (let x = 30; x < w; x += 520) g.fillText('★ FLAG CUP ★', x, h / 2); });
  ledTex.wrapS = THREE.RepeatWrapping; ledTex.repeat.set(2, 1);
  const led = new THREE.Mesh(new THREE.BoxGeometry(40, 0.9, 0.2), new THREE.MeshStandardMaterial({ map: ledTex, emissive: '#fff', emissiveMap: ledTex, emissiveIntensity: 0.5 })); led.position.set(0, 0.45, -21); scene.add(led);

  // flags
  const atlas = await new Promise((res) => { const im = new Image(); im.onload = () => res(im); im.src = '/flags/flags.png?v=2'; });
  const info = await (await fetch('/flags/flags.json?v=2')).json();
  const C = info.countries, idx = new Map(C.map((c, i) => [c.code, i]));
  const NAME = (code) => C[idx.get(code)].name;
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

  const ballTex = tex(1024, 512, (g, w, h) => {
    g.fillStyle = '#fafafa'; g.fillRect(0, 0, w, h);
    const ph = (1 + Math.sqrt(5)) / 2, v = [[-1, ph, 0], [1, ph, 0], [-1, -ph, 0], [1, -ph, 0], [0, -1, ph], [0, 1, ph], [0, -1, -ph], [0, 1, -ph], [ph, 0, -1], [ph, 0, 1], [-ph, 0, -1], [-ph, 0, 1]];
    g.fillStyle = '#151515';
    for (const [x, y, z] of v) {
      const l = Math.hypot(x, y, z), lat = Math.asin(y / l), lon = Math.atan2(z, x);
      const cx = (lon / (2 * Math.PI) + 0.5) * w, cy = (0.5 - lat / Math.PI) * h, r = 46, sx = 1 / Math.max(0.2, Math.cos(lat));
      for (const off of [-w, 0, w]) { g.beginPath(); for (let k = 0; k < 5; k++) { const a = -Math.PI / 2 + k * 2 * Math.PI / 5; g.lineTo(cx + off + Math.cos(a) * r * sx, cy + Math.sin(a) * r); } g.closePath(); g.fill(); }
    }
  });
  const ballMat = new THREE.MeshStandardMaterial({ map: ballTex, roughness: 0.45 });
  const ballGeo = new THREE.SphereGeometry(R, 40, 28);

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

  /* ---------- players (our own blocky style), made again for each set of countries ---------- */
  const skin = ['#f1c7a3', '#c68a5c', '#e8b48a', '#f3d1b5', '#8d5a3b', '#d9a77c'];
  const faceTex = (sk) => tex(256, 256, (g, w, h) => {
    g.fillStyle = sk; g.fillRect(0, 0, w, h); g.fillStyle = '#1b1b1b';
    g.beginPath(); g.ellipse(84, 110, 16, 22, 0, 0, 7); g.fill(); g.beginPath(); g.ellipse(172, 110, 16, 22, 0, 0, 7); g.fill();
    g.fillStyle = '#fff'; g.beginPath(); g.arc(89, 102, 6, 0, 7); g.fill(); g.beginPath(); g.arc(177, 102, 6, 0, 7); g.fill();
    g.strokeStyle = '#1b1b1b'; g.lineWidth = 10; g.lineCap = 'round'; g.beginPath(); g.arc(128, 150, 34, 0.35, Math.PI - 0.35); g.stroke();
    g.fillStyle = 'rgba(255,90,90,.25)'; g.beginPath(); g.arc(60, 150, 18, 0, 7); g.fill(); g.beginPath(); g.arc(196, 150, 18, 0, 7); g.fill();
  });
  const std = (c) => new THREE.MeshStandardMaterial({ color: c, roughness: 0.85 });   // matt, so arms do not shine
  const box = (w, h, d, mat) => { const m = new THREE.Mesh(new THREE.BoxGeometry(w, h, d), mat); m.castShadow = true; m.receiveShadow = true; return m; };
  let players = [];
  const team = new THREE.Group(); scene.add(team);
  const buildTeams = (codes) => {
    team.clear();
    players = codes.map((code, i) => {
      const [sh, trim, shorts, socks] = flagKit(code), sk = skin[i % 6], p = new THREE.Group();
      p.position.set(PX(i), 0, PZ); p.scale.setScalar(PS);
      const shirt = std(sh === 'stripes' ? '#bae6fd' : sh);
      const shirtM = new THREE.MeshStandardMaterial({ roughness: 0.85, map: tex(256, 256, (g, w, h) => {
        if (sh === 'stripes') { for (let k = 0; k < 8; k++) { g.fillStyle = k % 2 ? '#7dd3fc' : '#ffffff'; g.fillRect(k * 32, 0, 32, h); } } else { g.fillStyle = sh; g.fillRect(0, 0, w, h); }
        g.fillStyle = trim; g.fillRect(0, 0, w, 22);
        g.fillStyle = sh === 'stripes' ? '#111827' : trim; g.font = 'bold 120px Arial'; g.textAlign = 'center'; g.textBaseline = 'middle'; g.fillText(String([10, 7, 9, 11, 8, 5][i]), w / 2, h / 2 + 14);
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
      const hair = box(0.82, 0.2, 0.78, std(['#2b1d14', '#111', '#5b3a1e', '#111', '#c9a26b', '#111'][i])); hair.position.y = 3.15; p.add(hair);
      const frame = new THREE.Mesh(new THREE.PlaneGeometry(1.3, 1.0), new THREE.MeshBasicMaterial({ color: '#fff' })); frame.position.set(0, 4.05, -0.01); p.add(frame);
      const fl = new THREE.Mesh(new THREE.PlaneGeometry(1.2, 0.9), new THREE.MeshBasicMaterial({ map: tex(240, 180, (g) => drawFlag(g, code, 0, 0, 240, 180)) })); fl.position.set(0, 4.05, 0); p.add(fl);
      team.add(p);
      return { p, legL, legR, armL, armR, kicks: [] };
    });
  };
  const ballGroup = new THREE.Group(); scene.add(ballGroup);
  let meshes = [], quats = [];

  /* ---------- play ---------- */
  const snd = new Sound();
  let play = null, simT = 0, last = 0, playing = false, lastStep = 0, doneKick = new Set(), doneBump = 0, doneLand = 0, clanked = false, dinged = false, endT = 0;
  const axis = new THREE.Vector3(), dq = new THREE.Quaternion();
  const w1 = $('w1'), w2 = $('w2'), words = $('words');
  const setWords = (a, b, end) => { w1.textContent = a; w2.textContent = b; words.classList.toggle('end', end); w1.classList.remove('pop'); w2.classList.remove('pop'); void w1.offsetWidth; w1.classList.add('pop'); w2.classList.add('pop'); };

  const prepare = (codes, want) => {
    buildTeams(codes);
    play = findPlay(codes.length, want, ctl());
    if (!play) return false;
    players.forEach((pl, i) => { pl.kicks = play.kicks.filter((k) => k.team === i).map((k) => k.t); });
    ballGroup.clear();
    meshes = play.balls.map(() => { const m = new THREE.Mesh(ballGeo, ballMat); m.castShadow = true; ballGroup.add(m); return m; });
    quats = play.balls.map(() => new THREE.Quaternion().setFromEuler(new THREE.Euler(Math.random() * 6, Math.random() * 6, 0)));
    simT = 0; lastStep = 0; doneKick = new Set(); doneBump = 0; doneLand = 0; clanked = false; dinged = false;
    endT = play.touchT + 3.5;
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

  const frame = (now) => {
    requestAnimationFrame(frame);
    const real = Math.min(0.05, (now - (last || now)) / 1000); last = now;
    if (play && playing) {
      const near = simT > play.touchT - 0.6 && simT < play.touchT + 0.4;
      simT += real * (near ? SLOW : 1);
      if (simT >= endT) { simT = endT; playing = false; }
      // sounds at the right moments
      play.kicks.forEach((k, i) => { if (simT >= k.t && !doneKick.has(i)) { doneKick.add(i); snd.kick(); } });
      while (doneBump < play.bumps.length && play.bumps[doneBump] <= simT) { if (doneBump % 2 === 0) snd.bump(); doneBump++; }
      while (doneLand < play.lands.length && play.lands[doneLand].t <= simT) { snd.land(play.lands[doneLand].v); doneLand++; }
      if (!dinged && simT >= play.touchT) { dinged = true; snd.ding(); setWords($('t2').value.replace(/touches/i, 'touched').replace(/will touch/i, 'touched'), $('t3').value, true); }
    }
    const t = simT;
    if (play) {
      place(Math.min(play.steps - 2, Math.floor(t / DT)));
      players.forEach((pl, i) => {
        let a = 0;
        for (const k of pl.kicks) { const d = t - k; if (d > -0.3 && d < 0) a = -0.9 * Math.sin((d + 0.3) / 0.3 * Math.PI / 2); if (d >= 0 && d < 0.25) a = -0.9 * (1 - d / 0.25) + 1.1 * Math.sin(d / 0.25 * Math.PI); }
        const since = t - play.touchT;
        if (since > 0) {
          // the cup was touched: everybody jumps and dances, arms up in the air
          const go = Math.min(1, since / 0.25), ph = since * 9 + i * 1.3, hop = Math.abs(Math.sin(ph));
          pl.p.position.y = hop * 0.55 * go;
          pl.p.rotation.y = Math.sin(since * 5 + i) * 0.35 * go;
          pl.armL.rotation.set(0, 0, -(2.5 + Math.sin(ph * 2) * 0.35) * go); pl.armR.rotation.set(0, 0, (2.5 + Math.sin(ph * 2 + 1) * 0.35) * go);
          pl.legL.rotation.set(-(1 - hop) * 0.5 * go, 0, 0); pl.legR.rotation.set(-(1 - hop) * 0.5 * go, 0, 0);
          return;
        }
        pl.p.rotation.y = 0; pl.armL.rotation.z = 0; pl.armR.rotation.z = 0;
        pl.legR.rotation.x = -a; pl.legL.rotation.x = a * 0.2; pl.armL.rotation.x = a * 0.5; pl.armR.rotation.x = -a * 0.5;
        pl.p.position.y = Math.abs(Math.sin(now / 330 + i)) * 0.04;
      });
      const since = t - play.touchT;
      if (since > 0 && play.fall) {
        // falls over in ~0.5 s (faster and faster), a small bounce, then lies on the grass
        const f = play.fall, k = Math.min(1, since / 0.5), bounce = since > 0.5 && since < 0.75 ? Math.sin((since - 0.5) / 0.25 * Math.PI) * 0.12 : 0;
        cupPivot.position.set(CUP.x + f.dx * cupBase, 0.06, CUP.z + f.dz * cupBase); cup.position.set(-f.dx * cupBase, 0, -f.dz * cupBase);
        fallAxis.set(f.dz, 0, -f.dx); cupPivot.quaternion.setFromAxisAngle(fallAxis, k * k * (Math.PI / 2 - 0.12) - bounce);
        if (since > 0.5 && !clanked) { clanked = true; snd.clank(); snd.win(); }
      } else { cupPivot.position.set(CUP.x, 0.06, CUP.z); cup.position.set(0, 0, 0); cupPivot.quaternion.identity(); }
      ring.material.opacity = since > 0 && since < 0.6 ? 0.9 * (1 - since / 0.6) : 0; ring.scale.setScalar(since > 0 ? 1 + since * 3 : 1);
      const jump = since > 0 && since < 3 ? 0.25 : 0.06;
      for (let k = 0; k < ci; k++) { const [x, y, z, ph] = crowdPos[k]; m4.makeTranslation(x, y + Math.max(0, Math.sin(now / 140 + ph)) * jump, z); crowd.setMatrixAt(k, m4); }
      crowd.instanceMatrix.needsUpdate = true;
    }
    const k = play ? Math.min(1, simT / endT) : 0;
    camera.position.set(Math.sin(now / 3300) * 0.25, 3.2 - k * 0.5, 9.5 - k * 2.2); camera.lookAt(0, 2.6 - k * 0.6, -10);
    if (CLOSE) { camera.position.set(0.2, 0.9, 1.4); camera.lookAt(0, 0.62, -1.5); }   // ?closeup=1: a close look at the trophy
    renderer.render(scene, camera);
  };
  requestAnimationFrame(frame);

  /* ---------- the menu ---------- */
  const menu = $('menu'), teamsEl = $('teams'), winEl = $('win');
  const sorted = [...POPULAR.filter((c) => idx.has(c)), ...C.map((c) => c.code).filter((c) => !POPULAR.includes(c)).sort((a, b) => NAME(a).localeCompare(NAME(b)))];
  const flagCss = (code) => { const i = idx.get(code), rows = Math.ceil(C.length / info.cols); return `background-size:${info.cols * 30}px ${rows * 22}px;background-position:${-(i % info.cols) * 30}px ${-Math.floor(i / info.cols) * 22}px`; };
  let saved = DEFAULT;
  try { const s = JSON.parse(localStorage.getItem('fft_cupkick') || 'null'); if (Array.isArray(s) && s.length === 6 && s.every((c) => idx.has(c))) saved = s; } catch (e) { /* ignore */ }
  const selects = [];
  for (let i = 0; i < 6; i++) {
    const l = document.createElement('label'); const f = document.createElement('span'); f.className = 'fl';
    const s = document.createElement('select');
    s.innerHTML = sorted.map((c) => `<option value="${c}">${NAME(c)}</option>`).join('');
    s.value = saved[i]; f.style.cssText = flagCss(s.value);
    s.addEventListener('change', () => { f.style.cssText = flagCss(s.value); fillWin(); });
    l.append(f, s); teamsEl.appendChild(l); selects.push(s);
  }
  // control: ball speed, all kicks within N seconds, how messy (remembered on this phone)
  const ctl = () => ({ speed: Number($('speed').value) || 1, within: Number($('within').value) || 2, mess: Number($('mess').value), size: Number($('size').value) || 1 });
  const showCtl = () => {
    const sp = Number($('speed').value);
    $('speedV').textContent = (sp < 0.95 ? 'Slow' : sp > 1.05 ? 'Fast' : 'Normal') + ` (${sp.toFixed(1)}×)`;
    const w = Number($('within').value); $('withinV').textContent = `${w} second${w === 1 ? '' : 's'}`;
  };
  try {
    const c = JSON.parse(localStorage.getItem('fft_cupkick_ctl') || 'null');
    if (c) { if (c.speed) $('speed').value = c.speed; if (c.within) $('within').value = c.within; if (c.mess !== undefined) $('mess').value = String(c.mess); if (c.size) $('size').value = String(c.size); }
  } catch (e) { /* ignore */ }
  showCtl(); PS = ctl().size;
  $('size').addEventListener('change', () => { PS = ctl().size; play = null; ballGroup.clear(); buildTeams(selects.map((x) => x.value)); });   // see the new size at once
  ['speed', 'within', 'mess', 'size'].forEach((id) => $(id).addEventListener('input', () => {
    showCtl(); try { localStorage.setItem('fft_cupkick_ctl', JSON.stringify(ctl())); } catch (e) { /* ignore */ }
  }));
  const fillWin = () => { const v = winEl.value; winEl.innerHTML = '<option value="-1">🎲 Random</option>' + selects.map((s, i) => `<option value="${i}">${NAME(s.value)}</option>`).join(''); winEl.value = [...winEl.options].some((o) => o.value === v) ? v : '-1'; };
  fillWin();
  $('shuffle').addEventListener('click', () => {
    const pool = sorted.slice(0, 40).sort(() => Math.random() - 0.5), pick = [];
    for (const c of pool) { if (pick.length >= 6) break; if (!pick.includes(c)) pick.push(c); }
    selects.forEach((s, i) => { s.value = pick[i]; s.dispatchEvent(new Event('change')); });
  });
  $('go').addEventListener('click', () => {
    const codes = selects.map((s) => s.value);
    if (new Set(codes).size !== codes.length) { $('answer').textContent = '⚠️ Pick 6 different countries.'; return; }
    try { localStorage.setItem('fft_cupkick', JSON.stringify(codes)); } catch (e) { /* ignore */ }
    snd.on = $('snd').checked; snd.start();
    if (!prepare(codes, Number(winEl.value))) { $('answer').textContent = 'Could not make a play — press START again.'; return; }
    $('answer').textContent = `Answer for this play (only you see it here): ${NAME(codes[play.winner])}`;
    setWords($('t1').value, $('t2').value, false);
    menu.hidden = true; $('tap').hidden = true;
    simT = 0; playing = false;
    setTimeout(() => { playing = true; }, 1200);   // a moment to start the screen recording
  });
  stage.addEventListener('click', () => { if (!playing) menu.hidden = false; });
  // a still picture behind the menu
  buildTeams(saved);
}
main();
