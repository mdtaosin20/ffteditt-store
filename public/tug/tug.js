// FFTEdit · Tug of War. Two countries, one rope: every live-chat comment with a country name is one pull.
// The knot that crosses a team's line wins the round (or whoever is ahead when the time runs out),
// then the next pair of countries comes on. Made for 24/7 vertical live streams with LUNA as host.
import { Sfx, holdScreen, PACE, freshRun } from '/game/audio.js?v=13';
import { liveChat, countryMatcher } from '/game/chat.js?v=24';
import { goLive } from '/game/golive.js?v=2';
import { autoUpdate } from '/game/update.js?v=1';

const $ = (id) => document.getElementById(id);
const QS = new URLSearchParams(location.search);
const clamp = (v, a, b) => Math.max(a, Math.min(b, v));
const FAST = clamp(Number(QS.get('fast')) || 1, 1, 50);   // testing only
const pick = (a) => a[Math.floor(Math.random() * a.length)];

const DEF = { title: 'TUG OF WAR', pairs: [['bd', 'in'], ['in', 'pk'], ['br', 'ar'], ['us', 'ru'], ['id', 'my']], round: 180, power: 100, rev: '', reset: '' };
const OFFSET = 360;        // px from the centre to a team's line
const STEP = 0.045;        // one pull moves the knot this part of the way to a line (fewer when the chat is busy)
const COOLDOWN = 3;        // seconds: one viewer's comments count once per this time (more people, not spam, wins)
const WIN_SECS = 11;

async function fetchConfig() {
  try { const r = await fetch('/api/tug.php', { cache: 'no-store' }); if (r.ok) return await r.json(); } catch (e) { /* offline */ }
  return null;
}
function fitStage() {
  const vw = document.documentElement.clientWidth || innerWidth, vh = document.documentElement.clientHeight || innerHeight;
  $('stage').style.transform = `translate(-50%, -50%) scale(${Math.min(vw / 1080, vh / 1920)})`;
}
const fmt = (s) => { s = Math.max(0, Math.ceil(s)); return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0'); };

class TugSfx extends Sfx {
  pull(side) { if (!this.running || !this.limit('pull' + side, 120)) return; this.tone({ freq: side ? 330 : 260, to: side ? 220 : 180, type: 'triangle', dur: 0.12, vol: 0.12 }); }
  tick() { if (!this.running) return; this.tone({ freq: 1000, type: 'square', dur: 0.05, vol: 0.04 }); }
  horn() { if (!this.running) return; this.tone({ freq: 220, type: 'sawtooth', dur: 0.7, vol: 0.08 }); this.tone({ freq: 330, type: 'sawtooth', dur: 0.7, vol: 0.06, delay: 0.05 }); }
}

async function main() {
  fitStage(); addEventListener('resize', fitStage);
  const [remote, atlasInfo] = await Promise.all([fetchConfig(), fetch('/flags/flags.json').then((r) => r.json())]);
  const cfg = { ...DEF, ...(remote || {}) };
  if (cfg.autoplay === false) { holdScreen('/api/tug.php', cfg.rev); return; }
  const pace = PACE[cfg.pace] || 1;

  const C = atlasInfo.countries, COLS_A = atlasInfo.cols, ROWS_A = Math.ceil(C.length / COLS_A);
  const byCode = new Map(C.map((c, i) => [c.code, i]));
  const PAIRS = (Array.isArray(cfg.pairs) ? cfg.pairs : []).filter((p) => Array.isArray(p) && p.length === 2 && byCode.has(p[0]) && byCode.has(p[1]) && p[0] !== p[1]);
  if (!PAIRS.length) PAIRS.push(...DEF.pairs);
  const NAME = (code) => C[byCode.get(code)].name.toUpperCase().replace('UNITED ARAB EMIRATES', 'UAE').replace('UNITED KINGDOM', 'UK').replace('UNITED STATES', 'USA');
  const flagCss = (code, w) => { const i = byCode.get(code), h = w * 0.75; return `width:${w}px;height:${h}px;background-size:${COLS_A * w}px ${ROWS_A * h}px;background-position:${-(i % COLS_A) * w}px ${-Math.floor(i / COLS_A) * h}px`; };
  const flagHTML = (code, w) => `<span class="flag" style="${flagCss(code, w)}"></span>`;
  const flagNode = (code, w = 40) => { const d = document.createElement('span'); d.className = 'flag'; d.style.cssText = flagCss(code, w); return d; };
  $('title').textContent = cfg.title;

  /* ---------- saved: round number and today's wins (a reload continues) ---------- */
  const KEY = 'fft_tug';
  freshRun('tug', [KEY, 'fft_fans_tug']);
  const DAY = new Date().toISOString().slice(0, 10);
  let S = null;
  try { S = JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (e) { /* private mode */ }
  if (!S || S.reset !== cfg.reset) S = { reset: cfg.reset, round: 0, day: DAY, wins: {} };
  if (S.day !== DAY) { S.day = DAY; S.wins = {}; }
  const save = () => { try { localStorage.setItem(KEY, JSON.stringify(S)); } catch (e) { /* ignore */ } };
  const pairKey = (a, b) => [a, b].sort().join('-');
  const winsOf = (a, b) => { const w = S.wins[pairKey(a, b)] || {}; return [w[a] || 0, w[b] || 0]; };

  /* ---------- round state ---------- */
  let L = '', R = '', roundNo = S.round, phase = 'play', phaseT = 0, timeLeft = 0;
  let pos = 0, shown = 0, vel = 0;                         // pos < 0: knot on the left team's side
  let pulls = [0, 0], who = [new Map(), new Map()], lastPull = new Map(), recent = [];
  const swayA = Math.random() * 6, swayB = Math.random() * 6;
  let lastRealPull = -1e9, leadSide = 0, leadTalk = 0, nearSaid = false, lastSaid30 = false, nextCta = 0, simT = 0;

  /* ---------- sound ---------- */
  const sfx = new TugSfx();
  const soundBtn = $('sound-btn');
  const unlock = () => { sfx.resume(); soundBtn.hidden = true; };
  soundBtn.addEventListener('click', unlock); addEventListener('pointerdown', unlock); sfx.resume();
  setTimeout(() => { if (sfx.ok && !sfx.running) soundBtn.hidden = false; }, 1500);

  const banner = (text, small, red) => {
    const b = $('banner'), t = $('banner-text');
    t.className = 'banner-in' + (red ? ' red' : ''); t.innerHTML = `${text}<small>${small}</small>`;
    b.hidden = false; t.style.animation = 'none'; void t.offsetWidth; t.style.animation = '';
    clearTimeout(banner.timer); banner.timer = setTimeout(() => { b.hidden = true; }, 3100);
  };

  const sideOf = (code) => (code === L ? 0 : code === R ? 1 : -1);
  const topNames = (s, n = 3) => [...who[s].entries()].sort((a, b) => b[1] - a[1]).slice(0, n).map(([k]) => k);
  const inLater = (code) => PAIRS.some((p) => p.includes(code));
  const nextPair = () => PAIRS[(roundNo + 1) % PAIRS.length];

  /* ---------- live chat + LUNA ---------- */
  const chat = liveChat({
    game: 'tug', rows: 3,
    match: countryMatcher(C),
    badge: (code) => flagNode(code, 34),
    duck: (on) => sfx.duck(on),
    sfx: () => { sfx.tone({ freq: 784, type: 'sine', dur: 0.18, vol: 0.09 }); sfx.tone({ freq: 1175, type: 'sine', dur: 0.3, vol: 0.08, delay: 0.14 }); },
    onFan: (code, name) => pull(sideOf(code), name),
    emptyHint: 'Type one of the two countries — every comment is one pull!',
    hints: ['ONE COMMENT = ONE PULL — TYPE YOUR COUNTRY!', 'DROP A LIKE IF YOU ARE PULLING WITH US!', 'BRING YOUR FRIENDS — MORE PEOPLE, MORE POWER!'],
    idleLines: [
      ['EVERY COMMENT IS ONE PULL! TYPE YOUR COUNTRY AND HELP IT WIN!'],
      ['MORE PEOPLE = MORE POWER! SHARE THIS LIVE WITH FRIENDS FROM YOUR COUNTRY!'],
      ['WANT YOUR COUNTRY IN THE NEXT ROUND? TELL ME IN CHAT!'],
    ],
    welcome: (name, code, text) => {
      const hi = /salam|assalam|সালাম/i.test(text) ? 'WA ALAIKUM ASSALAM ' : 'WELCOME ';
      if (phase === 'play' && sideOf(code) >= 0) return [hi, { name }, '! YOUR PULL FOR ', { node: flagNode(code) }, NAME(code) + ' COUNTS — KEEP COMMENTING ' + NAME(code) + '!'];
      if (code && inLater(code)) return [hi, { name }, ' FROM ', { node: flagNode(code) }, NAME(code) + `! IT PLAYS IN A LATER ROUND — PULL FOR ${NAME(L)} OR ${NAME(R)} NOW!`];
      if (code) return [hi, { name }, ' FROM ', { node: flagNode(code) }, NAME(code) + `! THIS ROUND IS ${NAME(L)} VS ${NAME(R)} — PICK A SIDE!`];
      return [hi, { name }, `! TYPE ${NAME(L)} OR ${NAME(R)} IN CHAT TO PULL THE ROPE!`];
    },
    reply: (name, code, text) => {
      const s = sideOf(code);
      if (s >= 0 && phase === 'play') return [{ name }, ' PULLS FOR ', { node: flagNode(code) }, NAME(code) + `! ${pulls[s]} PULLS SO FAR — KEEP GOING!`];
      if (code && inLater(code)) return [{ node: flagNode(code) }, NAME(code) + ' PLAYS LATER, ', { name }, `! RIGHT NOW: ${NAME(L)} VS ${NAME(R)}!`];
      if (/who.*(win|lead)|winner|কে জিতবে/i.test(text)) return ['RIGHT NOW ', { node: flagNode(pos <= 0 ? L : R) }, NAME(pos <= 0 ? L : R) + ' IS AHEAD, ', { name }, '! COMMENT TO CHANGE IT!'];
      return null;
    },
    demoTexts: ['Bangladesh 🇧🇩', 'India!', 'bangladesh', 'INDIA INDIA', 'বাংলাদেশ', 'Go Pakistan', 'hello', 'Brasil 🔥', 'argentina', 'who will win?', 'USA', 'russia', 'Indonesia', 'malaysia', 'nice game'],
  });
  const host = (parts, prio = 2) => { if (chat.active) chat.say(parts, prio); };

  /* ---------- pulling ---------- */
  const pull = (s, name) => {
    if (s < 0 || phase !== 'play') return;
    const key = String(name || '').toUpperCase().slice(0, 14) || 'FRIEND';
    const prev = lastPull.get(key) || -1e9;
    if (simT - prev < COOLDOWN) return;           // the same viewer again too soon
    lastPull.set(key, simT);
    recent.push(simT);
    const busy = recent.filter((t) => simT - t < 10).length / 10;   // pulls per second right now
    const step = STEP * (cfg.power / 100) / Math.max(1, Math.sqrt(busy));
    vel += (s === 0 ? -1 : 1) * step * 2.12;   // the jerk adds up to exactly one step
    pulls[s]++; who[s].set(key, (who[s].get(key) || 0) + 1);
    lastRealPull = simT;
    sfx.pull(s);
    const team = $(s === 0 ? 'team-l' : 'team-r'); team.classList.remove('tug'); void team.offsetWidth; team.classList.add('tug');
    const fb = $(s === 0 ? 'side-l' : 'side-r').querySelector('.flagbox'); fb.classList.add('hit'); setTimeout(() => fb.classList.remove('hit'), 150);
  };

  /* ---------- rounds ---------- */
  const startRound = () => {
    [L, R] = PAIRS[roundNo % PAIRS.length];
    if (Math.random() < 0.5 && roundNo >= PAIRS.length) [L, R] = [R, L];
    phase = 'play'; phaseT = 0; timeLeft = cfg.round; pos = 0; shown = 0; vel = 0;
    pulls = [0, 0]; who = [new Map(), new Map()]; lastPull = new Map(); recent = [];
    leadSide = 0; nearSaid = false; lastSaid30 = false; nextCta = simT + 25;
    $('flag-l').style.cssText = flagCss(L, 320); $('flag-r').style.cssText = flagCss(R, 320);
    for (const [id, code] of [['name-l', L], ['name-r', R]]) { // long names: smaller letters until they fit
      const n = $(id); n.textContent = NAME(code); n.style.fontSize = '';
      for (let size = 58; n.scrollWidth > n.clientWidth && size > 30; size -= 2) n.style.fontSize = size + 'px';
    }
    $('team-l').innerHTML = [0, 1, 2].map(() => `<div class="pl">${flagHTML(L, 124)}</div>`).join('');
    $('team-r').innerHTML = [0, 1, 2].map(() => `<div class="pl">${flagHTML(R, 124)}</div>`).join('');
    const nx = nextPair();
    $('next').innerHTML = `NEXT ROUND: ${flagHTML(nx[0], 48)}${NAME(nx[0])} VS ${NAME(nx[1])}${flagHTML(nx[1], 48)}`;
    $('sub').textContent = `ROUND ${roundNo + 1} · COMMENT YOUR COUNTRY TO PULL THE ROPE`;
    $('win').hidden = true;
    banner(`${NAME(L)} VS ${NAME(R)}`, `ROUND ${roundNo + 1} · ONE COMMENT = ONE PULL`);
    sfx.horn();
    host([`ROUND ${roundNo + 1}! `, { node: flagNode(L) }, NAME(L) + ' VS ', { node: flagNode(R) }, NAME(R) + `! TYPE ${NAME(L)} OR ${NAME(R)} IN CHAT — EVERY COMMENT IS ONE PULL!`], 3);
    updateUI();
  };

  const finish = (s) => {   // s: 0 left wins, 1 right wins, -1 draw
    phase = 'won'; phaseT = 0; $('banner').hidden = true;
    const W = s === 0 ? L : s === 1 ? R : '';
    if (W) {
      const k = pairKey(L, R); S.wins[k] = S.wins[k] || {}; S.wins[k][W] = (S.wins[k][W] || 0) + 1;
    }
    roundNo++; S.round = roundNo; save();
    const heroes = s >= 0 ? topNames(s) : [];
    $('win-flag').style.cssText = W ? flagCss(W, 300) : 'display:none';
    $('win-name').textContent = W ? NAME(W) : 'DRAW!';
    $('win').querySelector('.t').textContent = W ? 'WINNER' : 'NOBODY MOVED THE ROPE';
    const [a, b] = winsOf(L, R);
    $('win-sub').textContent = `ROUND ${roundNo} · TODAY ${NAME(L)} ${a} – ${b} ${NAME(R)}`;
    $('win-heroes').innerHTML = heroes.length ? `🏅 STRONGEST PULLERS<b></b>` : '';
    if (heroes.length) $('win-heroes').querySelector('b').textContent = heroes.join(' · ');
    $('win').hidden = false; sfx.win();
    const nx = nextPair();
    if (W) {
      const thanks = heroes.length ? ['THANK YOU ', ...heroes.flatMap((h, i) => [{ name: h }, i < heroes.length - 1 ? ', ' : '']), '! '] : [];
      host([{ node: flagNode(W) }, NAME(W) + ` WINS ROUND ${roundNo}! `, ...thanks, `NEXT: ${NAME(nx[0])} VS ${NAME(nx[1])} — GET READY TO PULL!`], 3);
    } else host([`IT IS A DRAW! NEXT: ${NAME(nx[0])} VS ${NAME(nx[1])} — COMMENT TO PULL!`], 3);
    updateUI();
  };

  /* ---------- LUNA keeps the chat going ---------- */
  const cheer = () => {
    const behind = pos < 0 ? 1 : 0, ahead = 1 - behind, B = behind ? R : L, A = ahead ? R : L;
    const lines = [
      [`${NAME(B)} FANS, WHERE ARE YOU? COMMENT `, { node: flagNode(B) }, NAME(B) + ' NOW OR YOU LOSE THE ROPE!'],
      [`ONE COMMENT = ONE PULL! TYPE ${NAME(L)} OR ${NAME(R)} RIGHT NOW!`],
      ['INVITE YOUR FRIENDS FROM ', { node: flagNode(B) }, NAME(B) + '! MORE PEOPLE MEANS MORE PULLING POWER!'],
      ['SHARE THIS LIVE — THE COUNTRY WITH MORE PEOPLE IN CHAT WINS!'],
    ];
    const top = topNames(ahead, 1)[0];
    if (top) lines.push(['THE STRONGEST PULLER FOR ', { node: flagNode(A) }, NAME(A) + ' IS ', { name: top }, '! CAN ANYONE BEAT THEM?']);
    if (Math.abs(pos) < 0.12) lines.push(['IT IS NECK AND NECK! THE NEXT COMMENT COULD DECIDE IT — TYPE YOUR COUNTRY!']);
    host(pick(lines), 1);
  };

  /* ---------- screen ---------- */
  const updateUI = () => {
    $('clock').textContent = fmt(phase === 'play' ? timeLeft : 0);
    $('clock').classList.toggle('low', phase === 'play' && timeLeft <= 15);
    $('pulls-l').textContent = pulls[0]; $('pulls-r').textContent = pulls[1];
    const pl = Math.round(clamp(50 - shown * 50, 0, 100));
    $('meter-l').style.width = pl + '%'; $('pct-l').textContent = pl + '%'; $('pct-r').textContent = (100 - pl) + '%';
    const tl = topNames(0), tr = topNames(1);
    $('top-l').textContent = tl.length ? tl.join(' · ') : 'BE THE FIRST!';
    $('top-r').textContent = tr.length ? tr.join(' · ') : 'BE THE FIRST!';
    const [a, b] = winsOf(L, R);
    $('score').innerHTML = `TODAY ${flagHTML(L, 30)} ${a} – ${b} ${flagHTML(R, 30)}`;
  };

  const step = (dt) => {
    simT += dt;
    if (phase !== 'play') {
      phaseT += dt;
      if (phaseT > WIN_SECS) startRound();
      return;
    }
    timeLeft -= dt;
    // no comments for a while: the crowd sways the rope a little so the stream never stands still
    if (simT - lastRealPull > 8) {
      const sway = 0.3 * Math.sin(simT * 0.21 + swayA) + 0.12 * Math.sin(simT * 0.53 + swayB);
      pos += (sway - pos) * 0.35 * dt;
    }
    pos += vel * dt; vel *= Math.pow(0.12, dt);   // a pull is a quick jerk that settles
    if (pos <= -1) { pos = -1; finish(0); return; }
    if (pos >= 1) { pos = 1; finish(1); return; }
    if (timeLeft <= 0) {
      const s = Math.abs(pos) > 0.02 ? (pos < 0 ? 0 : 1) : pulls[0] !== pulls[1] ? (pulls[0] > pulls[1] ? 0 : 1) : -1;
      finish(s); return;
    }
    // what LUNA says during the round
    const side = pos < -0.15 ? -1 : pos > 0.15 ? 1 : 0;
    if (side && side !== leadSide && simT - leadTalk > 20) {
      const T = side < 0 ? L : R;
      if (leadSide) host([{ node: flagNode(T) }, NAME(T) + ' TAKES THE LEAD! THE ROPE IS MOVING — KEEP PULLING!'], 2);
      leadTalk = simT;
    }
    if (side) leadSide = side;
    if (!nearSaid && Math.abs(pos) > 0.75) {
      nearSaid = true;
      const A = pos < 0 ? L : R, B = pos < 0 ? R : L;
      banner(`${NAME(A)} ALMOST WINS!`, `${NAME(B)} FANS — COMMENT NOW!`, true); sfx.alarm?.();
      host([{ node: flagNode(A) }, NAME(A) + ' IS ALMOST WINNING! ', { node: flagNode(B) }, NAME(B) + ' FANS, PULL HARDER — COMMENT NOW!'], 3);
    }
    if (Math.abs(pos) < 0.5) nearSaid = false;
    if (!lastSaid30 && timeLeft <= 30) { lastSaid30 = true; host(['ONLY 30 SECONDS LEFT! EVERY COMMENT COUNTS — TYPE YOUR COUNTRY NOW!'], 2); }
    if (timeLeft <= 10 && Math.ceil(timeLeft) !== Math.ceil(timeLeft + dt)) sfx.tick();
    if (simT > nextCta) { nextCta = simT + 35 + Math.random() * 15; cheer(); }
  };

  const draw = () => {
    shown += (pos - shown) * 0.25;
    $('rope').style.transform = `translateX(${(shown * OFFSET).toFixed(1)}px)`;
  };

  /* ---------- settings from the admin panel ---------- */
  setInterval(async () => {
    const n = await fetchConfig();
    if (!n) return;
    if (n.reset !== cfg.reset) { try { localStorage.removeItem(KEY); } catch (e) { /* ignore */ } location.reload(); return; }
    if (n.rev !== cfg.rev && phase !== 'play') location.reload();
    else if (n.rev !== cfg.rev) cfg.pendingReload = true;
  }, 20000);

  /* ---------- loop ---------- */
  startRound();
  let last = performance.now(), lastUI = 0;
  const frame = (now) => {
    const dt = Math.min(0.1, (now - last) / 1000) * FAST * pace; last = now;
    const sub = Math.max(1, Math.ceil(dt / 0.05));
    for (let i = 0; i < sub; i++) step(dt / sub);
    if (cfg.pendingReload && phase !== 'play') location.reload();
    draw();
    if (now - lastUI > 250) { updateUI(); lastUI = now; }
    requestAnimationFrame(frame);
  };
  requestAnimationFrame(frame);
  autoUpdate('tug', save);
  goLive({ left: 40, top: 820, size: 230, duck: (on) => sfx.duck(on) });   // the owner joins the live from a phone (Admin → Join Live)   // after a deploy: reload with the game where it was (no Restart needed)
  window.__tug = { cfg, pos: () => pos, phase: () => phase, round: () => roundNo, pulls: () => pulls, pull, chat };
}

main();
