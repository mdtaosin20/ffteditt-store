// FFTEdit · Flag Race. Ten country flags race up the track to the finish line; every live-chat comment with a
// country gives it a BOOST, a new subscriber a TURBO, a Super Chat a MEGA TURBO. A race lasts about a minute,
// then the podium, then the next race — countries asked for in chat (and the ones chatting most) get the lanes.
// Made for 24/7 vertical live streams with LUNA as host.
import { Sfx, holdScreen, PACE, freshRun } from '/game/audio.js?v=13';
import { liveChat, countryMatcher } from '/game/chat.js?v=24';
import { goLive } from '/game/golive.js?v=2';
import { autoUpdate } from '/game/update.js?v=1';

const $ = (id) => document.getElementById(id);
const QS = new URLSearchParams(location.search);
const clamp = (v, a, b) => Math.max(a, Math.min(b, v));
const FAST = clamp(Number(QS.get('fast')) || 1, 1, 50);   // testing only
const pick = (a) => a[Math.floor(Math.random() * a.length)];
const shuffle = (a) => { for (let i = a.length - 1; i > 0; i--) { const j = Math.floor(Math.random() * (i + 1)); [a[i], a[j]] = [a[j], a[i]]; } return a; };

const DEF = { title: 'FLAG RACE', racers: 10, secs: 60, boost: 100, rev: '', reset: '' };
// the world's biggest countries (and the ones whose people watch these lives most): used to fill free lanes
const BIG = ['in', 'us', 'id', 'pk', 'br', 'tr', 'gb', 'pl', 'dz', 'eg', 'bd', 'ng', 'mx', 'de', 'ma', 'jp', 'ca', 'ph', 'ru', 'fr',
  'sa', 'it', 'es', 'ar', 'vn', 'th', 'kr', 'co', 'ir', 'iq', 'my', 'np', 'za', 'ke', 'ua', 'au', 'gr', 'se', 'nl', 'pt'];
// flags with holy writing are shown as a plain colour ball (like in the other games)
const PLAIN = { sa: ['#006c35', 'KSA'], af: ['#007a36', 'AFG'], iq: ['#ce1126', 'IRQ'] };
const COOLDOWN = 1.2;       // seconds: the same viewer boosts the same country once per this time
const COUNT_SECS = 3;       // 3, 2, 1, GO
const WIN_SECS = 8;         // podium on screen
const FINISH_Y = 60, START_Y = 606;   // ball centre in the track (px from its top)

async function fetchConfig() {
  try { const r = await fetch('/api/race.php', { cache: 'no-store' }); if (r.ok) return await r.json(); } catch (e) { /* offline */ }
  return null;
}
function fitStage() {
  const vw = document.documentElement.clientWidth || innerWidth, vh = document.documentElement.clientHeight || innerHeight;
  $('stage').style.transform = `translate(-50%, -50%) scale(${Math.min(vw / 1080, vh / 1920)})`;
}
const fmt = (s) => { s = Math.max(0, Math.ceil(s)); return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0'); };
const ORD = ['1ST', '2ND', '3RD'];
const ord = (i) => ORD[i] || (i + 1) + 'TH';

class RaceSfx extends Sfx {
  boost() { if (!this.running || !this.limit('boost', 90)) return; this.tone({ freq: 420, to: 880, type: 'sawtooth', dur: 0.14, vol: 0.05 }); }
  turbo() { if (!this.running) return; this.tone({ freq: 200, to: 1400, type: 'sawtooth', dur: 0.6, vol: 0.08 }); this.tone({ freq: 300, to: 1800, type: 'triangle', dur: 0.6, vol: 0.06, delay: 0.05 }); }
  beep(hi) { if (!this.running) return; this.tone({ freq: hi ? 1320 : 660, type: 'square', dur: hi ? 0.45 : 0.18, vol: 0.07 }); }
  lead() { if (!this.running || !this.limit('lead', 1500)) return; this.tone({ freq: 880, type: 'triangle', dur: 0.12, vol: 0.06 }); this.tone({ freq: 1175, type: 'triangle', dur: 0.16, vol: 0.06, delay: 0.1 }); }
}

async function main() {
  fitStage(); addEventListener('resize', fitStage);
  const [remote, atlasInfo] = await Promise.all([fetchConfig(), fetch('/flags/flags.json?v=2').then((r) => r.json())]);
  const cfg = { ...DEF, ...(remote || {}) };
  if (cfg.autoplay === false) { holdScreen('/api/race.php', cfg.rev); return; }
  const pace = PACE[cfg.pace] || 1;
  let popular = Array.isArray(cfg.popular) ? cfg.popular : [];   // [[code, people]] whose people chat in our lives

  const C = atlasInfo.countries, COLS_A = atlasInfo.cols, ROWS_A = Math.ceil(C.length / COLS_A);
  const byCode = new Map(C.map((c, i) => [c.code, i]));
  const NAME = (code) => C[byCode.get(code)].name.toUpperCase().replace('UNITED ARAB EMIRATES', 'UAE').replace('UNITED KINGDOM', 'UK').replace('UNITED STATES', 'USA').replace('CENTRAL AFRICAN REP.', 'C. AFRICA');
  const flagCss = (code, w) => { const i = byCode.get(code), h = w * 0.75; return `width:${w}px;height:${h}px;background-size:${COLS_A * w}px ${ROWS_A * h}px;background-position:${-(i % COLS_A) * w}px ${-Math.floor(i / COLS_A) * h}px`; };
  const flagHTML = (code, w) => `<span class="flag" style="${flagCss(code, w)}"></span>`;
  const flagNode = (code, w = 40) => { const d = document.createElement('span'); d.className = 'flag'; d.style.cssText = flagCss(code, w); return d; };
  const N = clamp(Math.round(cfg.racers) || 10, 6, 12);
  const SECS = clamp(Number(cfg.secs) || 60, 30, 180);
  const POWER = clamp(Number(cfg.boost) || 100, 20, 400) / 100;
  { const t = String(cfg.title || 'FLAG RACE').split(' '); $('title').innerHTML = t.length > 1 ? `${t[0]} <b>${t.slice(1).join(' ')}</b>` : t[0]; }
  $('n-racers').textContent = N;

  /* ---------- saved: race number, today's wins, countries asked for (a reload continues) ---------- */
  const KEY = 'fft_race';
  freshRun('race', [KEY, 'fft_fans_race']);
  const today = () => new Date().toISOString().slice(0, 10);
  let S = null;
  try { S = JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (e) { /* private mode */ }
  if (!S || S.reset !== cfg.reset) S = { reset: cfg.reset, race: 0, day: today(), wins: {}, races: 0, last: [], want: {} };
  const newDay = () => { if (S.day !== today()) { S.day = today(); S.wins = {}; S.races = 0; } };
  newDay();
  const save = () => { try { localStorage.setItem(KEY, JSON.stringify(S)); } catch (e) { /* ignore */ } };

  /* ---------- sound ---------- */
  const sfx = new RaceSfx();
  const soundBtn = $('sound-btn');
  const unlock = () => { sfx.resume(); soundBtn.hidden = true; };
  soundBtn.addEventListener('click', unlock); addEventListener('pointerdown', unlock); sfx.resume();
  setTimeout(() => { if (sfx.ok && !sfx.running) soundBtn.hidden = false; }, 1500);

  const banner = (html, cls = '', ms = 1200) => {
    const b = $('banner'), t = $('banner-text');
    t.className = 'banner-in ' + cls; t.innerHTML = html;
    b.hidden = false; t.style.animation = 'none'; void t.offsetWidth; t.style.animation = '';
    clearTimeout(banner.timer); banner.timer = setTimeout(() => { b.hidden = true; }, ms);
  };

  /* ---------- race state ---------- */
  let racers = [];                 // { code, p, f1, f2, a1, a2, boost, turboUntil, el, lane }
  let phase = 'count', phaseT = 0, simT = 0, leader = '', leadT = 0, said80 = false, nextCta = 0, finishOrder = [];
  const lastBoost = new Map();     // viewer|code -> time
  const heat = new Map();          // code -> { h, t } chats right now (also for countries not in the race)
  const hot = (c) => { const x = heat.get(c); return x ? x.h * Math.exp(-(simT - x.t) / 60) : 0; };
  const inRace = (code) => racers.some((r) => r.code === code);
  const rOf = (code) => racers.find((r) => r.code === code);
  const order = () => racers.slice().sort((a, b) => b.p - a.p);
  const placeOf = (code) => order().findIndex((r) => r.code === code);

  // Who races next: countries asked for in chat, then the ones chatting most right now, then the countries whose
  // people chat in our lives (a few at random so it changes), then big countries. The last winner defends its title.
  const chooseRacers = () => {
    const list = [];
    const add = (codes, max = N) => { for (const c of codes) { if (list.length >= max) break; if (byCode.has(c) && !list.includes(c)) list.push(c); } };
    if (S.last[0]) add([S.last[0]]);
    add(Object.entries(S.want || {}).sort((a, b) => b[1] - a[1]).map(([k]) => k));
    add([...heat.keys()].filter((c) => hot(c) > 0.3).sort((a, b) => hot(b) - hot(a)), Math.max(list.length, N - 3));
    add(shuffle(popular.slice(0, 24).map((x) => x[0])), N - 1);
    add(shuffle(BIG.slice(0, 30)));
    add(shuffle(C.map((x) => x.code)));
    S.want = {};
    return shuffle(list.slice(0, N));
  };

  const track = $('track'), lanesEl = $('lanes');
  let laneW = 100, ball = 84;
  const buildLanes = () => {
    const w = track.clientWidth;
    laneW = w / N; ball = Math.round(Math.min(92, laneW * 0.84));
    lanesEl.innerHTML = '';
    racers.forEach((r, i) => {
      const lane = document.createElement('div'); lane.className = 'lane';
      lane.style.cssText = `left:${i * laneW}px;width:${laneW}px`;
      const el = document.createElement('div'); el.className = 'runner';
      const pl = PLAIN[r.code];
      const inner = pl ? `<b class="plain" style="background:${pl[0]};font-size:${Math.round(ball * 0.32)}px">${pl[1]}</b>` : `<span class="flag" style="${flagCss(r.code, Math.round(ball / 0.75))};transform:translateX(-50%)"></span>`;
      el.innerHTML = `<div class="pos"></div><div class="ball" style="width:${ball}px;height:${ball}px">${inner}</div>`
        + `<div class="fire" style="top:${ball - 10}px;height:${Math.round(ball * 0.9)}px"></div><div class="nm">${NAME(r.code).slice(0, 12)}</div>`;
      lane.appendChild(el); lanesEl.appendChild(lane);
      r.el = el; r.posEl = el.querySelector('.pos'); r.lane = i;
      if (NAME(r.code).length > 9) el.querySelector('.nm').style.fontSize = '15px';
    });
  };

  const pop = (r, text, cls = '') => {
    const p = document.createElement('div'); p.className = 'pop ' + cls; p.textContent = text;
    const x = 26 + (r.lane + 0.5) * laneW, y = 512 + yOf(r) - ball;   // the track starts at x 22 / y 508 (+ its border)
    p.style.left = clamp(x - 90, 10, 880) + 'px'; p.style.top = y + 'px';
    $('pops').appendChild(p); setTimeout(() => p.remove(), 1300);
  };
  const yOf = (r) => START_Y - r.p * (START_Y - FINISH_Y);

  /* ---------- live chat + LUNA ---------- */
  const fanTally = new Map(), fanSeen = new Set();
  const tally = (code, author) => {
    const t = fanTally.get(code) || [0, 0]; t[0]++;
    const k = code + '|' + (author || '');
    if (author && !fanSeen.has(k)) { fanSeen.add(k); t[1]++; }
    fanTally.set(code, t);
  };
  if (QS.get('stream') && QS.get('sig')) setInterval(() => {   // tell the panel which countries the chat names
    if (!fanTally.size) return;
    const c = Object.fromEntries(fanTally); fanTally.clear();
    fetch('/api/fans.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, keepalive: true,
      body: JSON.stringify({ stream: Number(QS.get('stream')), sig: QS.get('sig'), game: 'race', c }) }).catch(() => {});
  }, 60000);

  const giveBoost = (r, amount) => { r.boost = Math.min(4, r.boost + amount / (1 + 0.25 * r.boost)); };
  const onFan = (code, name, author) => {
    tally(code, author);
    const h = heat.get(code); heat.set(code, { h: (h ? h.h * Math.exp(-(simT - h.t) / 60) : 0) + 1, t: simT });
    const r = rOf(code);
    if (!r) { S.want[code] = (S.want[code] || 0) + 1; return; }
    if (phase !== 'race') return;
    const k = (author || name || '') + '|' + code;
    if (simT - (lastBoost.get(k) || -1e9) < COOLDOWN) return;
    lastBoost.set(k, simT);
    giveBoost(r, 0.7 * POWER);
    sfx.boost();
    if (Math.random() < 0.5) pop(r, '⚡ ' + String(name || 'BOOST').toUpperCase().slice(0, 12));
  };
  const turbo = (code, name, mega) => {
    const r = rOf(code);
    if (!r) { if (code) S.want[code] = (S.want[code] || 0) + 3; return false; }
    r.turboUntil = Math.max(r.turboUntil, simT) + (mega ? 8 : 4);
    giveBoost(r, mega ? 4 : 2.5);
    sfx.turbo();
    pop(r, (mega ? '🚀🚀 MEGA TURBO ' : '🚀 TURBO ') + String(name || '').toUpperCase().slice(0, 12), 't');
    return true;
  };
  const statusLine = (code) => {
    if (!inRace(code)) return `IT JOINS THE NEXT RACE — KEEP TYPING ${NAME(code)}!`;
    if (phase !== 'race') return 'IT IS IN THE NEXT RACE — GET READY TO BOOST!';
    const i = placeOf(code);
    return i === 0 ? 'IT IS IN THE LEAD — KEEP BOOSTING!' : `IT IS ${ord(i)} — EVERY CHAT IS A BOOST!`;
  };
  const isSalam = (s) => /salam|salaam|assalam|asalam|সালাম|আসসালামু/i.test(s);
  const chat = liveChat({
    game: 'race', rows: 3, alwaysOn: true,
    aiInfo: (code) => `Game: FLAG RACE — ${N} country flags race up a track to the finish line, a race lasts about ${SECS} seconds, then a podium and the next race starts. `
      + (phase === 'race' && racers.length ? `Now leading: ${NAME(order()[0].code)}. ` : '') + (code ? `The viewer's country ${NAME(code)}: ${statusLine(code)} ` : '')
      + 'Rules: type your country in chat = a BOOST (more people typing = faster); subscribe = TURBO; Super Chat = MEGA TURBO; countries not in this race join the next race; today\'s wins are counted.',
    match: countryMatcher(C),
    badge: (code) => flagNode(code, 32),
    duck: (on) => sfx.duck(on),
    sfx: () => { sfx.tone({ freq: 784, type: 'sine', dur: 0.18, vol: 0.09 }); sfx.tone({ freq: 1175, type: 'sine', dur: 0.3, vol: 0.08, delay: 0.14 }); },
    onFan,
    onSub: (name, code) => {
      const c = code && inRace(code) ? code : order().slice(-1)[0]?.code;   // no country known: the last one gets it
      if (c && phase === 'race' && turbo(c, name, false)) host(['THANK YOU ', { name }, ' FOR SUBSCRIBING! ', { node: flagNode(c) }, NAME(c) + ' GETS A TURBO!'], 3);
    },
    onSuper: (code, name) => {
      if (code && phase === 'race' && turbo(code, name, true)) host([{ name }, ' — SUPER CHAT! ', { node: flagNode(code) }, NAME(code) + ' GETS A MEGA TURBO!'], 3);
    },
    emptyHint: 'Type your country — every chat is a BOOST!',
    hints: ['TYPE YOUR COUNTRY — EVERY CHAT IS A BOOST!', 'SUBSCRIBE = TURBO FOR YOUR COUNTRY!', 'LIKE IF YOUR COUNTRY IS RACING!'],
    idleLines: [
      ['TYPE YOUR COUNTRY IN CHAT! EVERY CHAT IS A BOOST — MORE PEOPLE, MORE SPEED!'],
      ['YOUR COUNTRY IS NOT RACING? TYPE IT AND IT JOINS THE NEXT RACE!'],
      ['SUBSCRIBE AND YOUR COUNTRY GETS A TURBO — RIGHT NOW!'],
      ['A NEW RACE EVERY MINUTE! WHICH COUNTRY WINS THE MOST RACES TODAY?'],
    ],
    welcome: (name, code, text) => {
      const hi = isSalam(text) ? 'WA ALAIKUM ASSALAM ' : 'WELCOME ';
      if (!code) return [hi, { name }, '! TYPE YOUR COUNTRY — EVERY CHAT IS A BOOST!'];
      return [hi, { name }, ' FROM ', { node: flagNode(code) }, NAME(code) + '! ' + statusLine(code)];
    },
    reply: (name, code, text) => {
      if (code) return [{ node: flagNode(code) }, NAME(code) + ', ', { name }, '! ' + statusLine(code)];
      if (/who.*(win|lead)|winner|কে জিতবে/i.test(text) && phase === 'race') {
        const t = order()[0];
        return [{ node: flagNode(t.code) }, NAME(t.code) + ' IS IN THE LEAD, ', { name }, '! TYPE YOUR COUNTRY TO CATCH IT!'];
      }
      if (/\b(hi|hii+|hello|hey)\b|salam|হাই|হ্যালো/i.test(text)) return [isSalam(text) ? 'WA ALAIKUM ASSALAM ' : 'HELLO ', { name }, '! WHICH COUNTRY ARE YOU BOOSTING?'];
      return null;
    },
    demoTexts: ['Türkiye 🇹🇷', 'Poland!', 'USA USA', 'Brasil 🔥', 'Algeria', 'Egypt', 'UK', 'India', 'Pakistan', 'hello', 'who will win?', 'Germany', 'Canada', 'Morocco', 'Japan', 'Indonesia', 'Bangladesh 🇧🇩'],
  });
  const host = (parts, prio = 2) => { if (chat.active) chat.say(parts, prio); };
  { // LUNA's box is small here: make a long line smaller until it fits
    const rbt = document.querySelector('.lc-race .lc-rb-text');
    if (rbt) new MutationObserver(() => {
      rbt.style.fontSize = '';
      for (let size = 26; rbt.scrollHeight > rbt.clientHeight + 2 && size > 16; size -= 1) rbt.style.fontSize = size + 'px';
    }).observe(rbt, { childList: true });
  }

  /* ---------- races ---------- */
  const newRace = () => {
    newDay();
    S.race++; save();
    racers = chooseRacers().map((code) => ({ code, p: 0, f1: 0.5 + Math.random(), f2: 1.3 + Math.random() * 1.4, a1: Math.random() * 7, a2: Math.random() * 7, boost: 0, turboUntil: 0 }));
    buildLanes();
    phase = 'count'; phaseT = 0; leader = ''; leadT = 0; said80 = false; finishOrder = []; lastBoost.clear();
    nextCta = simT + 20;
    $('win').hidden = true;
    $('race-no').textContent = `RACE #${S.race}`;
    $('pill-sub').textContent = '⚡ EVERY CHAT = BOOST';
    host([`RACE #${S.race}! ${N} COUNTRIES ON THE TRACK — TYPE YOUR COUNTRY: EVERY CHAT IS A BOOST!`], 3);
    updateUI(); draw();
  };

  const finish = (w) => {
    phase = 'win'; phaseT = 0;
    finishOrder = order().map((r) => r.code);
    newDay();
    S.wins[w.code] = (S.wins[w.code] || 0) + 1; S.races = (S.races || 0) + 1;
    S.last = [w.code, ...S.last.filter((c) => c !== w.code)].slice(0, 8);
    save();
    $('win-t').textContent = `🏆 RACE #${S.race} WINNER`;
    $('win-flag').style.cssText = PLAIN[w.code] ? `width:220px;height:165px;background:${PLAIN[w.code][0]}` : flagCss(w.code, 220);
    $('win-name').textContent = NAME(w.code);
    const [a, b, c] = finishOrder;
    const col = (code, cls, n) => code ? `<div class="${cls}"><div class="p">${n}</div>${flagHTML(code, 64)}<br>${NAME(code).slice(0, 12)}</div>` : '';
    $('pod').innerHTML = col(b, 's', 2) + col(a, 'g', 1) + col(c, 'b', 3);
    $('win-next').textContent = `${NAME(w.code)} HAS ${S.wins[w.code]} WIN${S.wins[w.code] > 1 ? 'S' : ''} TODAY · NEXT RACE IN ${WIN_SECS} … TYPE YOUR COUNTRY TO JOIN!`;
    $('win').hidden = false; $('banner').hidden = true;
    $('pill-sub').textContent = 'JOIN THE NEXT RACE';
    sfx.win();
    host([{ node: flagNode(w.code) }, NAME(w.code) + ` WINS RACE #${S.race}! ` + (b ? `${NAME(b)} SECOND, ` : '') + (c ? `${NAME(c)} THIRD! ` : '') + 'NEXT RACE IN A FEW SECONDS — TYPE YOUR COUNTRY TO JOIN!'], 3);
    updateUI();
  };

  /* ---------- screen ---------- */
  const updateUI = () => {
    const top = Object.entries(S.wins).sort((a, b) => b[1] - a[1]).slice(0, 3);
    $('wins').innerHTML = top.length
      ? top.map(([code, n], i) => `<div class="wrow">${i + 1}. ${flagHTML(code, 40)}<span class="nm">${NAME(code)}</span><b>${n} 🏆</b></div>`).join('')
      : '<div class="wrow empty">No winner yet today — type your country and win the first race!</div>';
    $('n-today').textContent = S.races || 0;
    $('last').innerHTML = S.last.slice(0, 8).map((c) => flagHTML(c, 48)).join('');
    const tm = $('tm');
    if (phase === 'count') { tm.textContent = 'GET READY'; tm.classList.remove('go'); }
    else if (phase === 'race') { const lead = order()[0]; tm.textContent = '⏱ ' + fmt((1 - (lead ? lead.p : 0)) * SECS); tm.classList.add('go'); }
    else { tm.textContent = '🏁 FINISH!'; tm.classList.remove('go'); }
  };

  const draw = () => {
    const ord_ = order();
    racers.forEach((r) => {
      const i = ord_.indexOf(r);
      r.el.style.transform = `translateY(${(yOf(r) - ball / 2).toFixed(1)}px)`;
      r.el.classList.toggle('lead', i === 0 && phase !== 'count');
      r.el.classList.toggle('boost', r.boost > 0.25 && phase === 'race');
      r.el.classList.toggle('turbo', r.turboUntil > simT && phase === 'race');
      r.posEl.textContent = phase === 'count' ? '' : i === 0 ? '👑' : String(i + 1);
    });
  };

  const step = (dt) => {
    simT += dt; phaseT += dt;
    if (phase === 'count') {
      const n = COUNT_SECS - Math.floor(phaseT);
      if (n !== step.lastN) {
        step.lastN = n;
        if (n > 0) { banner(String(n)); sfx.beep(false); }
        else { banner('GO!', 'go', 900); sfx.beep(true); phase = 'race'; phaseT = 0; }
      }
      return;
    }
    if (phase === 'win') { if (phaseT > WIN_SECS) { step.lastN = null; newRace(); } return; }
    // racing: each flag has its own changing pace (so the lead changes), boosts make it faster and fade in ~2.5 s
    const base = 1 / SECS;
    for (const r of racers) {
      const f = 1 + 0.22 * Math.sin(simT * r.f1 * 0.35 + r.a1) + 0.12 * Math.sin(simT * r.f2 * 0.35 + r.a2);
      const t = r.turboUntil > simT ? 1.2 : 0;
      r.p += base * f * (1 + r.boost + t) * dt;
      r.boost *= Math.exp(-dt / 2.5);
      if (r.p >= 1) { r.p = 1; finish(r); return; }
    }
    // LUNA during the race
    const lead = order()[0];
    if (lead.code !== leader) {
      if (leader && simT - leadT > 8) { host([{ node: flagNode(lead.code) }, NAME(lead.code) + ' TAKES THE LEAD! ' + pick(['TYPE YOUR COUNTRY TO CATCH IT!', 'CAN ANYONE STOP IT?', 'BOOST YOUR COUNTRY NOW!'])], 2); sfx.lead(); }
      leader = lead.code; leadT = simT;
    }
    if (!said80 && lead.p > 0.8) {
      said80 = true;
      const second = order()[1];
      banner('FINAL STRETCH!<small>TYPE YOUR COUNTRY NOW!</small>', 'gold long', 2600);
      host(['FINAL STRETCH! ', { node: flagNode(lead.code) }, NAME(lead.code) + ' IS ALMOST THERE — ' + (second ? NAME(second.code) + ' FANS, BOOST NOW!' : 'BOOST NOW!')], 3);
    }
    if (simT > nextCta) {
      nextCta = simT + 18 + Math.random() * 10;
      const last = order().slice(-1)[0];
      host(pick([
        ['EVERY CHAT IS A BOOST! TYPE YOUR COUNTRY AND WATCH IT FLY!'],
        [{ node: flagNode(last.code) }, NAME(last.code) + ' IS LAST! ' + NAME(last.code) + ' FANS, WHERE ARE YOU? TYPE IT NOW!'],
        ['SUBSCRIBE AND YOUR COUNTRY GETS A TURBO!'],
        ['YOUR COUNTRY IS NOT RACING? TYPE IT — IT JOINS THE NEXT RACE!'],
      ]), 1);
    }
  };

  /* ---------- settings from the admin panel ---------- */
  setInterval(async () => {
    const n = await fetchConfig();
    if (!n) return;
    if (Array.isArray(n.popular)) popular = n.popular;
    if (n.reset !== cfg.reset) { try { localStorage.removeItem(KEY); } catch (e) { /* ignore */ } location.reload(); return; }
    if (n.rev !== cfg.rev) cfg.pendingReload = true;
  }, 20000);

  /* ---------- loop ---------- */
  newRace();
  let last = performance.now(), lastUI = 0;
  const frame = (now) => {
    const dt = Math.min(0.1, (now - last) / 1000) * FAST * pace; last = now;
    const sub = Math.max(1, Math.ceil(dt / 0.05));
    for (let i = 0; i < sub; i++) step(dt / sub);
    if (cfg.pendingReload && phase === 'win') { save(); location.reload(); }
    draw();
    if (now - lastUI > 250) { updateUI(); lastUI = now; }
    requestAnimationFrame(frame);
  };
  requestAnimationFrame(frame);
  autoUpdate('race', save);   // after a deploy: reload with the game where it was (no Restart needed)
  goLive({ left: 40, top: 1020, size: 200, duck: (on) => sfx.duck(on) });   // the owner joins the live from a phone (Admin → Join Live)
  window.__race = { cfg, S, racers: () => racers, phase: () => phase, onFan, turbo, chat };
}

main();
