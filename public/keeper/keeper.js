// FFTEdit · Beat The Keeper — World Cup. Country flag balls roll down a chute, a spinning paddle
// kicks them towards the goal and a keeper moves up and down in front of it. A full tournament runs by
// itself: 6 groups → quarterfinals → semifinals → final → champion, then a new tournament.
// Typing a country in the live chat gives it more shots. Made for 24/7 vertical live streams with LUNA as host.
import { Sfx, holdScreen, PACE, freshRun } from '/game/audio.js?v=13';
import { liveChat, countryMatcher } from '/game/chat.js?v=13';
import { autoUpdate } from '/game/update.js?v=1';

const $ = (id) => document.getElementById(id);
const QS = new URLSearchParams(location.search);
const clamp = (v, a, b) => Math.max(a, Math.min(b, v));
const FAST = clamp(Number(QS.get('fast')) || 1, 1, 200);   // testing only
const pick = (a) => a[Math.floor(Math.random() * a.length)];
const shuffle = (a) => { for (let i = a.length - 1; i > 0; i--) { const j = Math.floor(Math.random() * (i + 1)); [a[i], a[j]] = [a[j], a[i]]; } return a; };

const DEF = {
  title: 'BEAT THE KEEPER', cup: 'WORLD CUP',
  teams: ['ph', 'my', 'us', 'in', 'bd', 'br', 'pk', 'id', 'gb', 'jp', 'ar', 'np', 'sa', 'tr', 'fr', 'de', 'ng', 'eg',
    'cn', 'vn', 'au', 'ca', 'mx', 'ru', 'pt', 'es', 'it', 'kr', 'th', 'ae', 'ma', 'nl', 'lk', 'za', 'ir', 'pl'],
  goals: 5, final_goals: 10, match_secs: 150, bonus_chats: 8, rev: '', reset: '',
};
// stage: how many go through from each match of 6
const STAGES = {
  G: { name: 'GROUP STAGE', need: 3, next: 'QUARTERFINALS' },
  Q: { name: 'QUARTERFINALS', need: 4, next: 'SEMIFINALS' },
  S: { name: 'SEMIFINALS', need: 3, next: 'FINAL' },
  F: { name: 'THE FINAL', need: 1, next: '' },
};
const GROUPS = 'ABCDEF';

/* ---------------------------------------------------------------- the track (canvas units) */
const TW = 1080, TH = 790, BR = Number(new URLSearchParams(location.search).get('br')) || 16, FIXED = 1 / 240;
const GRAV = 980;
const BOWL = { x: 230, y: 668, r: 118 };
const PADDLE = { half: 102, w: 8, spin: Number(new URLSearchParams(location.search).get('spin')) || 5.4 };   // a fast paddle: hard, quick shots
const KEEPER = { x: 850, half: 52, w: 9, mid: 520, amp: 140, period: 2.6 };   // floats in front of the goal, up and down
const GOAL = { x: 930, top: 330, line: 958 };   // a ball whose centre passes x=line under the bar scores
// the waiting balls roll down this ramp to a gate; the gate opens to let one ball at a time drop into the chute
const RAMP = [[345, 60], [66, 120]];
const GATE = { x: 100, y1: 66, y2: 112 };
const SPAWN = [324, 36];
const onRamp = (b) => b.y < 150 && b.x > GATE.x - 4;
const PLAIN = { sa: ['#006c35', 'KSA'], af: ['#007a36', 'AFG'], iq: ['#ce1126', 'IRQ'] };

// static walls: [x1, y1, x2, y2, half thickness]
const WALLS = [];
const seg = (x1, y1, x2, y2, t = 8) => WALLS.push([x1, y1, x2, y2, t]);
seg(14, 0, 14, TH); seg(1066, 0, 1066, TH); seg(0, 14, TW, 14);
seg(RAMP[0][0], RAMP[0][1], RAMP[1][0], RAMP[1][1], 7); seg(RAMP[0][0], 14, RAMP[0][0], RAMP[0][1], 7);   // the ramp and its back wall
seg(110, 176, 110, 452, 11);                                    // inner wall of the chute
seg(14, 470, 158, 572, 9);                                      // slide into the bowl
seg(250, 455, 250, 300, 18); seg(250, 300, 268, 220, 18); seg(268, 220, 320, 160, 18); seg(320, 160, 420, 128, 18); seg(420, 128, TW, 128, 18); // roof (high: room for big arcs)
for (let i = 0; i < 18; i++) {                                  // the bowl (bottom half circle)
  const a1 = (i / 18) * Math.PI, a2 = ((i + 1) / 18) * Math.PI;
  seg(BOWL.x + BOWL.r * Math.cos(a1), BOWL.y + BOWL.r * Math.sin(a1), BOWL.x + BOWL.r * Math.cos(a2), BOWL.y + BOWL.r * Math.sin(a2), 8);
}
seg(348, 668, 360, 702, 6);                                     // lip after the bowl
seg(490, 700, 930, 712, 6);                                     // the pitch, a little downhill to the goal
seg(930, 712, TW, 712, 6);
seg(GOAL.x, GOAL.top, TW, GOAL.top - 14, 7);   // the top of the goal slopes, balls roll off it
 seg(GOAL.x, GOAL.top, GOAL.x, GOAL.top + 16, 7); // crossbar

async function fetchConfig() {
  try { const r = await fetch('/api/keeper.php', { cache: 'no-store' }); if (r.ok) return await r.json(); } catch (e) { /* offline */ }
  return null;
}
function fitStage() {
  const vw = document.documentElement.clientWidth || innerWidth, vh = document.documentElement.clientHeight || innerHeight;
  $('stage').style.transform = `translate(-50%, -50%) scale(${Math.min(vw / 1080, vh / 1920)})`;
}

class KeeperSfx extends Sfx {
  kick() { if (!this.running || !this.limit('kick', 160)) return; this.tone({ freq: 180, to: 90, type: 'sine', dur: 0.12, vol: 0.18 }); this.tone({ freq: 900, type: 'triangle', dur: 0.04, vol: 0.05 }); }
  bounce() { if (!this.running || !this.limit('bounce', 140)) return; this.tone({ freq: 420 + Math.random() * 120, type: 'sine', dur: 0.05, vol: 0.05 }); }
  save() { if (!this.running || !this.limit('save', 250)) return; this.tone({ freq: 140, to: 60, type: 'sine', dur: 0.25, vol: 0.3 }); this.tone({ freq: 600, to: 300, type: 'triangle', dur: 0.12, vol: 0.08 }); }
  goal() {
    if (!this.running) return;
    this.tone({ freq: 2600, dur: 0.14, vol: 0.06 }); this.tone({ freq: 2600, dur: 0.3, vol: 0.06, delay: 0.18 });
    [523, 659, 784, 1047].forEach((f, i) => this.tone({ freq: f, type: 'triangle', dur: 0.5, vol: 0.14, delay: 0.1 + i * 0.08, attack: 0.02 }));
  }
  whistle() { if (!this.running) return; this.tone({ freq: 2800, dur: 0.18, vol: 0.07 }); this.tone({ freq: 2800, dur: 0.5, vol: 0.07, delay: 0.25 }); }
  win() { if (!this.running) return; [523, 659, 784, 1047, 1319].forEach((f, i) => this.tone({ freq: f, type: 'triangle', dur: 0.5, vol: 0.18, delay: i * 0.15 })); }
}

async function main() {
  fitStage(); addEventListener('resize', fitStage);
  const [remote, atlasInfo] = await Promise.all([fetchConfig(), fetch('/flags/flags.json').then((r) => r.json())]);
  const cfg = { ...DEF, ...(remote || {}) };
  if (cfg.autoplay === false) { holdScreen('/api/keeper.php', cfg.rev); return; }
  const pace = PACE[cfg.pace] || 1;
  const C = atlasInfo.countries, FW = atlasInfo.w, FH = atlasInfo.h, COLS_A = atlasInfo.cols, ROWS_A = Math.ceil(C.length / COLS_A);
  const byCode = new Map(C.map((c, i) => [c.code, i]));
  const NAME = (code) => C[byCode.get(code)].name.toUpperCase().replace('UNITED ARAB EMIRATES', 'UAE').replace('UNITED KINGDOM', 'UK')
    .replace('UNITED STATES', 'USA').replace('CENTRAL AFRICAN REP.', 'C. AFRICA');
  const flagCss = (code, w) => { const i = byCode.get(code), h = w * 0.75; return `width:${w}px;height:${h}px;background-size:${COLS_A * w}px ${ROWS_A * h}px;background-position:${-(i % COLS_A) * w}px ${-Math.floor(i / COLS_A) * h}px`; };
  const flagHTML = (code, w) => (PLAIN[code] ? `<span class="flag ksa" style="width:${w}px;height:${w * 0.75}px;font-size:${Math.round(w * 0.32)}px">${PLAIN[code][1]}</span>` : `<span class="flag" style="${flagCss(code, w)}"></span>`);
  const flagNode = (code, w = 40) => { const d = document.createElement('span'); d.innerHTML = flagHTML(code, w); return d.firstChild; };
  const POOL = (Array.isArray(cfg.teams) ? cfg.teams : []).filter((c) => byCode.has(c));
  while (POOL.length < 36) { const c = pick(DEF.teams.filter((x) => !POOL.includes(x)).concat(C.map((x) => x.code).filter((x) => !POOL.includes(x)))); POOL.push(c); }
  POOL.length = 36;
  const GOALS = clamp(Math.round(cfg.goals) || 5, 1, 20), FINAL_GOALS = clamp(Math.round(cfg.final_goals) || 10, 1, 30);
  const MATCH_SECS = clamp(Math.round(cfg.match_secs) || 150, 30, 900), BONUS_CHATS = Math.max(0, Math.round(cfg.bonus_chats) || 0);
  const target = (st) => (st === 'F' ? FINAL_GOALS : GOALS);
  const limit = () => MATCH_SECS * (S.m && S.m.st === 'F' ? 2 : 1);   // the final gets twice the time
  $('title').textContent = cfg.title;

  /* ---------------------------------------------------------------- saved tournament */
  const KEY = 'fft_keeper';
  freshRun('keeper', [KEY, 'fft_fans_keeper']);
  let S = null;
  try { S = JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (e) { /* private mode */ }
  if (!S || S.reset !== cfg.reset || !S.t) S = { reset: cfg.reset, no: 0, want: {}, t: null, m: null, phase: 'new', phaseT: 0, chat: {} };
  const save = () => { try { localStorage.setItem(KEY, JSON.stringify(S)); } catch (e) { /* ignore */ } };

  const newTournament = () => {
    const teams = shuffle(POOL.slice());
    // countries the chat asked for join (they replace the last ones)
    const asked = Object.entries(S.want || {}).filter(([c, n]) => n >= 3 && !teams.includes(c) && byCode.has(c)).sort((a, b) => b[1] - a[1]).slice(0, 6).map(([c]) => c);
    asked.forEach((c, i) => { teams[teams.length - 1 - i] = c; });
    S.want = {};
    S.no = (S.no || 0) + 1;
    S.t = { groups: [0, 1, 2, 3, 4, 5].map((g) => teams.slice(g * 6, g * 6 + 6)), stage: 'G', idx: 0, through: { G: [], Q: [], S: [] }, plan: null, champion: null, joined: asked };
    S.m = null; S.chat = {};
  };
  // the matches of a stage: groups, or the qualified teams shuffled into matches of 6
  const matchesOf = (st) => {
    if (st === 'G') return S.t.groups;
    if (!S.t.plan || S.t.plan.st !== st) {
      const from = st === 'Q' ? S.t.through.G : st === 'S' ? S.t.through.Q : S.t.through.S;
      const list = shuffle(from.slice());
      S.t.plan = { st, m: [] };
      for (let i = 0; i < list.length; i += 6) S.t.plan.m.push(list.slice(i, i + 6));
    }
    return S.t.plan.m;
  };
  const matchName = (st, i) => (st === 'G' ? `GROUP ${GROUPS[i]}` : st === 'F' ? 'THE FINAL' : `${st === 'Q' ? 'QUARTERFINAL' : 'SEMIFINAL'} ${i + 1}`);
  const startMatch = () => {
    const st = S.t.stage, list = matchesOf(st), teams = list[S.t.idx];
    S.m = { st, idx: S.t.idx, teams: teams.slice(), goals: teams.map(() => 0), qual: [], time: 0, done: false };
    S.chat = {};
  };

  /* ---------------------------------------------------------------- sound */
  const sfx = new KeeperSfx();
  const soundBtn = $('sound-btn');
  const unlock = () => { sfx.resume(); soundBtn.hidden = true; };
  soundBtn.addEventListener('click', unlock); addEventListener('pointerdown', unlock); sfx.resume();
  setTimeout(() => { if (sfx.ok && !sfx.running) soundBtn.hidden = false; }, 1500);

  /* ---------------------------------------------------------------- screens */
  const showCard = (big, small) => {
    const c = $('card'); c.innerHTML = `<b>${big}</b><small>${small}</small>`;
    c.hidden = false; c.style.animation = 'none'; void c.offsetWidth; c.style.animation = '';
    clearTimeout(showCard.t); showCard.t = setTimeout(() => { c.hidden = true; }, 2800);
  };
  const row = (c, cls = '', sc = '') => `<div class="t-row ${cls}">${flagHTML(c, 62)}<span class="n">${NAME(c)}</span>${sc !== '' ? `<b>${sc}</b>` : ''}</div>`;
  const tableHTML = (kind) => {
    const head = `<div class="t-title">${cfg.title}</div>`;
    if (kind === 'G1' || kind === 'G2') {
      const gs = kind === 'G1' ? [0, 1, 2] : [3, 4, 5];
      return head + `<div class="t-stage"><span>🌍 ${cfg.cup} · GROUP STAGE ${kind === 'G1' ? 1 : 2}/2</span></div><div class="t-grid">` +
        gs.map((g) => `<div class="t-col"><h3>GROUP ${GROUPS[g]}</h3><div class="t-two">${S.t.groups[g].map((c) => row(c, S.t.through.G.includes(c) ? 'q' : '')).join('')}</div></div>`).join('') +
        `</div><div class="t-rule" style="top:1500px">FIRST <em>3</em> COUNTRIES TO SCORE <em>${GOALS} GOALS</em><br>ADVANCE TO THE <em>QUARTERFINALS</em></div>`;
    }
    const st = kind, list = matchesOf(st), info = STAGES[st];
    if (st === 'F') {
      return head + `<div class="t-stage"><span>🏆 ${cfg.cup} · THE FINAL</span></div><div class="t-grid"><div class="t-col"><h3>FIRST TO ${FINAL_GOALS} GOALS IS CHAMPION</h3>${list[0].map((c) => row(c)).join('')}</div></div>` +
        `<div class="t-rule" style="top:1120px">ONE BALL · ONE KEEPER<br><em>WHO WILL BE CHAMPION?</em></div>`;
    }
    return head + `<div class="t-stage"><span>🌍 ${cfg.cup} · ${info.name}</span></div><div class="t-grid">` +
      list.map((m, i) => `<div class="t-col"><h3>${matchName(st, i)}</h3><div class="t-two">${m.map((c) => row(c)).join('')}</div></div>`).join('') +
      `</div><div class="t-rule" style="top:${st === 'Q' ? 1500 : 1240}px">FIRST <em>${info.need}</em> COUNTRIES TO SCORE <em>${GOALS} GOALS</em><br>GO TO THE <em>${info.next}</em></div>`;
  };
  const showTable = (kind) => { const t = $('table'); t.innerHTML = tableHTML(kind); t.hidden = false; };
  const hideTable = () => { $('table').hidden = true; };

  const renderBoard = () => {
    if (!S.m) { $('board').innerHTML = ''; return; }
    const tg = target(S.m.st);
    $('board').innerHTML = S.m.teams.map((c, i) => `<div class="sc${S.m.qual.includes(c) ? ' q' : ''}" id="sc-${c}">${flagHTML(c, 48)}<span class="nm">${NAME(c)}</span><b>${S.m.goals[i]}</b></div>`).join('');
    const need = STAGES[S.m.st].need;
    $('rule').innerHTML = S.m.st === 'F' ? `FIRST TO <em>${tg} GOALS</em> IS THE <em>${cfg.cup} CHAMPION</em>!`
      : `FIRST <em>${need}</em> COUNTRIES TO SCORE <em>${tg} GOALS</em> ADVANCE!`;
    $('pill').textContent = `🌍 ${cfg.cup} · ${matchName(S.m.st, S.m.idx)}`;
  };

  /* ---------------------------------------------------------------- balls */
  const ST = { kick: 0, save: 0, pit: 0, stuck: 0, goal: 0 };
  let balls = [], queue = [], releaseT = 0, paddleA = 0, keeperY = KEEPER.mid, simT = 0, flash = [], confetti = [];
  let plane = null, nextPlane = 40;
  const teamIdx = (c) => (S.m ? S.m.teams.indexOf(c) : -1);
  const active = (c) => S.m && !S.m.qual.includes(c) && teamIdx(c) >= 0;
  const resetBalls = () => {
    balls = []; queue = shuffle(S.m.teams.filter((c) => active(c)).map((c) => ({ c, bonus: false })));
    releaseT = 0.6; spawnT = 0;
  };
  let spawnT = 0, gateOpen = 0, gateBall = null;
  const spawn = () => { // the next ball in line appears at the top of the ramp and rolls down to the gate
    const q = queue.shift(); if (!q) return;
    balls.push({ c: q.c, bonus: q.bonus, x: SPAWN[0], y: SPAWN[1], vx: -60, vy: 0, still: 0, age: 0, spin: 0, gone: 0 });
  };
  const recycle = (b) => { // back to the end of the queue (a bonus ball is used up)
    b.gone = 1;
    if (!b.bonus && active(b.c) && !queue.some((q) => q.c === b.c && !q.bonus) && !balls.some((o) => o !== b && !o.gone && o.c === b.c && !o.bonus)) queue.push({ c: b.c, bonus: false });
  };

  const collideSeg = (b, x1, y1, x2, y2, t, vx0 = 0, vy0 = 0, rot = null, bounce = 0.5) => {
    const dx = x2 - x1, dy = y2 - y1, L2 = dx * dx + dy * dy;
    let u = L2 ? ((b.x - x1) * dx + (b.y - y1) * dy) / L2 : 0; u = clamp(u, 0, 1);
    const px = x1 + dx * u, py = y1 + dy * u;
    let nx = b.x - px, ny = b.y - py; const d = Math.hypot(nx, ny), min = BR + t;
    if (d >= min || d === 0) return false;
    nx /= d; ny /= d;
    b.x = px + nx * min; b.y = py + ny * min;
    // velocity of the wall at the contact point (moving paddle / keeper)
    let wx = vx0, wy = vy0;
    if (rot) { wx += -rot.w * (py - rot.cy); wy += rot.w * (px - rot.cx); }
    let rx = b.vx - wx, ry = b.vy - wy;
    const vn = rx * nx + ry * ny;
    if (vn < 0) {
      const e = rot ? 0.9 : bounce;
      rx -= (1 + e) * vn * nx; ry -= (1 + e) * vn * ny;
      // a little rolling friction along the wall
      const tx = -ny, ty = nx, vt = rx * tx + ry * ty;
      rx -= vt * 0.004 * tx; ry -= vt * 0.004 * ty;
      b.vx = rx + wx; b.vy = ry + wy;
      return -vn;
    }
    return 0;
  };

  const physics = (dt) => {
    paddleA += PADDLE.spin * dt;
    const kPrev = keeperY;
    keeperY = KEEPER.mid + KEEPER.amp * Math.sin(simT * 2 * Math.PI / KEEPER.period);
    const kvy = (keeperY - kPrev) / dt;
    const pc = Math.cos(paddleA), ps = Math.sin(paddleA);
    const P = [BOWL.x - pc * PADDLE.half, BOWL.y - ps * PADDLE.half, BOWL.x + pc * PADDLE.half, BOWL.y + ps * PADDLE.half];
    for (const b of balls) {
      if (b.gone) continue;
      b.vy += GRAV * dt;
      b.x += b.vx * dt; b.y += b.vy * dt; b.age += dt;
      b.spin += (b.vx / BR) * dt;
      for (const w of WALLS) { const hit = collideSeg(b, ...w); if (hit > 160) sfx.bounce(); }
      const kick = collideSeg(b, P[0], P[1], P[2], P[3], PADDLE.w, 0, 0, { w: PADDLE.spin, cx: BOWL.x, cy: BOWL.y });
      if (kick > 80) { sfx.kick(); ST.kick++; }
      if (gateOpen <= 0) collideSeg(b, GATE.x, GATE.y1, GATE.x, GATE.y2, 6);
      if (onRamp(b)) { b.age = 0; b.still = 0; }
      const save = collideSeg(b, KEEPER.x, keeperY - KEEPER.half, KEEPER.x, keeperY + KEEPER.half, KEEPER.w, 0, kvy, null, 0.95);   // the keeper punches it back
      if (save > 120) { sfx.save(); b.saved = simT; ST.save++; }
      const sp = Math.hypot(b.vx, b.vy);
      if (sp > 1900) { b.vx *= 1900 / sp; b.vy *= 1900 / sp; }
      if (!onRamp(b)) b.still = sp < 18 ? b.still + dt : 0;
      // scored? (centre past the goal line, under the bar)
      if (b.x > GOAL.line && b.y > GOAL.top + 10) { goal(b); continue; }
      if (b.y > TH + 30) { ST.pit++; recycle(b); continue; }                 // fell into a pit: try again
      if (b.still > 3 || b.age > 28) { ST.stuck++; recycle(b); continue; }    // stuck: back to the start
    }
    // balls bump into each other
    for (let i = 0; i < balls.length; i++) {
      const a = balls[i]; if (a.gone) continue;
      for (let j = i + 1; j < balls.length; j++) {
        const b = balls[j]; if (b.gone) continue;
        let dx = b.x - a.x, dy = b.y - a.y; const d = Math.hypot(dx, dy);
        if (d >= BR * 2 || d === 0) continue;
        dx /= d; dy /= d; const o = (BR * 2 - d) / 2;
        a.x -= dx * o; a.y -= dy * o; b.x += dx * o; b.y += dy * o;
        const vn = (b.vx - a.vx) * dx + (b.vy - a.vy) * dy;
        if (vn < 0) { const j2 = -vn * 0.9; a.vx -= dx * j2; a.vy -= dy * j2; b.vx += dx * j2; b.vy += dy * j2; }
      }
    }
    balls = balls.filter((b) => !b.gone);
  };

  /* ---------------------------------------------------------------- goals and the match */
  let lastGoalTalk = 0;
  const goal = (b) => {
    b.gone = 1; ST.goal++;
    const i = teamIdx(b.c);
    if (i < 0 || S.m.done || S.m.qual.includes(b.c)) { recycle(b); return; }
    S.m.goals[i]++;
    sfx.goal();
    flash.push({ t: 0, c: b.c });
    for (let k = 0; k < 50; k++) confetti.push({ x: 960 + Math.random() * 100, y: 360 + Math.random() * 60, vx: (Math.random() - 0.7) * 300, vy: -Math.random() * 300, c: `hsl(${Math.random() * 360},90%,60%)`, t: 0 });
    const tg = target(S.m.st);
    renderBoard();
    const el = $('sc-' + b.c); if (el) { el.classList.add('pop'); setTimeout(() => el.classList.remove('pop'), 250); }
    if (S.m.goals[i] >= tg) {
      S.m.qual.push(b.c);
      balls.forEach((o) => { if (o.c === b.c) o.gone = 1; });
      queue = queue.filter((q) => q.c !== b.c);
      renderBoard();
      if (S.m.st === 'F') host(['🏆 ', { node: flagNode(b.c) }, NAME(b.c) + ` SCORES ${tg} GOALS — ${NAME(b.c)} IS THE ${cfg.cup} CHAMPION!`], 3);
      else host([{ node: flagNode(b.c) }, NAME(b.c) + ` HAS ${tg} GOALS — ${NAME(b.c)} GOES TO THE ${STAGES[S.m.st].next}!`], 3);
      checkEnd();
    } else {
      if (!b.bonus) queue.push({ c: b.c, bonus: false });
      if (simT - lastGoalTalk > 7) { lastGoalTalk = simT; host([pick(['GOAL! ', 'WHAT A SHOT! ', 'GOOOAL! ']), { node: flagNode(b.c) }, NAME(b.c) + ` HAS ${S.m.goals[i]}! ${tg - S.m.goals[i]} MORE TO GO — TYPE ${NAME(b.c)} FOR MORE SHOTS!`], 1); }
    }
    save();
  };
  const checkEnd = () => {
    const need = STAGES[S.m.st].need;
    if (S.m.qual.length >= need) endMatch();
  };
  const endMatch = () => {
    if (S.m.done) return;
    const need = STAGES[S.m.st].need;
    if (S.m.qual.length < need) { // time is up: the ones with most goals go through
      const rest = S.m.teams.map((c, i) => [c, S.m.goals[i] + Math.random() * 0.1]).filter(([c]) => !S.m.qual.includes(c)).sort((a, b) => b[1] - a[1]);
      while (S.m.qual.length < need && rest.length) S.m.qual.push(rest.shift()[0]);
    }
    S.m.done = true; balls = []; queue = [];
    renderBoard();
    sfx.whistle();
    if (S.m.st === 'F') S.t.champion = S.m.qual[0];
    else {
      S.t.through[S.m.st].push(...S.m.qual);
      const out = S.m.teams.filter((c) => !S.m.qual.includes(c));
      host(['FULL TIME! ', ...S.m.qual.flatMap((c) => [{ node: flagNode(c) }, ' ']), `GO THROUGH! ${out.length ? 'HARD LUCK ' + out.slice(0, 2).map(NAME).join(' AND ') + '!' : ''}`], 3);
    }
    setPhase('end');
  };

  /* ---------------------------------------------------------------- the flow of the tournament */
  // phases: table (stage table) → card (match title) → play → end (results) → … → champ
  const setPhase = (p) => { S.phase = p; S.phaseT = 0; save(); };
  const nextAfterMatch = () => {
    const st = S.t.stage, n = matchesOf(st).length;
    if (st === 'F') { showChampion(); return; }
    S.t.idx++;
    if (st === 'G' && S.t.idx === 3) { S.tableKind = 'G2'; setPhase('table'); showTable('G2'); return; }
    if (S.t.idx >= n) {
      S.t.stage = st === 'G' ? 'Q' : st === 'Q' ? 'S' : 'F'; S.t.idx = 0; S.t.plan = null;
      S.tableKind = S.t.stage; setPhase('table'); showTable(S.t.stage);
      host([`${STAGES[S.t.stage].name} ARE NEXT! `, ...matchesOf(S.t.stage).flat().slice(0, 4).flatMap((c) => [{ node: flagNode(c) }]), ` ${matchesOf(S.t.stage).flat().length} COUNTRIES LEFT — TYPE YOURS FOR MORE SHOTS!`], 2);
      return;
    }
    beginMatch();
  };
  const beginMatch = () => {
    hideTable(); startMatch(); resetBalls(); renderBoard();
    setPhase('card');
    showCard(matchName(S.m.st, S.m.idx), S.m.st === 'G' ? 'GROUP STAGE' : STAGES[S.m.st].name);
    sfx.whistle();
    host([`${matchName(S.m.st, S.m.idx)}! `, ...S.m.teams.flatMap((c) => [{ node: flagNode(c) }]), ` FIRST ${STAGES[S.m.st].need === 1 ? 'TO' : STAGES[S.m.st].need + ' TO'} ${target(S.m.st)} GOALS ${S.m.st === 'F' ? 'WINS THE CUP' : 'GO THROUGH'}! TYPE YOUR COUNTRY FOR MORE SHOTS!`], 2);
  };
  const showChampion = () => {
    const c = S.t.champion; if (!c) { newTournament(); S.tableKind = 'G1'; setPhase('table'); showTable('G1'); return; }
    $('win-flag').outerHTML = `<span class="flag big" id="win-flag" style="${PLAIN[c] ? `width:420px;height:315px;background:${PLAIN[c][0]}` : flagCss(c, 420)}"></span>`;
    $('win-name').textContent = NAME(c); $('win-sub').textContent = `${cfg.cup} · TOURNAMENT #${S.no}`;
    $('win').hidden = false; sfx.win();
    host(['WE HAVE A CHAMPION! ', { node: flagNode(c) }, NAME(c) + ` WINS THE ${cfg.cup}! A NEW TOURNAMENT STARTS NOW — TYPE YOUR COUNTRY TO JOIN!`], 3);
    setPhase('champ');
  };

  /* ---------------------------------------------------------------- live chat */
  const where = (code) => { // what is happening with this country right now
    if (!S.t) return 'none';
    if (S.m && teamIdx(code) >= 0 && S.phase !== 'champ') return S.m.qual.includes(code) ? 'qual' : 'playing';
    const all = S.t.groups.flat();
    if (!all.includes(code)) return 'none';
    const st = S.t.stage;
    if (st !== 'G') { const alive = matchesOf(st).flat(); if (!alive.includes(code)) return 'out'; }
    return 'later';
  };
  const giveShot = (code, by) => {
    const st = (S.chat[code] = S.chat[code] || { n: 0, t: 0 });
    st.n++;
    // its waiting ball goes to the front of the line
    const k = queue.findIndex((q) => q.c === code && !q.bonus);
    if (k > 0) queue.unshift(queue.splice(k, 1)[0]);
    if (BONUS_CHATS && st.n % BONUS_CHATS === 0) {
      queue.unshift({ c: code, bonus: true });
      host(['BONUS SHOT FOR ', { node: flagNode(code) }, NAME(code) + '! THANK YOU ', { name: by }, `! EVERY ${BONUS_CHATS} CHATS = ONE MORE BALL!`], 2);
    }
  };
  const isGreeting = (s) => /\b(hi|hii+|hello|hey|salam|salaam|assalam\w*|asalam\w*)\b|হাই|হ্যালো|সালাম|আসসালামু/i.test(s);
  const isSalam = (s) => /salam|salaam|assalam|asalam|সালাম|আসসালামু/i.test(s);
  const statusLine = (code) => {
    const w = where(code), i = teamIdx(code);
    if (w === 'playing') return `${NAME(code)} HAS ${S.m.goals[i]} GOAL${S.m.goals[i] === 1 ? '' : 'S'}! ${target(S.m.st) - S.m.goals[i]} MORE TO GO — EVERY CHAT GIVES IT A FASTER SHOT!`;
    if (w === 'qual') return `${NAME(code)} IS ALREADY THROUGH! CHEER FOR IT IN THE NEXT ROUND!`;
    if (w === 'later') return `${NAME(code)} PLAYS SOON — KEEP CHATTING AND WATCH ITS MATCH!`;
    if (w === 'out') return `${NAME(code)} IS OUT OF THIS TOURNAMENT — IT PLAYS AGAIN IN THE NEXT ONE!`;
    return `${NAME(code)} JOINS THE NEXT TOURNAMENT — KEEP TYPING IT!`;
  };
  const chat = liveChat({
    game: 'keeper', rows: 3, alwaysOn: true,
    match: countryMatcher(C),
    badge: (code) => flagNode(code, 32),
    duck: (on) => sfx.duck(on),
    busy: () => S.phase === 'champ',
    sfx: () => { sfx.tone({ freq: 784, type: 'sine', dur: 0.18, vol: 0.09 }); sfx.tone({ freq: 1175, type: 'sine', dur: 0.3, vol: 0.08, delay: 0.14 }); },
    onFan: (code, name) => {
      if (where(code) === 'playing' && S.phase === 'play') giveShot(code, name.toUpperCase().slice(0, 12));
      else if (where(code) === 'none') S.want[code] = (S.want[code] || 0) + 1;
    },
    onSub: (name, code) => { if (code && where(code) === 'playing') { queue.unshift({ c: code, bonus: true }); queue.unshift({ c: code, bonus: true }); host(['THANK YOU ', { name }, ' FOR SUBSCRIBING! ', { node: flagNode(code) }, NAME(code) + ' GETS TWO BONUS SHOTS!'], 3); } },
    onSuper: (code, name) => { if (code && where(code) === 'playing') for (let k = 0; k < 3; k++) queue.unshift({ c: code, bonus: true }); },
    emptyHint: 'Type your country — every chat = a faster shot!',
    hints: ['TYPE YOUR COUNTRY — EVERY CHAT GIVES IT A FASTER SHOT!', 'WHERE ARE YOU WATCHING FROM? TYPE YOUR COUNTRY!', 'WHO WILL BEAT THE KEEPER? COMMENT YOUR PICK!'],
    idleLines: [
      ['TYPE YOUR COUNTRY IN CHAT — ITS BALL SHOOTS FASTER!'],
      ...(BONUS_CHATS ? [[`EVERY ${BONUS_CHATS} CHATS GIVE YOUR COUNTRY A BONUS BALL!`]] : []),
      ['YOUR COUNTRY IS NOT PLAYING? TYPE IT AND IT JOINS THE NEXT TOURNAMENT!'],
      ['SUBSCRIBE AND YOUR COUNTRY GETS TWO BONUS SHOTS!'],
    ],
    idleLine: () => {
      if (!S.m || S.phase !== 'play') return null;
      const order = S.m.teams.map((c, i) => [c, S.m.goals[i]]).filter(([c]) => !S.m.qual.includes(c)).sort((a, b) => b[1] - a[1]);
      if (!order.length) return null;
      const [lc, lg] = order[0], [tc, tg2] = order[order.length - 1];
      return pick([
        [{ node: flagNode(lc) }, NAME(lc) + ` LEADS WITH ${lg}! `, { node: flagNode(tc) }, NAME(tc) + ` HAS ONLY ${tg2} — ${NAME(tc)} FANS, TYPE NOW!`],
        [`${Math.max(0, Math.ceil(limit() - S.m.time))} SECONDS LEFT IN ${matchName(S.m.st, S.m.idx)}! TYPE YOUR COUNTRY FOR FASTER SHOTS!`],
      ]);
    },
    welcome: (name, code, text) => {
      const hi = isSalam(text) ? 'WA ALAIKUM ASSALAM ' : 'WELCOME ';
      if (!code) return [hi, { name }, '! WHERE ARE YOU FROM? TYPE YOUR COUNTRY — IT GETS MORE SHOTS!'];
      return [hi, { name }, ' FROM ', { node: flagNode(code) }, NAME(code) + '! ' + statusLine(code)];
    },
    reply: (name, code, text) => {
      if (code) return [pick(['GO GO ', 'YES ', 'COME ON ']), { name }, '! ', { node: flagNode(code) }, statusLine(code)];
      if (/who.*(win|lead)|winner|কে জিতবে/i.test(text) && S.m) {
        const best = S.m.teams.map((c, i) => [c, S.m.goals[i]]).sort((a, b) => b[1] - a[1])[0];
        return ['RIGHT NOW ', { node: flagNode(best[0]) }, NAME(best[0]) + ` LEADS WITH ${best[1]} GOALS, `, { name }, '!'];
      }
      if (isGreeting(text)) return [isSalam(text) ? 'WA ALAIKUM ASSALAM ' : 'HELLO ', { name }, '! WHICH COUNTRY ARE YOU CHEERING FOR?'];
      return null;
    },
    demoTexts: ['Philippines 🇵🇭', 'bangladesh', 'India!', 'go usa', 'Malaysia', 'brasil 🔥', 'hello', 'who will win?', 'Pakistan', 'nice game', 'Japan', 'বাংলাদেশ'],
  });
  const host = (parts, prio = 2) => { if (chat.active) chat.say(parts, prio); };
  const rbt = document.querySelector('.lc-keeper .lc-rb-text');
  if (rbt) new MutationObserver(() => {
    rbt.style.fontSize = '';
    for (let size = 28; rbt.scrollHeight > rbt.clientHeight + 2 && size > 17; size -= 1) rbt.style.fontSize = size + 'px';
  }).observe(rbt, { childList: true });

  /* ---------------------------------------------------------------- drawing */
  const cv = $('track'), c = cv.getContext('2d');
  const bg = document.createElement('canvas'), bc = bg.getContext('2d');
  const atlas = new Image(); atlas.src = '/flags/flags.png'; await atlas.decode().catch(() => {});
  let k = 1;
  const drawBg = () => {
    bg.width = cv.width; bg.height = cv.height; bc.setTransform(k, 0, 0, k, 0, 0);
    bc.fillStyle = '#7f8cf2'; bc.fillRect(0, 0, TW, TH);
    const cloud = (x, y, s) => { bc.fillStyle = 'rgba(255,255,255,.9)'; for (const [dx, dy, r] of [[0, 0, 18], [22, -8, 22], [46, 0, 18], [24, 6, 18]]) { bc.beginPath(); bc.arc(x + dx * s, y + dy * s, r * s, 0, 7); bc.fill(); } };
    [[420, 260, 1.2], [700, 330, 1], [560, 470, 1.1], [880, 250, 0.9], [330, 540, 0.9], [760, 600, 1], [150, 90, 0.8], [600, 80, 1]].forEach((a) => cloud(...a));
    bc.lineCap = 'round'; bc.strokeStyle = '#000';
    for (const [x1, y1, x2, y2, t] of WALLS) { if (y1 === 0 && y2 === TH) continue; bc.lineWidth = t * 2; bc.beginPath(); bc.moveTo(x1, y1); bc.lineTo(x2, y2); bc.stroke(); }
    bc.fillStyle = '#000'; bc.fillRect(0, 0, 14, TH); bc.fillRect(TW - 14, 0, 14, TH); bc.fillRect(0, 0, TW, 10);
    // ramp where the next balls wait
    // ground under the pitch + pits
    bc.fillStyle = '#000'; bc.fillRect(490, 712, TW - 490, TH - 712); bc.fillRect(354, 702, 26, TH - 702);
    bc.fillStyle = '#ff2d6f'; bc.fillRect(14, TH - 18, 96, 18);
    bc.fillStyle = '#1e90ff'; bc.fillRect(380, TH - 18, 110, 18);
    // grass
    for (let x = 492; x < TW - 14; x += 6) { bc.fillStyle = x % 12 ? '#2fa52f' : '#55d455'; const y = 700 + (x - 490) * 12 / 440; bc.fillRect(x, Math.min(y, 712) - 10 + (x % 18) / 3, 3, 10); }
    // goal: post, bar and net
    bc.fillStyle = '#fff'; bc.fillRect(GOAL.x - 8, GOAL.top, 16, 712 - GOAL.top); bc.fillRect(GOAL.x - 8, GOAL.top - 7, TW - GOAL.x, 14);
    bc.strokeStyle = 'rgba(255,255,255,.85)'; bc.lineWidth = 3;
    for (let x = GOAL.x + 20; x < TW - 14; x += 18) { bc.beginPath(); bc.moveTo(x, GOAL.top + 8); bc.lineTo(x, 712); bc.stroke(); }
    for (let y = GOAL.top + 26; y < 712; y += 18) { bc.beginPath(); bc.moveTo(GOAL.x + 8, y); bc.lineTo(TW - 14, y); bc.stroke(); }
    bc.fillStyle = '#fff'; bc.font = '700 20px Oswald, Arial'; bc.textAlign = 'center'; bc.fillText('↻', 62, TH - 26); bc.fillText('↻', 435, TH - 26);
  };
  const resize = () => { const r = cv.getBoundingClientRect(), dpr = Math.min(devicePixelRatio || 1, 1.5); cv.width = Math.round(r.width * dpr) || TW; cv.height = Math.round(r.height * dpr) || TH; k = cv.width / TW; drawBg(); };
  resize(); addEventListener('resize', resize);

  const drawBall = (code, x, y, r, spin = 0, gold = false) => {
    c.save(); c.translate(x, y); c.rotate(spin);
    c.beginPath(); c.arc(0, 0, r, 0, 7); c.closePath();
    const plain = PLAIN[code];
    c.fillStyle = plain ? plain[0] : '#fff'; c.fill(); c.clip();
    if (plain) { c.fillStyle = '#fff'; c.font = `700 ${Math.round(r * 0.7)}px Oswald, Arial`; c.textAlign = 'center'; c.textBaseline = 'middle'; c.fillText(plain[1], 0, 1); }
    else { const i = byCode.get(code); c.drawImage(atlas, (i % COLS_A) * FW, Math.floor(i / COLS_A) * FH, FW, FH, -r * 1.36, -r, r * 2.72, r * 2); }
    c.restore();
    c.lineWidth = gold ? 5 : 3; c.strokeStyle = gold ? '#ffd23f' : '#fff'; c.beginPath(); c.arc(x, y, r, 0, 7); c.stroke();
  };
  const draw = (dt) => {
    c.setTransform(1, 0, 0, 1, 0, 0); c.drawImage(bg, 0, 0); c.setTransform(k, 0, 0, k, 0, 0);
    // the gate (red when closed)
    if (gateOpen <= 0) { c.fillStyle = '#ff2d6f'; c.fillRect(GATE.x - 5, GATE.y1, 10, GATE.y2 - GATE.y1); }
    // paddle
    c.save(); c.translate(BOWL.x, BOWL.y); c.rotate(paddleA); c.fillStyle = '#f4c430'; c.fillRect(-PADDLE.half, -PADDLE.w, PADDLE.half * 2, PADDLE.w * 2); c.restore();
    c.fillStyle = '#111'; c.beginPath(); c.arc(BOWL.x, BOWL.y, 6, 0, 7); c.fill();
    // keeper
    c.fillStyle = '#000'; c.fillRect(KEEPER.x - KEEPER.w, keeperY - KEEPER.half, KEEPER.w * 2, KEEPER.half * 2);
    // balls
    for (const b of balls) drawBall(b.c, b.x, b.y, BR, b.spin, b.bonus);
    // the plane with the SUBSCRIBE banner
    if (plane) {
      const x = plane.x, y = 250;
      c.fillStyle = '#000'; c.fillRect(x + 40, y - 22, 230, 44); c.fillStyle = plane.col; c.font = '700 30px Oswald, Impact, Arial'; c.textAlign = 'center'; c.textBaseline = 'middle'; c.fillText('SUBSCRIBE', x + 155, y + 1);
      c.strokeStyle = '#fff'; c.lineWidth = 2; c.beginPath(); c.moveTo(x - 4, y); c.lineTo(x + 40, y); c.stroke();
      c.fillStyle = '#d62828'; c.beginPath(); c.ellipse(x - 40, y, 44, 14, 0, 0, 7); c.fill(); c.fillRect(x - 52, y - 26, 12, 52); c.fillStyle = '#fff'; c.beginPath(); c.arc(x - 80, y, 6, 0, 7); c.fill();
    }
    // GOAL! text + confetti
    for (const f of flash) {
      f.t += dt; const a = Math.max(0, 1 - f.t / 1.6), s = 1 + Math.min(f.t, 0.25) * 1.2;
      c.save(); c.globalAlpha = a; c.translate(800, 470); c.scale(s, s);
      c.font = '700 86px Oswald, Impact, Arial'; c.textAlign = 'center'; c.textBaseline = 'middle'; c.lineWidth = 10; c.strokeStyle = '#5a2a00'; c.strokeText('GOAL!', 0, 0);
      c.fillStyle = '#ffc93a'; c.fillText('GOAL!', 0, 0); c.restore();
    }
    flash = flash.filter((f) => f.t < 1.6);
    for (const p of confetti) { p.t += dt; p.vy += 500 * dt; p.x += p.vx * dt; p.y += p.vy * dt; c.fillStyle = p.c; c.fillRect(p.x, p.y, 8, 4); }
    confetti = confetti.filter((p) => p.t < 1.8);
    c.textBaseline = 'alphabetic';
  };

  /* ---------------------------------------------------------------- start / resume */
  if (!S.t) newTournament();
  if (S.phase === 'new') { S.tableKind = 'G1'; setPhase('table'); showTable('G1'); }
  else if (S.phase === 'table') showTable(S.tableKind || 'G1');
  else if (S.phase === 'champ') showChampion();
  else if (S.m) { resetBalls(); renderBoard(); if (S.phase === 'card') setPhase('play'); }
  const TABLE_SECS = 9, CARD_SECS = 2.6, END_SECS = 4, CHAMP_SECS = 15;

  const step = (dt) => {
    S.phaseT += dt;
    if (S.phase === 'table' && S.phaseT > TABLE_SECS) {
      if (S.tableKind === 'G1' || S.tableKind === 'G2' || S.tableKind === S.t.stage) beginMatch();
      return;
    }
    if (S.phase === 'card' && S.phaseT > CARD_SECS) setPhase('play');
    if (S.phase === 'end' && S.phaseT > END_SECS) { nextAfterMatch(); return; }
    if (S.phase === 'champ' && S.phaseT > CHAMP_SECS) { $('win').hidden = true; newTournament(); S.tableKind = 'G1'; setPhase('table'); showTable('G1'); return; }
    if (gateOpen > 0) { // the gate closes as soon as that ball is through
      gateOpen -= dt;
      if (!gateBall || gateBall.gone || gateBall.x < GATE.x - BR - 3) gateOpen = 0;
    }
    if (S.m && (S.phase === 'play' || S.phase === 'card')) {
      spawnT -= dt;   // keep the ramp filled (up to 6 waiting)
      if (spawnT <= 0 && queue.length && balls.filter(onRamp).length < 6 && !balls.some((b) => Math.hypot(b.x - SPAWN[0], b.y - SPAWN[1]) < BR * 2.4)) { spawn(); spawnT = 0.3; }
      if (S.phase === 'play') {
        S.m.time += dt;
        releaseT -= dt;
        const inPlay = balls.filter((b) => !onRamp(b)).length;
        if (releaseT <= 0 && inPlay < 8 && balls.some(onRamp)) { // open the gate: the first ball rolls through
          gateBall = balls.filter(onRamp).sort((a, b) => a.x - b.x)[0];
          gateBall.vx = Math.min(gateBall.vx, -170); gateOpen = 1.2; releaseT = 0.85;
        }
        if (S.m.time >= limit()) endMatch();
      }
    }
    physics(dt);
    simT += dt;
    // the SUBSCRIBE plane now and then
    nextPlane -= dt;
    if (!plane && nextPlane <= 0) { plane = { x: TW + 120, col: pick(['#ff4fd8', '#ffd23f', '#22e06b', '#38bdf8']) }; nextPlane = 75; }
    if (plane) { plane.x -= 150 * dt; if (plane.x < -320) plane = null; }
  };

  let last = performance.now(), acc = 0, lastSave = 0, lastTimer = -1;
  const frame = (now) => {
    const dt = Math.min(0.1, (now - last) / 1000) * FAST * pace; last = now;
    acc += dt; let n = 0;
    while (acc >= FIXED && n < 6000) { step(FIXED); acc -= FIXED; n++; }
    if (n >= 6000) acc = 0;
    draw(Math.min(dt, 0.1));
    if (S.m) {
      const left = Math.max(0, Math.ceil(limit() - S.m.time));
      if (left !== lastTimer) { lastTimer = left; const t = $('timer'); t.textContent = left; t.classList.toggle('hot', left <= 15 && S.phase === 'play'); }
    }
    if (now - lastSave > 2000) { save(); lastSave = now; }
    requestAnimationFrame(frame);
  };
  requestAnimationFrame(frame);
  autoUpdate('keeper', save);

  /* ---------------------------------------------------------------- settings from the admin panel */
  setInterval(async () => {
    const n = await fetchConfig();
    if (!n) return;
    if (n.reset !== cfg.reset) { try { localStorage.removeItem(KEY); } catch (e) { /* ignore */ } location.reload(); return; }
    if (n.rev !== cfg.rev && S.phase !== 'champ') { save(); location.reload(); }
  }, 20000);

  window.__keeper = { ST, simT: () => simT, S: () => S, balls: () => balls, queue: () => queue, cfg };
}

main();
