// FFTEdit · Territory War. Runs by itself, made for 24/7 vertical live streams.
// Every country has a flag ball that bounces inside its own land. When the ball hits a square of
// another country it takes that square and bounces back. A country whose land gets too small is out
// and its land goes to the country that beat it. The last country left wins the world.
import { Sfx, holdScreen, PACE, freshRun } from '/game/audio.js?v=13';
import { liveChat, countryMatcher } from '/game/chat.js?v=7';
import { autoUpdate } from '/game/update.js?v=1';

const $ = (id) => document.getElementById(id);
const QS = new URLSearchParams(location.search);
const clamp = (v, a, b) => Math.max(a, Math.min(b, v));
const FAST = clamp(Number(QS.get('fast')) || 1, 1, 400); // testing only: run the war faster

const DEF = { title: 'TERRITORY WAR', teams: ['bd', 'in', 'pk', 'us', 'br', 'ar', 'sa', 'tr', 'id', 'ng', 'ru', 'cn', 'jp', 'de', 'fr', 'gb'],
  grid: 40, speed: 100, sudden: 60, boost: true, fill: 'color', ball_chats: 15, revive_chats: 30, like_step: 20, rev: '', reset: '' };
const BONUS_SECS = 180, BONUS_MAX = 4;   // chat-won extra balls last 3 minutes, at most 4 at a time

// clear, different colours for the countries' land (in team order, shuffled each war)
const PALETTE = ['#ff9f1c', '#3c5bd6', '#2ec4b6', '#e63946', '#f4d21b', '#74b9ff', '#9b5de5', '#06d6a0', '#ff5a8a', '#8ac926',
  '#b30000', '#1e8f4e', '#ffffff', '#555c6e', '#00b3a4', '#8a1538', '#c77dff', '#ffb4a2', '#4cc9f0', '#fb5607',
  '#80b918', '#3a0ca3', '#ffd166', '#118ab2', '#ef476f', '#7f5539', '#90e0ef', '#d00000', '#fcbf49', '#2b9348', '#e0aaff', '#a3b18a'];

const MAP_W = 1000, MAP_H = 600, FIXED = 1 / 120;   // the map stays above YouTube's phone chat
const SUDDEN_EVERY = 90;            // seconds between Sudden Death eliminations (16 or fewer countries left)
const suddenEvery = (alive) => (alive <= 16 ? SUDDEN_EVERY : clamp(Math.round(1440 / alive), 15, SUDDEN_EVERY)); // faster while many are left
const BOOST_SECS = 8, BOOST_MULT = 1.5;
// Bigger countries get more balls and move a bit faster, and that edge grows as the war goes on,
// so countries fall one by one (tuned with a simulator: the first country falls after ~5–10 min, a 16-country war lasts ~1 hour with Sudden Death at 60).
const ARMY_STEP = 0.5, ARMY_MAX_BIG = 4;   // one more ball for every half "fair share" above it (max 4)
const EDGE_START = 0.08, EDGE_GROW = 0.4, EDGE_MINUTES = 45;

async function fetchConfig() {
  try { const r = await fetch('/api/war.php', { cache: 'no-store' }); if (r.ok) return await r.json(); } catch (e) { /* offline */ }
  return null;
}
function fitStage() {
  const vw = document.documentElement.clientWidth || innerWidth, vh = document.documentElement.clientHeight || innerHeight;
  $('stage').style.transform = `translate(-50%, -50%) scale(${Math.min(vw / 1080, vh / 1920)})`;
}
const fmtTime = (s) => {
  s = Math.max(0, Math.floor(s));
  const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), x = s % 60;
  return (h ? h + ':' + String(m).padStart(2, '0') : String(m)) + ':' + String(x).padStart(2, '0');
};
const shuffle = (a) => { for (let i = a.length - 1; i > 0; i--) { const j = Math.floor(Math.random() * (i + 1)); [a[i], a[j]] = [a[j], a[i]]; } return a; };

class WarSfx extends Sfx {
  take() { if (!this.running || !this.limit('take', 260)) return; this.tone({ freq: 520 + Math.random() * 260, type: 'sine', dur: 0.05, vol: 0.025 }); }
  out() { if (!this.running) return; this.tone({ freq: 700, to: 120, type: 'sawtooth', dur: 0.6, vol: 0.06 }); this.tone({ freq: 90, type: 'sine', dur: 0.5, vol: 0.12, delay: 0.05 }); }
  alarm() { if (!this.running) return; [0, 0.25, 0.5].forEach((d) => this.tone({ freq: 880, to: 660, type: 'square', dur: 0.18, vol: 0.05, delay: d })); }
}

async function main() {
  fitStage(); addEventListener('resize', fitStage);
  const [remote, atlasInfo] = await Promise.all([fetchConfig(), fetch('/flags/flags.json').then((r) => r.json())]);
  const cfg = { ...DEF, ...(remote || {}) };
  if (cfg.autoplay === false) { holdScreen('/api/war.php', cfg.rev); return; }
  const pace = PACE[cfg.pace] || 1;
  const atlas = new Image(); atlas.src = '/flags/flags.png';
  await atlas.decode().catch(() => {});

  const C = atlasInfo.countries, FW = atlasInfo.w, FH = atlasInfo.h, COLS_A = atlasInfo.cols, ROWS_A = Math.ceil(C.length / COLS_A);
  const byCode = new Map(C.map((c, i) => [c.code, i]));
  const TEAMS = cfg.teams.filter((c) => byCode.has(c)).slice(0, 200);
  if (TEAMS.length < 2) TEAMS.splice(0, TEAMS.length, ...DEF.teams);
  const N = TEAMS.length;
  const NAME = (t) => C[byCode.get(TEAMS[t])].name.toUpperCase().replace('UNITED ARAB EMIRATES', 'UAE').replace('UNITED KINGDOM', 'UK').replace('UNITED STATES', 'USA');

  // many countries need a finer map (about 30 squares each at the start)
  const GC = clamp(Math.max(Math.round(cfg.grid) || 40, Math.ceil(Math.sqrt(30 * N * MAP_W / MAP_H))), 20, 120);
  const ARMY_MAX = N > 40 ? 2 : ARMY_MAX_BIG;   // with 100+ countries: fewer balls each (screen and CPU)
  const CELL = MAP_W / GC, GR = Math.floor(MAP_H / CELL), CH = MAP_H / GR;
  const TOTAL = GC * GR;
  const ELIM = Math.max(4, Math.round(TOTAL / N * 0.3));  // land smaller than 30% of a fair share = out
  if (QS.get('fill')) cfg.fill = QS.get('fill');   // preview: ?fill=color
  const R = clamp(CELL * 1.45, 14, 40);                   // ball radius (big round flags)
  const BALL_CHATS = Math.max(0, Math.round(cfg.ball_chats) || 0), REV_CHATS = Math.max(0, Math.round(cfg.revive_chats) || 0);
  const FLAGFILL = cfg.fill !== 'color';
  const LIKE_STEP = Math.max(0, Math.round(cfg.like_step) || 0), SHARE_GAP = 600;   // one share reward per viewer every 10 minutes

  const flagCss = (i, w) => { const h = w * 0.75; return `width:${w}px;height:${h}px;background-size:${COLS_A * w}px ${ROWS_A * h}px;background-position:${-(i % COLS_A) * w}px ${-Math.floor(i / COLS_A) * h}px`; };
  // Flags with holy writing (the Kalima, Allahu Akbar) are not drawn on the land or the balls,
  // where they would be "run over" and conquered: those countries get a plain colour instead.
  const PLAIN = { sa: ['#006c35', 'KSA'], af: ['#007a36', 'AFG'], iq: ['#ce1126', 'IRQ'] };
  const flagHTML = (t, w) => `<span class="flag" style="${flagCss(byCode.get(TEAMS[t]), w)}"></span>`;
  const flagNode = (code, w = 40) => { const d = document.createElement('span'); d.className = 'flag'; d.style.cssText = flagCss(byCode.get(code), w); return d; };

  $('title').textContent = cfg.title;
  $('sub').textContent = `${N} COUNTRIES · ONLY ONE TAKES THE WORLD`;

  /* ---------- war state (saved, so a page reload continues the war) ---------- */
  const KEY = 'fft_war';
  freshRun('war', [KEY, 'fft_fans_war']);
  const sig = 'v3|' + cfg.reset + '|' + TEAMS.join(',') + '|' + GC;
  let W = null;
  try { W = JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (e) { /* private mode */ }
  if (!W || W.sig !== sig || typeof W.g !== 'string' || W.g.length !== TOTAL) W = null;

  let g = new Int16Array(TOTAL), cnt = new Int32Array(N), lastBy = new Int16Array(N).fill(-1);
  let balls = [], alive = N, simT = 0, warNo = 1, lastSudden = 0, colorOf = [], phase = 'play', phaseT = 0, champion = -1;
  let outOrder = [];   // countries in the order they were knocked out
  const teamAlive = new Uint8Array(N), boostUntil = new Float64Array(N);
  // viewers who cheered for each country (newest first): each of its balls carries one of their names
  const FAN_SECS = 240, FAN_MAX = 6, wall = () => performance.now() / 1000;
  const fans = Array.from({ length: N }, () => []);
  // chat power: every BALL_CHATS chats for a country = one extra ball for 3 minutes;
  // a country that is out comes back after REV_CHATS chats (as often as the chat wants)
  const chatProg = new Int32Array(N), chatTotal = new Int32Array(N), bonus = Array.from({ length: N }, () => []);

  const speedBase = () => 260 * (cfg.speed / 100);
  const startBall = (t, x, y) => { const a = Math.random() * Math.PI * 2; return { t, x, y, vx: Math.cos(a), vy: Math.sin(a) }; };

  const newWar = () => {
    const order = shuffle([...Array(N).keys()]);
    const cols = Math.max(1, Math.round(Math.sqrt(N * MAP_W / MAP_H))), rows = Math.ceil(N / cols);
    const seeds = [];
    for (let k = 0; k < N; k++) {
      const row = Math.floor(k / cols), inRow = row === rows - 1 ? N - row * cols : cols, col = k % cols;
      const cx = (col + 0.5) / inRow * MAP_W + (Math.random() - 0.5) * MAP_W / inRow * 0.3;
      const cy = (row + 0.5) / rows * MAP_H + (Math.random() - 0.5) * MAP_H / rows * 0.3;
      seeds.push([cx, cy, order[k]]);
    }
    for (let y = 0; y < GR; y++) for (let x = 0; x < GC; x++) {
      const px = (x + 0.5) * CELL, py = (y + 0.5) * CH;
      let best = 0, bd = Infinity;
      seeds.forEach((s, i) => { const d = (s[0] - px) ** 2 + (s[1] - py) ** 2; if (d < bd) { bd = d; best = i; } });
      g[y * GC + x] = seeds[best][2];
    }
    balls = seeds.map(([x, y, t]) => startBall(t, x, y));
    colorOf = shuffle(Array.from({ length: Math.max(N, 1) }, (_, i) => PALETTE[i] || `hsl(${(i * 137.5) % 360} 70% ${45 + (i % 3) * 10}%)`));
    teamAlive.fill(1); boostUntil.fill(0); outOrder = []; chatProg.fill(0); chatTotal.fill(0); bonus.forEach((l) => { l.length = 0; });
    alive = N; simT = 0; lastSudden = 0; phase = 'play'; phaseT = 0; champion = -1; lastBy.fill(-1);
  };

  if (W) {
    for (let k = 0; k < TOTAL; k++) g[k] = W.g.charCodeAt(k) - 65;
    balls = W.balls.map((b) => ({ ...startBall(b[0], b[1], b[2]), vx: b[3], vy: b[4] }));
    colorOf = W.colors; simT = W.simT; warNo = W.warNo || 1; lastSudden = W.lastSudden || 0;
    W.alive.forEach((a, t) => { teamAlive[t] = a; });
    outOrder = Array.isArray(W.outs) ? W.outs : [];
    if (W.chat && W.chat.t && W.chat.t.length === N) { W.chat.t.forEach((v, t) => { chatTotal[t] = v; chatProg[t] = W.chat.p[t] || 0; }); }
    alive = W.alive.filter(Boolean).length;
  } else newWar();
  const recount = () => { cnt.fill(0); for (let k = 0; k < TOTAL; k++) cnt[g[k]]++; };
  recount();
  const save = () => {
    try {
      localStorage.setItem(KEY, JSON.stringify({ sig, warNo, simT, lastSudden, colors: colorOf,
        g: String.fromCharCode(...Array.from(g, (v) => v + 65)),
        alive: Array.from(teamAlive), outs: outOrder, chat: { t: Array.from(chatTotal), p: Array.from(chatProg) },
        balls: balls.map((b) => [b.t, Math.round(b.x), Math.round(b.y), +b.vx.toFixed(3), +b.vy.toFixed(3)]) }));
    } catch (e) { /* ignore */ }
  };

  /* ---------- drawing the land (kept on its own canvas; only changed squares are redrawn) ---------- */
  const cv = $('map'), c = cv.getContext('2d');
  const land = document.createElement('canvas'), lc = land.getContext('2d');
  let k = 1;
  // Each country's land shows its own flag: one big flag stretched over the box around its land
  // (kept 4:3 and centred), so every square shows its piece of that flag.
  const bx0 = new Int16Array(N), by0 = new Int16Array(N), bx1 = new Int16Array(N), by1 = new Int16Array(N);
  const boxes = () => { // returns the countries whose box changed
    const nx0 = new Int16Array(N).fill(GC), ny0 = new Int16Array(N).fill(GR), nx1 = new Int16Array(N).fill(-1), ny1 = new Int16Array(N).fill(-1);
    for (let i = 0; i < TOTAL; i++) {
      const t = g[i], x = i % GC, y = (i - x) / GC;
      if (x < nx0[t]) nx0[t] = x; if (x > nx1[t]) nx1[t] = x; if (y < ny0[t]) ny0[t] = y; if (y > ny1[t]) ny1[t] = y;
    }
    const changed = [];
    for (let t = 0; t < N; t++) {
      if (nx1[t] < 0) continue;
      if (nx0[t] !== bx0[t] || ny0[t] !== by0[t] || nx1[t] !== bx1[t] || ny1[t] !== by1[t]) changed.push(t);
      bx0[t] = nx0[t]; by0[t] = ny0[t]; bx1[t] = nx1[t]; by1[t] = ny1[t];
    }
    return changed;
  };
  const drawCell = (idx) => {
    const x = idx % GC, y = (idx - x) / GC, t = g[idx];
    const px = Math.floor(x * CELL * k), py = Math.floor(y * CH * k), pw = Math.ceil((x + 1) * CELL * k) - px, ph = Math.ceil((y + 1) * CH * k) - py;
    // the flag over this country's box (map units)
    const w = (bx1[t] - bx0[t] + 1) * CELL, h = (by1[t] - by0[t] + 1) * CH;
    const s = Math.max(w / FW, h / FH), fx = bx0[t] * CELL + (w - FW * s) / 2, fy = by0[t] * CH + (h - FH * s) / 2;
    const fi = byCode.get(TEAMS[t]);
    const sx = (fi % COLS_A) * FW + clamp((x * CELL - fx) / s, 0, FW - 0.5), sy = Math.floor(fi / COLS_A) * FH + clamp((y * CH - fy) / s, 0, FH - 0.5);
    const plain = PLAIN[TEAMS[t]];
    lc.fillStyle = plain ? plain[0] : colorOf[t]; lc.fillRect(px, py, pw, ph);
    if (FLAGFILL && !plain && atlas.naturalWidth) lc.drawImage(atlas, sx, sy, Math.max(0.5, CELL / s), Math.max(0.5, CH / s), px, py, pw, ph);
    lc.fillStyle = 'rgba(0,0,0,.10)'; lc.fillRect(px, py, pw, 1); lc.fillRect(px, py, 1, ph);
  };
  const refreshFlags = () => { // a country's land grew or shrank: lay its flag again
    if (!FLAGFILL) return;
    const ch = boxes();
    if (!ch.length) return;
    const on = new Uint8Array(N); ch.forEach((t) => { on[t] = 1; });
    for (let i = 0; i < TOTAL; i++) if (on[g[i]]) drawCell(i);
  };
  const redrawLand = () => { boxes(); for (let i = 0; i < TOTAL; i++) drawCell(i); };
  const resize = () => {
    const r = cv.getBoundingClientRect(), dpr = Math.min(devicePixelRatio || 1, 1.5);
    cv.width = land.width = Math.max(1, Math.round(r.width * dpr)); cv.height = land.height = Math.max(1, Math.round(r.height * dpr));
    k = cv.width / MAP_W; redrawLand();
  };
  resize(); addEventListener('resize', resize);

  /* ---------- sound ---------- */
  const sfx = new WarSfx();
  const soundBtn = $('sound-btn');
  const unlock = () => { sfx.resume(); soundBtn.hidden = true; };
  soundBtn.addEventListener('click', unlock); addEventListener('pointerdown', unlock); sfx.resume();
  setTimeout(() => { if (sfx.ok && !sfx.running) soundBtn.hidden = false; }, 1500);

  /* ---------- live chat: a comment with a country boosts its ball ---------- */
  const teamOf = (code) => TEAMS.indexOf(code);
  const CN = (code) => C[byCode.get(code)].name.toUpperCase();
  const leaderT = () => { let b = -1; for (let t = 0; t < N; t++) if (teamAlive[t] && (b < 0 || cnt[t] > cnt[b])) b = t; return b; };
  const pct = (t) => (cnt[t] / TOTAL * 100);
  const state = (code) => {
    const t = teamOf(code);
    if (t < 0) return 'notin';
    return teamAlive[t] ? 'alive' : 'out';
  };
  const isGreeting = (s) => /\b(hi|hii+|hello|hey|salam|salaam|assalam\w*|asalam\w*)\b|হাই|হ্যালো|সালাম|আসসালামু/i.test(s);
  const isSalam = (s) => /salam|salaam|assalam|asalam|সালাম|আসসালামু/i.test(s);
  /* ---------- chat power panel + how to help ---------- */
  // in the field: one more ball · out: it comes back
  $('how').innerHTML = [
    (BALL_CHATS ? `💬 ${BALL_CHATS} chats → <b>+1 BALL</b>` : '') + (REV_CHATS ? ` · OUT: ${REV_CHATS} → <b class="hr">REVIVE</b>` : ''),
    `🔔 Subscribe → <b class="hv">+1 BALL</b> or <b class="hr">REVIVE</b>`,
    `🔁 Share + type <b class="hg">SHARED</b> → <b class="hv">+1 BALL</b> or <b class="hr">REVIVE</b>`,
    LIKE_STEP ? `👍 Every ${LIKE_STEP} likes → <b class="hv">+1 BALL</b> or <b class="hr">REVIVE</b>` : '',
    `⭐ Super Chat → <b class="hg">+2 BALLS</b> or <b class="hr">REVIVE</b>`,
  ].filter(Boolean).map((h) => `<div class="how-r">${h}</div>`).join('');
  const extraNow = (t) => bonus[t].filter((u) => u > simT).length;
  // every country people typed, 4 at a time; the pages turn every 5 seconds
  let powPage = 0;
  const renderPower = () => {
    const all = [...Array(N).keys()].filter((t) => chatTotal[t] > 0).sort((a, b) => chatTotal[b] - chatTotal[a]);
    const pages = Math.max(1, Math.ceil(all.length / 4));
    if (powPage >= pages) powPage = 0;
    $('cpow-pg').textContent = pages > 1 ? `${powPage + 1}/${pages}` : '';
    if (!all.length) { $('cpow').innerHTML = '<div class="cp-empty">TYPE YOUR COUNTRY IN CHAT — EVERY CHAT COUNTS HERE!</div>'; return; }
    $('cpow').innerHTML = all.slice(powPage * 4, powPage * 4 + 4).map((t, n) => {
      const on = !!teamAlive[t], need = on ? BALL_CHATS : REV_CHATS, w = need ? Math.min(100, chatProg[t] / need * 100) : 0;
      const lbl = on ? (need ? `${chatProg[t]}/${need}` : '') : (need ? `OUT ${chatProg[t]}/${need}` : 'OUT');
      const x = on ? extraNow(t) : 0;
      return `<div class="cp-row${on ? '' : ' out'}"><span class="n">${powPage * 4 + n + 1}.</span>${flagHTML(t, 34)}<span class="nm">${NAME(t)}</span>` +
        `<span class="c">💬${chatTotal[t]}</span><span class="bar"><i style="width:${w.toFixed(0)}%"></i></span><span class="lbl">${x ? `<em>+${x}⚽</em> ` : ''}${lbl}</span></div>`;
    }).join('');
  };
  setInterval(() => { powPage++; renderPower(); }, 5000);
  renderPower();
  // what LUNA says about a country's chats: how many, and what more chats will give it
  const perk = (t) => {
    if (!teamAlive[t]) return REV_CHATS ? `${Math.max(0, REV_CHATS - chatProg[t])} MORE CHATS AND IT COMES BACK TO THE WAR!` : 'IT CAN COME BACK NEXT WAR!';
    const x = extraNow(t);
    if (!BALL_CHATS) return `IT HOLDS ${pct(t).toFixed(1)}% OF THE WORLD!`;
    return `${Math.max(0, BALL_CHATS - chatProg[t])} MORE CHATS = ONE MORE BALL!` + (x ? ` IT HAS +${x} EXTRA BALL${x > 1 ? 'S' : ''} NOW!` : '');
  };
  const pick = (a) => a[Math.floor(Math.random() * a.length)];
  const shareAt = new Map();
  let likeBase = -1, likeVideo = '';
  // a gift for a country: one more ball if it is fighting, back in the war if it is out
  const reward = (t, by, why, quiet) => {
    if (phase !== 'play') return false;
    if (!teamAlive[t]) return revive(t, by);
    addBonus(t, by, quiet ? '' : why);
    return true;
  };
  // LUNA reads the chat meter out loud now and then: who is ahead, who is close to a ball or a comeback
  let standN = 0;
  const standings = () => {
    if (phase !== 'play') return null;
    const all = [...Array(N).keys()].filter((t) => chatTotal[t] > 0);
    standN++;
    if (!all.length) return [`NO CHATS YET! BE THE FIRST — TYPE YOUR COUNTRY AND ${BALL_CHATS || 15} CHATS GIVE IT ONE MORE BALL!`];
    const outs = all.filter((t) => !teamAlive[t]).sort((a, b) => chatProg[b] - chatProg[a]);
    if (standN % 3 === 2 && outs.length && REV_CHATS) {
      const u = outs[0];
      return [{ node: flagNode(TEAMS[u]) }, NAME(u) + ` IS OUT BUT ${chatProg[u]} OF ${REV_CHATS} CHATS ARE DONE — ${Math.max(0, REV_CHATS - chatProg[u])} MORE AND IT COMES BACK! TYPE ${NAME(u)}!`];
    }
    const top = all.filter((t) => teamAlive[t]).sort((a, b) => chatTotal[b] - chatTotal[a]);
    if (!top.length) return null;
    if (standN % 3 === 1 && top.length > 1) {
      return [{ node: flagNode(TEAMS[top[0]]) }, NAME(top[0]) + ` LEADS THE CHAT WITH ${chatTotal[top[0]]}! `, { node: flagNode(TEAMS[top[1]]) }, NAME(top[1]) + ` HAS ${chatTotal[top[1]]} — CAN YOU CATCH UP?`];
    }
    const close = top.slice().sort((a, b) => chatProg[b] - chatProg[a])[0];
    return [{ node: flagNode(TEAMS[close]) }, NAME(close) + ` HAS ${chatProg[close]}/${BALL_CHATS} — ONLY ${Math.max(0, BALL_CHATS - chatProg[close])} MORE CHATS FOR ONE MORE BALL! TYPE ${NAME(close)}!`];
  };

  const chat = liveChat({
    game: 'war', rows: 3, alwaysOn: true, idleLine: standings,
    match: countryMatcher(C),
    badge: (code) => flagNode(code, 34),
    duck: (on) => sfx.duck(on),
    sfx: () => { sfx.tone({ freq: 784, type: 'sine', dur: 0.18, vol: 0.09 }); sfx.tone({ freq: 1175, type: 'sine', dur: 0.3, vol: 0.08, delay: 0.14 }); },
    onFan: (code, name) => {
      const t = teamOf(code);
      if (t < 0 || phase !== 'play') return;
      const nm = name.toUpperCase().slice(0, 12);
      chatTotal[t]++;
      if (!teamAlive[t]) {
        if (REV_CHATS && ++chatProg[t] >= REV_CHATS && revive(t, nm)) chatProg[t] = 0;
        renderPower();
        return;
      }
      if (BALL_CHATS && ++chatProg[t] >= BALL_CHATS) { chatProg[t] = 0; addBonus(t, nm); }
      renderPower();
      fans.forEach((list) => { const j = list.findIndex((f) => f.name === nm); if (j >= 0) list.splice(j, 1); }); // one viewer, one country
      fans[t].unshift({ name: nm, until: wall() + FAN_SECS, hot: wall() + BOOST_SECS + 4 });
      if (fans[t].length > FAN_MAX) fans[t].length = FAN_MAX;
      if (cfg.boost) boostUntil[t] = simT + BOOST_SECS;
    },
    onSub: (name, code) => { // their country gets a ball — or comes back if it is out
      let t = code ? teamOf(code) : -1;
      if (t < 0) { for (let u = 0; u < N; u++) if (teamAlive[u] && (t < 0 || chatTotal[u] > chatTotal[t])) t = u; }
      if (t >= 0) reward(t, name.toUpperCase().slice(0, 12), 'SUBSCRIBE');
    },
    onText: (name, text, code, author) => { // "shared" in chat: we can't see shares, so we trust the viewer (once every 10 min)
      if (!/\bshared\b|\bshare\s*(done|korsi|korchi|korlam|korechi|korsi|dilam|disi|diyechi|kore\s*disi)|শেয়ার\s*(করেছি|করলাম|দিলাম|করছি|দিছি|দিয়েছি)/i.test(text)) return null;
      const t = code ? teamOf(code) : -1;
      if (t < 0) return ['THANK YOU FOR SHARING, ', { name }, '! TYPE "SHARED" WITH YOUR COUNTRY NAME AND IT GETS ONE MORE BALL!'];
      const w = wall();
      if ((shareAt.get(author) || 0) > w - SHARE_GAP) return ['THANK YOU FOR SHARING AGAIN, ', { name }, '! ONE SHARE GIFT EVERY 10 MINUTES — KEEP CHATTING FOR ', { node: flagNode(code) }, CN(code) + '!'];
      shareAt.set(author, w);
      const was = teamAlive[t];
      reward(t, name.toUpperCase().slice(0, 12), 'SHARE', true);
      return ['THANK YOU FOR SHARING, ', { name }, '! ', { node: flagNode(code) }, CN(code) + (was ? ' GETS ONE MORE BALL!' : ' IS BACK IN THE WAR!') + ' MORE FRIENDS = MORE POWER!'];
    },
    onStats: (st) => { // every LIKE_STEP new likes: the country closest to a comeback returns, or the chat's favourite gets a ball
      if (!LIKE_STEP || !st || phase !== 'play') return;
      if (likeBase < 0 || st.video !== likeVideo) { likeBase = st.likes; likeVideo = st.video; return; }
      while (st.likes - likeBase >= LIKE_STEP) {
        likeBase += LIKE_STEP;
        const outs = [...Array(N).keys()].filter((u) => !teamAlive[u] && chatProg[u] > 0).sort((a, b) => chatProg[b] - chatProg[a]);
        let t = outs[0] ?? -1;
        if (t < 0) for (let u = 0; u < N; u++) if (teamAlive[u] && (t < 0 || chatTotal[u] > chatTotal[t])) t = u;
        banner('LIKE GOAL!', `${st.likes} LIKES — THANK YOU!`);
        if (t >= 0) reward(t, 'YOUR LIKES', 'LIKES');
      }
    },
    onSuper: (code, name) => {
      const t = code ? teamOf(code) : -1;
      if (t < 0 || phase !== 'play') return;
      if (!teamAlive[t]) { revive(t, name.toUpperCase().slice(0, 12)); return; }
      addBonus(t, name.toUpperCase().slice(0, 12), 'SUPER CHAT'); addBonus(t, name.toUpperCase().slice(0, 12), 'SUPER CHAT');
    },
    emptyHint: 'Type your country — every chat counts!',
    hints: [`TYPE YOUR COUNTRY — ${BALL_CHATS || 15} CHATS GIVE IT ONE MORE BALL!`, 'WHERE ARE YOU WATCHING FROM? TYPE YOUR COUNTRY!', 'TYPE A COUNTRY FROM THE MAP TO BOOST ITS BALL!', 'DROP A LIKE IF YOU ARE ENJOYING THE WAR!', 'WHICH COUNTRY WILL TAKE THE WORLD? TELL ME IN CHAT!'],
    idleLines: [
      ['TYPE YOUR COUNTRY IN CHAT — ITS BALL GETS A SPEED BOOST AND CONQUERS FASTER!'],
      ...(BALL_CHATS ? [[`EVERY ${BALL_CHATS} CHATS GIVE YOUR COUNTRY ONE MORE BALL! TYPE IT NOW!`]] : []),
      ...(REV_CHATS ? [[`YOUR COUNTRY IS OUT? ${REV_CHATS} CHATS BRING IT BACK TO THE MAP!`]] : []),
      ['SUBSCRIBE NOW — YOUR COUNTRY GETS ONE MORE BALL, AND IF IT IS OUT IT COMES BACK!'],
      ['SHARE THIS LIVE WITH A FRIEND AND TYPE "SHARED" WITH YOUR COUNTRY — IT GETS ONE MORE BALL OR COMES BACK!'],
      ...(LIKE_STEP ? [[`EVERY ${LIKE_STEP} LIKES I GIVE A COUNTRY ONE MORE BALL OR BRING ONE BACK — PRESS LIKE NOW!`]] : []),
      ['WHICH COUNTRY WILL TAKE THE WHOLE WORLD? COMMENT YOUR PICK!'],
      ['YOUR COMMENTS CHANGE THE WAR — CHEER FOR YOUR COUNTRY NOW!'],
    ],
    welcome: (name, code, text) => {
      const hi = isSalam(text) ? 'WA ALAIKUM ASSALAM ' : 'WELCOME ';
      if (!code) return [hi, { name }, `! WHERE ARE YOU FROM? TYPE YOUR COUNTRY — EVERY ${BALL_CHATS || 15} CHATS GIVE IT ONE MORE BALL!`];
      const s = state(code);
      if (s === 'alive') return [hi, { name }, ' FROM ', { node: flagNode(code) }, CN(code) + `! ${chatTotal[teamOf(code)]} CHAT${chatTotal[teamOf(code)] === 1 ? '' : 'S'} FOR IT SO FAR — ${perk(teamOf(code))}`];
      if (s === 'out') return [hi, { name }, ' FROM ', { node: flagNode(code) }, CN(code) + (REV_CHATS ? `! IT IS OUT — ${Math.max(0, REV_CHATS - chatProg[teamOf(code)])} MORE CHATS AND IT COMES BACK!` : '! IT WAS CONQUERED THIS WAR — CHEER IT IN THE NEXT ONE!')];
      return [hi, { name }, ' FROM ', { node: flagNode(code) }, CN(code) + `! IT IS NOT IN THIS WAR — PICK ONE OF THE ${N} COUNTRIES ON THE MAP!`];
    },
    reply: (name, code, text) => {
      if (code) {
        const s = state(code), t = teamOf(code);
        if (s === 'alive') return [pick(['GO GO! ', 'YES ', 'COME ON ', 'NICE ']), { name }, '! ', { node: flagNode(code) }, CN(code) + ` HAS ${chatTotal[t]} CHATS! ` + perk(t)];
        if (s === 'out') return REV_CHATS ? [{ node: flagNode(code) }, CN(code) + ` IS OUT, `, { name }, `! ` + perk(t) + ' TELL YOUR FRIENDS TO CHAT!']
          : [{ node: flagNode(code) }, CN(code) + ' IS ALREADY OUT, ', { name }, '… NEXT WAR STARTS SOON!'];
        return null;
      }
      if (/reviv|back|ফির|রিভাইভ/i.test(text)) {
        const outs = [...Array(N).keys()].filter((u) => !teamAlive[u]);
        if (!outs.length) return ['NO COUNTRY IS OUT YET, ', { name }, '! TYPE YOUR COUNTRY — ' + (BALL_CHATS ? `${BALL_CHATS} CHATS GIVE IT ONE MORE BALL!` : 'BOOST IT!')];
        const near = outs.sort((a, b) => chatProg[b] - chatProg[a]).slice(0, 2);
        return [{ name }, `, TO REVIVE A COUNTRY TYPE ITS NAME ${REV_CHATS} TIMES! `, ...near.flatMap((u) => [{ node: flagNode(TEAMS[u]) }, `${NAME(u)} ${chatProg[u]}/${REV_CHATS}, NEEDS ${Math.max(0, REV_CHATS - chatProg[u])} MORE. `])];
      }
      if (/who.*(win|lead)|winner|kon|কে জিতবে/i.test(text)) {
        const l = leaderT();
        return l >= 0 ? ['RIGHT NOW ', { node: flagNode(TEAMS[l]) }, NAME(l) + ` LEADS WITH ${pct(l).toFixed(1)}%, `, { name }, '!'] : null;
      }
      if (isGreeting(text)) return [isSalam(text) ? 'WA ALAIKUM ASSALAM ' : 'HELLO AGAIN ', { name }, '! WHICH COUNTRY ARE YOU CHEERING FOR?'];
      return null;
    },
    demoTexts: ['shared Bangladesh', 'Bangladesh 🇧🇩', 'India!', 'Brasil 🔥', 'Go Pakistan', 'hello', 'USA USA', 'who will win?', 'China', 'assalamu alaikum', 'Nepal', 'Saudi Arabia', 'Japan ❤️', 'বাংলাদেশ', 'nice game'],
  });
  const host = (parts, prio = 2) => { if (chat.active) chat.say(parts, prio); };
  // LUNA's box is small here: make a long line smaller until it fits
  const rbt = document.querySelector('.lc-war .lc-rb-text');
  if (rbt) new MutationObserver(() => {
    rbt.style.fontSize = '';
    for (let size = 33; rbt.scrollHeight > rbt.clientHeight + 2 && size > 20; size -= 1) rbt.style.fontSize = size + 'px';
  }).observe(rbt, { childList: true });

  /* ---------- the war ---------- */
  let feedAt = 0;
  const feed = (html) => {
    feedAt = performance.now();
    const f = $('feed'); f.classList.remove('rules'); f.innerHTML = html; f.style.fontSize = '';
    for (let size = 32; f.scrollWidth > 1050 && size > 18; size -= 2) f.style.fontSize = size + 'px'; // long names: smaller text
    f.classList.remove('pop'); void f.offsetWidth; f.classList.add('pop');
  };
  const banner = (text, small, red) => {
    const b = $('banner'), t = $('banner-text');
    t.className = 'banner-in' + (red ? ' red' : ''); t.innerHTML = `${text}<small>${small}</small>`;
    b.hidden = false; t.style.animation = 'none'; void t.offsetWidth; t.style.animation = '';
    clearTimeout(banner.timer); banner.timer = setTimeout(() => { b.hidden = true; }, 3100);
  };

  const neighbourOf = (o) => { // the country that touches o's land the most
    const touch = new Int32Array(N);
    for (let k2 = 0; k2 < TOTAL; k2++) {
      if (g[k2] !== o) continue;
      const x = k2 % GC;
      for (const n of [x > 0 ? k2 - 1 : -1, x < GC - 1 ? k2 + 1 : -1, k2 - GC, k2 + GC]) if (n >= 0 && n < TOTAL && g[n] !== o) touch[g[n]]++;
    }
    let best = -1; for (let t = 0; t < N; t++) if (t !== o && teamAlive[t] && (best < 0 || touch[t] > touch[best])) best = t;
    return best;
  };

  const eliminate = (o, by) => {
    if (!teamAlive[o] || alive <= 1) return;
    if (by < 0 || !teamAlive[by]) by = neighbourOf(o);
    teamAlive[o] = 0; alive--; outOrder.push(o); chatProg[o] = 0; bonus[o].length = 0; renderPower();
    balls = balls.filter((b) => b.t !== o);
    for (let k2 = 0; k2 < TOTAL; k2++) if (g[k2] === o) g[k2] = by;
    redrawLand();
    cnt[by] += cnt[o]; cnt[o] = 0;
    feed(`💥 ${flagHTML(o, 40)}<span>${NAME(o)} IS OUT — CONQUERED BY</span>${flagHTML(by, 40)}<span>${NAME(by)}</span>`);
    sfx.out();
    host([{ node: flagNode(TEAMS[o]) }, NAME(o) + ' IS OUT! CONQUERED BY ', { node: flagNode(TEAMS[by]) }, NAME(by) + `! ${alive} COUNTRIES LEFT!`], 2);
    if (alive === 1) crown(by);
    else if (alive === 2) {
      const two = [...Array(N).keys()].filter((t) => teamAlive[t]);
      banner('FINAL BATTLE', `${NAME(two[0])} VS ${NAME(two[1])}`);
      sfx.alarm();
      host(['FINAL BATTLE! ', { node: flagNode(TEAMS[two[0]]) }, NAME(two[0]) + ' AGAINST ', { node: flagNode(TEAMS[two[1]]) }, NAME(two[1]) + '! WHO TAKES THE WORLD? COMMENT NOW!'], 3);
    }
    save();
  };

  const addBonus = (t, by, why) => {
    const l = bonus[t].filter((u) => u > simT);
    if (l.length >= BONUS_MAX) l.shift();
    l.push(simT + BONUS_SECS); bonus[t] = l;
    armies();
    feed(`➕ ${flagHTML(t, 40)}<span>${NAME(t)} GETS ONE MORE BALL — THANKS ${by}!</span>`);
    sfx.tone({ freq: 660, to: 1320, type: 'triangle', dur: 0.3, vol: 0.12 });
    host(why ? [`THANK YOU `, { name: by }, ` FOR THE ${why}! `, { node: flagNode(TEAMS[t]) }, NAME(t) + ' GETS ONE MORE BALL!']
      : [{ node: flagNode(TEAMS[t]) }, NAME(t) + ` GETS ONE MORE BALL! THANK YOU `, { name: by }, `! ${BALL_CHATS} MORE CHATS = ANOTHER BALL!`], why ? 3 : 2);
    renderPower();
  };
  const revive = (t, by) => { // the chat brings a country back: it takes a piece of the biggest country's land
    if (teamAlive[t] || phase !== 'play' || alive < 2) return false;
    let big = -1; for (let u = 0; u < N; u++) if (teamAlive[u] && (big < 0 || cnt[u] > cnt[big])) big = u;
    if (big < 0) return false;
    const want = Math.max(ELIM * 3, Math.round(TOTAL / N * 0.6));
    let start = -1; for (let tries = 0; tries < 400 && start < 0; tries++) { const i = Math.floor(Math.random() * TOTAL); if (g[i] === big) start = i; }
    if (start < 0) return false;
    const queue = [start], seen = new Uint8Array(TOTAL); seen[start] = 1; let took = 0;
    while (queue.length && took < want) {
      const i = queue.shift(); g[i] = t; took++;
      const x = i % GC;
      for (const n of [x > 0 ? i - 1 : -1, x < GC - 1 ? i + 1 : -1, i - GC, i + GC]) if (n >= 0 && n < TOTAL && !seen[n] && g[n] === big) { seen[n] = 1; queue.push(n); }
    }
    teamAlive[t] = 1; alive++; outOrder = outOrder.filter((o) => o !== t); boostUntil[t] = simT + BOOST_SECS;
    recount(); redrawLand();
    const x = start % GC, y = (start - x) / GC;
    balls.push(startBall(t, clamp((x + 0.5) * CELL, R, MAP_W - R), clamp((y + 0.5) * CH, R, MAP_H - R)));
    banner(`${NAME(t)} IS BACK!`, `REVIVED BY ${by} & THE CHAT`);
    feed(`♻️ ${flagHTML(t, 40)}<span>${NAME(t)} IS BACK — THE CHAT REVIVED IT!</span>`);
    sfx.tone({ freq: 523, type: 'sine', dur: 0.25, vol: 0.12 }); sfx.tone({ freq: 784, type: 'sine', dur: 0.35, vol: 0.12, delay: 0.15 });
    host([{ node: flagNode(TEAMS[t]) }, NAME(t) + ' IS BACK IN THE WAR! THE CHAT REVIVED IT — THANK YOU ', { name: by }, '!'], 3);
    save(); updateUI(); renderPower();
    return true;
  };

  const crown = (t) => {
    champion = t; phase = 'won'; phaseT = 0;
    for (let k2 = 0; k2 < TOTAL; k2++) g[k2] = t;
    redrawLand();
    recount();
    const w = $('win'); $('win-flag').style.cssText = flagCss(byCode.get(TEAMS[t]), 300);
    $('win-name').textContent = NAME(t); $('win-sub').textContent = `WAR #${warNo} · ${fmtTime(simT)}`;
    w.hidden = false; sfx.win();
    host(['WE HAVE A WORLD CHAMPION! ', { node: flagNode(TEAMS[t]) }, NAME(t) + ` CONQUERED THE WHOLE WORLD IN ${Math.max(1, Math.round(simT / 60))} MINUTES! CONGRATULATIONS!`], 3);
  };

  const capture = (idx, t) => {
    const o = g[idx];
    g[idx] = t; cnt[o]--; cnt[t]++; lastBy[o] = t;
    drawCell(idx); sfx.take();
    if (cnt[o] < ELIM) eliminate(o, t);
  };

  const step = (dt) => {
    const base = speedBase() * (simT > cfg.sudden * 60 ? 1.25 : 1);
    const edge = EDGE_START + EDGE_GROW * Math.min(1.5, simT / 60 / EDGE_MINUTES);
    for (const b of balls) {
      if (!teamAlive[b.t]) continue;
      const strength = Math.max(0.35, 1 + edge * Math.min(3, cnt[b.t] / TOTAL * alive - 1));
      const sp = base * strength * (boostUntil[b.t] > simT ? BOOST_MULT : 1) * CELL / 25;
      const m = Math.hypot(b.vx, b.vy) || 1; b.vx /= m; b.vy /= m;
      // x
      let nx = b.x + b.vx * sp * dt;
      if (nx < R || nx > MAP_W - R) { b.vx = -b.vx; nx = clamp(nx, R, MAP_W - R); }
      let cx = Math.floor((nx + Math.sign(b.vx) * R * 0.55) / CELL), cy = Math.floor(b.y / CH);
      cx = clamp(cx, 0, GC - 1); cy = clamp(cy, 0, GR - 1);
      if (g[cy * GC + cx] !== b.t) { capture(cy * GC + cx, b.t); b.vx = -b.vx; } else b.x = nx;
      // y
      let ny = b.y + b.vy * sp * dt;
      if (ny < R || ny > MAP_H - R) { b.vy = -b.vy; ny = clamp(ny, R, MAP_H - R); }
      cx = clamp(Math.floor(b.x / CELL), 0, GC - 1); cy = clamp(Math.floor((ny + Math.sign(b.vy) * R * 0.55) / CH), 0, GR - 1);
      if (g[cy * GC + cx] !== b.t) { capture(cy * GC + cx, b.t); b.vy = -b.vy; } else b.y = ny;
      if (phase === 'won') return;
    }
  };

  const suddenDeath = () => {
    if (alive <= 1 || simT < cfg.sudden * 60) return;
    if (!lastSudden) {
      lastSudden = simT;
      banner('SUDDEN DEATH', `SMALLEST COUNTRY OUT EVERY ${suddenEvery(alive)} SECONDS`, true); sfx.alarm();
      host([`SUDDEN DEATH! FROM NOW ON THE SMALLEST COUNTRY IS OUT EVERY ${suddenEvery(alive)} SECONDS — BOOST YOUR COUNTRY IN CHAT!`], 3);
      return;
    }
    if (simT - lastSudden < suddenEvery(alive)) return;
    lastSudden = simT;
    let s = -1; for (let t = 0; t < N; t++) if (teamAlive[t] && (s < 0 || cnt[t] < cnt[s])) s = t;
    if (s >= 0) eliminate(s, neighbourOf(s));
  };

  /* ---------- armies: bigger countries get more balls ---------- */
  const armies = () => {
    const have = new Int32Array(N);
    for (const b of balls) have[b.t]++;
    for (let t = 0; t < N; t++) {
      if (!teamAlive[t]) continue;
      const extra = bonus[t].length ? (bonus[t] = bonus[t].filter((u) => u > simT)).length : 0;
      const want = clamp(1 + Math.floor((cnt[t] / TOTAL * N - 1) / ARMY_STEP), 1, ARMY_MAX) + extra;
      if (have[t] < want) { // new ball on a random square of its own land
        for (let tries = 0; tries < 80; tries++) {
          const idx = Math.floor(Math.random() * TOTAL);
          if (g[idx] !== t) continue;
          const x = idx % GC, y = (idx - x) / GC;
          balls.push(startBall(t, clamp((x + 0.5) * CELL, R, MAP_W - R), clamp((y + 0.5) * CH, R, MAP_H - R)));
          break;
        }
      } else if (have[t] > want) {
        const i = balls.map((b) => b.t).lastIndexOf(t);
        if (i >= 0) balls.splice(i, 1);
      }
    }
  };

  /* ---------- drawing ---------- */
  const draw = () => {
    c.setTransform(1, 0, 0, 1, 0, 0);
    c.drawImage(land, 0, 0);
    c.setTransform(k, 0, 0, k, 0, 0);
    const now = simT;
    for (const b of balls) {
      const i = byCode.get(TEAMS[b.t]);
      const boosted = boostUntil[b.t] > now;
      if (boosted) { // glow while boosted
        c.globalAlpha = 0.35 + 0.25 * Math.sin(now * 12);
        c.fillStyle = '#ffd166'; c.beginPath(); c.arc(b.x, b.y, R * 1.7, 0, 7); c.fill(); c.globalAlpha = 1;
      }
      const plain = PLAIN[TEAMS[b.t]];
      c.save(); c.beginPath(); c.arc(b.x, b.y, R, 0, 7); c.closePath();
      c.fillStyle = plain ? plain[0] : '#fff'; c.fill(); c.clip();
      if (plain) {
        c.fillStyle = '#fff'; c.font = `700 ${Math.round(R * 0.72)}px Oswald, Rajdhani, sans-serif`; c.textAlign = 'center'; c.textBaseline = 'middle';
        c.fillText(plain[1], b.x, b.y + 1); c.textBaseline = 'alphabetic';
      } else c.drawImage(atlas, (i % COLS_A) * FW, Math.floor(i / COLS_A) * FH, FW, FH, b.x - R * 1.36, b.y - R, R * 2.72, R * 2);
      c.restore();
      c.lineWidth = boosted ? 5 : 4; c.strokeStyle = boosted ? '#ffd166' : '#fff';
      c.beginPath(); c.arc(b.x, b.y, R, 0, 7); c.stroke();
    }
    // viewers' names over the balls: every ball of a country carries one of its fans' names
    // (the newest fan gets the first ball; gold while their boost is on)
    c.font = '700 22px Rajdhani, Oswald, sans-serif'; c.textAlign = 'center'; c.textBaseline = 'middle';
    const tnow = wall(), seen = new Int16Array(N);
    for (let t = 0; t < N; t++) { const list = fans[t]; while (list.length && list[list.length - 1].until < tnow) list.pop(); }
    const tags = [];
    for (const b of balls) { const f = fans[b.t][seen[b.t]++]; if (f) tags.push([b, f]); }
    tags.sort((a, z) => (a[1].hot > tnow) - (z[1].hot > tnow)); // the boosting fan's name on top
    for (const [b, f] of tags) {
      const hot = f.hot > tnow, label = (hot ? '★ ' : '') + f.name, tw = c.measureText(label).width + 20, y = b.y - R - 20;
      c.fillStyle = hot ? '#ffc93a' : 'rgba(8,14,34,.82)'; c.beginPath(); c.roundRect(b.x - tw / 2, y - 15, tw, 30, 15); c.fill();
      if (!hot) { c.lineWidth = 2; c.strokeStyle = 'rgba(255,255,255,.7)'; c.stroke(); }
      c.fillStyle = hot ? '#1a1200' : '#fff'; c.fillText(label, b.x, y + 1);
    }
    c.textBaseline = 'alphabetic';
  };

  /* ---------- scoreboard ---------- */
  let lastLeader = -1, lastLeaderTalk = 0;
  const updateUI = () => {
    $('st-left').textContent = `${alive} / ${N}`;
    const order = [...Array(N).keys()].filter((t) => teamAlive[t]).sort((a, b) => cnt[b] - cnt[a]);
    const lead = order[0];
    $('st-lead').textContent = lead >= 0 ? NAME(lead) : '—';
    // living countries by size, then the latest knocked-out ones (greyed)
    const rows = order.map((t) => [t, true]).concat(outOrder.slice().reverse().map((t) => [t, false])).slice(0, 8);
    $('board').innerHTML = rows.map(([t, on], n) => {
      const p = pct(t);
      return `<div class="r${n === 0 && on ? ' top' : ''}${on ? '' : ' out'}"><span class="n">${on ? '#' + (n + 1) : '✖'}</span>${flagHTML(t, 34)}<span class="nm">${NAME(t)}</span>` +
        `<span class="p">${on ? p.toFixed(1) + '%' : 'OUT'}</span>` +
        ((on ? BALL_CHATS : REV_CHATS) ? `<i class="cp${on ? '' : ' rv'}" style="width:${Math.min(100, chatProg[t] / (on ? BALL_CHATS : REV_CHATS) * 100).toFixed(0)}%"></i>` : '') + '</div>';
    }).join('');
    const ph = $('phase');
    if (phase === 'play' && alive === 2) { ph.hidden = false; ph.className = 'phase final'; ph.textContent = '⚔ FINAL BATTLE'; }
    else if (phase === 'play' && simT > cfg.sudden * 60) { ph.hidden = false; ph.className = 'phase'; ph.textContent = `☠ SUDDEN DEATH · NEXT OUT IN ${Math.max(0, Math.ceil(suddenEvery(alive) - (simT - lastSudden)))}s`; }
    else ph.hidden = true;
    // the host mentions a new leader now and then
    if (phase === 'play' && lead !== lastLeader && lead >= 0 && simT - lastLeaderTalk > 150) {
      if (lastLeader >= 0) host([{ node: flagNode(TEAMS[lead]) }, NAME(lead) + ` TAKES THE LEAD WITH ${pct(lead).toFixed(1)}% OF THE WORLD!`], 1);
      lastLeaderTalk = simT;
    }
    lastLeader = lead;
  };

  /* ---------- settings from the admin panel ---------- */
  setInterval(async () => {
    const n = await fetchConfig();
    if (!n) return;
    if (n.reset !== cfg.reset) { try { localStorage.removeItem(KEY); } catch (e) { /* ignore */ } location.reload(); return; }
    if (n.rev !== cfg.rev && phase !== 'won') { save(); location.reload(); }
  }, 20000);

  /* ---------- game loop ---------- */
  if (!W) setTimeout(() => { banner(`WAR #${warNo}`, `${N} COUNTRIES · 1 WINNER`); host([`WELCOME TO TERRITORY WAR! ${N} COUNTRIES FIGHT FOR THE WORLD. TYPE YOUR COUNTRY IN CHAT TO BOOST ITS BALL!`], 2); }, 1200);
  let lastArmy = 0;
  let last = performance.now(), acc = 0, lastDraw = 0, lastUI = 0, lastSave = performance.now();
  const frame = (now) => {
    const dt = Math.min(0.1, (now - last) / 1000) * FAST * pace; last = now;
    acc += dt;
    if (phase === 'play') {
      let n = 0;
      while (acc >= FIXED && n < 4000) {
        step(FIXED); simT += FIXED; acc -= FIXED; n++;
        if (phase !== 'play') break;
        suddenDeath();
        if (simT - lastArmy > 0.5) { armies(); lastArmy = simT; }
      }
      if (n >= 4000) acc = 0;
    } else {
      acc = 0; phaseT += dt / FAST;
      if (phaseT > 14) { // next war
        $('win').hidden = true; warNo++; newWar(); recount(); redrawLand(); save(); renderPower();
        banner(`WAR #${warNo}`, `${N} COUNTRIES · 1 WINNER`);
        host([`A NEW WAR BEGINS! ${N} COUNTRIES, ONE WORLD. COMMENT YOUR COUNTRY TO BOOST IT!`], 2);
      }
    }
    if (now - lastUI > 1000) {
      updateUI(); refreshFlags(); lastUI = now;
    }
    if (now - lastSave > 5000) { save(); lastSave = now; }
    if (now - lastDraw >= 31) { draw(); lastDraw = now; } // ~30 frames a second is plenty for a stream
    requestAnimationFrame(frame);
  };
  updateUI();
  requestAnimationFrame(frame);
  autoUpdate('war', save);   // after a deploy: reload with the game where it was (no Restart needed)
  window.__war = { cfg, cnt, teamAlive, balls: () => balls, alive: () => alive, simT: () => simT, phase: () => phase, warNo: () => warNo, chat, fans };
}

main();
