// FFTEdit · Ball Drop Game (3D). Runs by itself, made for 24/7 vertical live streams.
import * as THREE from 'three';
import { RoomEnvironment } from './vendor/RoomEnvironment.js';
import { Sfx, Commentator } from './audio.js?v=6';

/* ------------------------------------------------------------------ config */
const DEFAULTS = {
  target: 20,
  players: [
    { name: 'MESSI',   team: 'PINK TEAM', color: '#ff2f9e', number: '10', photo: '/game/img/p1-hero.jpg', face: '/game/img/p1-face.jpg', ball: '' },
    { name: 'RONALDO', team: 'GOLD TEAM', color: '#ffd21a', number: '7',  photo: '/game/img/p2-hero.jpg', face: '/game/img/p2-face.jpg', ball: '' },
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
    const up = (c.players || [])[i] || {};
    Object.entries(up).forEach(([k, v]) => { if (v !== '' && v !== null && v !== undefined) q[k] = v; });
    if (up.photo) q.face = up.photo; // an uploaded photo replaces the built-in pictures everywhere
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
      c.globalAlpha = 1;
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

  drop(angle, delay, height = 6) {
    this.dropH = height;
    this.landed = false;
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

  emit(type, strength) { if (this.onEvent) this.onEvent(type, strength, this); }

  hide() { this.state = 'hidden'; this.mesh.visible = this.halo.visible = false; this.trail.forEach((s) => (s.visible = false)); }

  step(dt) {
    if (this.state === 'dropping') {
      this.t += dt;
      const r = this.pos.length(), ground = surfaceY(r) + BALL_R;
      const H = this.dropH || 6;
      if (this.t < 0) { this.y = ground + H; this.mesh.visible = this.halo.visible = false; return; }
      this.mesh.visible = this.halo.visible = true;
      const T = 0.25 + 0.075 * H; // fall + one bounce
      if (this.t < T) {
        const k = this.t / T;
        this.y = ground + H * (1 - k) * (1 - k);
      } else if (this.t < T + 0.35) {
        if (!this.landed) { this.landed = true; this.emit('land', 1); }
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
        if (vn > 0) { this.vel.addScaledVector(n, -1.6 * vn); if (vn > 0.6) this.emit('rim', Math.min(1, vn / 4)); }
        this.pos.copy(n.multiplyScalar(RIM_R - BALL_R));
      }
      this.y = surfaceY(this.pos.length()) + BALL_R;
      if (this.pos.length() < HOLE_R - 0.12) { this.state = 'falling'; this.t = 0; this.emit('fall', 1); }
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

// Draws a shiny ball with a face, used by the 2D mode and by the tubes.
function paintBall(ctx, x, y, R, hex, img) {
  const c0 = new THREE.Color(hex);
  const bg = ctx.createRadialGradient(x - R * 0.35, y - R * 0.4, R * 0.1, x, y, R);
  bg.addColorStop(0, '#' + c0.clone().lerp(new THREE.Color('#ffffff'), 0.6).getHexString());
  bg.addColorStop(0.55, hex);
  bg.addColorStop(1, '#' + c0.clone().multiplyScalar(0.45).getHexString());
  ctx.fillStyle = bg; ctx.beginPath(); ctx.arc(x, y, R, 0, Math.PI * 2); ctx.fill();
  if (img) {
    ctx.save(); ctx.beginPath(); ctx.arc(x, y, R * 0.68, 0, Math.PI * 2); ctx.clip();
    const s = Math.max((R * 1.36) / img.width, (R * 1.36) / img.height);
    ctx.drawImage(img, x - (img.width * s) / 2, y - (img.height * s) / 2, img.width * s, img.height * s);
    ctx.restore();
  }
  const sh = ctx.createRadialGradient(x - R * 0.4, y - R * 0.45, 0, x - R * 0.4, y - R * 0.45, R * 0.6);
  sh.addColorStop(0, 'rgba(255,255,255,.75)'); sh.addColorStop(1, 'rgba(255,255,255,0)');
  ctx.fillStyle = sh; ctx.beginPath(); ctx.arc(x, y, R, 0, Math.PI * 2); ctx.fill();
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
    if (-rel > 0.4) a.emit('clack', Math.min(1, -rel / 4));
  }
}


/* ------------------------------------------------------------------- tubes */
// Two glass tubes above the bowl. Each round the lowest ball rolls out and drops in.
class Tubes {
  constructor(canvas, camera, players, imgs) {
    this.cv = canvas; this.ctx = canvas.getContext('2d');
    this.P = players; this.imgs = imgs;
    this.DROP_H = 0.9;
    const v = new THREE.Vector3();
    const proj = (x, y, z) => { v.set(x, y, z).project(camera); return [((v.x + 1) / 2) * 1080, 960 + ((1 - v.y) / 2) * 860]; };
    const r = RIM_R - 0.55;
    const point = (a) => proj(r * Math.cos(a), surfaceY(r) + BALL_R + this.DROP_H, r * Math.sin(a));
    const findAngle = (tx, ty) => {
      let best = 0, bd = 1e9;
      for (let a = Math.PI * 0.85; a < Math.PI * 2.15; a += 0.004) { const [x, y] = point(a); const d = (x - tx) ** 2 + (y - ty) ** 2; if (d < bd) { bd = d; best = a; } }
      return best;
    };
    const tops = [[34, 958], [1046, 958]];
    const aims = [[330, 1150], [750, 1150]];
    this.t = players.map((p, i) => {
      const angle = findAngle(...aims[i]);
      const mouth = point(angle);
      const [ex] = proj(r * Math.cos(angle) + BALL_R, surfaceY(r) + BALL_R + this.DROP_H, r * Math.sin(angle));
      const R = Math.abs(ex - mouth[0]) * 1.02;
      const top = tops[i];
      const dx = mouth[0] - top[0], dy = mouth[1] - top[1], len = Math.hypot(dx, dy);
      const u = [dx / len, dy / len];
      const n = Math.max(3, Math.min(6, Math.floor((len - R * 0.3) / (R * 2.1))));
      return { angle, mouth, top, u, R, n, start: -1, color: p.color };
    });
    this.dirty = true;
  }
  slot(tb, k) { // k = 0 is the ball next to the mouth; -1 is just outside
    const d = tb.R * 1.05 + k * tb.R * 2.1;
    return [tb.mouth[0] - tb.u[0] * d, tb.mouth[1] - tb.u[1] * d];
  }
  release(i, delay, now) { this.t[i].start = now + delay; this.dirty = true; }
  resize() {
    const r = this.cv.getBoundingClientRect();
    const dpr = Math.min(devicePixelRatio || 1, 1.5);
    this.cv.width = Math.max(1, Math.round(r.width * dpr));
    this.cv.height = Math.max(1, Math.round(r.height * dpr));
    this.k = this.cv.width / 1080;
    this.dirty = true;
  }
  glass(c, tb, front) {
    const [x0, y0] = tb.top, [x1, y1] = [tb.mouth[0] + tb.u[0] * tb.R * 0.2, tb.mouth[1] + tb.u[1] * tb.R * 0.2];
    const w = tb.R * 2.35, nx = -tb.u[1], ny = tb.u[0];
    if (!front) {
      c.save(); c.lineCap = 'round';
      c.shadowColor = tb.color; c.shadowBlur = 40; c.strokeStyle = tb.color; c.globalAlpha = 0.35; c.lineWidth = w + 10;
      c.beginPath(); c.moveTo(x0, y0); c.lineTo(x1, y1); c.stroke();
      c.shadowBlur = 0; c.globalAlpha = 1; c.strokeStyle = 'rgba(20,14,34,.55)'; c.lineWidth = w;
      c.beginPath(); c.moveTo(x0, y0); c.lineTo(x1, y1); c.stroke();
      c.restore();
      return;
    }
    c.save(); c.lineCap = 'round';
    const edge = (off, a, lw) => { c.strokeStyle = `rgba(255,255,255,${a})`; c.lineWidth = lw; c.beginPath(); c.moveTo(x0 + nx * off, y0 + ny * off); c.lineTo(x1 + nx * off, y1 + ny * off); c.stroke(); };
    edge(w / 2, 0.75, 5); edge(-w / 2, 0.5, 5); edge(w / 2 - 14, 0.35, 4); edge(-w / 2 + 10, 0.12, 8);
    c.strokeStyle = 'rgba(255,255,255,.08)'; c.lineWidth = w; c.beginPath(); c.moveTo(x0, y0); c.lineTo(x1, y1); c.stroke();
    const ring = (x, y) => {
      c.save(); c.translate(x, y); c.rotate(Math.atan2(tb.u[1], tb.u[0]));
      c.strokeStyle = tb.color; c.shadowColor = tb.color; c.shadowBlur = 22; c.lineWidth = 9;
      c.beginPath(); c.ellipse(0, 0, 12, w / 2 + 6, 0, 0, Math.PI * 2); c.stroke(); c.restore();
    };
    ring(x0, y0); ring(x1, y1);
    c.restore();
  }
  draw(now) {
    const busy = this.t.some((tb) => tb.start >= 0 && now - tb.start < 0.6);
    if (!this.dirty && !busy) return;
    this.dirty = busy;
    const c = this.ctx;
    c.setTransform(1, 0, 0, 1, 0, 0); c.clearRect(0, 0, this.cv.width, this.cv.height);
    c.setTransform(this.k, 0, 0, this.k, 0, 0);
    this.t.forEach((tb, i) => {
      this.glass(c, tb, false);
      const p = tb.start < 0 ? 1 : Math.max(0, Math.min(1, (now - tb.start) / 0.45));
      const moving = tb.start >= 0 && now >= tb.start && p < 1;
      const shift = moving ? p : (tb.start >= 0 && now < tb.start ? 0 : 0);
      for (let k = 0; k < tb.n; k++) {
        if (k === 0 && moving && p > 0.55) continue; // handed over to the bowl
        const [x, y] = this.slot(tb, k - shift);
        c.globalAlpha = k === tb.n - 1 && moving ? p : 1;
        paintBall(c, x, y, tb.R, tb.color, this.imgs[i]);
      }
      c.globalAlpha = 1;
      this.glass(c, tb, true);
    });
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
    const av = p.face || p.photo || placeholderPhoto(p.color, p.number, false);
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

  // Drawing. 3D (WebGL) is used when the browser supports it; otherwise the same scene is
  // drawn with plain 2D canvas, so the game always runs (e.g. on servers without 3D support).
  const canvas = $('three');
  const camera = new THREE.PerspectiveCamera(30, 1080 / 860, 0.1, 100);
  camera.position.set(0, 11.6, 15.6);
  camera.lookAt(0, -0.9, 0);
  camera.updateMatrixWorld();
  const Q = Math.min(2, Math.max(0.3, parseFloat(new URLSearchParams(location.search).get('q')) || 1));
  const DPR = Math.min(devicePixelRatio || 1, 1.5);
  const imgs = await Promise.all(P.map((p) => loadImage(p.ball || p.face || p.photo)));
  let balls = [];
  let gfx = null;
  const force2D = new URLSearchParams(location.search).get('mode') === '2d';
  if (!force2D) {
    try { gfx = make3D(); } catch (e) { console.warn('3D not available, using 2D drawing:', e && e.message); gfx = null; }
  }
  if (!gfx) gfx = make2D();
  document.documentElement.dataset.render = gfx.mode;

  function make3D() {
    const renderer = new THREE.WebGLRenderer({ canvas, antialias: false, alpha: true, powerPreference: 'high-performance' });
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
    scene.add(new THREE.HemisphereLight(0xffffff, 0x221133, 0.35));
    const key = new THREE.DirectionalLight(0xffffff, 0.9); key.position.set(0, 12, 9); scene.add(key);
    const pl = new THREE.PointLight(new THREE.Color(P[0].color), 40, 18); pl.position.set(-6.5, 3, 3); scene.add(pl);
    const gl = new THREE.PointLight(new THREE.Color(P[1].color), 40, 18); gl.position.set(6.5, 3, 3); scene.add(gl);

    // The bowl never moves, so it is drawn once in high quality ("baked") into a picture.
    // Every frame only the balls are drawn on top.
    const bowl = buildBowl(scene, P);
    const looks = [];
    const depthOnly = new THREE.MeshBasicMaterial({ colorWrite: false });
    bowl.traverse((o) => { if (o.isMesh || o.isSprite) looks.push([o, o.material]); });
    const playMode = () => looks.forEach(([o]) => { if (o.isSprite) o.visible = false; else o.material = depthOnly; });
    const lookMode = () => looks.forEach(([o, m]) => { o.visible = true; o.material = m; });

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

    balls = P.map((p, i) => new Ball(scene, i, p, imgs[i]));
    balls.forEach((b) => b.hide());
    const resize = () => {
      const r = canvas.getBoundingClientRect();
      renderer.setSize(Math.max(1, Math.round(r.width)), Math.max(1, Math.round(r.height)), false);
      bake();
    };
    resize();
    // a first test frame: if WebGL is broken this throws here, and we switch to 2D
    renderer.render(scene, camera);
    return {
      mode: '3d',
      resize,
      draw(dt) { balls.forEach((b) => b.render(dt)); renderer.render(scene, camera); },
    };
  }

  function make2D() {
    // a fresh canvas, in case a failed WebGL attempt left the old one unusable
    const fresh = canvas.cloneNode(false);
    canvas.replaceWith(fresh);
    const ctx = fresh.getContext('2d');
    const dummy = new THREE.Scene();
    balls = P.map((p, i) => new Ball(dummy, i, p, imgs[i]));
    balls.forEach((b) => b.hide());
    let W = 1, H = 1, holePath = null;
    const v = new THREE.Vector3();
    const proj = (x, y, z) => { v.set(x, y, z).project(camera); return [((v.x + 1) / 2) * W, ((1 - v.y) / 2) * H]; };
    const ring = (r, y, a0 = 0, a1 = Math.PI * 2, n = 120) => {
      const pts = [];
      for (let i = 0; i <= n; i++) { const a = a0 + ((a1 - a0) * i) / n; pts.push(proj(r * Math.cos(a), y, r * Math.sin(a))); }
      return pts;
    };
    const pathOf = (c, pts, close = true) => { c.beginPath(); pts.forEach(([x, y], i) => (i ? c.lineTo(x, y) : c.moveTo(x, y))); if (close) c.closePath(); };
    const col = (hex, a) => { const c = new THREE.Color(hex); return `rgba(${Math.round(c.r * 255)},${Math.round(c.g * 255)},${Math.round(c.b * 255)},${a})`; };
    const neonHalves = (c, r, y, w) => {
      c.lineWidth = w; c.lineCap = 'round';
      [[P[0].color, Math.PI / 2, Math.PI * 1.5], [P[1].color, -Math.PI / 2, Math.PI / 2]].forEach(([hex, a0, a1]) => {
        c.strokeStyle = hex; c.shadowColor = hex; c.shadowBlur = w * 3;
        pathOf(c, ring(r, y, a0, a1, 90), false); c.stroke();
      });
      c.shadowBlur = 0;
    };

    const bakeBowl = () => {
      const bc = document.createElement('canvas'); bc.width = W; bc.height = H;
      const c = bc.getContext('2d');
      const S = H / 860; // pixels per stage pixel
      // base band (front side)
      const topF = ring(5.4, 0.0, 0, Math.PI, 90), botF = ring(5.25, -1.7, Math.PI, 0, 90);
      const g = c.createLinearGradient(0, proj(0, 0, 5.4)[1], 0, proj(0, -1.7, 5.25)[1]);
      g.addColorStop(0, '#221c30'); g.addColorStop(1, '#09070f');
      c.fillStyle = g; pathOf(c, topF.concat(botF)); c.fill();
      neonHalves(c, 5.27, -1.7, 7 * S);
      // team panels and football on the band
      const panel = (ang, hex, text) => {
        const [x, y] = proj(5.35 * Math.cos(ang), -0.85, 5.35 * Math.sin(ang));
        const [ax, ay] = proj(5.35 * Math.cos(ang - 0.05), -0.85, 5.35 * Math.sin(ang - 0.05));
        const [bx, by] = proj(5.35 * Math.cos(ang + 0.05), -0.85, 5.35 * Math.sin(ang + 0.05));
        let rot = Math.atan2(by - ay, bx - ax); if (Math.abs(rot) > Math.PI / 2) rot += Math.PI;
        c.save(); c.translate(x, y); c.rotate(rot);
        c.font = `italic 900 ${Math.round(64 * S)}px Oswald, Impact, Arial Black, sans-serif`;
        c.textAlign = 'center'; c.textBaseline = 'middle';
        c.fillStyle = hex; c.shadowColor = hex; c.shadowBlur = 18 * S;
        c.fillText(text, 0, 0);
        c.restore();
      };
      panel(Math.PI / 2 + 0.55, P[0].color, P[0].name);
      panel(Math.PI / 2 - 0.55, P[1].color, P[1].name);
      const [ex, ey] = proj(0, -0.85, 5.4);
      c.fillStyle = '#0c0a14'; c.strokeStyle = '#8fa8ff'; c.lineWidth = 4 * S;
      c.beginPath(); c.ellipse(ex, ey, 46 * S, 36 * S, 0, 0, Math.PI * 2); c.fill(); c.stroke();
      drawFootball(c, ex, ey, 26 * S);
      // outer lip
      c.fillStyle = '#d9d7e2'; pathOf(c, ring(5.42, 0.2)); c.fill();
      // funnel: rings from the rim down to the hole, getting darker with depth
      const N = 48;
      for (let k = 0; k <= N; k++) {
        const t = k / N, r = RIM_R + 0.6 * (1 - t) * 0 + (HOLE_R - RIM_R) * t + (k === 0 ? 0.6 : 0);
        const y = surfaceY(Math.min(RIM_R, r));
        const pts = ring(r, y);
        const xs = pts.map((q) => q[0]);
        const lg = c.createLinearGradient(Math.min(...xs), 0, Math.max(...xs), 0);
        const shade = 0.96 - 0.32 * Math.pow(t, 1.4);
        const base = Math.round(255 * shade);
        lg.addColorStop(0, `rgb(${base},${Math.round(base * 0.9)},${Math.round(base * 0.95)})`);
        lg.addColorStop(0.5, `rgb(${base},${base},${Math.min(255, base + 6)})`);
        lg.addColorStop(1, `rgb(${base},${Math.round(base * 0.97)},${Math.round(base * 0.85)})`);
        c.fillStyle = lg; pathOf(c, pts); c.fill();
      }
      // soft shine on the back wall
      const [sx, sy] = proj(0, -0.4, -2.2);
      const sg = c.createRadialGradient(sx, sy, 0, sx, sy, 260 * S);
      sg.addColorStop(0, 'rgba(255,255,255,.55)'); sg.addColorStop(1, 'rgba(255,255,255,0)');
      c.fillStyle = sg; pathOf(c, ring(RIM_R, surfaceY(RIM_R))); c.fill();
      // rim neon
      neonHalves(c, 5.36, 0.22, 6 * S);
      // hole
      const hy = surfaceY(HOLE_R);
      const hole = ring(HOLE_R, hy);
      const [hx, hcy] = proj(0, hy, 0);
      const hg = c.createRadialGradient(hx, hcy, 0, hx, hcy, 70 * S);
      hg.addColorStop(0, '#000'); hg.addColorStop(0.8, '#120f1c'); hg.addColorStop(1, '#2b2540');
      c.fillStyle = hg; pathOf(c, hole); c.fill();
      neonHalves(c, HOLE_R + 0.12, hy + 0.04, 5 * S);
      $('bowl-img').src = bc.toDataURL('image/png');
      holePath = hole;
    };

    const glow = (x, y, r, hex, a) => {
      const g = ctx.createRadialGradient(x, y, 0, x, y, r);
      g.addColorStop(0, col(hex, a)); g.addColorStop(1, col(hex, 0));
      ctx.fillStyle = g; ctx.beginPath(); ctx.arc(x, y, r, 0, Math.PI * 2); ctx.fill();
    };
    const drawBall = (b) => {
      if (b.state === 'hidden' || b.state === 'scored' || !b.mesh.visible) return;
      const hex = P[b.idx].color, m = b.mesh.position;
      const [x, y] = proj(m.x, m.y, m.z);
      const [x2] = proj(m.x + BALL_R, m.y, m.z);
      const R = Math.abs(x2 - x) * b.mesh.scale.x;
      if (b.state === 'rolling') {
        const sp = b.vel.length();
        b.history.forEach((h, i) => {
          if (i % 2 || i < 2) return;
          const k = 1 - i / b.history.length;
          const [tx, ty] = proj(h.x, h.y, h.z);
          glow(tx, ty, R * (1.1 + 1.2 * k), hex, 0.35 * k * Math.min(1, sp / 2));
        });
      }
      ctx.save();
      if (b.state === 'falling' && holePath) { pathOf(ctx, holePath); ctx.clip(); }
      glow(x, y, R * 2.4, hex, 0.55);
      paintBall(ctx, x, y, R, hex, imgs[b.idx]);
      ctx.restore();
    };

    const resize = () => {
      const r = fresh.getBoundingClientRect();
      const dpr = DPR * Q;
      W = fresh.width = Math.max(1, Math.round(r.width * dpr));
      H = fresh.height = Math.max(1, Math.round(r.height * dpr));
      bakeBowl();
    };
    resize();
    return {
      mode: '2d',
      resize,
      draw(dt) {
        balls.forEach((b) => b.render(dt)); // updates positions and trail history
        ctx.clearRect(0, 0, W, H);
        balls.slice().sort((a, b) => a.mesh.position.z - b.mesh.position.z).forEach(drawBall);
      },
    };
  }

  addEventListener('resize', () => gfx.resize());

  // sounds, commentator, tubes
  const sfx = new Sfx();
  const voice = new Commentator(cfg.voice, sfx);
  const tubes = new Tubes($('fx'), camera, P, imgs);
  tubes.resize(); addEventListener('resize', () => tubes.resize());
  balls.forEach((b) => (b.onEvent = (type, s) => {
    if (type === 'land') sfx.land(s); else if (type === 'rim') sfx.rim(s);
    else if (type === 'clack') sfx.clack(s); else if (type === 'fall') sfx.fall();
  }));
  // Browsers block sound until the visitor taps. The stream browser allows it automatically.
  const soundBtn = $('sound-btn');
  const unlock = () => { sfx.resume(); soundBtn.hidden = true; };
  soundBtn.addEventListener('click', unlock);
  addEventListener('pointerdown', unlock);
  sfx.resume();
  setTimeout(() => { if (sfx.ok && !sfx.running) soundBtn.hidden = false; }, 1500);

  // game flow
  let phase = 'start', phaseT = 0, roundWinner = -1, gameT = 0;
  let nextCall = 25 + Math.random() * 15, nextSub = 200 + Math.random() * 100;
  const leader = () => (score[0] > score[1] ? 0 : score[1] > score[0] ? 1 : -1);
  const newRound = () => {
    balls[0].drop(tubes.t[0].angle, 0.25, tubes.DROP_H);
    balls[1].drop(tubes.t[1].angle, 0.6, tubes.DROP_H);
    tubes.release(0, 0, gameT); tubes.release(1, 0.35, gameT);
    sfx.release(); setTimeout(() => sfx.release(), 350);
    phase = 'play'; phaseT = 0; roundWinner = -1;
    voice.say('drop', { chance: 0.2 });
  };
  const onGoal = (w) => {
    const before = leader();
    score[w]++; saveScore(); showScore(w); popup(w);
    sfx.goal();
    if (score[w] >= cfg.target) return; // the winner line plays on the winner screen
    voice.say('goal_p' + (w + 1), { chance: 0.8 });
    const after = leader();
    if (score[w] === cfg.target - 1) voice.say('close');
    else if (after === -1) voice.say('tie', { chance: 0.7 });
    else if (after === w && before !== w) voice.say('lead_p' + (w + 1));
  };
  setTimeout(() => voice.say('intro'), 2500);
  const popup = (i) => {
    const p = $('popup'); $('popup-name').textContent = P[i].name;
    p.className = 'popup p' + (i + 1); p.hidden = false;
    const inner = p.firstElementChild; inner.style.animation = 'none'; void inner.offsetWidth; inner.style.animation = '';
  };
  const showWinner = (i) => {
    const w = $('winner'); w.className = 'winner p' + (i + 1); w.hidden = false;
    $('winner-photo').src = P[i].face || P[i].photo || placeholderPhoto(P[i].color, P[i].number, false);
    $('winner-name').textContent = P[i].name;
    $('winner-score').textContent = `${score[i]} – ${score[1 - i]}`;
    sfx.win();
    voice.say('win_p' + (i + 1), { urgent: true });
  };

  const clock = new THREE.Clock();
  let acc = 0;
  const FIXED = 1 / 120;
  function frame() {
    const dt = Math.min(0.1, clock.getDelta());
    phaseT += dt; gameT += dt;
    if (phase === 'start' && phaseT > 0.8) newRound();
    if (phase === 'play') {
      acc += dt;
      while (acc >= FIXED) {
        balls.forEach((b) => b.step(FIXED));
        collide(balls[0], balls[1]);
        acc -= FIXED;
        if (roundWinner < 0) {
          const w = balls.findIndex((b) => b.state === 'falling' || b.state === 'scored');
          if (w >= 0) { roundWinner = w; onGoal(w); }
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
      voice.say('new_game', { urgent: true });
    }
    // invite comments now and then
    if (phase === 'play' && voice.idle && gameT > nextCall) { voice.say('call'); nextCall = gameT + 30 + Math.random() * 25; }
    if (phase === 'play' && voice.idle && gameT > nextSub) { voice.say('sub'); nextSub = gameT + 240 + Math.random() * 120; }
    sfx.setRoll(balls.reduce((s, b) => s + (b.state === 'rolling' ? b.vel.length() : 0), 0));
    gfx.draw(dt);
    tubes.draw(gameT);
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
  window.__fft = { balls, score: () => score, mode: gfx.mode, sfx, voice, tubes }; // for testing
}

main();
