// FFTEdit · Ball Drop Game (3D). Runs by itself, made for 24/7 vertical live streams.
import * as THREE from 'three';
import { RoomEnvironment } from './vendor/RoomEnvironment.js';

/* ------------------------------------------------------------------ config */
const DEFAULTS = {
  target: 20,
  players: [
    { name: 'MESSI',   team: 'PINK TEAM', color: '#ff2f9e', number: '10', photo: '', ball: '' },
    { name: 'RONALDO', team: 'GOLD TEAM', color: '#ffd21a', number: '7',  photo: '', ball: '' },
  ],
};

async function fetchConfig() {
  try {
    const r = await fetch('/api/game.php', { cache: 'no-store' });
    if (r.ok) return await r.json();
  } catch (e) { /* offline or not set up yet */ }
  return null;
}
async function loadConfig() {
  const c = await fetchConfig();
  if (!c) return { ...DEFAULTS, rev: '', reset: '' };
  const players = DEFAULTS.players.map((p, i) => {
    const q = { ...p };
    Object.entries((c.players || [])[i] || {}).forEach(([k, v]) => { if (v !== '' && v !== null && v !== undefined) q[k] = v; });
    return q;
  });
  return { ...DEFAULTS, ...c, players };
}

/* ------------------------------------------------------------ helpers */
const $ = (id) => document.getElementById(id);

function fitStage() {
  const s = Math.min(innerWidth / 1080, innerHeight / 1920);
  const stage = $('stage');
  stage.style.transform = `scale(${s}) translate(-50%, -50%)`;
  stage.style.transform = `translate(-50%, -50%) scale(${s})`;
  stage.style.transformOrigin = '50% 50%';
}

function placeholderPhoto(color, number, big) {
  // Simple silhouette used until a real photo is uploaded in the admin panel.
  const w = big ? 600 : 300, h = big ? 700 : 300;
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${w}" height="${h}" viewBox="0 0 600 700">
    <defs><radialGradient id="g" cx="50%" cy="35%" r="70%"><stop offset="0" stop-color="${color}" stop-opacity=".95"/><stop offset="1" stop-color="#0a0612"/></radialGradient></defs>
    <rect width="600" height="700" fill="url(#g)"/>
    <circle cx="300" cy="250" r="120" fill="#0b0712" opacity=".85"/>
    <path d="M90 700 C110 500 200 420 300 420 C400 420 490 500 510 700Z" fill="#0b0712" opacity=".85"/>
    <text x="300" y="640" text-anchor="middle" font-family="Arial Black,Arial" font-weight="900" font-size="150" fill="${color}" opacity=".9">${number}</text>
  </svg>`;
  return 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
}

function loadImage(src) {
  return new Promise((res) => {
    if (!src) return res(null);
    const im = new Image();
    im.crossOrigin = 'anonymous';
    im.onload = () => res(im);
    im.onerror = () => res(null);
    im.src = src;
  });
}

/* ------------------------------------------------------- stadium background */
function drawBackground(players) {
  const c = $('bg').getContext('2d');
  const W = 1080, H = 1920;
  c.fillStyle = '#07050d'; c.fillRect(0, 0, W, H);
  const glow = (x, y, r, col, a) => {
    const g = c.createRadialGradient(x, y, 0, x, y, r);
    g.addColorStop(0, col + Math.round(a * 255).toString(16).padStart(2, '0'));
    g.addColorStop(1, col + '00');
    c.fillStyle = g; c.fillRect(0, 0, W, H);
  };
  glow(0, 300, 900, players[0].color, 0.55);
  glow(W, 300, 900, players[1].color, 0.5);
  glow(W / 2, 1450, 700, '#5a3cff', 0.18);
  glow(0, 1600, 600, players[0].color, 0.35);
  glow(W, 1600, 600, players[1].color, 0.32);
  // stadium light banks
  let seed = 7;
  const rnd = () => ((seed = (seed * 16807) % 2147483647) / 2147483647);
  const bank = (x0, y0, cols, rows, col) => {
    for (let i = 0; i < cols; i++) for (let j = 0; j < rows; j++) {
      const x = x0 + i * 26, y = y0 + j * 22;
      c.fillStyle = col; c.globalAlpha = 0.55 + rnd() * 0.45;
      c.fillRect(x, y, 14, 10);
    }
    c.globalAlpha = 1;
  };
  c.shadowBlur = 18;
  c.shadowColor = '#ffffff'; bank(30, 210, 4, 2, '#fff');  bank(60, 1040, 4, 2, '#ffd9f0');
  bank(900, 210, 4, 2, '#fff');  bank(880, 1040, 4, 2, '#fff6cf');
  c.shadowBlur = 0;
  // bokeh dots
  for (let i = 0; i < 70; i++) {
    const left = rnd() < 0.5;
    const x = left ? rnd() * 420 : W - rnd() * 420, y = rnd() * H;
    const r = 3 + rnd() * 9;
    c.fillStyle = (left ? players[0].color : players[1].color);
    c.globalAlpha = 0.12 + rnd() * 0.3;
    c.beginPath(); c.arc(x, y, r, 0, Math.PI * 2); c.fill();
  }
  c.globalAlpha = 1;
}

/* ------------------------------------------------------------- bowl geometry */
const RIM_R = 4.75;     // inner edge of the flat lip
const HOLE_R = 0.82;    // radius of the hole
const DEPTH = 1.75;     // how deep the bowl goes at the hole
const BALL_R = 0.42;
const SPAN = RIM_R - HOLE_R;

// Height of the bowl surface at distance r from the centre (smooth funnel).
function surfaceY(r) {
  const t = Math.min(1, Math.max(0, (RIM_R - r) / SPAN));
  return 0.3 - DEPTH * Math.pow(t, 2.3);
}
function surfaceSlope(r) { // dy/dr (positive: rises outward)
  const t = Math.min(1, Math.max(0, (RIM_R - r) / SPAN));
  return (DEPTH * 2.3 * Math.pow(t, 1.3)) / SPAN;
}

function buildBowl(scene, players) {
  const group = new THREE.Group();
  scene.add(group);

  // Glossy white bowl (lathe of the profile)
  const pts = [];
  pts.push(new THREE.Vector2(HOLE_R - 0.02, surfaceY(HOLE_R) - 1.6));
  for (let i = 0; i <= 80; i++) {
    const r = HOLE_R + (SPAN * i) / 80;
    pts.push(new THREE.Vector2(r, surfaceY(r)));
  }
  pts.push(new THREE.Vector2(5.05, 0.34));
  pts.push(new THREE.Vector2(5.35, 0.22));
  pts.push(new THREE.Vector2(5.4, 0.0));
  const bowlGeo = new THREE.LatheGeometry(pts, 160);
  const bowlMat = new THREE.MeshPhysicalMaterial({
    color: 0xeceaf2, roughness: 0.32, metalness: 0.0, clearcoat: 1.0, clearcoatRoughness: 0.08,
    side: THREE.DoubleSide,
  });
  const bowl = new THREE.Mesh(bowlGeo, bowlMat);
  group.add(bowl);

  // Dark inside of the hole
  const hole = new THREE.Mesh(
    new THREE.CylinderGeometry(HOLE_R - 0.03, HOLE_R - 0.03, 1.6, 64, 1, true),
    new THREE.MeshBasicMaterial({ color: 0x050308, side: THREE.BackSide }),
  );
  hole.position.y = surfaceY(HOLE_R) - 0.8;
  group.add(hole);
  const holeBottom = new THREE.Mesh(new THREE.CircleGeometry(HOLE_R, 48), new THREE.MeshBasicMaterial({ color: 0x000000 }));
  holeBottom.rotation.x = -Math.PI / 2; holeBottom.position.y = surfaceY(HOLE_R) - 1.55;
  group.add(holeBottom);

  // Neon ring around the hole: half pink, half gold
  const c1 = new THREE.Color(players[0].color), c2 = new THREE.Color(players[1].color);
  const ringY = surfaceY(HOLE_R + 0.12) + 0.04;
  const arc = (rad, tube, col, start, y) => {
    const m = new THREE.Mesh(new THREE.TorusGeometry(rad, tube, 12, 90, Math.PI), new THREE.MeshBasicMaterial({ color: col }));
    m.rotation.x = Math.PI / 2; m.rotation.z = start; m.position.y = y;
    group.add(m); return m;
  };
  arc(HOLE_R + 0.12, 0.06, c1, Math.PI / 2, ringY);
  arc(HOLE_R + 0.12, 0.06, c2, -Math.PI / 2, ringY);

  // Neon on the outer rim
  arc(5.36, 0.07, c1, Math.PI / 2, 0.16);
  arc(5.36, 0.07, c2, -Math.PI / 2, 0.16);

  // Base band with the team panels
  const band = new THREE.Mesh(
    new THREE.CylinderGeometry(5.4, 5.25, 1.7, 160, 1, true, Math.PI, Math.PI * 2),
    new THREE.MeshStandardMaterial({ map: bandTexture(players), roughness: 0.5, metalness: 0.2, emissive: 0xffffff, emissiveMap: bandTexture(players, true), emissiveIntensity: 1.0 }),
  );
  band.position.y = -0.85;
  group.add(band);
  arc(5.27, 0.09, c1, Math.PI / 2, -1.7);
  arc(5.27, 0.09, c2, -Math.PI / 2, -1.7);
  const floor = new THREE.Mesh(new THREE.CircleGeometry(5.3, 64), new THREE.MeshBasicMaterial({ color: 0x0a0812 }));
  floor.rotation.x = -Math.PI / 2; floor.position.y = -1.69;
  group.add(floor);

  // Soft glow under the bowl
  const glow = new THREE.Sprite(new THREE.SpriteMaterial({ map: glowTexture('#7a5cff'), transparent: true, opacity: 0.35, depthWrite: false, blending: THREE.AdditiveBlending }));
  glow.scale.set(16, 6, 1); glow.position.set(0, -2.2, 0.5);
  group.add(glow);
  return group;
}

function bandTexture(players, emissive) {
  const cv = document.createElement('canvas'); cv.width = 4096; cv.height = 256;
  const c = cv.getContext('2d');
  c.fillStyle = emissive ? '#000' : '#14111f'; c.fillRect(0, 0, 4096, 256);
  if (!emissive) {
    const g = c.createLinearGradient(0, 0, 0, 256); g.addColorStop(0, 'rgba(255,255,255,.08)'); g.addColorStop(1, 'rgba(0,0,0,.4)');
    c.fillStyle = g; c.fillRect(0, 0, 4096, 256);
  }
  const panel = (cx, col, text, skew) => {
    const w = 640, h = 150, x = cx - w / 2, y = 52;
    c.save();
    c.translate(cx, y + h / 2); c.transform(1, 0, skew, 1, 0, 0); c.translate(-cx, -(y + h / 2));
    c.fillStyle = emissive ? col + '33' : '#1c0f22';
    c.strokeStyle = col; c.lineWidth = 10;
    c.beginPath(); c.roundRect(x, y, w, h, 18); c.fill(); c.stroke();
    c.font = 'italic 900 112px Oswald, Impact, Arial Black, sans-serif';
    c.textAlign = 'center'; c.textBaseline = 'middle';
    c.fillStyle = col; c.shadowColor = col; c.shadowBlur = emissive ? 30 : 0;
    c.fillText(text, cx, y + h / 2 + 6);
    c.restore();
  };
  // u = 0.5 is the front of the bowl (facing the camera)
  panel(4096 * 0.41, players[0].color, players[0].name, -0.25);
  panel(4096 * 0.59, players[1].color, players[1].name, 0.25);
  // football emblem in the middle
  const ex = 2048, ey = 128;
  c.fillStyle = emissive ? '#223' : '#0c0a14'; c.strokeStyle = '#8fa8ff'; c.lineWidth = 8;
  c.beginPath(); c.arc(ex, ey, 92, 0, Math.PI * 2); c.fill(); c.stroke();
  if (!emissive) drawFootball(c, ex, ey, 64);
  const t = new THREE.CanvasTexture(cv);
  t.colorSpace = THREE.SRGBColorSpace; t.anisotropy = 4;
  return t;
}

function drawFootball(c, x, y, R) {
  const g = c.createRadialGradient(x - R * 0.3, y - R * 0.35, R * 0.1, x, y, R);
  g.addColorStop(0, '#ffffff'); g.addColorStop(0.65, '#e2e6f1'); g.addColorStop(1, '#8a90a6');
  c.save();
  c.beginPath(); c.arc(x, y, R, 0, Math.PI * 2); c.fillStyle = g; c.fill(); c.clip();
  const pent = (cx, cy, r, rot) => {
    c.beginPath();
    for (let i = 0; i < 5; i++) { const a = rot + (i * 2 * Math.PI) / 5; c.lineTo(cx + r * Math.cos(a), cy + r * Math.sin(a)); }
    c.closePath(); c.fill();
  };
  c.fillStyle = '#111';
  pent(x, y, R * 0.3, -Math.PI / 2);
  for (let i = 0; i < 5; i++) {
    const a = -Math.PI / 2 + (i * 2 * Math.PI) / 5;
    pent(x + R * 0.88 * Math.cos(a), y + R * 0.88 * Math.sin(a), R * 0.3, a + Math.PI);
  }
  c.restore();
}

function glowTexture(color) {
  const cv = document.createElement('canvas'); cv.width = cv.height = 128;
  const c = cv.getContext('2d');
  const g = c.createRadialGradient(64, 64, 0, 64, 64, 64);
  g.addColorStop(0, color); g.addColorStop(0.35, color + 'aa'); g.addColorStop(1, color + '00');
  c.fillStyle = g; c.fillRect(0, 0, 128, 128);
  const t = new THREE.CanvasTexture(cv); t.colorSpace = THREE.SRGBColorSpace;
  return t;
}

/* ------------------------------------------------------------------- balls */
function ballTexture(p, img) {
  const cv = document.createElement('canvas'); cv.width = 1024; cv.height = 512;
  const c = cv.getContext('2d');
  const base = new THREE.Color(p.color);
  const g = c.createLinearGradient(0, 0, 0, 512);
  g.addColorStop(0, '#' + base.clone().lerp(new THREE.Color('#ffffff'), 0.35).getHexString());
  g.addColorStop(0.5, p.color);
  g.addColorStop(1, '#' + base.clone().multiplyScalar(0.55).getHexString());
  c.fillStyle = g; c.fillRect(0, 0, 1024, 512);
  // face on the front, number on the back
  const face = (cx) => {
    c.save(); c.beginPath(); c.arc(cx, 256, 150, 0, Math.PI * 2); c.clip();
    if (img) {
      const s = Math.max(300 / img.width, 300 / img.height);
      c.globalAlpha = 0.9; c.globalCompositeOperation = 'multiply';
      c.drawImage(img, cx - (img.width * s) / 2, 256 - (img.height * s) / 2, img.width * s, img.height * s);
    } else {
      c.fillStyle = 'rgba(0,0,0,.55)';
      c.beginPath(); c.arc(cx, 220, 60, 0, Math.PI * 2); c.fill();
      c.beginPath(); c.ellipse(cx, 380, 110, 90, 0, Math.PI, 0); c.fill();
    }
    c.restore();
  };
  face(256);
  face(768);
  c.font = '900 120px Oswald, Impact, Arial Black, sans-serif';
  c.textAlign = 'center'; c.textBaseline = 'middle';
  c.fillStyle = 'rgba(0,0,0,.75)';
  c.fillText(p.number, 256 - 190, 256); c.fillText(p.number, 768 + 190, 256);
  const t = new THREE.CanvasTexture(cv); t.colorSpace = THREE.SRGBColorSpace; t.anisotropy = 4;
  return t;
}

class Ball {
  constructor(scene, idx, p, img) {
    this.idx = idx;
    this.color = new THREE.Color(p.color);
    this.mesh = new THREE.Mesh(
      new THREE.SphereGeometry(BALL_R, 32, 20),
      new THREE.MeshStandardMaterial({ map: ballTexture(p, img), roughness: 0.25, metalness: 0.05, emissive: this.color, emissiveIntensity: 0.18 }),
    );
    scene.add(this.mesh);
    this.halo = new THREE.Sprite(new THREE.SpriteMaterial({ map: glowTexture(p.color), transparent: true, depthWrite: false, blending: THREE.AdditiveBlending, opacity: 0.85 }));
    this.halo.scale.set(2.2, 2.2, 1);
    scene.add(this.halo);
    this.trail = [];
    const tmat = new THREE.SpriteMaterial({ map: glowTexture(p.color), transparent: true, depthWrite: false, blending: THREE.AdditiveBlending });
    for (let i = 0; i < 18; i++) {
      const s = new THREE.Sprite(tmat.clone()); s.visible = false; scene.add(s); this.trail.push(s);
    }
    this.history = [];
    this.pos = new THREE.Vector2(); this.vel = new THREE.Vector2(); this.y = 0;
    this.state = 'hidden'; this.t = 0;
  }

  drop(angle, delay) {
    const r = RIM_R - 0.55;
    this.pos.set(Math.cos(angle) * r, Math.sin(angle) * r);
    // speed close to a circular orbit, with a little randomness so every round is different
    const s = surfaceSlope(r), g = 9.8;
    const v = Math.sqrt((r * g * s) / (1 + s * s)) * (0.9 + Math.random() * 0.22);
    const dir = Math.random() < 0.5 ? 1 : -1;
    this.vel.set(-Math.sin(angle) * v * dir, Math.cos(angle) * v * dir).multiplyScalar(1).add(new THREE.Vector2(-Math.cos(angle), -Math.sin(angle)).multiplyScalar(0.3 * Math.random()));
    this.state = 'dropping'; this.t = -delay; this.history.length = 0;
    this.mesh.visible = this.halo.visible = true;
    this.mesh.scale.setScalar(1);
  }

  hide() { this.state = 'hidden'; this.mesh.visible = this.halo.visible = false; this.trail.forEach((s) => (s.visible = false)); }

  step(dt) {
    if (this.state === 'dropping') {
      this.t += dt;
      const r = this.pos.length(), ground = surfaceY(r) + BALL_R;
      if (this.t < 0) { this.y = ground + 6; this.mesh.visible = this.halo.visible = false; return; }
      this.mesh.visible = this.halo.visible = true;
      const T = 0.7; // fall + one bounce
      if (this.t < T) {
        const k = this.t / T;
        this.y = ground + 6 * (1 - k) * (1 - k);
      } else if (this.t < T + 0.35) {
        const k = (this.t - T) / 0.35;
        this.y = ground + 0.7 * Math.sin(Math.PI * k);
      } else { this.y = ground; this.state = 'rolling'; }
      return;
    }
    if (this.state === 'rolling') {
      const r = Math.max(0.0001, this.pos.length());
      const s = surfaceSlope(r);
      const a = (-9.8 * s) / (1 + s * s);
      this.vel.x += (a * this.pos.x) / r * dt;
      this.vel.y += (a * this.pos.y) / r * dt;
      const drag = 0.085 + 0.02 * this.vel.length();
      this.vel.multiplyScalar(Math.max(0, 1 - drag * dt));
      this.pos.addScaledVector(this.vel, dt);
      const nr = this.pos.length();
      if (nr > RIM_R - BALL_R) { // bounce off the lip
        const n = this.pos.clone().divideScalar(nr);
        const vn = this.vel.dot(n);
        if (vn > 0) this.vel.addScaledVector(n, -1.6 * vn);
        this.pos.copy(n.multiplyScalar(RIM_R - BALL_R));
      }
      this.y = surfaceY(this.pos.length()) + BALL_R;
      if (this.pos.length() < HOLE_R - 0.12) { this.state = 'falling'; this.t = 0; }
      return;
    }
    if (this.state === 'falling') {
      this.t += dt;
      this.pos.multiplyScalar(Math.max(0, 1 - 6 * dt));
      this.y -= (2 + 14 * this.t) * dt;
      this.mesh.scale.setScalar(Math.max(0.2, 1 - this.t * 0.9));
      if (this.t > 0.7) this.hide(), (this.state = 'scored');
    }
  }

  render(dt) {
    if (this.state === 'hidden' || this.state === 'scored') return;
    this.mesh.position.set(this.pos.x, this.y, this.pos.y);
    // roll: rotate around the axis perpendicular to the motion
    const sp = this.vel.length();
    if (sp > 0.001 && this.state === 'rolling') {
      const axis = new THREE.Vector3(this.vel.y, 0, -this.vel.x).normalize();
      this.mesh.quaternion.premultiply(new THREE.Quaternion().setFromAxisAngle(axis, (sp * dt) / BALL_R));
    }
    this.halo.position.copy(this.mesh.position);
    this.halo.material.opacity = this.state === 'falling' ? Math.max(0, 0.85 - this.t) : 0.75;
    // trail
    this.history.unshift(this.mesh.position.clone());
    if (this.history.length > this.trail.length * 2) this.history.length = this.trail.length * 2;
    this.trail.forEach((s, i) => {
      const h = this.history[i * 2 + 2];
      if (!h || this.state !== 'rolling') { s.visible = false; return; }
      s.visible = true; s.position.copy(h);
      const k = 1 - i / this.trail.length;
      s.scale.setScalar(1.25 * k + 0.2);
      s.material.opacity = 0.42 * k * Math.min(1, sp / 2);
    });
  }
}

function collide(a, b) {
  if (a.state !== 'rolling' || b.state !== 'rolling') return;
  const d = b.pos.clone().sub(a.pos), dist = d.length(), min = BALL_R * 2;
  if (dist === 0 || dist >= min) return;
  const n = d.divideScalar(dist);
  const overlap = (min - dist) / 2;
  a.pos.addScaledVector(n, -overlap); b.pos.addScaledVector(n, overlap);
  const rel = b.vel.clone().sub(a.vel).dot(n);
  if (rel < 0) {
    const j = -(1 + 0.85) * rel / 2;
    a.vel.addScaledVector(n, -j); b.vel.addScaledVector(n, j);
  }
}

/* ------------------------------------------------------------------- main */
async function main() {
  fitStage();
  addEventListener('resize', fitStage);
  const cfg = await loadConfig();
  const P = cfg.players;

  // texts and photos
  P.forEach((p, i) => {
    const n = i + 1;
    const hero = p.photo || placeholderPhoto(p.color, p.number, true);
    const av = p.photo || placeholderPhoto(p.color, p.number, false);
    $(`p${n}-hero`).src = hero; $(`p${n}-avatar`).src = av;
    $(`p${n}-name`).textContent = p.name; $(`p${n}-team`).textContent = p.team;
    $(`p${n}-title`).textContent = p.name; $(`p${n}-foot`).textContent = p.name;
  });
  $('target').innerHTML = `FIRST TO <b>${cfg.target}</b> WINS`;
  // Shrink text that is too wide (long names, or before the font has loaded).
  const fitAll = () => {
    const h1 = document.querySelector('h1');
    h1.style.transform = 'skewX(-6deg)';
    if (h1.offsetWidth > 1030) h1.style.transform = `skewX(-6deg) scale(${1030 / h1.offsetWidth})`;
    document.querySelectorAll('.who').forEach((el) => {
      const inner = el.firstElementChild.parentElement;
      el.style.transform = '';
      const k = el.clientWidth / el.scrollWidth;
      if (k < 1) el.style.transform = `scale(${k})`;
    });
    const f = document.querySelector('.footer-in');
    f.style.transform = '';
    if (f.offsetWidth > 1020) f.style.transform = `scale(${1020 / f.offsetWidth})`;
  };
  fitAll(); document.fonts && document.fonts.ready.then(fitAll);
  drawBackground(P);

  // scores survive a page reload (the stream browser restarting)
  // Scores survive a page reload; the admin "Reset scores" button changes cfg.reset.
  let score = [0, 0];
  try {
    const s = JSON.parse(localStorage.getItem('fft_score') || 'null');
    if (s && Array.isArray(s.score) && s.reset === cfg.reset) score = s.score;
  } catch (e) {}
  const saveScore = () => { try { localStorage.setItem('fft_score', JSON.stringify({ score, reset: cfg.reset })); } catch (e) {} };
  saveScore();

  // Every 20 s: reload when the admin changed names/photos, reset when asked.
  setInterval(async () => {
    const c = await fetchConfig();
    if (!c) return;
    if (c.reset !== cfg.reset) {
      cfg.reset = c.reset; score = [0, 0]; saveScore(); showScore();
      $('winner').hidden = true;
    }
    if (c.rev !== cfg.rev) location.reload();
  }, 20000);
  const showScore = (bump) => {
    $('p1-score').textContent = score[0]; $('p2-score').textContent = score[1];
    $('p1-crown').classList.toggle('on', score[0] > score[1]);
    $('p2-crown').classList.toggle('on', score[1] > score[0]);
    if (bump !== undefined) {
      const el = $(`p${bump + 1}-score`); el.classList.add('bump'); setTimeout(() => el.classList.remove('bump'), 260);
    }
  };
  showScore();

  // three.js
  // The bowl never moves, so it is drawn once in high quality ("baked") into a picture.
  // Every frame only the balls are drawn on top. This keeps the stream smooth on servers
  // without a graphics card.
  const canvas = $('three');
  const renderer = new THREE.WebGLRenderer({ canvas, antialias: false, alpha: true, powerPreference: 'high-performance' });
  // ?q=0.5 draws the balls at half resolution (lighter for small servers)
  const Q = Math.min(2, Math.max(0.3, parseFloat(new URLSearchParams(location.search).get('q')) || 1));
  const DPR = Math.min(devicePixelRatio || 1, 1.5);
  renderer.setPixelRatio(DPR * Q);
  const setupRenderer = (r) => {
    r.toneMapping = THREE.ACESFilmicToneMapping;
    r.toneMappingExposure = 0.82;
    r.outputColorSpace = THREE.SRGBColorSpace;
  };
  setupRenderer(renderer);
  const scene = new THREE.Scene();
  scene.environment = new THREE.PMREMGenerator(renderer).fromScene(new RoomEnvironment(), 0.04).texture;
  scene.environmentIntensity = 0.4;

  const camera = new THREE.PerspectiveCamera(30, 1080 / 860, 0.1, 100);
  camera.position.set(0, 11.6, 15.6);
  camera.lookAt(0, -0.9, 0);

  scene.add(new THREE.HemisphereLight(0xffffff, 0x221133, 0.35));
  const key = new THREE.DirectionalLight(0xffffff, 0.9); key.position.set(0, 12, 9); scene.add(key);
  const pl = new THREE.PointLight(new THREE.Color(P[0].color), 40, 18); pl.position.set(-6.5, 3, 3); scene.add(pl);
  const gl = new THREE.PointLight(new THREE.Color(P[1].color), 40, 18); gl.position.set(6.5, 3, 3); scene.add(gl);

  const bowl = buildBowl(scene, P);
  // remember the real look; during play the bowl only blocks balls (e.g. inside the hole)
  const looks = [];
  const depthOnly = new THREE.MeshBasicMaterial({ colorWrite: false });
  bowl.traverse((o) => { if (o.isMesh || o.isSprite) looks.push([o, o.material]); });
  const playMode = () => looks.forEach(([o]) => { if (o.isSprite) o.visible = false; else o.material = depthOnly; });
  const lookMode = () => looks.forEach(([o, m]) => { o.visible = true; o.material = m; });

  let balls = [];
  const bake = () => {
    const r = canvas.getBoundingClientRect();
    const w = Math.max(1, Math.round(r.width)), h = Math.max(1, Math.round(r.height));
    const bc = document.createElement('canvas');
    const br = new THREE.WebGLRenderer({ canvas: bc, antialias: true, alpha: true, preserveDrawingBuffer: true });
    setupRenderer(br);
    br.setPixelRatio(Math.min(2, DPR * 1.5));
    br.setSize(w, h, false);
    const pm = new THREE.PMREMGenerator(br);
    const env = pm.fromScene(new RoomEnvironment(), 0.04).texture;
    const keepEnv = scene.environment;
    scene.environment = env;
    const shown = balls.map((b) => [b.mesh.visible, b.halo.visible]);
    balls.forEach((b) => { b.mesh.visible = b.halo.visible = false; b.trail.forEach((s) => (s.visible = false)); });
    lookMode();
    br.render(scene, camera);
    $('bowl-img').src = bc.toDataURL('image/png');
    playMode();
    balls.forEach((b, i) => { b.mesh.visible = shown[i][0]; b.halo.visible = shown[i][1]; });
    scene.environment = keepEnv;
    env.dispose(); pm.dispose(); br.dispose(); br.forceContextLoss();
  };

  const imgs = await Promise.all(P.map((p) => loadImage(p.ball || p.photo)));
  balls = P.map((p, i) => new Ball(scene, i, p, imgs[i]));
  balls.forEach((b) => b.hide());

  const resize = () => {
    const r = canvas.getBoundingClientRect();
    renderer.setSize(Math.max(1, Math.round(r.width)), Math.max(1, Math.round(r.height)), false);
    bake();
  };
  resize(); addEventListener('resize', resize);

  // game flow
  let phase = 'start', phaseT = 0, roundWinner = -1;
  const newRound = () => {
    const a = Math.random() * Math.PI * 2;
    balls[0].drop(a, 0);
    balls[1].drop(a + Math.PI * (0.7 + Math.random() * 0.6), 0.35);
    phase = 'play'; phaseT = 0; roundWinner = -1;
  };
  const popup = (i) => {
    const p = $('popup'); $('popup-name').textContent = P[i].name;
    p.className = 'popup p' + (i + 1); p.hidden = false;
    const inner = p.firstElementChild; inner.style.animation = 'none'; void inner.offsetWidth; inner.style.animation = '';
  };
  const showWinner = (i) => {
    const w = $('winner'); w.className = 'winner p' + (i + 1); w.hidden = false;
    $('winner-photo').src = P[i].photo || placeholderPhoto(P[i].color, P[i].number, false);
    $('winner-name').textContent = P[i].name;
    $('winner-score').textContent = `${score[i]} – ${score[1 - i]}`;
  };

  const clock = new THREE.Clock();
  let acc = 0;
  const FIXED = 1 / 120;
  function frame() {
    const dt = Math.min(0.1, clock.getDelta());
    phaseT += dt;
    if (phase === 'start' && phaseT > 0.8) newRound();
    if (phase === 'play') {
      acc += dt;
      while (acc >= FIXED) {
        balls.forEach((b) => b.step(FIXED));
        collide(balls[0], balls[1]);
        acc -= FIXED;
        if (roundWinner < 0) {
          const w = balls.findIndex((b) => b.state === 'falling' || b.state === 'scored');
          if (w >= 0) {
            roundWinner = w; score[w]++; saveScore(); showScore(w); popup(w);
          }
        }
      }
      // keep the round from lasting forever: after 45 s give both balls a nudge inward
      if (phaseT > 45) balls.forEach((b) => b.state === 'rolling' && b.vel.addScaledVector(b.pos.clone().normalize(), -0.5 * dt));
      if (roundWinner >= 0 && phaseT > 0 && (phase = 'after')) phaseT = 0;
    } else if (phase === 'after') {
      balls.forEach((b) => b.step(dt));
      if (phaseT > 2.0) {
        balls.forEach((b) => b.hide());
        if (score[roundWinner] >= cfg.target) { showWinner(roundWinner); phase = 'winner'; phaseT = 0; }
        else newRound();
      }
    } else if (phase === 'winner' && phaseT > 8) {
      $('winner').hidden = true; score = [0, 0]; saveScore(); showScore(); newRound();
    }
    balls.forEach((b) => b.render(dt));
    renderer.render(scene, camera);
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
  window.__fft = { balls, score: () => score }; // for testing
}

main();
