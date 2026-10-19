// FFTEdit · Flag Arena. Runs by itself, made for 24/7 vertical live streams.
// 64 flag balls bounce in a round arena with turning bars. Every few seconds the black hole in the
// middle draws the flag nearest to it: that country is out. The live chat can save countries:
// enough chats give a SHIELD (survives one draw), a country that is out can be REVIVED, a new
// subscriber gives a free shield, a Super Chat gives both. Likes and viewers unlock goals for everyone.
// The last flag left is the round champion, then a new round starts (about an hour a round).
import { Sfx, holdScreen, PACE, reporter, freshRun } from '/game/audio.js?v=13';
import { liveChat, countryMatcher } from '/game/chat.js?v=14';
import { Music, SongPlayer } from '/game/music.js?v=2';
import { autoUpdate } from '/game/update.js?v=1';

const $ = (id) => document.getElementById(id);
const QS = new URLSearchParams(location.search);
const clamp = (v, a, b) => Math.max(a, Math.min(b, v));
const FAST = clamp(Number(QS.get('fast')) || 1, 1, 200);   // testing only
const pick = (a) => a[Math.floor(Math.random() * a.length)];
const shuffle = (a) => { for (let i = a.length - 1; i > 0; i--) { const j = Math.floor(Math.random() * (i + 1)); [a[i], a[j]] = [a[j], a[i]]; } return a; };

const DEF = { gap: 5, music: true, music_style: 'upbeat', music_vol: 35, title: 'FLAG ARENA', count: 64, minutes: 55, shield: 20, revive: 40, revives: 5, likes: 50, viewers: 100, events: true, rev: '', reset: '' };
// countries that are always in a round (most viewers come from these)
const CORE = ['bd', 'in', 'pk', 'us', 'br', 'ar', 'sa', 'tr', 'id', 'ng', 'eg', 'ma', 'my', 'np', 'ph', 'mx', 'gb', 'fr', 'de', 'ru',
  'cn', 'jp', 'ca', 'es', 'it', 'dz', 'iq', 'af', 'lk', 'vn', 'th', 'kr'];
// flags with holy writing are shown as a plain colour ball (like in Territory War)
const PLAIN = { sa: ['#006c35', 'KSA'], af: ['#007a36', 'AFG'], iq: ['#ce1126', 'IRQ'] };

/* ---------------------------------------------------------------- arena geometry (canvas units) */
const W = 1080, H = 990;                 // canvas size (stage y 450 … 1440) — the arena fills the width
const CX = 540, CY = 492, RW = 474;      // arena centre and inner wall radius
const HOLE = 40;                         // black centre of the hole when fully open (about a flag)
const RING = 132;                        // the pink ring around it (flags roll over it)
// Like the arena games on YouTube: the hole is closed ~4.4 s, then its iris turns open (0.4 s),
// stays open 0.8 s and closes (0.4 s) — about every 6 seconds. A flag over the open hole falls in.
const OPEN = 1.2, OPEN_IN = 0.35;      // the iris opens (0.35 s), stays open, closes (0.35 s)
const openness = (left) => { const t = OPEN - left; return t < OPEN_IN ? t / OPEN_IN : left < OPEN_IN ? left / OPEN_IN : 1; };
const BAR_LEN = 172, BAR_W = 11, BARS = 6, BAR_TILT = 0.32;   // six bars fixed to the wall, turning with it
const FIXED = 1 / 120;
const DRAW_SECS = 0.9;                   // how long a flag takes to fall in
const WIN_SECS = 16;

async function fetchConfig() {
  try { const r = await fetch('/api/flags.php', { cache: 'no-store' }); if (r.ok) return await r.json(); } catch (e) { /* offline */ }
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
  const [remote, atlasInfo] = await Promise.all([fetchConfig(), fetch('/flags/flags.json').then((r) => r.json())]);
  const cfg = { ...DEF, ...(remote || {}) };
  if (cfg.autoplay === false) { holdScreen('/api/flags.php', cfg.rev); return; }
  const pace = PACE[cfg.pace] || 1;
  const demo = QS.has('chatdemo');
  const atlas = new Image(); atlas.src = '/flags/flags.png';
  await atlas.decode().catch(() => {});

  const C = atlasInfo.countries, FW = atlasInfo.w, FH = atlasInfo.h, COLS_A = atlasInfo.cols, ROWS_A = Math.ceil(C.length / COLS_A);
  const byCode = new Map(C.map((c, i) => [c.code, i]));
  const NAME = (code) => C[byCode.get(code)].name.toUpperCase().replace('UNITED ARAB EMIRATES', 'UAE').replace('UNITED KINGDOM', 'UK').replace('UNITED STATES', 'USA').replace('CENTRAL AFRICAN REP.', 'C. AFRICA');
  const flagCss = (code, w) => { const i = byCode.get(code), h = w * 0.75; return `width:${w}px;height:${h}px;background-size:${COLS_A * w}px ${ROWS_A * h}px;background-position:${-(i % COLS_A) * w}px ${-Math.floor(i / COLS_A) * h}px`; };
  const flagHTML = (code, w) => `<span class="flag" style="${flagCss(code, w)}"></span>`;
  const flagNode = (code, w = 40) => { const d = document.createElement('span'); d.className = 'flag'; d.style.cssText = flagCss(code, w); return d; };
  const COUNT = clamp(Math.round(cfg.count) || 64, 16, 100);
  const R = COUNT > 72 ? 31 : COUNT > 48 ? 36 : 41;      // ball radius
  const SPEED = 190;
  const CYCLE = clamp(Number(cfg.gap) || 5, 2, 30);   // seconds the hole stays closed between openings

  { const [a, b] = cfg.title.split(' '); $('title').innerHTML = b ? `${a} <b>${cfg.title.slice(a.length + 1)}</b>` : a; }
  $('rules').innerHTML = `💬 ${cfg.shield} chats → <b class="s">SHIELD</b><br>💬 ${cfg.revive} chats if out → <b class="r">REVIVE</b><br>🔔 Subscribe → <b class="f">FREE SHIELD</b><br>⭐ Super Chat → <b class="x">BOTH</b>`;

  /* ---------------------------------------------------------------- saved state (a reload continues the round) */
  const KEY = 'fft_arena';
  freshRun('flags', [KEY, 'fft_fans_flags']);
  let S = null;
  try { S = JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (e) { /* private mode */ }
  if (!S || S.reset !== cfg.reset || S.count !== COUNT) S = { reset: cfg.reset, count: COUNT, round: 0, want: {} };
  const save = () => {
    try {
      S.live = phase === 'play' ? { teams, alive: [...alive], out, shield: [...shield.entries()], revived: [...revived], revLeft, toNext, power: [...power.entries()],
        fans: [...fans.entries()].map(([k, m]) => [k, [...m.entries()].slice(0, 20)]), hold: Math.max(0, holdUntil - simT), likeGoal, viewGoal, t: simT } : null;
      localStorage.setItem(KEY, JSON.stringify(S));
    } catch (e) { /* ignore */ }
  };

  /* ---------------------------------------------------------------- round state */
  const NOLIMIT = !(Number(cfg.revives) > 0);   // 0 = revive as often as the chat wants
  let teams = [], alive = new Set(), out = [], balls = [], shield = new Map(), revived = new Set(), revLeft = cfg.revives;
  let power = new Map();                 // country -> chats this round (CHAT POWER)
  let prog = new Map();                  // country -> chats towards the next shield / revive
  let fans = new Map();                  // country -> Map(name -> chats)
  let phase = 'play', phaseT = 0, simT = 0, toNext = 10, openLeft = 0, falling = [], doubleNext = false, holdUntil = 0, speedUntil = 0;
  let likeGoal = 0, viewGoal = 0, lastStats = null, nextEvent = 0, barA = 0, final10 = false, finalSaid = false;
  const tags = new Map();                // country -> { name, until, kind }

  const ball = (code, x, y) => { const a = Math.random() * Math.PI * 2; return { code, x, y, vx: Math.cos(a) * SPEED, vy: Math.sin(a) * SPEED, hot: 0 }; };
  const freeSpot = () => {
    for (let t = 0; t < 400; t++) {
      const a = Math.random() * Math.PI * 2, d = RING + R + Math.random() * (RW - BAR_LEN * 0.4 - RING - 2 * R);
      const x = CX + Math.cos(a) * d, y = CY + Math.sin(a) * d;
      if (balls.every((b) => Math.hypot(b.x - x, b.y - y) > 2 * R + 4)) return [x, y];
    }
    const a = Math.random() * Math.PI * 2; return [CX + Math.cos(a) * (RW - R - 4), CY + Math.sin(a) * (RW - R - 4)];
  };

  const chooseTeams = () => {
    const want = Object.entries(S.want || {}).sort((a, b) => b[1] - a[1]).map(([k]) => k).filter((k) => byCode.has(k));
    const list = [];
    for (const c of [...CORE, ...want]) if (byCode.has(c) && !list.includes(c) && list.length < COUNT) list.push(c);
    for (const c of shuffle(C.map((x) => x.code))) { if (list.length >= COUNT) break; if (!list.includes(c)) list.push(c); }
    S.want = {};
    return list;
  };

  const newRound = () => {
    S.round++;
    teams = chooseTeams(); alive = new Set(teams); out = []; balls = []; shield = new Map(); revived = new Set(); revLeft = cfg.revives;
    power = new Map(); prog = new Map(); fans = new Map(); tags.clear();
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
    toNext = L.toNext; power = new Map(L.power); fans = new Map(L.fans.map(([k, a]) => [k, new Map(a)])); holdUntil = L.hold; likeGoal = L.likeGoal; viewGoal = L.viewGoal;
    simT = 0; nextEvent = 120 + Math.random() * 120; final10 = alive.size <= 10; finalSaid = alive.size <= 2;
    balls = []; for (const c of alive) { const [x, y] = freeSpot(); balls.push(ball(c, x, y)); }
  };

  /* ---------------------------------------------------------------- sound + screen helpers */
  const sfx = new ArenaSfx();
  const soundBtn = $('sound-btn');
  const unlock = () => { sfx.resume(); soundBtn.hidden = true; };
  soundBtn.addEventListener('click', unlock); addEventListener('pointerdown', unlock); sfx.resume();
  setTimeout(() => { if (sfx.ok && !sfx.running) soundBtn.hidden = false; }, 1500);
  const report = reporter('flags');
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
    revived.add(code); alive.add(code); out = out.filter((c) => c !== code);
    const [x, y] = freeSpot(); const b = ball(code, x, y); b.hot = 4; balls.push(b);
    popAt('rev', code, 'REVIVED!', NAME(code), x, y - 60);
    tags.set(code, { name: by || '', until: simT + 12, kind: 'rev' });
    banner(`${NAME(code)} IS BACK!`, by ? `REVIVED BY ${by} & FRIENDS` : 'REVIVED!', 'gold', '♻️');
    sfx.revive();
    host([{ node: flagNode(code) }, NAME(code) + ' IS BACK IN THE ARENA! THE CHAT REVIVED IT', ...(by ? [' — THANK YOU ', { name: by }] : []), '!'], 3);
    save(); updateUI();
    return true;
  };
  const onFan = (code, name) => {
    if (phase !== 'play') return;
    if (!teams.includes(code)) { S.want[code] = (S.want[code] || 0) + 1; return; }
    power.set(code, (power.get(code) || 0) + 1);
    addFan(code, name);
    prog.set(code, (prog.get(code) || 0) + 1);
    const b = balls.find((x) => x.code === code);
    if (b) b.hot = 3;
    tags.set(code, { name, until: simT + 6, kind: 'fan' });
    if (alive.has(code) && !shield.has(code) && prog.get(code) >= cfg.shield) { prog.set(code, 0); giveShield(code, name, 'SHIELD ON'); }
    else if (!alive.has(code) && prog.get(code) >= cfg.revive) { if (revive(code, name)) prog.set(code, 0); }
  };
  const statusLine = (code) => {
    if (!teams.includes(code)) return `IT JOINS THE NEXT ROUND — KEEP COMMENTING ${NAME(code)}!`;
    if (!alive.has(code)) {
      if ((!NOLIMIT && revLeft <= 0) || alive.size <= 2) return 'NO REVIVES LEFT THIS ROUND — CHEER FOR IT NEXT ROUND!';
      return `IT IS OUT! ${Math.max(0, cfg.revive - (prog.get(code) || 0))} MORE CHATS AND IT COMES BACK!`;
    }
    if (shield.has(code)) return 'IT HAS A SHIELD — SAFE FOR THE NEXT DRAW!';
    return `${Math.max(0, cfg.shield - (prog.get(code) || 0))} MORE CHATS FOR A SHIELD!`;
  };

  const isGreeting = (s) => /\b(hi|hii+|hello|hey|salam|salaam|assalam\w*|asalam\w*)\b|হাই|হ্যালো|সালাম|আসসালামু/i.test(s);
  const isSalam = (s) => /salam|salaam|assalam|asalam|সালাম|আসসালামু/i.test(s);
  const chat = liveChat({
    game: 'flags', rows: 3, alwaysOn: true,
    match: countryMatcher(C),
    badge: (code) => flagNode(code, 32),
    duck: duckAll,
    sfx: () => { sfx.tone({ freq: 784, type: 'sine', dur: 0.18, vol: 0.09 }); sfx.tone({ freq: 1175, type: 'sine', dur: 0.3, vol: 0.08, delay: 0.14 }); },
    onFan,
    onSub: (name, code) => {
      const c = code && alive.has(code) && !shield.has(code) ? code : [...power.entries()].sort((a, b) => b[1] - a[1]).map(([k]) => k).find((k) => alive.has(k) && !shield.has(k));
      if (c) giveShield(c, name.toUpperCase().slice(0, 14), 'FREE SHIELD · SUBSCRIBER');
    },
    onSuper: (code, name) => {
      if (!code || !teams.includes(code)) return;
      if (!alive.has(code)) revive(code, name, true); else giveShield(code, name, 'SUPER CHAT SHIELD');
    },
    onStats: (st) => { lastStats = st; },
    emptyHint: 'Type your country — 20 chats give it a SHIELD!',
    hints: ['TYPE YOUR COUNTRY — ENOUGH CHATS GIVE IT A SHIELD!', 'LIKE IF YOU SEE YOUR COUNTRY FLAG!', 'SUBSCRIBE AND YOUR COUNTRY GETS A FREE SHIELD!'],
    idleLines: [
      [`TYPE YOUR COUNTRY IN CHAT! ${cfg.shield} CHATS GIVE IT A SHIELD AGAINST THE BLACK HOLE!`],
      ['LIKE IF YOU SEE YOUR COUNTRY FLAG IN THE ARENA!'],
      ['SUBSCRIBE AND YOUR COUNTRY GETS A FREE SHIELD — RIGHT NOW!'],
      ['YOUR COUNTRY IS NOT IN THE ARENA? COMMENT IT AND IT JOINS THE NEXT ROUND!'],
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
        return top ? [{ node: flagNode(top[0]) }, NAME(top[0]) + ' HAS THE MOST CHAT POWER, ', { name }, '! TYPE YOURS TO BEAT IT!'] : null;
      }
      if (isGreeting(text)) return [isSalam(text) ? 'WA ALAIKUM ASSALAM ' : 'HELLO AGAIN ', { name }, '! WHICH COUNTRY ARE YOU SAVING?'];
      return null;
    },
    demoTexts: ['Bangladesh 🇧🇩', 'India!', 'Brasil 🔥', 'Pakistan', 'hello', 'USA USA', 'who will win?', 'Nepal', 'Egypt', 'বাংলাদেশ', 'Indonesia', 'Mexico', 'nice game', 'Morocco'],
  });
  const host = (parts, prio = 2) => { if (chat.active) chat.say(parts, prio); };
  { // LUNA's box is small here: make a long line smaller until it fits
    const rbt = document.querySelector('.lc-flags .lc-rb-text');
    if (rbt) new MutationObserver(() => {
      rbt.style.fontSize = '';
      for (let size = 26; rbt.scrollHeight > rbt.clientHeight + 2 && size > 16; size -= 1) rbt.style.fontSize = size + 'px';
    }).observe(rbt, { childList: true });
  }

  /* ---------------------------------------------------------------- the draw (black hole) */
  // A flag falls when at least half of it is over the open black hole (its centre inside the hole).
  const holeNow = () => HOLE * (openLeft > OPEN ? 1 : openness(openLeft));

  // Only ONE flag falls each time the hole opens (the one nearest the middle), like the big arena channels.
  let tookOne = false, shakeT = 0;
  const catchFlags = () => { // the hole is open: flags over it fall in (a shield bounces its flag out instead)
    if (tookOne) return;
    const near = balls.filter((b) => !b.gone && Math.hypot(b.x - CX, b.y - CY) <= holeNow())
      .sort((p, q) => Math.hypot(p.x - CX, p.y - CY) - Math.hypot(q.x - CX, q.y - CY));
    for (const b of near) {
      if (shield.has(b.code)) {
        shield.delete(b.code);
        popAt('shd', b.code, 'SHIELD SAVED', NAME(b.code), b.x, b.y - 70);
        host(['THE SHIELD SAVED ', { node: flagNode(b.code) }, NAME(b.code) + '! TYPE IT AGAIN FOR A NEW SHIELD!'], 2);
        const a = Math.random() * Math.PI * 2; b.x = CX + Math.cos(a) * (HOLE + R + 6); b.y = CY + Math.sin(a) * (HOLE + R + 6);
        b.vx = Math.cos(a) * SPEED * 1.6; b.vy = Math.sin(a) * SPEED * 1.6;
        continue;
      }
      if (alive.size - falling.length <= 1) return;   // never take the last flag
      b.gone = true; falling.push({ b, t: 0, x0: b.x, y0: b.y });
      sfx.draw(); tookOne = true;
      return;
    }
  };

  const eliminate = (b) => {
    balls = balls.filter((x) => x !== b);
    sfx.gone(); flashT = 0.35; shakeT = 0.3;   // flash + boom + a light shake   // a short white flash, like a camera
    alive.delete(b.code); out.push(b.code); prog.set(b.code, 0);
    const canRev = (NOLIMIT || revLeft > 0) && alive.size > 2;
    popAt('out', b.code, 'ELIMINATED', NAME(b.code), CX - 150, CY + 120);
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
    $('win-sub').textContent = `ROUND ${S.round} · ${COUNT} COUNTRIES`;
    $('win-heroes').innerHTML = top.length ? '🏅 TOP SUPPORTERS<b></b>' : '';
    if (top.length) $('win-heroes').querySelector('b').textContent = top.join(' · ');
    $('win').hidden = false; $('banner').hidden = true; sfx.win();
    host(['WE HAVE A CHAMPION! ', { node: flagNode(code) }, NAME(code) + ` WINS ROUND ${S.round}!`, ...(top.length ? [' THANK YOU ', ...top.flatMap((n, i) => [{ name: n }, i < top.length - 1 ? ', ' : ''])] : []), '! A NEW ROUND STARTS SOON!'], 3);
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
  const barSeg = (k) => { // from the wall inwards, a little slanted
    const a = barA + k * Math.PI * 2 / BARS, a2 = a + BAR_TILT, rIn = RW - BAR_LEN;
    return [CX + Math.cos(a) * (RW - 4), CY + Math.sin(a) * (RW - 4), CX + Math.cos(a2) * rIn, CY + Math.sin(a2) * rIn, a];
  };
  const step = (dt) => {
    simT += dt;
    barA += dt * 0.22;
    const sp = SPEED * (speedUntil > simT ? 1.8 : 1);
    for (const b of balls) {
      if (b.gone) continue;
      b.x += b.vx * dt; b.y += b.vy * dt;
      // wall
      let dx = b.x - CX, dy = b.y - CY, d = Math.hypot(dx, dy);
      if (d > RW - R) { const nx = dx / d, ny = dy / d, v = b.vx * nx + b.vy * ny; if (v > 0) { b.vx -= 2 * v * nx; b.vy -= 2 * v * ny; sfx.knock(); } b.x = CX + nx * (RW - R); b.y = CY + ny * (RW - R); }
      // turning bars
      for (let k = 0; k < BARS; k++) {
        const [x1, y1, x2, y2] = barSeg(k);
        const ex = x2 - x1, ey = y2 - y1, L2 = ex * ex + ey * ey;
        const t = clamp(((b.x - x1) * ex + (b.y - y1) * ey) / L2, 0, 1);
        const px = x1 + ex * t, py = y1 + ey * t;
        dx = b.x - px; dy = b.y - py; d = Math.hypot(dx, dy);
        if (d < R + BAR_W && d > 0) {
          const nx = dx / d, ny = dy / d;
          // the bar point moves with the turn: push the ball along
          const bvx = -(py - CY) * 0.22, bvy = (px - CX) * 0.22;
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
  const drawBall = (b) => {
    const s = b.scale ?? 1, r = R * s;
    const plain = PLAIN[b.code], i = byCode.get(b.code);
    c.save(); c.translate(b.x, b.y); if (b.spin) c.rotate(b.spin);
    if (shield.has(b.code)) { c.globalAlpha = 0.45 + 0.2 * Math.sin(simT * 6); c.fillStyle = '#7dd3fc'; c.beginPath(); c.arc(0, 0, r + 12, 0, 7); c.fill(); c.globalAlpha = 1; }
    if (b.hot > 0) { c.globalAlpha = Math.min(1, b.hot) * 0.7; c.fillStyle = '#ffc93a'; c.beginPath(); c.arc(0, 0, r + 9, 0, 7); c.fill(); c.globalAlpha = 1; }
    c.beginPath(); c.arc(0, 0, r, 0, 7); c.closePath(); c.fillStyle = plain ? plain[0] : '#fff'; c.fill();
    c.save(); c.clip();
    if (plain) { c.fillStyle = '#fff'; c.font = `700 ${Math.round(r * 0.7)}px Oswald, Rajdhani, sans-serif`; c.textAlign = 'center'; c.textBaseline = 'middle'; c.fillText(plain[1], 0, 1); }
    else c.drawImage(atlas, (i % COLS_A) * FW, Math.floor(i / COLS_A) * FH, FW, FH, -r * 1.36, -r, r * 2.72, r * 2);
    c.restore();
    c.lineWidth = 4; c.strokeStyle = shield.has(b.code) ? '#7dd3fc' : b.hot > 0 ? '#ffc93a' : '#fff'; c.beginPath(); c.arc(0, 0, r, 0, 7); c.stroke();
    c.restore();
  };
  const draw = () => {
    if (shakeT > 0) { // the whole arena shakes for a moment when a flag is eliminated
      const m = 7 * Math.min(1, shakeT / 0.2);
      cv.style.translate = `${(Math.random() * 2 - 1) * m}px ${(Math.random() * 2 - 1) * m}px`;
      shakeT -= 0.033; if (shakeT <= 0) cv.style.translate = '';
    }
    c.setTransform(k, 0, 0, k, 0, 0);
    c.clearRect(0, 0, W, H);
    // arena floor + wall
    const g = c.createRadialGradient(CX, CY, 40, CX, CY, RW); g.addColorStop(0, '#1d2747'); g.addColorStop(1, '#0b1026');
    c.fillStyle = g; c.beginPath(); c.arc(CX, CY, RW + 6, 0, 7); c.fill();
    c.lineWidth = 12; c.strokeStyle = '#22d3ee'; c.shadowColor = '#22d3ee'; c.shadowBlur = 30; c.beginPath(); c.arc(CX, CY, RW + 6, 0, 7); c.stroke(); c.shadowBlur = 0;
    // bars
    c.lineCap = 'round'; c.lineWidth = BAR_W * 2; c.strokeStyle = '#8b7cf6';
    for (let i = 0; i < BARS; i++) { const [x1, y1, x2, y2] = barSeg(i); c.beginPath(); c.moveTo(x1, y1); c.lineTo(x2, y2); c.stroke(); }
    // the hole: a gold ring with iris blades; when it opens the ring turns pink and the blades turn open
    const open = openLeft > 0 && phase === 'play', lock = simT < holdUntil && phase === 'play';
    irisO = open ? (openLeft > OPEN ? 1 : openness(openLeft)) : 0;
    irisRot += open ? 0.12 : 0.004;
    c.fillStyle = 'rgba(95,90,170,.55)'; c.beginPath(); c.arc(CX, CY, RING, 0, 7); c.fill();
    const inner = Math.max(4, HOLE * irisO);   // same size as the catch area
    for (let q = 0; q < 8; q++) {
      const a0 = irisRot + q * Math.PI / 4, a1 = a0 + Math.PI / 4;
      c.fillStyle = q % 2 ? 'rgba(130,120,200,.55)' : 'rgba(105,95,180,.55)';
      c.beginPath(); c.arc(CX, CY, RING - 4, a0, a1); c.arc(CX, CY, inner, a1 + irisO * 0.6, a0 + irisO * 0.6, true); c.closePath(); c.fill();
    }
    if (irisO > 0.02) {
      const hg = c.createRadialGradient(CX, CY, 2, CX, CY, inner + 6); hg.addColorStop(0, '#000'); hg.addColorStop(0.8, '#05000a'); hg.addColorStop(1, 'rgba(0,0,0,0)');
      c.fillStyle = hg; c.beginPath(); c.arc(CX, CY, inner + 6, 0, 7); c.fill();
    }
    c.lineWidth = 6; c.strokeStyle = lock ? '#22d3ee' : open ? '#ff4fd8' : '#f5c33b';
    c.shadowColor = c.strokeStyle; c.shadowBlur = open ? 26 : 10; c.beginPath(); c.arc(CX, CY, RING, 0, 7); c.stroke(); c.shadowBlur = 0;
    if (lock) { c.fillStyle = '#7dd3fc'; c.font = '700 20px Oswald, sans-serif'; c.textAlign = 'center'; c.textBaseline = 'middle'; c.fillText('🔒 ' + Math.ceil(holdUntil - simT) + 's', CX, CY + RING + 18); }
    // flags (falling ones on top)
    for (const b of balls) if (!b.gone) drawBall(b);
    for (const f of falling) drawBall(f.b);
    if (flashT > 0) { c.fillStyle = `rgba(255,255,255,${Math.min(0.55, flashT * 1.8)})`; c.beginPath(); c.arc(CX, CY, RW + 6, 0, 7); c.fill(); flashT -= 0.033; }
    // names over shielded / cheered flags
    c.font = '700 20px Rajdhani, Oswald, sans-serif'; c.textAlign = 'center'; c.textBaseline = 'middle';
    for (const b of balls) {
      const t = tags.get(b.code); if (!t || t.until < simT || !t.name || b.gone) continue;
      const label = (t.kind === 'shield' ? '🛡️ ' : t.kind === 'rev' ? '♻️ ' : '★ ') + t.name, tw = c.measureText(label).width + 18, y = b.y - R - 18;
      c.fillStyle = t.kind === 'shield' ? '#7dd3fc' : t.kind === 'rev' ? '#f9a8d4' : '#ffc93a'; c.beginPath(); c.roundRect(b.x - tw / 2, y - 14, tw, 28, 14); c.fill();
      c.fillStyle = '#10131f'; c.fillText(label, b.x, y + 1);
    }
  };

  /* ---------------------------------------------------------------- panels */
  const updateUI = () => {
    $('round').textContent = `ROUND ${S.round} · LIVE 24/7`;
    $('n-all').textContent = COUNT; $('n-left').textContent = alive.size;
    const top = [...power.entries()].sort((a, b) => b[1] - a[1]).slice(0, 3);
    $('power-rows').innerHTML = top.length
      ? top.map(([code, n], i) => `<div class="prow">${i + 1}. ${flagHTML(code, 40)}<span class="nm">${NAME(code)}</span><b>${n}</b></div>`).join('')
      : `<div class="prow empty">Type your country in chat — the top 3 show here!</div>`;
    $('rev-left').textContent = NOLIMIT ? '♾️ REVIVE: NO LIMIT' : `REVIVES LEFT ${revLeft}/${cfg.revives}`;
    $('grave').innerHTML = out.slice(-18).reverse().map((code) => `<span class="gf">${flagHTML(code, 52)}</span>`).join('');
    $('prog').style.width = (out.length / Math.max(1, COUNT - 1) * 100) + '%';
    $('out-count').textContent = `${out.length} / ${COUNT} ELIMINATED`;
  };
  const updateClock = () => {
    const el = $('clock'), lock = simT < holdUntil && phase === 'play';
    const gap = CYCLE, left = lock ? holdUntil - simT : openLeft > 0 ? 0 : Math.max(0, toNext);
    el.classList.toggle('lock', lock); el.classList.toggle('hot', !lock && left <= 3 && phase === 'play');
    $('clock-n').textContent = phase !== 'play' ? '♛' : lock ? '🔒' : openLeft > 0 ? '!' : Math.ceil(left);
    $('clock-arc').style.strokeDashoffset = String(264 * (1 - clamp(left / (lock ? 30 : gap), 0, 1)));
  };

  /* ---------------------------------------------------------------- settings from the panel */
  setInterval(async () => {
    const n = await fetchConfig();
    if (!n) return;
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
  autoUpdate('flags', save);   // after a deploy: reload with the game where it was (no Restart needed)
  window.__arena = { cfg, S, alive: () => alive, out: () => out, balls: () => balls, shield, phase: () => phase, simT: () => simT, toNext: () => toNext, onFan, revive, chat };
}

main();
