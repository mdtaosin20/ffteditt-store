// FFTEdit · Country Flag Battle. Runs by itself, made for 24/7 vertical live streams.
// 195 flags bounce inside a turning ring with a gap. A flag that slips out is eliminated;
// the last flag left qualifies for the final. When the final is full, the final round finds the champion.
import { Sfx, Commentator } from '/game/audio.js?v=6';

const $ = (id) => document.getElementById(id);
const QS = new URLSearchParams(location.search);
const FAST = Math.max(1, Math.min(60, Number(QS.get('fast')) || 1)); // testing only: run the game faster

/* ---------------------------------------------------------------- settings */
const DEF = { title: 'COUNTRY FLAG BATTLE', target: 70, ring: 100, speed: 100, gap: 100, rev: '', reset: '', voice: null };

async function fetchConfig() {
  try {
    const r = await fetch('/api/flags.php', { cache: 'no-store' });
    if (r.ok) return await r.json();
  } catch (e) { /* offline or not set up yet */ }
  return null;
}

function fitStage() {
  const s = Math.min(innerWidth / 1080, innerHeight / 1920);
  $('stage').style.transform = `translate(-50%, -50%) scale(${s})`;
}

const clamp = (v, a, b) => Math.max(a, Math.min(b, v));
const TAU = Math.PI * 2;
const angDiff = (a, b) => { let d = (a - b) % TAU; if (d > Math.PI) d -= TAU; if (d < -Math.PI) d += TAU; return d; };
const fmtTime = (s) => {
  s = Math.max(0, Math.round(s));
  const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), x = s % 60;
  return (h ? h + ':' + String(m).padStart(2, '0') : String(m)) + ':' + String(x).padStart(2, '0');
};

/* ------------------------------------------------------------------ arena */
const CX = 540, CY = 520;            // ring centre inside the arena canvas (stage y = 960)
const RING = 430, LINE = 12;
const RIN = RING - LINE / 2;          // inner edge of the ring line
const FIXED = 1 / 90;

/* ------------------------------------------------------------------ sounds */
class FlagSfx extends Sfx {
  wall(s = 1) { if (!this.running || !this.limit('wall', 85)) return; this.tone({ freq: 480 + Math.random() * 160, type: 'triangle', dur: 0.06, vol: 0.06 * s }); }
  hit(s = 1) { if (!this.running || !this.limit('hit', 110)) return; this.burst({ freq: 2300 + Math.random() * 600, q: 3, dur: 0.04, vol: 0.14 * s }); }
  out() {
    if (!this.running || !this.limit('out', 70)) return;
    this.tone({ freq: 880, to: 160, type: 'sine', dur: 0.38, vol: 0.16 });
    this.burst({ freq: 1800, sweepTo: 300, q: 1.2, dur: 0.35, vol: 0.18 });
  }
  spawn() {
    if (!this.running) return;
    this.tone({ freq: 220, to: 880, type: 'sawtooth', dur: 0.8, vol: 0.05 });
    this.burst({ freq: 500, sweepTo: 4000, q: 0.8, dur: 0.8, vol: 0.18 });
  }
  tick() { if (this.running) this.tone({ freq: 1250, type: 'square', dur: 0.05, vol: 0.05 }); }
}

/* ------------------------------------------------------------------- main */
async function main() {
  fitStage(); addEventListener('resize', fitStage);

  const [remote, atlasInfo] = await Promise.all([
    fetchConfig(),
    fetch('/flags/flags.json').then((r) => r.json()),
  ]);
  const cfg = { ...DEF, ...(remote || {}) };
  const atlas = new Image();
  atlas.src = '/flags/flags.png';
  await atlas.decode().catch(() => {});

  const C = atlasInfo.countries;                    // [{code, name}]
  const FW = atlasInfo.w, FH = atlasInfo.h, COLS = atlasInfo.cols, ROWS = Math.ceil(C.length / COLS);
  const byCode = new Map(C.map((c, i) => [c.code, i]));
  const NAME = (i) => C[i].name.toUpperCase();

  const sprite = (el, i, w) => {
    const h = w * 0.75;
    el.style.width = w + 'px'; el.style.height = h + 'px';
    el.style.backgroundSize = `${COLS * w}px ${ROWS * h}px`;
    el.style.backgroundPosition = `${-(i % COLS) * w}px ${-Math.floor(i / COLS) * h}px`;
  };
  const spriteHTML = (i, w) => {
    const h = w * 0.75;
    return `<span class="flag" style="width:${w}px;height:${h}px;background-size:${COLS * w}px ${ROWS * h}px;background-position:${-(i % COLS) * w}px ${-Math.floor(i / COLS) * h}px"></span>`;
  };

  /* ---------- saved progress (survives a page reload) ---------- */
  const KEY = 'fft_flags';
  let st = null;
  try { st = JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (e) { /* private mode */ }
  if (!st || st.reset !== cfg.reset || !Array.isArray(st.qualified)) st = { reset: cfg.reset, qualified: [], champs: [], avg: 0, cup: 1 };
  st.qualified = st.qualified.filter((c) => byCode.has(c));
  st.champs = (st.champs || []).filter((c) => byCode.has(c));
  const save = () => { try { localStorage.setItem(KEY, JSON.stringify(st)); } catch (e) { /* ignore */ } };

  $('title').textContent = cfg.title;
  $('st-target').textContent = cfg.target;

  /* ---------- the small flag grid ---------- */
  const gridEls = C.map((c, i) => { const d = document.createElement('div'); d.className = 'flag'; sprite(d, i, 32); d.title = c.name; return d; });
  $('grid').append(...gridEls);

  /* ---------- sound + voice ---------- */
  const sfx = new FlagSfx();
  const voice = new Commentator(cfg.voice, sfx);
  const soundBtn = $('sound-btn');
  const unlock = () => { sfx.resume(); soundBtn.hidden = true; };
  soundBtn.addEventListener('click', unlock);
  addEventListener('pointerdown', unlock);
  sfx.resume();
  setTimeout(() => { if (sfx.ok && !sfx.running) soundBtn.hidden = false; }, 1500);

  /* ---------- canvas ---------- */
  const cv = $('arena'), c = cv.getContext('2d');
  let k = 1;
  const resize = () => {
    const r = cv.getBoundingClientRect();
    const dpr = Math.min(devicePixelRatio || 1, 1.5);
    cv.width = Math.max(1, Math.round(r.width * dpr));
    cv.height = Math.max(1, Math.round(r.height * dpr));
    k = cv.width / 1080;
  };
  resize(); addEventListener('resize', resize);

  /* ---------- round state ---------- */
  let flags = [], order = [], alive = 0, entrants = 0, isFinal = false;
  let phase = 'spawn', phaseT = 0, roundT = 0, simT = 0;
  let gapA = Math.random() * TAU, ringDir = 1, gapNow = 0, size = 40, winner = null, lastOut = null;
  let nextCall = 28 + Math.random() * 15, nextSub = 200 + Math.random() * 100;

  const FLAG_W = 42; // every flag keeps the same size for the whole round
  const targetSize = () => FLAG_W;
  const speed = () => 360 * (cfg.speed / 100) * (1 + 0.3 * clamp((30 - alive) / 30, 0, 1));
  const gapTarget = () => {
    const r = size * 0.47;
    const stall = 1 + clamp((roundT - 90) / 45, 0, 2); // very long round: open the gap wider
    const px = Math.max(150, 2 * r * 2.1) * (cfg.gap / 100) * stall;
    return Math.asin(Math.min(0.95, px / (2 * RIN)));
  };

  const gridClass = () => {
    const inArena = new Set(flags.filter((f) => f.state === 'in').map((f) => f.i));
    const q = new Set(st.qualified.map((x) => byCode.get(x)));
    gridEls.forEach((el, i) => {
      let cls = 'flag';
      if (q.has(i)) cls += ' q';
      if (!inArena.has(i) && (isFinal || !q.has(i))) cls += ' out';
      if (winner && winner.i === i && phase === 'won' && isFinal) cls = 'flag q champ';
      el.className = cls;
    });
  };

  const showQualified = (fresh) => {
    const list = st.qualified.slice(-5);
    const first = st.qualified.length - list.length;
    let html = list.map((code, n) => {
      const i = byCode.get(code);
      const hi = fresh && n === list.length - 1 ? ' hi' : '';
      return `<div class="row${hi}"><span class="n">#${first + n + 1}</span><span class="nm">${spriteHTML(i, 44)}${C[i].name}</span><span class="w">WIN</span></div>`;
    }).join('');
    for (let n = list.length; n < 5; n++) html += '<div class="row empty">— waiting —</div>';
    $('qual-rows').innerHTML = html;
    $('qual-title').textContent = isFinal ? 'GRAND FINAL' : 'QUALIFIED FOR FINAL';
  };

  const updateUI = () => {
    const q = Math.min(st.qualified.length, cfg.target);
    const spots = Math.max(0, cfg.target - q);
    const round = isFinal ? 'FINAL' : String(q + 1);
    $('st-round').textContent = round;
    $('st-qual').textContent = q;
    $('st-k3').textContent = isFinal ? 'FLAGS LEFT' : 'SPOTS LEFT';
    $('st-left').textContent = isFinal ? alive : spots;
    $('left').innerHTML = `<b>${alive}</b> / ${entrants} FLAGS LEFT`;
    $('bar').style.width = (entrants > 1 ? ((entrants - alive) / (entrants - 1)) * 100 : 0) + '%';
    $('foot-r').textContent = isFinal ? `CUP #${st.cup} · ${entrants} FLAGS · 1 CHAMPION` : `ROUND ${round} · ${spots} SPOTS LEFT`;
    $('foot-m').textContent = isFinal ? 'GRAND FINAL' : `ROUND ${round} · QUALIFYING`;
  };

  const updateTimer = () => {
    if (isFinal) { $('timer').innerHTML = phase === 'won' ? 'WE HAVE A <b>CHAMPION</b>' : 'GRAND FINAL · <b>LIVE</b>'; return; }
    const avg = st.avg || 95;
    const left = (cfg.target - st.qualified.length) * avg - Math.min(roundT, avg * 0.95);
    $('timer').innerHTML = `FINAL IN <b>${fmtTime(left)}</b>`;
  };

  const banner = (text, small) => {
    const b = $('banner'), t = $('banner-text');
    t.innerHTML = `${text}<small>${small}</small>`;
    b.hidden = false; t.style.animation = 'none'; void t.offsetWidth; t.style.animation = '';
    clearTimeout(banner.timer); banner.timer = setTimeout(() => { b.hidden = true; }, 2300);
  };

  const startRound = () => {
    isFinal = st.qualified.length >= cfg.target;
    const qs = new Set(st.qualified);
    const pool = isFinal ? st.qualified.slice(0, cfg.target) : C.map((x) => x.code).filter((x) => !qs.has(x));
    entrants = alive = pool.length;
    size = targetSize(alive);
    const r0 = RIN - size * 0.6;
    flags = pool.map((code, n) => {
      const a = Math.random() * TAU, d = Math.sqrt(Math.random()) * r0, va = Math.random() * TAU;
      const sp = speed();
      return {
        i: byCode.get(code), x: CX + Math.cos(a) * d, y: CY + Math.sin(a) * d,
        vx: Math.cos(va) * sp, vy: Math.sin(va) * sp, rot: (Math.random() - 0.5) * 0.6, spin: (Math.random() - 0.5) * 1.6,
        state: 'in', exiting: false, t: 0, delay: (n / pool.length) * 1.1,
      };
    });
    order = flags.slice();
    winner = null; lastOut = null; roundT = 0; phase = 'spawn'; phaseT = 0; gapNow = 0;
    ringDir = Math.random() < 0.5 ? -1 : 1;
    $('win').hidden = true; $('feed').innerHTML = '';
    gridClass(); showQualified(false); updateUI(); updateTimer();
    if (isFinal) banner('GRAND FINAL', `${entrants} FLAGS · 1 CHAMPION`);
    else banner(`ROUND ${st.qualified.length + 1}`, `QUALIFYING · ${entrants} FLAGS`);
    sfx.spawn();
    if (isFinal) voice.say('flag_final', { urgent: true });
    else if (simT > 5) voice.say('flag_start', { chance: 0.35 });
  };

  const eliminate = (f) => {
    f.state = 'out'; f.t = 0;
    alive--;
    order = order.filter((g) => g !== f);
    lastOut = f;
    const fe = $('feed');
    fe.innerHTML = `${spriteHTML(f.i, 40)}<span>${NAME(f.i)} IS OUT</span>`;
    fe.classList.remove('pop'); void fe.offsetWidth; fe.classList.add('pop');
    gridEls[f.i].classList.add('out');
    sfx.out();
    updateUI();
    if (alive === 5) voice.say('flag_five', { chance: 0.9 });
    else if (alive === 2) voice.say('flag_two', { chance: 0.9 });
    else if (alive > 5 && voice.idle && Math.random() < 0.07) voice.say('flag_out');
    if (alive <= 1) onWinner();
  };

  const onWinner = () => {
    winner = flags.find((f) => f.state === 'in') || lastOut;
    if (winner.state !== 'in') { winner.state = 'in'; alive = 1; } // two flags left together: the last one out still wins
    phase = 'won'; phaseT = 0;
    st.avg = st.avg ? st.avg * 0.8 + roundT * 0.2 : roundT;
    const code = C[winner.i].code;
    if (isFinal) {
      st.champs.unshift(code); st.champs = st.champs.slice(0, 10);
    } else {
      st.qualified.push(code);
      showQualified(true);
    }
    save();
    updateUI(); gridClass(); updateTimer();
  };

  const showWinPopup = () => {
    const w = $('win');
    w.className = isFinal ? 'win champ' : 'win';
    $('win-t').textContent = isFinal ? 'CHAMPION!' : 'ROUND WINNER';
    sprite($('win-flag'), winner.i, isFinal ? 300 : 240);
    $('win-name').textContent = C[winner.i].name;
    $('win-sub').textContent = isFinal ? `CUP #${st.cup} WINNER` : `QUALIFIED #${st.qualified.length} / ${cfg.target}`;
    w.hidden = false;
    if (isFinal) { sfx.win(); voice.say('flag_champ', { urgent: true, prefix: NAME(winner.i) + '!' }); }
    else { sfx.goal(); voice.say('flag_win', { urgent: true, prefix: NAME(winner.i) + '!' }); }
  };

  /* ---------- physics ---------- */
  let hits = 0, walls = 0;
  const step = (dt) => {
    const r = size * 0.47, D = 2 * r, v = speed();
    gapA += ringDir * 0.6 * (cfg.ring / 100) * dt;
    const want = phase === 'spawn' ? 0 : gapTarget();
    gapNow += (want - gapNow) * Math.min(1, dt * 2.5);
    const passMargin = Math.asin(Math.min(1, (r * 0.85) / RIN));

    for (const f of flags) {
      if (f.state === 'gone') continue;
      if (f.state === 'out') {
        f.t += dt; f.vy += 1400 * dt; f.x += f.vx * dt; f.y += f.vy * dt; f.rot += f.spin * 3 * dt;
        if (f.t > 1) f.state = 'gone';
        continue;
      }
      if (phase === 'won' && f === winner) { // winner glides to the middle
        f.x += (CX - f.x) * Math.min(1, dt * 3); f.y += (CY - f.y) * Math.min(1, dt * 3); f.rot *= 1 - Math.min(1, dt * 4);
        continue;
      }
      // keep everyone moving at about the same speed
      const sp = Math.hypot(f.vx, f.vy) || 1;
      const m = 1 + (v / sp - 1) * 0.05;
      f.vx *= m; f.vy *= m;
      f.x += f.vx * dt; f.y += f.vy * dt; f.rot += f.spin * dt;
    }

    // flag against flag (sweep along x)
    for (let a = 1; a < order.length; a++) { // insertion sort, list is almost sorted already
      const f = order[a]; let b = a - 1;
      while (b >= 0 && order[b].x > f.x) { order[b + 1] = order[b]; b--; }
      order[b + 1] = f;
    }
    if (phase !== 'won') {
      for (let a = 0; a < order.length; a++) {
        const A = order[a];
        for (let b = a + 1; b < order.length; b++) {
          const B = order[b];
          if (B.x - A.x >= D) break;
          const dx = B.x - A.x, dy = B.y - A.y, d2 = dx * dx + dy * dy;
          if (d2 >= D * D || d2 === 0) continue;
          const d = Math.sqrt(d2), nx = dx / d, ny = dy / d, pen = (D - d) / 2;
          A.x -= nx * pen; A.y -= ny * pen; B.x += nx * pen; B.y += ny * pen;
          const rv = (B.vx - A.vx) * nx + (B.vy - A.vy) * ny;
          if (rv < 0) {
            A.vx += rv * nx; A.vy += rv * ny; B.vx -= rv * nx; B.vy -= rv * ny;
            A.spin += (Math.random() - 0.5) * 0.8; B.spin -= (Math.random() - 0.5) * 0.8;
            A.spin = clamp(A.spin, -3, 3); B.spin = clamp(B.spin, -3, 3);
            if (rv < -150) hits++;
          }
        }
      }
    }

    // flag against the ring
    for (const f of order) {
      if (f.state !== 'in' || (phase === 'won' && f === winner)) continue;
      const dx = f.x - CX, dy = f.y - CY, d = Math.hypot(dx, dy) || 1;
      if (f.exiting) {
        if (d < RIN - r - 4) f.exiting = false;       // pushed back inside
        else if (d > RIN + r + 2) { eliminate(f); if (phase === 'won') return; }
        continue;
      }
      if (d <= RIN - r) continue;
      const delta = Math.abs(angDiff(Math.atan2(dy, dx), gapA));
      if (phase !== 'spawn' && delta < gapNow - passMargin) { f.exiting = true; continue; }
      const nx = dx / d, ny = dy / d;
      f.x = CX + nx * (RIN - r); f.y = CY + ny * (RIN - r);
      const vn = f.vx * nx + f.vy * ny;
      if (vn > 0) {
        f.vx -= 2 * vn * nx; f.vy -= 2 * vn * ny;
        const j = (Math.random() - 0.5) * 0.25, cs = Math.cos(j), sn = Math.sin(j); // small random kick
        const vx = f.vx * cs - f.vy * sn; f.vy = f.vx * sn + f.vy * cs; f.vx = vx;
        if (vn > 120) walls++;
      }
    }
  };

  /* ---------- drawing ---------- */
  const draw = () => {
    c.setTransform(1, 0, 0, 1, 0, 0);
    c.clearRect(0, 0, cv.width, cv.height);
    c.setTransform(k, 0, 0, k, 0, 0);

    // ring with a soft glow, open at the gap
    const a0 = gapA + gapNow, a1 = gapA + TAU - gapNow;
    c.lineCap = 'round';
    [[40, 'rgba(90,140,255,.08)'], [26, 'rgba(110,160,255,.16)'], [LINE, '#eef3ff']].forEach(([w, col]) => {
      c.lineWidth = w; c.strokeStyle = col; c.beginPath(); c.arc(CX, CY, RING, a0, a1); c.stroke();
    });

    const w = size, h = size * 0.75;
    const showNames = alive <= 8 && phase !== 'spawn';
    for (const f of flags) {
      if (f.state === 'gone') continue;
      let s = 1, alpha = 1;
      if (phase === 'spawn') { s = clamp((phaseT - f.delay) / 0.3, 0, 1); if (s <= 0) continue; }
      if (f.state === 'out') { alpha = clamp(1 - f.t, 0, 1); s = 1 + f.t * 0.3; }
      if (phase === 'won' && f === winner) s = 1 + clamp(phaseT / 0.8, 0, 1) * 0.9;
      const cs = Math.cos(f.rot) * s * k, sn = Math.sin(f.rot) * s * k;
      c.setTransform(cs, sn, -sn, cs, f.x * k, f.y * k);
      c.globalAlpha = alpha;
      c.fillStyle = 'rgba(0,0,0,.35)'; c.fillRect(-w / 2 + 2, -h / 2 + 3, w, h);
      c.drawImage(atlas, (f.i % COLS) * FW, Math.floor(f.i / COLS) * FH, FW, FH, -w / 2, -h / 2, w, h);
      if (phase === 'won' && f === winner) { c.lineWidth = 4; c.strokeStyle = '#ffc93a'; c.strokeRect(-w / 2, -h / 2, w, h); }
    }
    c.globalAlpha = 1;
    if (showNames) {
      c.setTransform(k, 0, 0, k, 0, 0);
      c.font = '700 24px Rajdhani, Oswald, sans-serif'; c.textAlign = 'center'; c.lineJoin = 'round';
      for (const f of flags) {
        if (f.state !== 'in') continue;
        const y = f.y + h * 0.5 + 24;
        c.lineWidth = 6; c.strokeStyle = 'rgba(0,0,0,.85)'; c.strokeText(NAME(f.i), f.x, y);
        c.fillStyle = '#fff'; c.fillText(NAME(f.i), f.x, y);
      }
    }
  };

  /* ---------- settings from the admin panel ---------- */
  setInterval(async () => {
    const n = await fetchConfig();
    if (!n) return;
    if (n.reset !== cfg.reset) { try { localStorage.removeItem(KEY); } catch (e) { /* ignore */ } location.reload(); return; }
    if (n.rev !== cfg.rev && phase !== 'won') location.reload();
  }, 20000);

  /* ---------- game loop ---------- */
  setTimeout(() => voice.say('flag_intro'), 2500);
  startRound();
  let last = performance.now(), acc = 0, lastDraw = 0, lastTimer = 0;
  const frame = (now) => {
    const dt = Math.min(0.1, (now - last) / 1000) * FAST; last = now;
    phaseT += dt; simT += dt;
    if (phase !== 'won') roundT += dt;
    acc += dt;
    while (acc >= FIXED) { step(FIXED); acc -= FIXED; }
    if (hits) { sfx.hit(Math.min(1, hits / 3)); hits = 0; }
    if (walls) { sfx.wall(); walls = 0; }

    if (phase === 'spawn' && phaseT > 1.8) { phase = 'play'; phaseT = 0; }
    if (phase === 'won') {
      if (phaseT > 1 && $('win').hidden) showWinPopup();
      if (phaseT > (isFinal ? 11 : 6)) {
        if (isFinal) { // new tournament
          st.qualified = []; st.cup = (st.cup || 1) + 1; save();
          startRound();
          voice.say('flag_newcup', { urgent: true });
        } else startRound();
      }
    }
    if (phase === 'play' && voice.idle && simT > nextCall) { voice.say('flag_call'); nextCall = simT + 30 + Math.random() * 25; }
    if (phase === 'play' && voice.idle && simT > nextSub) { voice.say('flag_sub'); nextSub = simT + 240 + Math.random() * 120; }

    if (now - lastTimer > 500) { updateTimer(); lastTimer = now; }
    if (now - lastDraw >= 31) { draw(); lastDraw = now; } // about 30 frames a second is plenty for a stream
    requestAnimationFrame(frame);
  };
  requestAnimationFrame(frame);

  window.__flags = { st, cfg, phase: () => phase, alive: () => alive, roundT: () => roundT, flags: () => flags, voice, sfx };
}

main();
