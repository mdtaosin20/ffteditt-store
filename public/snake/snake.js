// FFTEdit · Snake Live. A snake plays by itself on a 20×20 board and eats fruit, 24/7. It looks for a safe way
// (it only goes for a fruit when it can still reach its own tail afterwards), so a round lasts long; when it is
// stuck or crashes — or fills the whole board — a new round starts. Every chat message drops a fruit with the
// viewer's name on the board; a new subscriber drops a GOLDEN fruit. LUNA, the live host, welcomes new viewers
// and answers the chat. Our own look (not Google's): purple snake, green board, gold frame.
import { Sfx, freshRun, holdScreen, PACE } from '/game/audio.js?v=13';
import { liveChat } from '/game/chat.js?v=29';
import { goLive } from '/game/golive.js?v=2';
import { Music } from '/game/music.js?v=2';
import { autoUpdate } from '/game/update.js?v=1';

const $ = (id) => document.getElementById(id);
const QS = new URLSearchParams(location.search);
const pick = (a) => a[Math.floor(Math.random() * a.length)];
const clamp = (v, a, b) => Math.max(a, Math.min(b, v));
const FAST = Math.max(0.1, Math.min(50, Number(QS.get('fast')) || 1));   // testing only
const FRUITS = ['🍎', '🍊', '🍋', '🍉', '🍇', '🍓', '🍒', '🍑', '🍍', '🥝', '🍌', '🥭', '🍐', '🫐', '🥥'];
const KEY = 'fft_snake';

/* ---------- settings (Admin → Snake Live), checked again every 30 seconds ---------- */
const DEF = { title: 'SNAKE LIVE', speed: 9, grid: 20, chat: true, cooldown: 8, named: 6, gold: 3, eat_sound: true, music: true, chan: '', autoplay: true, pace: 'normal', rev: '', reset: '' };
const getCfg = async () => { try { const r = await fetch('/api/snake.php', { cache: 'no-store' }); if (r.ok) return await r.json(); } catch (e) { /* offline */ } return null; };
const cfg = { ...DEF, ...((await getCfg()) || {}) };
if (cfg.autoplay === false) { holdScreen('/api/snake.php', cfg.rev); throw new Error('paused'); }
const steps = () => clamp(Number(cfg.speed) || 9, 3, 20) * (PACE[cfg.pace] || 1);   // moves a second
const SIZE = 900;
let N = clamp(Math.round(cfg.grid) || 20, 12, 30), CELL = SIZE / N;

/* ---------- state (kept over reloads) ---------- */
const day = () => new Date().toISOString().slice(0, 10);
let S = { round: 1, best: 0, today: 0, day: day(), reset: cfg.reset };
freshRun('snake', [KEY]);
try { S = { ...S, ...(JSON.parse(localStorage.getItem(KEY) || 'null') || {}) }; } catch (e) { /* ignore */ }
if (S.reset !== cfg.reset) S = { round: 1, best: 0, today: 0, day: day(), reset: cfg.reset };
if (S.day !== day()) { S.day = day(); S.today = 0; }
const save = () => { try { localStorage.setItem(KEY, JSON.stringify(S)); } catch (e) { /* ignore */ } };

/* ---------- screen size ---------- */
const stage = $('stage');
let scale = 1;
const fit = () => {
  scale = Math.min(innerWidth / 1080, innerHeight / 1920);
  stage.style.transform = `translate(-50%, -50%) scale(${scale})`;
  sizeCanvas();
};

/* ---------- sound ---------- */
const sfx = new Sfx();
const soundBtn = $('sound-btn');
const unlock = () => { sfx.resume(); soundBtn.hidden = true; };
soundBtn.addEventListener('click', unlock); addEventListener('pointerdown', unlock); sfx.resume();
setTimeout(() => { if (sfx.ok && !sfx.running) soundBtn.hidden = false; }, 1500);
const musicBus = sfx.ok ? sfx.ctx.createGain() : null;
if (musicBus) musicBus.connect(sfx.ctx.destination);
const duckAll = (on) => { sfx.duck(on); if (musicBus) musicBus.gain.setTargetAtTime(on ? 0.25 : 1, sfx.ctx.currentTime, on ? 0.15 : 0.6); };
const music = sfx.ok && !QS.has('nomusic') ? new Music(sfx.ctx, musicBus, { style: 'upbeat', volume: 0.2 }) : null;
if (music && cfg.music) music.start();
// "nom nom": two quick crunchy bites (a short noise burst + a low thump), then a little gulp
let noiseBuf = null;
const crunch = (at, vol) => {
  if (!noiseBuf) {
    noiseBuf = sfx.ctx.createBuffer(1, Math.round(sfx.ctx.sampleRate * 0.09), sfx.ctx.sampleRate);
    const d = noiseBuf.getChannelData(0); for (let i = 0; i < d.length; i++) d[i] = (Math.random() * 2 - 1) * (1 - i / d.length) ** 2;
  }
  const t = sfx.ctx.currentTime + at, src = sfx.ctx.createBufferSource(), bp = sfx.ctx.createBiquadFilter(), g = sfx.ctx.createGain();
  src.buffer = noiseBuf; bp.type = 'bandpass'; bp.frequency.value = 1700 + Math.random() * 600; bp.Q.value = 0.9; g.gain.value = vol;
  src.connect(bp); bp.connect(g); g.connect(sfx.master || sfx.ctx.destination); src.start(t);
  sfx.tone({ freq: 210, to: 90, type: 'sine', dur: 0.09, vol: vol * 0.9, delay: at });
};
const chomp = (big, at = 0) => {
  if (!cfg.eat_sound || !sfx.running) return;
  crunch(at, 0.5); crunch(at + 0.13, 0.42);
  sfx.tone({ freq: big ? 700 : 420, to: big ? 1400 : 260, type: 'sine', dur: 0.16, vol: 0.06, delay: at + 0.27 });   // gulp
};

/* ---------- board picture (drawn once per size) ---------- */
const cv = $('board'), c = cv.getContext('2d');
let k = 1, boardImg = null;
const sprites = new Map();
function sizeCanvas(force) {
  const px = Math.max(300, Math.round(SIZE * scale * Math.min(devicePixelRatio || 1, 2)));
  if (cv.width === px && !force) return;
  cv.width = cv.height = px; k = px / SIZE;
  boardImg = document.createElement('canvas'); boardImg.width = boardImg.height = px;
  const g = boardImg.getContext('2d'); g.scale(k, k);
  for (let y = 0; y < N; y++) for (let x = 0; x < N; x++) { g.fillStyle = (x + y) % 2 ? '#2f8f6b' : '#38a67c'; g.fillRect(x * CELL, y * CELL, CELL, CELL); }
  sprites.clear();
}
const fruitSprite = (emo, big) => {   // an emoji drawn once into its own little picture
  const id = emo + (big ? '!' : '');
  if (!sprites.has(id)) {
    const s = Math.ceil(CELL * (big ? 1.3 : 1.1) * k), p = document.createElement('canvas'); p.width = p.height = s;
    const g = p.getContext('2d'); g.font = `${Math.round(s * 0.82)}px "Noto Color Emoji","Apple Color Emoji","Segoe UI Emoji",sans-serif`;
    g.textAlign = 'center'; g.textBaseline = 'middle'; g.fillText(emo, s / 2, s / 2 + s * 0.04);
    sprites.set(id, p);
  }
  return sprites.get(id);
};

/* ---------- the snake and its brain ---------- */
const idx = (x, y) => y * N + x;
const DIRS = [[1, 0], [0, 1], [-1, 0], [0, -1]];
let body = [], prevTail = null, dir = [1, 0], grow = 0, fruits = [], alive = true, phase = 'play', phaseT = 0, ate = 0, flash = 0;
const newRound = () => {
  const g = clamp(Math.round(cfg.grid) || 20, 12, 30);
  if (g !== N) { N = g; CELL = SIZE / N; fruits = []; sizeCanvas(true); }   // a new board size starts with a new round
  const y = Math.floor(N / 2);
  body = [[6, y], [5, y], [4, y], [3, y]]; prevTail = [2, y]; dir = [1, 0]; grow = 0; ate = 0; alive = true; phase = 'play';
  fruits = fruits.filter((f) => f.name);   // fruits with names stay for the next round
  for (const f of fruits) if (body.some(([x, yy]) => x === f.x && yy === f.y)) { const p = freeCell(); if (p) [f.x, f.y] = p; }
  ensureFruit(); updateUI();
};
const occupied = () => { const o = new Uint8Array(N * N); for (const [x, y] of body) o[idx(x, y)] = 1; return o; };
const freeCell = () => {
  const o = occupied(); for (const f of fruits) o[idx(f.x, f.y)] = 1;
  const free = []; for (let i = 0; i < N * N; i++) if (!o[i]) free.push([i % N, Math.floor(i / N)]);
  return free.length ? pick(free) : null;
};
const ensureFruit = () => {
  if (fruits.some((f) => !f.name)) return;
  const p = freeCell(); if (p) fruits.push({ x: p[0], y: p[1], emo: pick(FRUITS), born: performance.now() });
};
// shortest way from a cell to a goal; the snake's body blocks, except the tail end (it moves away)
const bfs = (snake, sx, sy, gx, gy) => {
  const block = new Uint8Array(N * N); for (let i = 0; i < snake.length - 1; i++) block[idx(snake[i][0], snake[i][1])] = 1;
  const prev = new Int16Array(N * N).fill(-1), q = [idx(sx, sy)]; prev[q[0]] = q[0];
  for (let h = 0; h < q.length; h++) {
    const cur = q[h], cx = cur % N, cy = Math.floor(cur / N);
    if (cx === gx && cy === gy) { const path = []; let p = cur; while (p !== q[0]) { path.push([p % N, Math.floor(p / N)]); p = prev[p]; } return path.reverse(); }
    for (const [dx, dy] of DIRS) {
      const nx = cx + dx, ny = cy + dy; if (nx < 0 || ny < 0 || nx >= N || ny >= N) continue;
      const ni = idx(nx, ny); if (prev[ni] !== -1 || (block[ni] && !(nx === gx && ny === gy))) continue;
      prev[ni] = cur; q.push(ni);
    }
  }
  return null;
};
const floodSize = (snake, sx, sy) => {
  const block = new Uint8Array(N * N); for (let i = 0; i < snake.length - 1; i++) block[idx(snake[i][0], snake[i][1])] = 1;
  const seen = new Uint8Array(N * N), q = [idx(sx, sy)]; seen[q[0]] = 1;
  for (let h = 0; h < q.length; h++) { const cur = q[h], cx = cur % N, cy = Math.floor(cur / N);
    for (const [dx, dy] of DIRS) { const nx = cx + dx, ny = cy + dy; if (nx < 0 || ny < 0 || nx >= N || ny >= N) continue; const ni = idx(nx, ny); if (seen[ni] || block[ni]) continue; seen[ni] = 1; q.push(ni); } }
  return q.length;
};
const walk = (snake, path, grows) => {   // where the snake is after following a path (it grows at the end)
  let s = snake.slice();
  path.forEach(([x, y], i) => { s.unshift([x, y]); if (!(i === path.length - 1 && grows)) s.pop(); });
  return s;
};
const think = () => {
  const [hx, hy] = body[0];
  // 1) a fruit, if after eating it the tail can still be reached (so the snake never traps itself)
  const order = fruits.map((f) => ({ f, d: Math.abs(f.x - hx) + Math.abs(f.y - hy) - (f.name ? 6 : 0) - (f.gold ? 10 : 0) })).sort((a, b) => a.d - b.d);
  for (const { f } of order.slice(0, 4)) {
    const path = bfs(body, hx, hy, f.x, f.y);
    if (!path) continue;
    const after = walk(body, path, true), t = after[after.length - 1];
    if (after.length >= N * N - 1 || bfs(after, after[0][0], after[0][1], t[0], t[1])) return path[0];
  }
  // 2) no safe fruit: follow the tail the long way round (it keeps space free), 3) else the biggest open space
  const tail = body[body.length - 1];
  let best = null;
  for (const [dx, dy] of DIRS) {
    const nx = hx + dx, ny = hy + dy;
    if (nx < 0 || ny < 0 || nx >= N || ny >= N) continue;
    if (body.slice(0, -1).some(([x, y]) => x === nx && y === ny)) continue;
    const after = walk(body, [[nx, ny]], false);
    const toTail = bfs(after, nx, ny, tail[0], tail[1]);
    const score = (toTail ? 1000 + toTail.length : 0) + floodSize(after, nx, ny);
    if (!best || score > best[1]) best = [[nx, ny], score];
  }
  return best ? best[0] : null;
};

/* ---------- one move ---------- */
const pops = $('pops');
const pop = (text, x, y, big) => {   // x, y in board cells
  const e = document.createElement('div'); e.className = 'pop' + (big ? ' big' : ''); e.textContent = text;
  e.style.left = Math.max(90 + 130, Math.min(990 - 130, 90 + (x + 0.5) * CELL)) + 'px'; e.style.top = (342 + (y + 0.5) * CELL) + 'px';
  pops.appendChild(e); setTimeout(() => e.remove(), 1500);
};
const banner = (html, cls = '', ms = 2500) => {
  const b = $('banner'), t = $('banner-text'); t.className = 'banner-in ' + cls; t.innerHTML = html;
  b.hidden = false; clearTimeout(banner.tm); banner.tm = setTimeout(() => { b.hidden = true; }, ms);
};
const esc = (s) => String(s).replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[m]);
let newBestSaid = false;
let stepNo = 0, eating = null;
const step = () => {
  if (phase !== 'play') return;
  stepNo++;
  if (eating && eating.step < stepNo - 1) eating = null;
  const nxt = think();
  if (!nxt) return endRound(false);
  dir = [nxt[0] - body[0][0], nxt[1] - body[0][1]];
  body.unshift(nxt);
  const fi = fruits.findIndex((f) => f.x === nxt[0] && f.y === nxt[1]);
  if (fi >= 0) {
    const f = fruits.splice(fi, 1)[0], plus = f.gold ? clamp(Math.round(cfg.gold) || 3, 1, 10) : 1;
    grow += plus - 1; ate += plus; S.today += plus; save();
    const arrive = 0.85 / steps() / FAST;   // seconds until the head is on the fruit
    chomp(f.gold, arrive);
    eating = { ...f, step: stepNo };
    setTimeout(() => { bite(f); pop(f.name ? `+${plus} ${f.name}` : `+${plus}`, f.x, f.y, f.gold); }, arrive * 1000);
    if (f.name) host([f.gold ? '🌟 GOLDEN FRUIT FROM ' : '', { name: f.name }, f.gold ? '' : pick([' FED THE SNAKE! 🍓', '\'S FRUIT IS GONE — YUMMY!', ' JUST MADE THE SNAKE LONGER!']), ` IT IS ${body.length} LONG NOW!`], 1);
    if (ate > S.best) {
      S.best = ate; save();
      if (!newBestSaid && ate >= 10) { newBestSaid = true; banner(`🏆 NEW BEST: ${ate}!`, 'gold', 2500); host(['🏆 NEW RECORD! THE SNAKE ATE ', String(ate), ' FRUITS — HELP IT GO EVEN FURTHER!'], 2); }
    }
    ensureFruit(); updateUI(true);
    prevTail = null;
  } else if (grow > 0) { grow--; prevTail = null; }
  else prevTail = body.pop();
  if (body.length >= N * N) endRound(true);
};
const endRound = (full) => {
  phase = 'over'; phaseT = full ? 7 : 4; flash = full ? 0 : 1.2; newBestSaid = false;
  if (full) { banner(`🏆 THE SNAKE FILLED THE BOARD!<small>ROUND ${S.round} · ${ate} FRUITS</small>`, 'gold', 6500); host(['🏆 UNBELIEVABLE! THE SNAKE FILLED THE WHOLE BOARD! A NEW ROUND STARTS NOW!'], 3); }
  else { banner(`OUCH! 🐍<small>ROUND ${S.round} · ${ate} FRUITS · BEST ${S.best}</small>`, 'red', 3500); host(['OUCH! THE SNAKE GOT STUCK AFTER ', String(ate), ' FRUITS. NEW ROUND — TYPE IN CHAT TO FEED IT!'], 2); }
  sfx.tone({ freq: full ? 523 : 330, to: full ? 1046 : 110, type: 'sawtooth', dur: 0.6, vol: 0.06 });
};

/* ---------- drawing ---------- */
let t = 0;   // 0…1: how far between two moves (smooth movement)

/* ---------- eating: open mouth, bite, juice drops, and the lump that goes down the body ---------- */
const JUICE = { '🍎': '#ef4444', '🍊': '#fb923c', '🍋': '#facc15', '🍉': '#f43f5e', '🍇': '#8b5cf6', '🍓': '#e11d48', '🍒': '#dc2626', '🍑': '#fdba74',
  '🍍': '#fbbf24', '🥝': '#84cc16', '🍌': '#fde047', '🥭': '#f59e0b', '🍐': '#a3e635', '🫐': '#6366f1', '🥥': '#f5f5f4', '🌟': '#ffd23a' };
let drops = [], lumps = [], snap = 0;
const bite = (f) => {
  snap = 1;   // the jaws close hard
  const col = JUICE[f.emo] || '#f43f5e', n = f.gold ? 18 : 11;
  for (let i = 0; i < n; i++) {
    const a = Math.random() * Math.PI * 2, v = (f.gold ? 260 : 170) * (0.5 + Math.random());
    drops.push({ x: cc(f.x), y: cc(f.y), vx: Math.cos(a) * v, vy: Math.sin(a) * v, life: 0.45 + Math.random() * 0.3, r: CELL * (0.06 + Math.random() * 0.07), col: f.gold && i % 2 ? '#fff6c2' : col });
  }
  lumps.push({ d: 0.3, big: f.gold ? 1.25 : 1 }); if (lumps.length > 4) lumps.shift();
};
const pointAt = (pts, d) => {   // the point d cells behind the head, along the drawn body
  for (let i = 1; i < pts.length; i++) {
    const L = Math.hypot(pts[i][0] - pts[i - 1][0], pts[i][1] - pts[i - 1][1]) / CELL;
    if (d <= L || i === pts.length - 1) { const u = L ? Math.min(1, d / L) : 0; return [pts[i - 1][0] + (pts[i][0] - pts[i - 1][0]) * u, pts[i - 1][1] + (pts[i][1] - pts[i - 1][1]) * u]; }
    d -= L;
  }
  return pts[0];
};
const effects = (dt) => {   // move the drops and the lumps (once a frame)
  snap = Math.max(0, snap - dt * 6);
  for (const p of drops) { p.x += p.vx * dt; p.y += p.vy * dt; p.vx *= 0.92; p.vy *= 0.92; p.life -= dt; }
  drops = drops.filter((p) => p.life > 0);
  const len = body.length;
  for (const l of lumps) l.d += dt * steps() * 0.55;   // slowly down the body
  lumps = lumps.filter((l) => l.d < Math.min(len - 1, 22));
};
const cc = (v) => (v + 0.5) * CELL;
const draw = (now) => {
  c.setTransform(1, 0, 0, 1, 0, 0);
  c.drawImage(boardImg, 0, 0);
  c.setTransform(k, 0, 0, k, 0, 0);
  // fruits (pop in with a bounce), names under them
  for (const f of fruits) {
    const a = Math.min(1, (now - f.born) / 350), s = a < 1 ? 0.4 + 0.8 * Math.sin(a * Math.PI / 2) : 1 + Math.sin(now / 300 + f.x) * 0.04;
    const img = fruitSprite(f.emo, f.gold), w = img.width / k * s;
    if (f.gold) { c.fillStyle = 'rgba(255,215,60,.35)'; c.beginPath(); c.arc(cc(f.x), cc(f.y), CELL * 0.9, 0, 7); c.fill(); }
    c.drawImage(img, cc(f.x) - w / 2, cc(f.y) - w / 2, w, w);
    if (f.name) {
      c.font = '700 17px Rajdhani, Oswald, sans-serif'; c.textAlign = 'center'; c.textBaseline = 'middle';
      const tw = c.measureText(f.name).width + 12, ty = f.y >= N - 1 ? cc(f.y) - CELL * 0.85 : cc(f.y) + CELL * 0.78;
      c.fillStyle = f.gold ? 'rgba(120,80,0,.9)' : 'rgba(10,14,30,.82)'; c.beginPath(); c.roundRect(cc(f.x) - tw / 2, ty - 11, tw, 22, 11); c.fill();
      c.fillStyle = f.gold ? '#ffe27a' : '#fff'; c.fillText(f.name, cc(f.x), ty + 1);
    }
  }
  // the snake: the head slides forward, the tail end slides after it
  const pts = body.map(([x, y]) => [cc(x), cc(y)]);
  if (phase === 'play' && pts.length > 1) {
    pts[0] = [pts[1][0] + (pts[0][0] - pts[1][0]) * t, pts[1][1] + (pts[0][1] - pts[1][1]) * t];
    if (prevTail) { const L = pts[pts.length - 1], P = [cc(prevTail[0]), cc(prevTail[1])]; pts.push([P[0] + (L[0] - P[0]) * t, P[1] + (L[1] - P[1]) * t]); }
  }
  const red = flash > 0 && Math.floor(flash * 8) % 2 === 0;
  c.lineCap = 'round'; c.lineJoin = 'round';
  c.beginPath(); pts.forEach(([x, y], i) => (i ? c.lineTo(x, y) : c.moveTo(x, y)));
  c.strokeStyle = red ? '#7f1d1d' : '#4c1d95'; c.lineWidth = CELL * 0.84; c.stroke();
  c.strokeStyle = red ? '#ef4444' : '#a855f7'; c.lineWidth = CELL * 0.64; c.stroke();
  c.strokeStyle = red ? '#fecaca' : 'rgba(233,213,255,.75)'; c.lineWidth = CELL * 0.13; c.setLineDash([CELL * 0.12, CELL * 0.42]); c.stroke(); c.setLineDash([]);
  // the lumps of eaten fruit going down the body
  if (!red) for (const l of lumps) {
    const [lx, ly] = pointAt(pts, l.d), fade = Math.min(1, l.d / 0.8, (Math.min(body.length - 1, 22) - l.d) / 3), r = CELL * 0.5 * l.big * (0.75 + 0.25 * fade);
    c.fillStyle = '#4c1d95'; c.beginPath(); c.arc(lx, ly, r, 0, 7); c.fill();
    c.fillStyle = '#b26bff'; c.beginPath(); c.arc(lx, ly, r - CELL * 0.1, 0, 7); c.fill();
    c.fillStyle = 'rgba(255,255,255,.35)'; c.beginPath(); c.arc(lx - r * 0.3, ly - r * 0.3, r * 0.28, 0, 7); c.fill();
  }
  // head: eyes look where it goes; the mouth opens wide when a fruit is close and bites it ("nom nom")
  const [hx, hy] = pts[0], [dx, dy] = dir, side = [-dy, dx];
  let open = 0;
  if (phase === 'play') {
    if (eating && eating.step === stepNo) {
      open = t < 0.7 ? 1 : Math.max(0, (1 - t) / 0.3);
      const sh = t < 0.55 ? 1 : Math.max(0.2, 1 - (t - 0.55) / 0.45), img = fruitSprite(eating.emo, eating.gold), w = img.width / k * sh;   // the fruit goes into the mouth
      c.drawImage(img, cc(eating.x) - w / 2, cc(eating.y) - w / 2, w, w);
    } else if (fruits.some((f) => Math.abs(f.x - body[0][0]) + Math.abs(f.y - body[0][1]) === 1)) open = Math.min(1, t * 1.2);
  }
  open *= 1 - snap;
  const HR = CELL * 0.52 * (1 + snap * 0.12), ang = Math.atan2(dy, dx), gap = open * 1.05;
  if (gap > 0.04) {   // inside of the mouth and the tongue
    c.fillStyle = '#3b0a2a'; c.beginPath(); c.moveTo(hx, hy); c.arc(hx, hy, HR * 0.95, ang - gap, ang + gap); c.closePath(); c.fill();
    c.fillStyle = '#ff6b9a'; c.beginPath(); c.ellipse(hx + dx * HR * 0.45, hy + dy * HR * 0.45, HR * 0.3, HR * 0.14 * (0.5 + open), ang, 0, 7); c.fill();
  }
  c.fillStyle = red ? '#ef4444' : '#a855f7'; c.beginPath();
  if (gap > 0.04) { c.moveTo(hx, hy); c.arc(hx, hy, HR, ang + gap, ang - gap + Math.PI * 2); c.closePath(); } else c.arc(hx, hy, HR, 0, 7);
  c.fill();
  if (gap > 0.04) {   // little teeth
    c.fillStyle = '#fff';
    for (const sg of [-1, 1]) { const a = ang + sg * gap * 0.75, tx = hx + Math.cos(a) * HR * 0.86, ty = hy + Math.sin(a) * HR * 0.86;
      c.beginPath(); c.arc(tx, ty, CELL * 0.055, 0, 7); c.fill(); }
  }
  for (const sgn of [-1, 1]) {
    const back = CELL * (0.05 + 0.16 * open), ex = hx + side[0] * sgn * CELL * 0.27 - dx * back, ey = hy + side[1] * sgn * CELL * 0.27 - dy * back;
    c.fillStyle = '#fff'; c.beginPath(); c.arc(ex, ey, CELL * 0.17, 0, 7); c.fill();
    if (red) { c.strokeStyle = '#111'; c.lineWidth = 3; c.beginPath(); c.moveTo(ex - 5, ey - 5); c.lineTo(ex + 5, ey + 5); c.moveTo(ex + 5, ey - 5); c.lineTo(ex - 5, ey + 5); c.stroke(); }
    else { c.fillStyle = '#111'; c.beginPath(); c.arc(ex + dx * CELL * 0.06, ey + dy * CELL * 0.06, CELL * 0.085, 0, 7); c.fill(); }
  }
  for (const p of drops) { c.globalAlpha = Math.min(1, p.life * 3); c.fillStyle = p.col; c.beginPath(); c.arc(p.x, p.y, p.r, 0, 7); c.fill(); }   // juice
  c.globalAlpha = 1;
};

/* ---------- numbers on screen ---------- */
const updateUI = (bumpIt) => {
  $('score').textContent = ate; $('best').textContent = S.best; $('today').textContent = S.today.toLocaleString('en');
  $('round').textContent = `ROUND ${S.round} · LIVE 24/7`;
  if (bumpIt) { const b = $('score'); b.classList.remove('bump'); void b.offsetWidth; b.classList.add('bump'); }
};
// the hand taps SUBSCRIBE every 25 seconds
setInterval(() => {
  const h = $('hand'), s = $('subscribe'); h.classList.remove('on'); void h.offsetWidth; h.classList.add('on');
  setTimeout(() => { s.classList.remove('tap'); void s.offsetWidth; s.classList.add('tap'); sfx.tone({ freq: 1200, type: 'sine', dur: 0.06, vol: 0.04 }); }, 650);
}, 25000);

/* ---------- chat: every message drops a fruit with a name ---------- */
const lastDrop = new Map();
const dropFruit = (name, gold) => {
  const named = fruits.filter((f) => f.name).length;
  if (!gold && named >= clamp(Math.round(cfg.named) || 6, 1, 20)) return false;
  const p = freeCell(); if (!p) return false;
  fruits.push({ x: p[0], y: p[1], emo: gold ? '🌟' : pick(FRUITS), name: String(name).toUpperCase().slice(0, 12), gold: !!gold, born: performance.now() });
  sfx.tone({ freq: 880, to: 1320, type: 'sine', dur: 0.12, vol: 0.05 });
  return true;
};
const chat = liveChat({
  game: 'snake', rows: 3, alwaysOn: true,
  aiInfo: () => `Game: SNAKE LIVE — a snake plays by itself on a 20x20 board and eats fruit; every fruit makes it one longer. `
    + `Now: round ${S.round}, it ate ${ate} fruits this round, it is ${body.length} long, best round ever ${S.best}, ${S.today} fruits today. `
    + `Rules: every chat message drops a fruit with the viewer's name on the board and the snake goes to eat it; a new subscriber drops a GOLDEN fruit (+3); `
    + `when the snake gets stuck a new round starts; if it fills the whole board it is a big win.`,
  duck: duckAll,
  sfx: () => { sfx.tone({ freq: 784, type: 'sine', dur: 0.18, vol: 0.09 }); sfx.tone({ freq: 1175, type: 'sine', dur: 0.3, vol: 0.08, delay: 0.14 }); },
  onSub: (name) => { dropFruit(name, true); banner(`🌟 GOLDEN FRUIT<small>THANK YOU ${esc(String(name).toUpperCase().slice(0, 16))} FOR SUBSCRIBING!</small>`, 'gold', 3500); },
  onSuper: (code, name) => { dropFruit(name, true); dropFruit(name, true); },
  onText: (name, text, code, author) => {   // a fruit for (almost) every chat — one per viewer every 8 seconds
    const now = performance.now();
    if (!cfg.chat || (lastDrop.get(author) || -1e9) > now - clamp(Number(cfg.cooldown), 0, 120) * 1000) return null;
    if (dropFruit(name, false)) lastDrop.set(author, now);
    return null;   // LUNA still welcomes / answers as usual
  },
  emptyHint: 'Type anything — a fruit with your name drops for the snake!',
  welcomeHint: 'TYPE ANYTHING — A FRUIT WITH YOUR NAME DROPS FOR THE SNAKE!',
  hints: ['TYPE ANYTHING = A FRUIT WITH YOUR NAME!', 'SUBSCRIBE = A GOLDEN FRUIT 🌟', 'LIKE IF THE SNAKE SHOULD GET EVEN LONGER!'],
  idleLines: [
    ['TYPE ANYTHING IN CHAT — A FRUIT WITH YOUR NAME DROPS AND THE SNAKE COMES TO EAT IT!'],
    ['SUBSCRIBE AND A GOLDEN FRUIT DROPS — IT MAKES THE SNAKE THREE LONGER!'],
    ['CAN THE SNAKE FILL THE WHOLE BOARD? KEEP FEEDING IT!'],
    ['LIKE THE LIVE IF YOU WANT THE SNAKE TO BREAK THE RECORD!'],
  ],
  idleLine: () => (phase === 'play' && ate > 0 ? [`THE SNAKE ATE ${ate} FRUITS THIS ROUND — THE RECORD IS ${S.best}. TYPE IN CHAT TO FEED IT!`] : null),
  welcome: (name, code, text) => [/salam|সালাম|আসসালামু/i.test(text) ? 'WA ALAIKUM ASSALAM ' : pick(['WELCOME ', 'HELLO ', 'HI ']), { name }, '! YOUR FRUIT IS ON THE BOARD — WATCH THE SNAKE EAT IT! 🍓'],
  reply: (name, code, text) => {
    if (/score|long|record|best|কত|বড়/i.test(text)) return [{ name }, `, THE SNAKE IS ${body.length} LONG — ${ate} FRUITS THIS ROUND, THE RECORD IS ${S.best}!`];
    if (/\b(hi|hii+|hello|hey)\b|salam|হাই|হ্যালো/i.test(text)) return [/salam/i.test(text) ? 'WA ALAIKUM ASSALAM ' : 'HELLO ', { name }, '! YOUR FRUIT JUST DROPPED — WATCH THE SNAKE GO FOR IT!'];
    return null;
  },
  demoTexts: ['hello', 'go snake go!', 'how long is it?', 'Bangladesh 🇧🇩', 'nice', 'eat my fruit', 'wow', 'salam', 'record?', 'faster!'],
});
const host = (parts, prio = 2) => { if (chat.active) chat.say(parts, prio); };
const showText = () => {
  const t = String(cfg.title || 'SNAKE LIVE').toUpperCase().split(' ').map((w) => w.replace(/[&<>"']/g, ''));
  $('title').innerHTML = t.length > 1 ? `${t.slice(0, -1).join(' ')} <b>${t[t.length - 1]}</b>` : t[0];
  $('chan').textContent = QS.get('chan') || cfg.chan || '';
  $('pill').hidden = !cfg.chat;
};
showText();
setInterval(async () => {   // new settings from the admin panel
  const n = await getCfg(); if (!n) return;
  if (n.reset !== cfg.reset) { try { localStorage.removeItem(KEY); } catch (e) { /* ignore */ } location.reload(); return; }
  if (n.autoplay === false) { save(); location.reload(); return; }
  if (n.rev === cfg.rev) return;
  const musicWas = cfg.music; Object.assign(cfg, n); showText();
  if (music && cfg.music && !musicWas) music.start(); else if (music && !cfg.music && musicWas) music.stop();
}, 30000);

/* ---------- loop ---------- */
addEventListener('resize', fit); fit();
newRound();
let last = performance.now(), acc = 0;
const frame = (now) => {
  const dt = Math.min(0.1, (now - last) / 1000) * FAST; last = now;
  if (phase === 'play') {
    acc += dt * steps();
    let guard = 0;
    while (acc >= 1 && guard++ < 60) { acc -= 1; step(); if (phase !== 'play') break; }
    t = Math.min(1, acc);
  } else {
    phaseT -= dt; flash = Math.max(0, flash - dt);
    if (phaseT <= 0) { S.round++; save(); newRound(); acc = 0; lumps = []; eating = null; }
  }
  effects(dt);
  if (sizeOK()) draw(now);
  requestAnimationFrame(frame);
};
const sizeOK = () => !!boardImg;
requestAnimationFrame(frame);
setInterval(ensureFruit, 3000);
autoUpdate('snake', save);   // after a deploy: reload with the game where it was (no Restart needed)
goLive({ left: 40, top: 1336, size: 190, duck: duckAll });   // the owner joins the live from a phone (Admin → Join Live)
window.__snake = { S, body: () => body, fruits: () => fruits, dropFruit, phase: () => phase };
