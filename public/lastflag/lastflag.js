// FFTEdit · Last Flag Standing — the neon Flag Arena. Runs by itself, made for 24/7 vertical live streams.
// 50 flag balls bounce in a six-sided arena; a bearing (a ring with three bars) turns around the black hole
// and pushes them around. Every few seconds the black hole draws the flag nearest to it: that country is out. The live chat can save countries:
// enough chats give a SHIELD (survives one draw), a country that is out can be REVIVED, a new
// subscriber (or a viewer who shares and types "shared" + country) gives a shield — or brings the country back if it is out; a Super Chat gives both. Likes and viewers unlock goals for everyone.
// The last flag left is the round champion, then a new round starts (about an hour a round).
import { Sfx, holdScreen, PACE, reporter, freshRun } from '/game/audio.js?v=13';
import { liveChat, countryMatcher } from '/game/chat.js?v=26';
import { goLive } from '/game/golive.js?v=2';
import { Music, SongPlayer } from '/game/music.js?v=2';
import { autoUpdate } from '/game/update.js?v=1';

const $ = (id) => document.getElementById(id);
const QS = new URLSearchParams(location.search);
const clamp = (v, a, b) => Math.max(a, Math.min(b, v));
const FAST = clamp(Number(QS.get('fast')) || 1, 1, 200);   // testing only
const pick = (a) => a[Math.floor(Math.random() * a.length)];
const shuffle = (a) => { for (let i = a.length - 1; i > 0; i--) { const j = Math.floor(Math.random() * (i + 1)); [a[i], a[j]] = [a[j], a[i]]; } return a; };

const DEF = { gap: 5, music: true, music_style: 'upbeat', music_vol: 35, title: 'LAST FLAG STANDING', count: 99, minutes: 55, shield: 10, revive: 20, revives: 5, likes: 50, viewers: 100, events: true, rev: '', reset: '' };
// the world's biggest countries, biggest first: the first BIG_ALWAYS are in every round, the rest fill up the free places
const BIG = ['cn', 'in', 'us', 'id', 'pk', 'ng', 'br', 'bd', 'ru', 'mx', 'jp', 'et', 'ph', 'eg', 'vn', 'cd', 'ir', 'tr', 'de', 'th',
  'gb', 'fr', 'it', 'za', 'tz', 'ke', 'kr', 'co', 'mm', 'es', 'ar', 'dz', 'sd', 'ug', 'iq', 'ca', 'pl', 'ma', 'af', 'ua',
  'sa', 'uz', 'pe', 'my', 'ao', 'gh', 'mz', 'ye', 'np', 've', 'au', 'cm', 'ci', 'ne', 'lk', 'kz', 'cl', 'nl', 'ro', 'sy'];
const BIG_ALWAYS = 24;
// countries that were always in a round before (kept as a last fill)
const CORE = ['bd', 'in', 'pk', 'us', 'br', 'ar', 'sa', 'tr', 'id', 'ng', 'eg', 'ma', 'my', 'np', 'ph', 'mx', 'gb', 'fr', 'de', 'ru',
  'cn', 'jp', 'ca', 'es', 'it', 'dz', 'iq', 'af', 'lk', 'vn', 'th', 'kr'];
// flags with holy writing are shown as a plain colour ball (like in Territory War)
const PLAIN = { sa: ['#006c35', 'KSA'], af: ['#007a36', 'AFG'], iq: ['#ce1126', 'IRQ'] };

/* ---------------------------------------------------------------- arena geometry (canvas units) */
const W = 1080, H = 800;                 // canvas size (stage y 612 … 1412)
const CX = 540, CY = 400;                // arena centre
const HX = 508, HY = 386;                // the six-sided wall: corners left/right at ±HX, top/bottom edges at ±HY
const HEX = [[CX - HX, CY], [CX - HX / 2, CY - HY], [CX + HX / 2, CY - HY], [CX + HX, CY], [CX + HX / 2, CY + HY], [CX - HX / 2, CY + HY]];
const EDGES = HEX.map((p, i) => { const q = HEX[(i + 1) % 6], ex = q[0] - p[0], ey = q[1] - p[1], L = Math.hypot(ex, ey); return { x: p[0], y: p[1], nx: -ey / L, ny: ex / L }; });   // inward normals
const RW = 380;                          // (roughly how far the wall is, for spawning)
const HOLE = 54;                         // black centre of the hole when fully open (about a flag)
const TOUCH = 14;                        // how far (px) a flag must reach into the black hole to be caught
const RING = 132;                        // the bearing ring around the hole (flags roll over it)
const OPEN = 3.0, OPEN_IN = 0.35;      // the iris opens (0.35 s), stays open up to 3 s, closes (0.35 s) — or closes at once when it takes a flag
const openness = (left) => { const t = OPEN - left; return t < OPEN_IN ? t / OPEN_IN : left < OPEN_IN ? left / OPEN_IN : 1; };
const BARS = 3, BAR_W = 13, BAR_IN = RING + 8, BAR_OUT = 352, SPIN = 0.32;   // the bearing's three bars and how fast it turns (rad/s)
const FIXED = 1 / 120;
const DRAW_SECS = 0.9;                   // how long a flag takes to fall in
const WIN_SECS = 16;

async function fetchConfig() {
  try { const r = await fetch('/api/lastflag.php', { cache: 'no-store' }); if (r.ok) return await r.json(); } catch (e) { /* offline */ }
  return null;
}
function fitStage() {
  const vw = document.documentElement.clientWidth || innerWidth, vh = document.documentElement.clientHeight || innerHeight;
  $('stage').style.transform = `translate(-50%, -50%) scale(${Math.min(vw / 1080, vh / 1920)})`;
}

class ArenaSfx extends Sfx {
  knock() { if (!this.running || !this.limit('knock', 420)) return; this.tone({ freq: 300 + Math.random() * 90, type: 'sine', dur: 0.06, vol: 0.09 }); }
  tick() { if (this.running) this.tone({ freq: 1250, type: 'square', dur: 0.05, vol: 0.11 }); }
  draw() { if (!this.running) return; this.tone({ freq: 520, to: 70, type: 'sawtooth', dur: 1.1, vol: 0.15 }); this.tone({ freq: 90, type: 'sine', dur: 0.7, vol: 0.26, delay: 0.5 }); }
  shield() { if (!this.running) return; this.tone({ freq: 660, to: 1320, type: 'triangle', dur: 0.35, vol: 0.18 }); }
  gone() { this.impact(); }
  // A short punchy "boom-crash" like the big arena channels: a burst of noise that quickly darkens
  // and fades (about a third of a second) on top of a deep thump.
  impact() {
    if (!this.running) return;
    const c = this.ctx, t = c.currentTime;
    if (!this.noise) {
      const n = c.sampleRate * 0.6, buf = c.createBuffer(1, n, c.sampleRate), d = buf.getChannelData(0);
      for (let i = 0; i < n; i++) d[i] = Math.random() * 2 - 1;
      this.noise = buf;
    }
    const src = c.createBufferSource(); src.buffer = this.noise;
    const lp = c.createBiquadFilter(); lp.type = 'lowpass'; lp.Q.value = 0.7;
    lp.frequency.setValueAtTime(7000, t); lp.frequency.exponentialRampToValueAtTime(350, t + 0.35);
    const g = c.createGain(); g.gain.setValueAtTime(0.0001, t); g.gain.exponentialRampToValueAtTime(0.55, t + 0.006); g.gain.exponentialRampToValueAtTime(0.0001, t + 0.38);
    src.connect(lp); lp.connect(g); g.connect(this.master); src.start(t); src.stop(t + 0.42);
    this.tone({ freq: 140, to: 38, type: 'sine', dur: 0.5, vol: 0.6, attack: 0.004 });   // thump
    this.tone({ freq: 2200, to: 900, type: 'triangle', dur: 0.06, vol: 0.12 });            // click on top
  }
  revive() { if (!this.running) return; [0, 0.12, 0.24].forEach((d, i) => this.tone({ freq: 523 * (1 + i * 0.25), type: 'sine', dur: 0.25, vol: 0.20, delay: d })); }
}

async function main() {
  fitStage(); addEventListener('resize', fitStage);
  const [remote, atlasInfo] = await Promise.all([fetchConfig(), fetch('/flags/flags.json?v=2').then((r) => r.json())]);
  const cfg = { ...DEF, ...(remote || {}) };
  let popular = Array.isArray(cfg.popular) ? cfg.popular : [];   // [[code, people]] from the panel (last 7 days)
  if (cfg.autoplay === false) { holdScreen('/api/lastflag.php', cfg.rev); return; }
  const pace = PACE[cfg.pace] || 1;
  const demo = QS.has('chatdemo');
  const atlas = new Image(); atlas.src = '/flags/flags.png?v=2';
  await atlas.decode().catch(() => {});

  const C = atlasInfo.countries, FW = atlasInfo.w, FH = atlasInfo.h, COLS_A = atlasInfo.cols, ROWS_A = Math.ceil(C.length / COLS_A);
  const byCode = new Map(C.map((c, i) => [c.code, i]));
  const NAME = (code) => C[byCode.get(code)].name.toUpperCase().replace('UNITED ARAB EMIRATES', 'UAE').replace('UNITED KINGDOM', 'UK').replace('UNITED STATES', 'USA').replace('CENTRAL AFRICAN REP.', 'C. AFRICA');
  const flagCss = (code, w) => { const i = byCode.get(code), h = w * 0.75; return `width:${w}px;height:${h}px;background-size:${COLS_A * w}px ${ROWS_A * h}px;background-position:${-(i % COLS_A) * w}px ${-Math.floor(i / COLS_A) * h}px`; };
  const flagHTML = (code, w) => `<span class="flag" style="${flagCss(code, w)}"></span>`;
  const flagNode = (code, w = 40) => { const d = document.createElement('span'); d.className = 'flag'; d.style.cssText = flagCss(code, w); return d; };
  const COUNT = clamp(Math.round(cfg.count) || 99, 16, 100);
  const R = COUNT > 72 ? 31 : COUNT > 48 ? 36 : 41;      // ball radius
  const SPEED = 190;
  const CYCLE = clamp(Number(cfg.gap) || 5, 2, 30);   // seconds the hole stays closed between openings

  { // the title in two lines: the last word glows cyan
    const w = cfg.title.trim().split(/\s+/), last = w.length > 1 ? w.pop() : '';
    $('title').innerHTML = ''; $('title').append(w.join(' ')); if (last) { $('title').append(document.createElement('br')); const em = document.createElement('em'); em.textContent = last; $('title').append(em); }
  }
  $('how').querySelector('.s').innerHTML = `🛡 <b>SHIELD</b> = ${cfg.shield} chats`;
  $('how').querySelector('.r').innerHTML = `♻ <b>REVIVE</b> = ${cfg.revive} chats <small>(if out)</small>`;
  $('rule-s').textContent = `${cfg.shield} chats = SHIELD`;
  $('rule-r').textContent = `Out? ${cfg.revive} chats = BACK IN`;
  $('rules').innerHTML = `<div style="border-color:#33f0ff;color:#9df7ff">💬 ${cfg.shield} = 🛡</div><div style="border-color:#ff2bd6;color:#ffb3ef">💬 ${cfg.revive} = ♻</div>`
    + `<div style="border-color:#ffd23a;color:#ffe680">🔔📢 = 🛡/♻</div><div style="border-color:#7dff8a;color:#c4ffca">⭐ SUPER = 🛡+♻</div>`;

  /* ---------------------------------------------------------------- saved state (a reload continues the round) */
  const KEY = 'fft_lastflag';
  freshRun('lastflag', [KEY, 'fft_fans_lastflag']);
  let S = null;
  try { S = JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (e) { /* private mode */ }
  if (!S || S.reset !== cfg.reset || S.count !== COUNT) S = { reset: cfg.reset, count: COUNT, round: 0, want: {} };
  const save = () => {
    try {
      S.live = phase === 'play' ? { teams, alive: [...alive], out, shield: [...shield.entries()], revived: [...revived], revLeft, toNext, power: [...power.entries()], prog: [...prog.entries()], got: [...got],
        fans: [...fans.entries()].map(([k, m]) => [k, [...m.entries()].slice(0, 20)]), hold: Math.max(0, holdUntil - simT), likeGoal, viewGoal, t: simT } : null;
      localStorage.setItem(KEY, JSON.stringify(S));
    } catch (e) { /* ignore */ }
  };

  /* ---------------------------------------------------------------- round state */
  const NOLIMIT = !(Number(cfg.revives) > 0);   // 0 = revive as often as the chat wants
  let teams = [], alive = new Set(), out = [], balls = [], shield = new Map(), revived = new Set(), revLeft = cfg.revives;
  let power = new Map();                 // country -> chats this round (CHAT POWER)
  let prog = new Map();                  // country -> chats towards the next shield / revive
  let heat = new Map();                  // country -> { h, t }: how much it chats right now (fades in about half a minute)
  const support = { order: [], page: 0, at: 0, flip: false };
  const SUPPORT_SECS = 4, SUPPORT_MAX = 15;
  let got = new Set();                   // countries that already got a shield / revive this round (their bar turns red)
  let fans = new Map();                  // country -> Map(name -> chats)
  let phase = 'play', phaseT = 0, simT = 0, toNext = 10, openLeft = 0, falling = [], doubleNext = false, holdUntil = 0, speedUntil = 0;
  let likeGoal = 0, viewGoal = 0, lastStats = null, nextEvent = 0, barA = 0, final10 = false, finalSaid = false;
  const tags = new Map();                // country -> { name, until, kind }
  let feed = [];                         // the last flags pulled in / revived, shown under the arena

  const ball = (code, x, y) => { const a = Math.random() * Math.PI * 2; return { code, x, y, vx: Math.cos(a) * SPEED, vy: Math.sin(a) * SPEED, hot: 0 }; };
  const inHex = (x, y, m) => EDGES.every((e) => (x - e.x) * e.nx + (y - e.y) * e.ny > m);
  const freeSpot = () => {
    for (let t = 0; t < 600; t++) {
      const x = CX - HX + Math.random() * 2 * HX, y = CY - HY + Math.random() * 2 * HY;
      if (Math.hypot(x - CX, y - CY) < RING + R + 6 || !inHex(x, y, R + 8)) continue;
      if (balls.every((b) => Math.hypot(b.x - x, b.y - y) > 2 * R + 4)) return [x, y];
    }
    const a = Math.random() * Math.PI * 2; return [CX + Math.cos(a) * (RING + R + 40), CY + Math.sin(a) * (RING + R + 40)];
  };

  // Who is in the next round: the biggest countries, the countries asked for in chat this round, the countries whose
  // people chatted here in the last 7 days (most people first), then the next biggest. Countries nobody types are left out.
  const chooseTeams = () => {
    const want = Object.entries(S.want || {}).sort((a, b) => b[1] - a[1]).map(([k]) => k);
    const pop = popular.map((x) => x[0]);
    const list = [];
    const add = (codes) => { for (const c of codes) { if (list.length >= COUNT) break; if (byCode.has(c) && !list.includes(c)) list.push(c); } };
    add(BIG.slice(0, BIG_ALWAYS)); add(want); add(pop); add(BIG); add(CORE);
    add(shuffle(C.map((x) => x.code)));   // only if still not full
    S.want = {};
    return list;
  };

  const newRound = () => {
    S.round++;
    // a shield is never lost unused: shields still on at the end of a round go with their country to its next round
    S.carry = Object.assign(S.carry || {}, Object.fromEntries(shield));
    teams = chooseTeams(); alive = new Set(teams); out = []; balls = []; shield = new Map(); revived = new Set(); revLeft = cfg.revives;
    for (const c of teams) if (S.carry[c] !== undefined) { shield.set(c, S.carry[c]); delete S.carry[c]; }
    power = new Map(); prog = new Map(); got = new Set(); heat = new Map(); support.order = []; support.page = 0; fans = new Map(); tags.clear(); feed = [];
    phase = 'play'; phaseT = 0; simT = 0; toNext = 8; openLeft = 0; falling = []; doubleNext = false; holdUntil = 0; speedUntil = 0; final10 = false; finalSaid = false;
    likeGoal = 0; viewGoal = cfg.viewers; nextEvent = 240 + Math.random() * 120;
    for (const c of shuffle(teams.slice())) { const [x, y] = freeSpot(); balls.push(ball(c, x, y)); }
    $('win').hidden = true;
    banner(`ROUND ${S.round}`, `${COUNT} COUNTRIES · TYPE YOURS TO SAVE IT!`);
    host([`ROUND ${S.round} STARTS! ${COUNT} COUNTRIES IN THE ARENA. TYPE YOUR COUNTRY IN CHAT — ${cfg.shield} CHATS GIVE IT A SHIELD!`], 3);
    save(); updateUI();
  };

  const resume = (L) => {
    teams = L.teams; alive = new Set(L.alive); out = L.out; shield = new Map(L.shield); revived = new Set(L.revived); revLeft = L.revLeft;
    toNext = L.toNext; power = new Map(L.power); prog = new Map(L.prog || []); got = new Set(L.got || []); fans = new Map(L.fans.map(([k, a]) => [k, new Map(a)])); holdUntil = L.hold; likeGoal = L.likeGoal; viewGoal = L.viewGoal;
    simT = 0; nextEvent = 120 + Math.random() * 120; final10 = alive.size <= 10; finalSaid = alive.size <= 2;
    balls = []; for (const c of alive) { const [x, y] = freeSpot(); balls.push(ball(c, x, y)); }
  };

  /* ---------------------------------------------------------------- sound + screen helpers */
  const sfx = new ArenaSfx();
  const soundBtn = $('sound-btn');
  const unlock = () => { sfx.resume(); soundBtn.hidden = true; };
  soundBtn.addEventListener('click', unlock); addEventListener('pointerdown', unlock); sfx.resume();
  setTimeout(() => { if (sfx.ok && !sfx.running) soundBtn.hidden = false; }, 1500);
  const report = () => {};   // (scores on the website are only kept for Flag Arena)
  // background music (our own, made in code — no copyright claims); it starts when sound is allowed
  // (NCS songs, when the panel has them and this page is the live stream; otherwise our own music)
  // The music has its own volume knob straight to the speakers (not through the effects'
  // compressor, so it can't push the game sounds and LUNA down). While LUNA talks it drops low.
  const musicBus = sfx.ok ? sfx.ctx.createGain() : null;
  if (musicBus) musicBus.connect(sfx.ctx.destination);
  const duckAll = (on) => { sfx.duck(on); if (musicBus) musicBus.gain.setTargetAtTime(on ? 0.25 : 1, sfx.ctx.currentTime, on ? 0.15 : 0.6); };
  if (cfg.music !== false && sfx.ok && !QS.has('nomusic')) {
    const vol = Math.max(0, Math.min(100, Number(cfg.music_vol ?? 35))) / 100, sid = QS.get('stream'), sg = QS.get('sig');
    const own = () => new Music(sfx.ctx, musicBus, { style: cfg.music_style, volume: vol * 0.6 }).start();
    if (cfg.music_src === 'songs' && sid && sg) {
      const q = `stream=${encodeURIComponent(sid)}&sig=${encodeURIComponent(sg)}`;
      fetch('/api/music.php?' + q, { cache: 'no-store' }).then((r) => (r.ok ? r.json() : [])).then((songs) => {
        if (!Array.isArray(songs) || !songs.length) return own();
        new SongPlayer(sfx.ctx, musicBus, { songs, volume: vol * 0.5, url: (f) => `/api/music.php?${q}&f=${encodeURIComponent(f)}` }).start();
      }).catch(own);
    } else own();
  }

  const banner = (text, small, cls = '', box = '') => {
    const b = $('banner'), t = $('banner-text');
    t.className = 'banner-in ' + cls; t.innerHTML = `${box ? `<span class="box">${box}</span>` : ''}${text}<small>${small}</small>`;
    b.hidden = false; t.style.animation = 'none'; void t.offsetWidth; t.style.animation = '';
    clearTimeout(banner.timer); banner.timer = setTimeout(() => { b.hidden = true; }, 3500);
  };
  const popAt = (cls, code, label, name, x, y) => {
    const p = document.createElement('div'); p.className = 'pop ' + cls;
    p.style.left = clamp(x - 150, 20, W - 340) + 'px'; p.style.top = clamp(y - 40, 10, H - 90) + 'px';
    p.innerHTML = `${flagHTML(code, 60)}<div><small>${label}</small><b></b></div>`;
    p.querySelector('b').textContent = name;
    $('pops').append(p); setTimeout(() => p.remove(), 3300);
  };

  /* ---------------------------------------------------------------- chat powers */
  const addFan = (code, name) => {
    if (!fans.has(code)) fans.set(code, new Map());
    const m = fans.get(code); m.set(name, (m.get(name) || 0) + 1);
  };
  const giveShield = (code, by, why) => {
    if (!alive.has(code) || shield.has(code)) return false;
    shield.set(code, by || '');
    const b = balls.find((x) => x.code === code);
    if (b) popAt('shd', code, why || 'SHIELD ON', NAME(code), b.x, b.y - 60);
    tags.set(code, { name: by || '', until: simT + 12, kind: 'shield' });
    sfx.shield();
    host([{ node: flagNode(code) }, NAME(code) + ' GOT A SHIELD', ...(by ? [' THANKS TO ', { name: by }] : []), '! IT SURVIVES THE NEXT DRAW!'], 2);
    return true;
  };
  const revive = (code, by, free) => {
    if (alive.has(code) || alive.size <= 2 || phase !== 'play' || (free && revived.has(code)) || (!free && !NOLIMIT && revLeft <= 0)) return false;
    if (!free && !NOLIMIT) revLeft--;
    revived.add(code); alive.add(code); out = out.filter((c) => c !== code); feed.push([code, 'r']);
    const [x, y] = freeSpot(); const b = ball(code, x, y); b.hot = 4; balls.push(b);
    popAt('rev', code, 'REVIVED!', NAME(code), x, y - 60);
    tags.set(code, { name: by || '', until: simT + 12, kind: 'rev' });
    banner(`${NAME(code)} IS BACK!`, by ? `REVIVED BY ${by} & FRIENDS` : 'REVIVED!', 'gold', '♻️');
    sfx.revive();
    host([{ node: flagNode(code) }, NAME(code) + ' IS BACK IN THE ARENA! THE CHAT REVIVED IT', ...(by ? [' — THANK YOU ', { name: by }] : []), '!'], 3);
    save(); updateUI();
    return true;
  };
  // tell the panel which countries the chat names (once a minute): chats and different people per country
  const fanTally = new Map(), fanSeen = new Set();
  let fanDay = new Date().toDateString();
  const tally = (code, author) => {
    if (new Date().toDateString() !== fanDay) { fanDay = new Date().toDateString(); fanSeen.clear(); }
    const t = fanTally.get(code) || [0, 0]; t[0]++;
    const k = code + '|' + (author || '');
    if (author && !fanSeen.has(k)) { fanSeen.add(k); t[1]++; }
    fanTally.set(code, t);
  };
  if (QS.get('stream') && QS.get('sig')) setInterval(() => {
    if (!fanTally.size) return;
    const c = Object.fromEntries(fanTally); fanTally.clear();
    fetch('/api/fans.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, keepalive: true,
      body: JSON.stringify({ stream: Number(QS.get('stream')), sig: QS.get('sig'), game: 'lastflag', c }) }).catch(() => {});
  }, 60000);
  const onFan = (code, name, author) => {
    tally(code, author);
    // a country that is not in this round joins the next one (also when it is asked for between rounds)
    const want = () => { S.want[code] = (S.want[code] || 0) + 1; };
    if (!teams.includes(code)) { want(); return; }
    if (phase !== 'play') { if (!alive.has(code)) want(); return; }
    power.set(code, (power.get(code) || 0) + 1);
    { const now = performance.now() / 1000, h = heat.get(code); heat.set(code, { h: (h ? h.h * Math.exp(-(now - h.t) / 30) : 0) + 1, t: now }); }
    addFan(code, name);
    prog.set(code, (prog.get(code) || 0) + 1);
    const b = balls.find((x) => x.code === code);
    if (b) b.hot = 3;
    tags.set(code, { name, until: simT + 6, kind: 'fan' });
    if (alive.has(code) && !shield.has(code) && prog.get(code) >= cfg.shield) { prog.set(code, 0); got.add(code); giveShield(code, name, 'SHIELD ON'); }
    else if (!alive.has(code) && prog.get(code) >= cfg.revive) { if (revive(code, name)) { prog.set(code, 0); got.add(code); } }
  };
  // subscribe / share gift: in the arena = a shield, out = it comes back
  const SUB_RX = /\bsubscribed\b|\bsubscriber?\s*(done|korsi|korlam|korechi|dilam|disi|kiya|kar\s*diya)\b|\bsub(bed|\s*done)\b|\bi\s*(just\s*)?subscribe\b|সাবস্ক্রাইব|सब्सक्राइब/i;
  const SHARE_RX = /\bshar(e|ed|ing|ed it)\b|\bshr\b|শেয়ার|শেয়ার|शेयर/i;
  const shareAt = new Map(), giftWait = new Map(), subDone = new Set();
  const cleanName = (n) => String(n || '').toUpperCase().slice(0, 14);
  const gift = (code, by, why) => {
    if (!teams.includes(code) || phase !== 'play') return false;
    if (alive.has(code)) return giveShield(code, by, `FREE SHIELD · ${why}`);
    return revive(code, by);
  };
  const statusLine = (code) => {
    if (!teams.includes(code)) return `IT JOINS THE NEXT ROUND — KEEP COMMENTING ${NAME(code)}!`;
    if (!alive.has(code)) {
      if ((!NOLIMIT && revLeft <= 0) || alive.size <= 2) return 'NO REVIVES LEFT THIS ROUND — CHEER FOR IT NEXT ROUND!';
      return `IT IS OUT! ${Math.max(0, cfg.revive - (prog.get(code) || 0))} MORE CHATS AND IT COMES BACK!`;
    }
    if (shield.has(code)) return 'IT HAS A SHIELD — IF THE HOLE TAKES IT, THE SHIELD BREAKS AND IT STAYS IN!';
    return `${Math.max(0, cfg.shield - (prog.get(code) || 0))} MORE CHATS FOR A SHIELD!`;
  };

  const isGreeting = (s) => /\b(hi|hii+|hello|hey|salam|salaam|assalam\w*|asalam\w*)\b|হাই|হ্যালো|সালাম|আসসালামু/i.test(s);
  const isSalam = (s) => /salam|salaam|assalam|asalam|সালাম|আসসালামু/i.test(s);
  const chat = liveChat({
    game: 'lastflag', rows: 4, alwaysOn: true,
    aiInfo: (code) => `Game: LAST FLAG STANDING — ${teams.length} country flags bounce in a six-sided neon arena; a bearing with three bars turns around the black hole and pushes them; the black hole in the middle opens every ${cfg.gap} seconds and a flag that falls in is OUT; the last flag left wins the round, then a new round starts. `
      + `Now: ${alive.size} of ${teams.length} left. ` + (code ? `The viewer's country ${NAME(code)}: ${statusLine(code)} ` : '')
      + `Rules: type your country in chat to boost it; ${cfg.shield} chats = a SHIELD (it stays on until the hole would take the flag; then the shield breaks and the flag stays in); ${cfg.revive} chats bring an OUT country back${NOLIMIT ? ' (no limit)' : ''}; subscribe, or share the live and type "shared" + your country = a SHIELD (or it comes back if it is OUT); Super Chat = shield and revive; countries not in this round join the next round.`,
    match: countryMatcher(C),
    badge: (code) => flagNode(code, 32),
    duck: duckAll,
    sfx: () => { sfx.tone({ freq: 784, type: 'sine', dur: 0.18, vol: 0.09 }); sfx.tone({ freq: 1175, type: 'sine', dur: 0.3, vol: 0.08, delay: 0.14 }); },
    onFan,
    onSub: (name, code) => {   // a new subscriber (seen by YouTube): their country gets a shield — or comes back if it is out
      if (code && gift(code, name, 'SUBSCRIBER')) return;
      const c = [...power.entries()].sort((a, b) => b[1] - a[1]).map(([k]) => k).find((k) => alive.has(k) && !shield.has(k));
      if (c) giveShield(c, name.toUpperCase().slice(0, 14), 'FREE SHIELD · SUBSCRIBER');
    },
    // "subscribed" / "shared" + country in chat: YouTube does not tell us about shares (and a subscribe only by name),
    // so we trust the viewer — subscribe once per viewer, share once every 10 minutes per viewer
    onText: (name, text, code, author) => {
      const no = (w) => new RegExp("\\b(don'?t|do not|not|no|never|can'?t|won'?t|will)\\s+" + w, 'i').test(text);
      let kind = SUB_RX.test(text) && !no('sub') ? 'sub' : SHARE_RX.test(text) && !no('shar') ? 'share' : '';
      const now = performance.now() / 1000, pend = giftWait.get(author);
      if (!kind && pend && pend.at > now - 300 && code) kind = pend.kind;
      else if (!kind) return null;
      const VERB = kind === 'sub' ? 'SUBSCRIBING' : 'SHARING';
      if (kind === 'sub' && subDone.has(author)) return ['YOU ARE ALREADY IN THE FAMILY, ', { name }, '! NOW SHARE THE LIVE AND TYPE "SHARED" + YOUR COUNTRY!'];
      if (kind === 'share' && (shareAt.get(author) || -1e9) > now - 600) return ['THANK YOU FOR SHARING AGAIN, ', { name }, '! ONE SHARE GIFT EVERY 10 MINUTES — KEEP CHATTING!'];
      if (!code) { giftWait.set(author, { kind, at: now }); return [`THANK YOU FOR ${VERB}, `, { name }, '! NOW TYPE YOUR COUNTRY — IT GETS A SHIELD, OR COMES BACK IF IT IS OUT!']; }
      giftWait.delete(author);
      if (!teams.includes(code)) { S.want[code] = (S.want[code] || 0) + 3; return [`THANK YOU FOR ${VERB}, `, { name }, '! ', { node: flagNode(code) }, NAME(code) + ' JOINS THE NEXT ROUND!']; }
      if (phase !== 'play') { giftWait.set(author, { kind, at: now }); return [`THANK YOU FOR ${VERB}, `, { name }, '! A NEW ROUND STARTS IN A MOMENT — TYPE ', { node: flagNode(code) }, NAME(code) + ' THEN!']; }
      const wasOut = !alive.has(code);
      if (!gift(code, cleanName(name), kind === 'sub' ? 'SUBSCRIBER' : 'SHARE')) return [`THANK YOU FOR ${VERB}, `, { name }, '! ', { node: flagNode(code) }, NAME(code) + (shield.has(code) ? ' ALREADY HAS A SHIELD — KEEP CHATTING!' : ' — KEEP CHATTING FOR IT!')];
      if (kind === 'sub') { subDone.add(author); chat.subBanner?.(name); } else shareAt.set(author, now);
      return [`THANK YOU FOR ${VERB}, `, { name }, '! ', { node: flagNode(code) }, NAME(code) + (wasOut ? ' IS BACK IN THE ARENA!' : ' GOT A SHIELD!') + ' MORE FRIENDS = SAFER COUNTRY!'];
    },
    onSuper: (code, name) => {
      if (!code || !teams.includes(code)) return;
      if (!alive.has(code)) revive(code, name, true); else giveShield(code, name, 'SUPER CHAT SHIELD');
    },
    onStats: (st) => { lastStats = st; },
    emptyHint: `Type your country — ${cfg.shield} chats give it a SHIELD!`,
    hints: ['TYPE YOUR COUNTRY — ENOUGH CHATS GIVE IT A SHIELD!', 'LIKE IF YOU SEE YOUR COUNTRY FLAG!', 'SUBSCRIBE OR SHARE — YOUR COUNTRY GETS A SHIELD OR COMES BACK!'],
    idleLines: [
      [`TYPE YOUR COUNTRY IN CHAT! ${cfg.shield} CHATS GIVE IT A SHIELD AGAINST THE BLACK HOLE!`],
      ['LIKE IF YOU SEE YOUR COUNTRY FLAG IN THE ARENA!'],
      ['SUBSCRIBE AND YOUR COUNTRY GETS A FREE SHIELD — AND IF IT IS OUT, IT COMES BACK!'],
      ['SHARE THE LIVE AND TYPE SHARED + YOUR COUNTRY — IT GETS A SHIELD, OR COMES BACK IF IT IS OUT!'],
      ['YOUR COUNTRY IS NOT IN THE ARENA? COMMENT IT AND IT JOINS THE NEXT ROUND!'],
      ['WATCH THE SPINNING BEARING — IT PUSHES THE FLAGS TOWARDS THE BLACK HOLE! A SHIELD KEEPS YOUR COUNTRY SAFE!'],
      [`YOUR COUNTRY IS OUT? ${cfg.revive} CHATS AND IT COMES BACK — SEE THE REVIVE BOX AT THE TOP!`],
    ],
    welcome: (name, code, text) => {
      const hi = isSalam(text) ? 'WA ALAIKUM ASSALAM ' : 'WELCOME ';
      if (!code) return [hi, { name }, `! TYPE YOUR COUNTRY — ${cfg.shield} CHATS GIVE IT A SHIELD!`];
      return [hi, { name }, ' FROM ', { node: flagNode(code) }, NAME(code) + '! ' + statusLine(code)];
    },
    reply: (name, code, text) => {
      if (code) return [{ node: flagNode(code) }, NAME(code) + ', ', { name }, '! ' + statusLine(code)];
      if (/who.*(win|lead)|winner|কে জিতবে/i.test(text)) {
        const top = [...power.entries()].filter(([k]) => alive.has(k)).sort((a, b) => b[1] - a[1])[0];
        return top ? [{ node: flagNode(top[0]) }, NAME(top[0]) + ' HAS THE MOST CHATS, ', { name }, '! TYPE YOURS TO BEAT IT!'] : null;
      }
      if (isGreeting(text)) return [isSalam(text) ? 'WA ALAIKUM ASSALAM ' : 'HELLO AGAIN ', { name }, '! WHICH COUNTRY ARE YOU SAVING?'];
      return null;
    },
    demoTexts: ['Bangladesh 🇧🇩', 'India!', 'Brasil 🔥', 'Pakistan', 'hello', 'USA USA', 'who will win?', 'Nepal', 'Egypt', 'বাংলাদেশ', 'Indonesia', 'Mexico', 'nice game', 'Morocco'],
  });
  const host = (parts, prio = 2) => { if (chat.active) chat.say(parts, prio); };
  { // LUNA's box is small here: make a long line smaller until it fits
    const rbt = document.querySelector('.lc-lastflag .lc-rb-text');
    if (rbt) new MutationObserver(() => {
      rbt.style.fontSize = '';
      for (let size = 24; rbt.scrollHeight > rbt.clientHeight + 2 && size > 15; size -= 1) rbt.style.fontSize = size + 'px';
    }).observe(rbt, { childList: true });
  }

  /* ---------------------------------------------------------------- the draw (black hole) */
  // A flag is caught as soon as it touches the open black hole: a shield breaks, no shield = out.
  const holeNow = () => HOLE * (openLeft > OPEN ? 1 : openness(openLeft));

  // Only ONE flag falls each time the hole opens (the one nearest the middle), like the big arena channels.
  let tookOne = false, shakeT = 0;
  const catchFlags = () => { // the hole is open: flags over it fall in (a shield bounces its flag out instead)
    if (tookOne) return;
    // a flag is caught when it is clearly ON the open black hole (its edge reaches TOUCH px into the black),
    // so on a phone everyone can see it touched — a flag only passing close by is not taken
    const near = balls.filter((b) => !b.gone && holeNow() > 4 && Math.hypot(b.x - CX, b.y - CY) <= holeNow() + R - TOUCH)
      .sort((p, q) => Math.hypot(p.x - CX, p.y - CY) - Math.hypot(q.x - CX, q.y - CY));
    // The shield breaks ONLY when its flag is really the one going down: it stays on through every
    // other opening, and when the hole would take its flag, the shield breaks instead and the flag is thrown out.
    const b = near[0];
    if (!b) return;
    if (shield.has(b.code)) {
      shield.delete(b.code);
      popAt('shd', b.code, 'SHIELD BROKE — SAVED!', NAME(b.code), b.x, b.y - 70);
      host(['THE SHIELD SAVED ', { node: flagNode(b.code) }, NAME(b.code) + '! IT BROKE, BUT THE FLAG LIVES — TYPE IT AGAIN FOR A NEW SHIELD!'], 2);
      const a = Math.random() * Math.PI * 2; b.x = CX + Math.cos(a) * (HOLE + R + 24); b.y = CY + Math.sin(a) * (HOLE + R + 24);
      b.vx = Math.cos(a) * SPEED * 1.6; b.vy = Math.sin(a) * SPEED * 1.6;
      sfx.shield(); shakeT = 0.15; tookOne = true;   // this opening is used up: the shield took the hit
      openLeft = Math.min(openLeft, OPEN_IN);        // …and the hole closes at once
      return;
    }
    if (alive.size - falling.length <= 1) return;   // never take the last flag
    b.gone = true; falling.push({ b, t: 0, x0: b.x, y0: b.y });
    sfx.draw(); tookOne = true;
    openLeft = Math.min(openLeft, DRAW_SECS + OPEN_IN);   // the hole closes right after swallowing it
  };

  const eliminate = (b) => {
    balls = balls.filter((x) => x !== b);
    sfx.gone(); flashT = 0.35; shakeT = 0.3;   // flash + boom + a light shake   // a short white flash, like a camera
    alive.delete(b.code); out.push(b.code); prog.set(b.code, 0); feed.push([b.code, 'o']);
    const canRev = (NOLIMIT || revLeft > 0) && alive.size > 2;
    popAt('out', b.code, 'PULLED IN', NAME(b.code), CX - 150, CY + 150);
    if (canRev) host([{ node: flagNode(b.code) }, NAME(b.code) + ` IS OUT! ${NAME(b.code)} FANS — ${cfg.revive} CHATS AND IT COMES BACK!`], 2);
    else host([{ node: flagNode(b.code) }, NAME(b.code) + ` IS OUT! ${alive.size} COUNTRIES LEFT!`], 1);
    if (alive.size === 10 && !final10) { final10 = true; banner('FINAL 10', 'TYPE YOUR COUNTRY — SHIELDS DECIDE IT NOW!', 'red'); host(['ONLY 10 COUNTRIES LEFT! EVERY SHIELD COUNTS NOW — TYPE YOUR COUNTRY!'], 3); }
    if (alive.size === 2 && !finalSaid) {
      finalSaid = true;
      const [a, c] = [...alive];
      banner('FINAL BATTLE', `${NAME(a)} VS ${NAME(c)}`, 'red');
      host(['FINAL BATTLE! ', { node: flagNode(a) }, NAME(a) + ' AGAINST ', { node: flagNode(c) }, NAME(c) + '! SHIELD YOUR COUNTRY IN CHAT!'], 3);
    }
    if (alive.size === 1) crown([...alive][0]);
    report({ round: S.round, final: false, qualified: out.length });
    save(); updateUI();
  };

  const crown = (code) => {
    phase = 'won'; phaseT = 0;
    const top = [...(fans.get(code) || new Map()).entries()].sort((a, b) => b[1] - a[1]).slice(0, 3).map(([n]) => n);
    $('win-flag').style.cssText = flagCss(code, 300);
    $('win-name').textContent = NAME(code);
    $('win-sub').textContent = `ROUND ${S.round} CHAMPION · ${COUNT} COUNTRIES`;
    $('win-heroes').innerHTML = top.length ? '🏅 TOP SUPPORTERS<b></b>' : '';
    if (top.length) $('win-heroes').querySelector('b').textContent = top.join(' · ');
    $('win').hidden = false; $('banner').hidden = true; sfx.win();
    host(['LAST FLAG STANDING! ', { node: flagNode(code) }, NAME(code) + ` WINS ROUND ${S.round}!`, ...(top.length ? [' THANK YOU ', ...top.flatMap((n, i) => [{ name: n }, i < top.length - 1 ? ', ' : ''])] : []), '! A NEW ROUND STARTS SOON!'], 3);
    report({ round: S.round, final: false, qualified: out.length, event: 'qualified', code, name: C[byCode.get(code)].name });
    report({ round: S.round, final: true, qualified: out.length, event: 'champion', code, name: C[byCode.get(code)].name });
    S.live = null; save();
  };

  /* ---------------------------------------------------------------- mystery boxes + goals */
  const mystery = () => {
    const opts = ['shields', 'speed'];
    if (alive.size > 12) opts.push('double');
    if (out.some((c) => !revived.has(c)) && alive.size > 2) opts.push('revive');
    const e = pick(opts);
    if (e === 'double') { doubleNext = true; banner('BIG HOLE!', 'THE HOLE STAYS OPEN LONGER NEXT TIME', 'red', '🎁'); host(['MYSTERY BOX: BIG HOLE! NEXT TIME THE BLACK HOLE STAYS OPEN LONGER — SHIELD YOUR COUNTRY!'], 3); }
    if (e === 'shields') {
      banner('LUCKY SHIELDS!', '3 RANDOM COUNTRIES GET A SHIELD', 'gold', '🎁');
      shuffle([...alive].filter((c) => !shield.has(c))).slice(0, 3).forEach((c) => giveShield(c, '', 'LUCKY SHIELD'));
    }
    if (e === 'speed') { speedUntil = simT + 20; banner('SPEED STORM!', 'ALL FLAGS GO FAST FOR 20 SECONDS', '', '🎁'); host(['MYSTERY BOX: SPEED STORM! HOLD ON TIGHT!'], 2); }
    if (e === 'revive') {
      const c = pick(out.filter((x) => !revived.has(x)));
      banner('LUCKY COMEBACK!', `${NAME(c)} RETURNS FOR FREE`, 'gold', '🎁'); revive(c, '', true);
    }
  };
  const checkGoals = () => {
    let st = lastStats;
    if (demo) st = { likes: Math.floor(simT / 9) + 12, viewers: 60 + Math.floor(simT / 6) }; // made-up numbers for the preview
    $('goals').hidden = !st;
    if (!st) return;
    if (!likeGoal) likeGoal = (Math.floor(st.likes / cfg.likes) + 1) * cfg.likes;
    if (st.likes >= likeGoal && phase === 'play') {
      likeGoal = (Math.floor(st.likes / cfg.likes) + 1) * cfg.likes;
      holdUntil = Math.max(holdUntil, simT) + 30;
      banner('LIKE GOAL!', 'THE BLACK HOLE IS CLOSED FOR 30 SECONDS', 'gold', '👍');
      host([`LIKE GOAL REACHED! THE BLACK HOLE IS CLOSED FOR 30 SECONDS. NEXT GOAL: ${likeGoal} LIKES!`], 3);
    }
    if (st.viewers >= viewGoal && phase === 'play') {
      viewGoal += cfg.viewers;
      const top = [...power.entries()].filter(([k]) => alive.has(k) && !shield.has(k)).sort((a, b) => b[1] - a[1]).slice(0, 3).map(([k]) => k);
      banner('VIEWER GOAL!', `${st.viewers} WATCHING — FREE SHIELDS!`, 'gold', '👀');
      top.forEach((c) => giveShield(c, '', 'VIEWER GOAL SHIELD'));
    }
    const lp = clamp(1 - (likeGoal - st.likes) / cfg.likes, 0, 1), vp = clamp(st.viewers / viewGoal, 0, 1);
    $('g-like').style.width = (lp * 100) + '%'; $('g-like-t').textContent = `👍 LIKES ${st.likes}/${likeGoal}`;
    $('g-view').style.width = (vp * 100) + '%'; $('g-view-t').textContent = `👀 WATCHING ${st.viewers}/${viewGoal}`;
  };

  /* ---------------------------------------------------------------- physics */
  const barSeg = (k) => { // a straight bar from the bearing ring outwards
    const a = barA + k * Math.PI * 2 / BARS;
    return [CX + Math.cos(a) * BAR_IN, CY + Math.sin(a) * BAR_IN, CX + Math.cos(a) * BAR_OUT, CY + Math.sin(a) * BAR_OUT, a];
  };
  const step = (dt) => {
    simT += dt;
    barA += dt * SPIN;
    const sp = SPEED * (speedUntil > simT ? 1.8 : 1);
    for (const b of balls) {
      if (b.gone) continue;
      b.x += b.vx * dt; b.y += b.vy * dt;
      // the six walls
      for (const e of EDGES) {
        const d = (b.x - e.x) * e.nx + (b.y - e.y) * e.ny;
        if (d < R) { const v = b.vx * e.nx + b.vy * e.ny; if (v < 0) { b.vx -= 2 * v * e.nx; b.vy -= 2 * v * e.ny; sfx.knock(); } b.x += e.nx * (R - d); b.y += e.ny * (R - d); }
      }
      // the bearing's turning bars
      for (let k = 0; k < BARS; k++) {
        const [x1, y1, x2, y2] = barSeg(k);
        const ex = x2 - x1, ey = y2 - y1, L2 = ex * ex + ey * ey;
        const t = clamp(((b.x - x1) * ex + (b.y - y1) * ey) / L2, 0, 1);
        const px = x1 + ex * t, py = y1 + ey * t;
        const dx = b.x - px, dy = b.y - py, d = Math.hypot(dx, dy);
        if (d < R + BAR_W && d > 0) {
          const nx = dx / d, ny = dy / d;
          const bvx = -(py - CY) * SPIN, bvy = (px - CX) * SPIN;   // the bar point moves with the turn: it pushes the flag along
          const rel = (b.vx - bvx) * nx + (b.vy - bvy) * ny;
          if (rel < 0) { b.vx -= 2 * rel * nx; b.vy -= 2 * rel * ny; }
          b.x = px + nx * (R + BAR_W); b.y = py + ny * (R + BAR_W);
        }
      }
    }
    // flags bump each other
    for (let i = 0; i < balls.length; i++) {
      const a = balls[i]; if (a.gone) continue;
      for (let j = i + 1; j < balls.length; j++) {
        const b = balls[j]; if (b.gone) continue;
        const dx = b.x - a.x, dy = b.y - a.y, d2 = dx * dx + dy * dy;
        if (d2 >= 4 * R * R || d2 === 0) continue;
        const d = Math.sqrt(d2), nx = dx / d, ny = dy / d, ov = 2 * R - d;
        a.x -= nx * ov / 2; a.y -= ny * ov / 2; b.x += nx * ov / 2; b.y += ny * ov / 2;
        const rel = (b.vx - a.vx) * nx + (b.vy - a.vy) * ny;
        if (rel < 0) { a.vx += rel * nx; a.vy += rel * ny; b.vx -= rel * nx; b.vy -= rel * ny; }
      }
    }
    for (const b of balls) { // keep every flag moving at the same speed
      const m = Math.hypot(b.vx, b.vy) || 1; const k = 1 + (sp / m - 1) * Math.min(1, dt * 3);
      b.vx *= k; b.vy *= k; if (b.hot > 0) b.hot -= dt;
    }

    if (phase !== 'play') { phaseT += dt / FAST; if (phaseT > WIN_SECS) newRound(); return; }
    for (const f of falling) {
      f.t += dt;
      const p = Math.min(1, f.t / DRAW_SECS), b = f.b;
      b.x = f.x0 + (CX - f.x0) * p; b.y = f.y0 + (CY - f.y0) * p; b.scale = 1 - p * 0.9; b.spin = p * 9;
      if (p >= 1) { f.done = true; eliminate(b); }
    }
    falling = falling.filter((f) => !f.done);
    if (phase !== 'play') return;
    if (openLeft > 0) { openLeft -= dt; catchFlags(); if (openLeft <= 0) toNext = CYCLE; return; }
    if (simT < holdUntil) return;
    const before = Math.ceil(toNext);
    toNext -= dt;
    if (toNext <= 3 && Math.ceil(toNext) !== before && toNext > 0) sfx.tick();
    if (toNext <= 0) { tookOne = false; openLeft = OPEN; if (doubleNext) { openLeft = OPEN * 2.5; doubleNext = false; } sfx.tone({ freq: 160, to: 60, type: 'sine', dur: 0.4, vol: 0.26 }); }
    if (cfg.events && simT > nextEvent && alive.size > 3) { nextEvent = simT + 240 + Math.random() * 120; mystery(); }
  };

  /* ---------------------------------------------------------------- drawing */
  const cv = $('arena'), c = cv.getContext('2d');
  let k = 1;
  let irisO = 0, irisRot = 0, flashT = 0;
  const resize = () => { const r = cv.getBoundingClientRect(), dpr = Math.min(devicePixelRatio || 1, 1.5); cv.width = Math.round(r.width * dpr) || W; cv.height = Math.round(r.height * dpr) || H; k = cv.width / W; };
  resize(); addEventListener('resize', resize);
  // Pictures that look the same every frame are drawn once and then copied (a copy costs a fraction of
  // drawing a glow or a clipped flag again) — the live server has no graphics card, so this saves a lot of CPU.
  const sprites = new Map(); let spriteK = 0;
  const sprite = (key, half, paint, halfH = half) => {
    if (spriteK !== k) { sprites.clear(); spriteK = k; }
    let sp = sprites.get(key);
    if (!sp) {
      sp = document.createElement('canvas'); sp.width = Math.ceil(half * 2 * k); sp.height = Math.ceil(halfH * 2 * k);
      const x = sp.getContext('2d'); x.setTransform(k, 0, 0, k, half * k, halfH * k); paint(x);
      if (sprites.size > 600) sprites.clear();
      sprites.set(key, sp);
    }
    return sp;
  };
  const paintFlag = (x, code, r, stroke) => {
    const plain = PLAIN[code], i = byCode.get(code);
    x.beginPath(); x.arc(0, 0, r, 0, 7); x.closePath(); x.fillStyle = plain ? plain[0] : '#fff'; x.fill();
    x.save(); x.clip();
    if (plain) { x.fillStyle = '#fff'; x.font = `700 ${Math.round(r * 0.7)}px Oswald, Rajdhani, sans-serif`; x.textAlign = 'center'; x.textBaseline = 'middle'; x.fillText(plain[1], 0, 1); }
    else x.drawImage(atlas, (i % COLS_A) * FW, Math.floor(i / COLS_A) * FH, FW, FH, -r * 1.36, -r, r * 2.72, r * 2);
    x.restore();
    x.lineWidth = 4; x.strokeStyle = stroke; x.beginPath(); x.arc(0, 0, r, 0, 7); x.stroke();
  };
  const drawBall = (b) => {
    const s = b.scale ?? 1, r = R * s;
    const plain = PLAIN[b.code], i = byCode.get(b.code);
    if (s === 1) {   // the usual case: copy the ready flag picture
      const stroke = shield.has(b.code) ? '#33f0ff' : b.hot > 0 ? '#ffd23a' : '#fff';
      c.save(); c.translate(b.x, b.y); if (b.spin) c.rotate(b.spin);
      if (shield.has(b.code)) { c.globalAlpha = 0.45 + 0.2 * Math.sin(simT * 6); c.fillStyle = '#33f0ff'; c.beginPath(); c.arc(0, 0, r + 12, 0, 7); c.fill(); c.globalAlpha = 1; }
      if (b.hot > 0) { c.globalAlpha = Math.min(1, b.hot) * 0.7; c.fillStyle = '#ffc93a'; c.beginPath(); c.arc(0, 0, r + 9, 0, 7); c.fill(); c.globalAlpha = 1; }
      const half = r + 3;
      c.drawImage(sprite('f' + b.code + stroke, half, (x) => paintFlag(x, b.code, r, stroke)), -half, -half, half * 2, half * 2);
      c.restore();
      return;
    }
    c.save(); c.translate(b.x, b.y); if (b.spin) c.rotate(b.spin);
    if (shield.has(b.code)) { c.globalAlpha = 0.45 + 0.2 * Math.sin(simT * 6); c.fillStyle = '#33f0ff'; c.beginPath(); c.arc(0, 0, r + 12, 0, 7); c.fill(); c.globalAlpha = 1; }
    if (b.hot > 0) { c.globalAlpha = Math.min(1, b.hot) * 0.7; c.fillStyle = '#ffc93a'; c.beginPath(); c.arc(0, 0, r + 9, 0, 7); c.fill(); c.globalAlpha = 1; }
    c.beginPath(); c.arc(0, 0, r, 0, 7); c.closePath(); c.fillStyle = plain ? plain[0] : '#fff'; c.fill();
    c.save(); c.clip();
    if (plain) { c.fillStyle = '#fff'; c.font = `700 ${Math.round(r * 0.7)}px Oswald, Rajdhani, sans-serif`; c.textAlign = 'center'; c.textBaseline = 'middle'; c.fillText(plain[1], 0, 1); }
    else c.drawImage(atlas, (i % COLS_A) * FW, Math.floor(i / COLS_A) * FH, FW, FH, -r * 1.36, -r, r * 2.72, r * 2);
    c.restore();
    c.lineWidth = 4; c.strokeStyle = shield.has(b.code) ? '#33f0ff' : b.hot > 0 ? '#ffd23a' : '#fff'; c.beginPath(); c.arc(0, 0, r, 0, 7); c.stroke();
    c.restore();
  };
  const hexPath = (grow = 0) => { c.beginPath(); HEX.forEach(([x, y], i) => { const gx = x + (x - CX) / HX * grow, gy = y + (y - CY) / HY * grow; if (i) c.lineTo(gx, gy); else c.moveTo(gx, gy); }); c.closePath(); };
  const draw = () => {
    if (shakeT > 0) { // the whole arena shakes for a moment when a flag is eliminated
      const m = 7 * Math.min(1, shakeT / 0.2);
      cv.style.translate = `${(Math.random() * 2 - 1) * m}px ${(Math.random() * 2 - 1) * m}px`;
      shakeT -= 0.033; if (shakeT <= 0) cv.style.translate = '';
    }
    c.setTransform(k, 0, 0, k, 0, 0);
    c.clearRect(0, 0, W, H);
    // arena floor + neon wall (pink → cyan)
    const FH2 = Math.max(HX, HY) + 6 + 6 + 50;   // floor + neon wall, drawn once
    c.drawImage(sprite('floor', FH2, (x) => {
      const hp = () => { x.beginPath(); HEX.forEach(([hx, hy], i) => { const gx = hx - CX + (hx - CX) / HX * 6, gy = hy - CY + (hy - CY) / HY * 6; if (i) x.lineTo(gx, gy); else x.moveTo(gx, gy); }); x.closePath(); };
      const g = x.createRadialGradient(0, 0, 40, 0, 0, HX); g.addColorStop(0, '#1d1040'); g.addColorStop(1, '#0a0620');
      hp(); x.fillStyle = g; x.fill();
      const lg = x.createLinearGradient(-HX, -HY, HX, HY); lg.addColorStop(0, '#ff2bd6'); lg.addColorStop(1, '#33f0ff');
      x.lineJoin = 'round'; x.lineWidth = 12; x.strokeStyle = lg; x.shadowColor = '#b14dff'; x.shadowBlur = 34; hp(); x.stroke(); x.shadowBlur = 0;
    }), CX - FH2, CY - FH2, FH2 * 2, FH2 * 2);
    // the hole: iris blades inside the bearing ring; when it opens the blades turn open and it glows pink
    const open = openLeft > 0 && phase === 'play', lock = simT < holdUntil && phase === 'play';
    irisO = open ? (openLeft > OPEN ? 1 : openness(openLeft)) : 0;
    irisRot += open ? 0.12 : 0.004;
    c.fillStyle = 'rgba(60, 30, 120, .55)'; c.beginPath(); c.arc(CX, CY, RING, 0, 7); c.fill();
    const inner = Math.max(4, HOLE * irisO);   // same size as the catch area
    for (let q = 0; q < 8; q++) {
      const a0 = irisRot + q * Math.PI / 4, a1 = a0 + Math.PI / 4;
      c.fillStyle = q % 2 ? 'rgba(120, 70, 220, .5)' : 'rgba(90, 50, 190, .5)';
      c.beginPath(); c.arc(CX, CY, RING - 6, a0, a1); c.arc(CX, CY, inner, a1 + irisO * 0.6, a0 + irisO * 0.6, true); c.closePath(); c.fill();
    }
    if (irisO > 0.02) {
      c.fillStyle = '#000'; c.beginPath(); c.arc(CX, CY, inner, 0, 7); c.fill();
      c.lineWidth = 3; c.strokeStyle = `rgba(255,43,214,${Math.min(1, irisO) * 0.95})`; c.beginPath(); c.arc(CX, CY, inner, 0, 7); c.stroke();
    }
    // the bearing: three neon bars and the ring with its little balls, all turning together
    c.lineCap = 'round';
    for (let i = 0; i < BARS; i++) {   // each bar is one ready picture, turned to its place
      const [x1, y1, x2, y2] = barSeg(i);
      const len = Math.hypot(x2 - x1, y2 - y1), bh = len / 2 + BAR_W + 50, bv = BAR_W + 50;
      c.save(); c.translate((x1 + x2) / 2, (y1 + y2) / 2); c.rotate(Math.atan2(y2 - y1, x2 - x1));
      c.drawImage(sprite('bar' + Math.round(len), bh, (x) => {
        const bg = x.createLinearGradient(-len / 2, 0, len / 2, 0); bg.addColorStop(0, '#33f0ff'); bg.addColorStop(1, '#b14dff');
        x.lineCap = 'round'; x.lineWidth = BAR_W * 2; x.strokeStyle = bg; x.shadowColor = '#33f0ff'; x.shadowBlur = 22; x.beginPath(); x.moveTo(-len / 2, 0); x.lineTo(len / 2, 0); x.stroke(); x.shadowBlur = 0;
        x.lineWidth = 5; x.strokeStyle = 'rgba(255,255,255,.6)'; x.beginPath(); x.moveTo(-len / 2, 0); x.lineTo(len / 2, 0); x.stroke();
      }, bv), -bh, -bv, bh * 2, bv * 2);
      c.restore();
    }
    const rc = lock ? '#7dff8a' : open ? '#ff2bd6' : '#33f0ff', rb = open ? 30 : 18, RH = RING + 5 + rb * 2;
    c.drawImage(sprite('ring' + rc + rb, RH, (x) => {
      x.lineWidth = 10; x.strokeStyle = rc; x.shadowColor = rc; x.shadowBlur = rb; x.beginPath(); x.arc(0, 0, RING, 0, 7); x.stroke(); x.shadowBlur = 0;
    }), CX - RH, CY - RH, RH * 2, RH * 2);
    c.lineWidth = 3; c.strokeStyle = 'rgba(255,255,255,.8)'; c.fillStyle = 'rgba(10,5,30,.9)';
    for (let q = 0; q < 12; q++) { const a = barA + q * Math.PI / 6; c.beginPath(); c.arc(CX + Math.cos(a) * RING, CY + Math.sin(a) * RING, 6, 0, 7); c.fill(); c.stroke(); }
    if (lock) { c.fillStyle = '#c4ffca'; c.font = '700 24px Rajdhani, Oswald, sans-serif'; c.textAlign = 'center'; c.textBaseline = 'middle'; c.fillText('🔒 ' + Math.ceil(holdUntil - simT) + 's', CX, CY + RING + 26); }
    // flags (falling ones on top)
    for (const b of balls) if (!b.gone) drawBall(b);
    for (const f of falling) drawBall(f.b);
    if (flashT > 0) { c.fillStyle = `rgba(255,255,255,${Math.min(0.55, flashT * 1.8)})`; hexPath(6); c.fill(); flashT -= 0.033; }
    // names over shielded / cheered flags
    c.font = '700 20px Rajdhani, Oswald, sans-serif'; c.textAlign = 'center'; c.textBaseline = 'middle';
    for (const b of balls) {
      let t = tags.get(b.code);
      if (!t || t.until < simT || !t.name) t = shield.has(b.code) ? { kind: 'shield', name: shield.get(b.code) || 'SHIELD' } : null;   // the shield label stays until the shield is used
      if (!t || b.gone) continue;
      const label = (t.kind === 'shield' ? '🛡️ ' : t.kind === 'rev' ? '♻️ ' : '★ ') + t.name, tw = c.measureText(label).width + 18, y = b.y - R - 18;
      c.fillStyle = t.kind === 'shield' ? '#33f0ff' : t.kind === 'rev' ? '#7dff8a' : '#ffd23a'; c.beginPath(); c.roundRect(b.x - tw / 2, y - 14, tw, 28, 14); c.fill();
      c.fillStyle = '#10131f'; c.fillText(label, b.x, y + 1);
    }
  };

  /* ---------------------------------------------------------------- panels */
  const circ = (code, s = 34) => `<span class="fc">${PLAIN[code] ? `<span style="width:${s}px;height:${s}px;border-radius:50%;background:${PLAIN[code][0]};display:inline-flex;align-items:center;justify-content:center;font-size:${Math.round(s * 0.36)}px;font-weight:700">${PLAIN[code][1]}</span>` : flagHTML(code, Math.round(s * 4 / 3))}</span>`;
  const SHORT = (code) => { const n = NAME(code); return n.length > 9 ? n.slice(0, 8) + '.' : n; };
  const revPage = { page: 0, at: 0 };
  const updateUI = () => {
    $('round').textContent = `ROUND ${S.round} · LIVE 24/7`;
    $('n-left').textContent = alive.size; $('n-all').textContent = `OF ${COUNT} ALIVE`;
    // CHAT SUPPORT: countries in the arena that chatted this round, the ones chatting NOW first (quiet ones sink),
    // 3 at a time; the panel turns to the next 3 every few seconds and starts again at the top
    const nowS = performance.now() / 1000;
    if (!support.order.length || nowS - support.at >= SUPPORT_SECS) {
      support.page++;
      if (support.page * 3 >= support.order.length) {
        const hot = (cc) => { const x = heat.get(cc); return x ? x.h * Math.exp(-(nowS - x.t) / 30) : 0; };
        support.order = [...power.keys()].filter((cc) => alive.has(cc)).sort((a, b) => (hot(b) - hot(a)) || (power.get(b) - power.get(a))).slice(0, SUPPORT_MAX);
        support.page = 0;
      }
      support.at = nowS; support.flip = support.order.length > 3;
    }
    const pages = Math.ceil(support.order.length / 3);
    $('sup-pg').textContent = pages > 1 ? `◀ ${support.page + 1}/${pages} ▶` : '';
    const sr = $('sup-rows');
    if (support.flip) { support.flip = false; sr.classList.remove('flip'); void sr.offsetWidth; sr.classList.add('flip'); }
    const srows = support.order.slice(support.page * 3, support.page * 3 + 3).filter((cc) => alive.has(cc));
    sr.innerHTML = srows.length ? srows.map((code) => {
      const on = shield.has(code), have = Math.min(cfg.shield, prog.get(code) || 0);
      return `<div class="row${on ? ' on' : ''}">${circ(code)}<span class="nm">${SHORT(code)}</span><span class="bar"><i style="width:${on ? 100 : have / cfg.shield * 100}%"></i></span><span class="ct">${on ? '🛡 ON' : `${have}/${cfg.shield}`}</span></div>`;
    }).join('') : `<div class="empty">Type your country in chat — ${cfg.shield} chats = 🛡 SHIELD!</div>`;
    // REVIVE: countries that are out, the closest to coming back first
    const outs = [...out].reverse().sort((a, b) => (prog.get(b) || 0) - (prog.get(a) || 0));
    const rpages = Math.ceil(outs.length / 3);
    if (nowS - revPage.at >= SUPPORT_SECS) { revPage.at = nowS; revPage.page = rpages ? (revPage.page + 1) % rpages : 0; if (rpages > 1) { const rr = $('rev-rows'); rr.classList.remove('flip'); void rr.offsetWidth; rr.classList.add('flip'); } }
    if (revPage.page >= rpages) revPage.page = 0;
    const rrows = outs.slice(revPage.page * 3, revPage.page * 3 + 3);
    const canRev = (NOLIMIT || revLeft > 0) && alive.size > 2;
    $('rev-rows').innerHTML = rrows.length ? rrows.map((code) => {
      const have = Math.min(cfg.revive, prog.get(code) || 0);
      return `<div class="row">${circ(code)}<span class="nm">${SHORT(code)}</span><span class="bar"><i style="width:${have / cfg.revive * 100}%"></i></span><span class="ct">${have}/${cfg.revive}</span></div>`;
    }).join('') : `<div class="empty">No country is out yet. Out? ${cfg.revive} chats bring it back!</div>`;
    $('rev-foot').textContent = !canRev && out.length ? 'NO REVIVES LEFT THIS ROUND' : out.length ? `${out.length} countries out${rpages > 1 ? ` · ${revPage.page + 1}/${rpages}` : ''}` : (NOLIMIT ? '♾️ NO LIMIT' : `REVIVES LEFT ${revLeft}/${cfg.revives}`);
    // the last few flags pulled in / revived
    feed = feed.slice(-4);
    const fk = feed.map((x) => x.join()).join('|');
    if ($('feed').dataset.k !== fk) {
      $('feed').dataset.k = fk;
      $('feed').innerHTML = feed.slice().reverse().map(([code, kind]) => `<span class="kf ${kind === 'r' ? 'r' : ''}">${circ(code, 34)} ${kind === 'r' ? '♻ REVIVED' : '❌ PULLED IN'}</span>`).join('');
    }
    $('out-count').textContent = `${out.length} / ${COUNT} OUT`;
  };
  const updateClock = () => {
    const el = $('clock'), lock = simT < holdUntil && phase === 'play';
    const gap = CYCLE, left = lock ? holdUntil - simT : openLeft > 0 ? 0 : Math.max(0, toNext);
    el.classList.toggle('lock', lock); el.classList.toggle('hot', !lock && left <= 3 && phase === 'play');
    $('clock-n').textContent = phase !== 'play' ? '♛' : lock ? '🔒' : openLeft > 0 ? '!' : `0:${String(Math.ceil(left)).padStart(2, '0')}`;
    $('clock-arc').style.strokeDashoffset = String(264 * (1 - clamp(left / (lock ? 30 : gap), 0, 1)));
  };

  /* ---------------------------------------------------------------- settings from the panel */
  setInterval(async () => {
    const n = await fetchConfig();
    if (!n) return;
    if (Array.isArray(n.popular)) popular = n.popular;
    if (n.reset !== cfg.reset) { try { localStorage.removeItem(KEY); } catch (e) { /* ignore */ } location.reload(); return; }
    if (n.rev !== cfg.rev) { save(); location.reload(); }
  }, 20000);

  /* ---------------------------------------------------------------- loop */
  if (S.live && Array.isArray(S.live.alive) && S.live.alive.length > 1) resume(S.live); else newRound();
  updateUI();
  let last = performance.now(), acc = 0, lastUI = 0, lastSave = performance.now(), lastDraw = 0;
  const frame = (now) => {
    const dt = Math.min(0.1, (now - last) / 1000) * FAST * pace; last = now;
    acc += dt;
    let n = 0;
    while (acc >= FIXED && n < 3000) { step(FIXED); acc -= FIXED; n++; }
    if (n >= 3000) acc = 0;
    if (now - lastUI > 400) { updateUI(); updateClock(); checkGoals(); lastUI = now; }
    if (now - lastSave > 5000) { save(); lastSave = now; }
    if (now - lastDraw >= 31) { draw(); lastDraw = now; }
    requestAnimationFrame(frame);
  };
  requestAnimationFrame(frame);
  autoUpdate('lastflag', save);
  goLive({ left: 30, top: 1150, size: 230, duck: duckAll });   // the owner joins the live from a phone (Admin → Join Live)   // after a deploy: reload with the game where it was (no Restart needed)
  window.__lastflag = { open: () => openLeft, cfg, S, alive: () => alive, out: () => out, balls: () => balls, shield, phase: () => phase, simT: () => simT, toNext: () => toNext, onFan, revive, chat };
}

main();
