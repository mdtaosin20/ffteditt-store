// FFTEdit · live chat on the stream: chat box, top fans, new-subscriber banner and the robot host.
// The VPS worker reads the YouTube live chat → the panel → /api/chat.php → this module.
// Add ?chatdemo=1 to a game address to see it with made-up messages.
//
//   const chat = liveChat({ game: 'flags' | 'ball', ...hooks })
//
// All text from viewers is put on screen with textContent (never as HTML).

const QS = new URLSearchParams(location.search);
const el = (tag, cls, text) => { const e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; };
const pick = (a) => a[Math.floor(Math.random() * a.length)];
const cleanName = (n) => String(n || '').replace(/^@+/, '').replace(/[-_.]+(?=[a-z0-9]*\d)[a-z0-9]{2,6}$/i, '').trim().slice(0, 18) || 'FRIEND';

const ROBOT_SVG = `<svg viewBox="0 0 120 140" aria-hidden="true">
  <line x1="60" y1="6" x2="60" y2="26" stroke="#9ff5c8" stroke-width="5"/><circle cx="60" cy="8" r="7" fill="#46e39a" class="rb-led"/>
  <rect x="10" y="26" width="100" height="78" rx="26" fill="#dfe8f2" stroke="#46e39a" stroke-width="5"/>
  <rect x="22" y="40" width="76" height="48" rx="18" fill="#0d1726"/>
  <g class="rb-eyes"><circle cx="44" cy="60" r="9" fill="#46e39a"/><circle cx="76" cy="60" r="9" fill="#46e39a"/></g>
  <rect class="rb-mouth" x="46" y="74" width="28" height="7" rx="3.5" fill="#46e39a"/>
  <rect x="2" y="52" width="10" height="26" rx="5" fill="#46e39a"/><rect x="108" y="52" width="10" height="26" rx="5" fill="#46e39a"/>
  <rect x="30" y="110" width="60" height="24" rx="12" fill="#c6d2e0" stroke="#46e39a" stroke-width="4"/>
</svg>`;

/* ---------------------------------------------------------------- country words */
const ALIAS = {
  bd: ['bangladesh', 'bangla', 'bd', 'ban', 'বাংলাদেশ', 'bangladeshi'], in: ['india', 'bharat', 'ind', 'ভারত', 'indian', 'hindustan'],
  pk: ['pakistan', 'pak', 'পাকিস্তান', 'pakistani'], us: ['usa', 'america', 'united states', 'us of a', 'american'],
  gb: ['uk', 'united kingdom', 'england', 'britain', 'great britain', 'scotland', 'wales'], ae: ['uae', 'emirates', 'dubai'],
  sa: ['saudi', 'ksa', 'saudi arabia', 'সৌদি'], tr: ['turkey', 'turkiye', 'türkiye', 'turkish'], br: ['brazil', 'brasil', 'bra'],
  ar: ['argentina', 'arg'], de: ['germany', 'deutschland', 'ger'], fr: ['france', 'fra'], es: ['spain', 'espana', 'españa'],
  pt: ['portugal', 'por'], nl: ['netherlands', 'holland'], kr: ['south korea', 'korea'], kp: ['north korea'],
  ru: ['russia', 'rus'], cn: ['china', 'chn'], jp: ['japan', 'jpn'], id: ['indonesia', 'indo'], my: ['malaysia'],
  ps: ['palestine', 'free palestine', 'ফিলিস্তিন'], ir: ['iran'], iq: ['iraq'], af: ['afghanistan', 'afg'], np: ['nepal', 'nepali', 'নেপাল'],
  lk: ['sri lanka', 'srilanka'], eg: ['egypt', 'masr'], ma: ['morocco', 'maroc'], dz: ['algeria'], ng: ['nigeria', 'naija'],
  ph: ['philippines', 'pinas', 'pilipinas'], vn: ['vietnam', 'viet nam'], th: ['thailand'], mx: ['mexico', 'méxico'],
  it: ['italy', 'italia'], cz: ['czechia', 'czech'], ci: ["cote d'ivoire", 'ivory coast'], cd: ['dr congo', 'drc'],
  cf: ['central african republic'], va: ['vatican'], tl: ['east timor', 'timor leste'], mm: ['myanmar', 'burma'],
  qa: ['qatar'], kw: ['kuwait'], om: ['oman'], bh: ['bahrain'], jo: ['jordan'], ye: ['yemen'], sy: ['syria'], lb: ['lebanon'],
  ua: ['ukraine'], pl: ['poland', 'polska'], ca: ['canada'], au: ['australia', 'aussie'], nz: ['new zealand'],
  za: ['south africa'], et: ['ethiopia'], ke: ['kenya'], gh: ['ghana'], so: ['somalia'], sd: ['sudan'], ss: ['south sudan'],
  mv: ['maldives'], bt: ['bhutan'], kz: ['kazakhstan'], uz: ['uzbekistan'], tj: ['tajikistan'], kg: ['kyrgyzstan'], tm: ['turkmenistan'],
  az: ['azerbaijan'], ge: ['georgia'], am: ['armenia'], gr: ['greece'], se: ['sweden'], no: ['norway'], dk: ['denmark'], fi: ['finland'],
  ch: ['switzerland'], at: ['austria'], be: ['belgium'], ie: ['ireland'], co: ['colombia'], cl: ['chile'], pe: ['peru'], ve: ['venezuela'],
  ec: ['ecuador'], uy: ['uruguay'], py: ['paraguay'], bo: ['bolivia'], cu: ['cuba'], ht: ['haiti'], jm: ['jamaica'],
};

/** Finds a country in a chat message: flag emoji, a name, or a short code typed alone ("BD"). */
export function countryMatcher(countries) {
  const norm = (x) => String(x || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
    .replace(/[^\p{L}\p{M}\p{N}\s]/gu, ' ').replace(/\s+/g, ' ').trim();
  const codes = new Set(countries.map((c) => c.code));
  const words = [], short = new Map();
  for (const c of countries) {
    const n = norm(c.name);
    if (n.length >= 4) words.push([n, c.code]);
    if (c.name.includes('&')) words.push([norm(c.name.replace('&', ' and ')), c.code]);
    for (const a of ALIAS[c.code] || []) { const w = norm(a); if (w.length <= 3) short.set(w, c.code); else words.push([w, c.code]); }
  }
  words.sort((a, b) => b[0].length - a[0].length);
  const isLatin = (s) => /^[a-z0-9 ]+$/.test(s);
  return (text) => {
    const raw = String(text || '');
    const fe = raw.match(/([\u{1F1E6}-\u{1F1FF}])([\u{1F1E6}-\u{1F1FF}])/u);
    if (fe) {
      const code = String.fromCharCode(fe[1].codePointAt(0) - 0x1F1E6 + 97, fe[2].codePointAt(0) - 0x1F1E6 + 97);
      if (codes.has(code)) return code;
    }
    const t = norm(raw);
    if (t.length <= 3) { // a short code typed alone: "bd", "IND"; plain two letters only in capitals ("NO" yes, "no" no)
      if (short.has(t)) return short.get(t);
      return codes.has(t) && /^[A-Z]{2}$/.test(raw.trim()) ? t : null;
    }
    const padded = ' ' + t + ' ';
    for (const [w, code] of words) if (isLatin(w) ? padded.includes(' ' + w + ' ') : t.includes(w)) return code;
    return null;
  };
}

/* ---------------------------------------------------------------- main */
export function liveChat(o) {
  const stream = QS.get('stream'), sig = QS.get('sig'), demo = QS.has('chatdemo');
  if (!demo && !(stream && sig)) return { active: false };

  const stage = document.getElementById('stage');
  const root = el('div', 'lc lc-' + o.game);
  const sub = el('div', 'lc-sub'); sub.hidden = true;
  const fans = el('div', 'lc-fans'); fans.hidden = true;
  const box = el('div', 'lc-box'); box.hidden = true;
  const robot = el('div', 'lc-robot'); robot.hidden = true;
  root.append(sub, fans, box, robot);
  stage.append(root);

  box.append(el('div', 'lc-box-h', 'LIVE CHAT'));
  const rows = el('div', 'lc-rows'); box.append(rows);
  const rbFace = el('div', 'lc-rb-face'); rbFace.innerHTML = ROBOT_SVG;
  const rbName = el('div', 'lc-rb-name', 'ROBO HOST');
  const rbText = el('div', 'lc-rb-text');
  const rbBody = el('div', 'lc-rb-body'); rbBody.append(rbName, rbText);
  robot.append(rbFace, rbBody);

  let cfg = { enabled: true, robot: true, name: 'ROBO HOST', box: true, subs: true, idle: true };
  const seen = new Set();          // authors already welcomed (this session)
  const counts = new Map();        // fan key -> number of comments
  const lastSaid = new Map();      // author -> time of the last robot answer to them
  let lastMsg = performance.now(), lastIdle = performance.now();

  /* ---------- chat box ---------- */
  const addRow = (name, text, key) => {
    const r = el('div', 'lc-row');
    r.append(el('b', '', cleanName(name) + ':'), el('span', 'lc-t', text.slice(0, 60)));
    if (key && o.badge) { const b = o.badge(key); if (b) r.append(b); }
    rows.append(r);
    while (rows.children.length > (o.rows || 4)) rows.firstChild.remove();
    rows.querySelector('.lc-empty')?.remove();
  };
  const emptyRow = () => { if (!rows.children.length) rows.append(el('div', 'lc-row lc-empty', o.emptyHint)); };
  emptyRow();

  /* ---------- top fans ---------- */
  const renderFans = () => {
    const top = [...counts.entries()].sort((a, b) => b[1] - a[1]).slice(0, o.fansMax || 4);
    if (!top.length) { fans.hidden = true; return; }
    fans.replaceChildren(el('span', 'lc-fans-k', o.fansLabel || 'TOP FANS:'));
    for (const [key, n] of top) {
      const it = el('span', 'lc-fan');
      const b = o.badge && o.badge(key); if (b) it.append(b);
      if (o.fanLabel) it.append(el('span', 'lc-fan-l', o.fanLabel(key)));
      it.append(el('b', '', String(n)));
      fans.append(it);
    }
    fans.hidden = false;
  };
  const DAY = new Date().toISOString().slice(0, 10), FKEY = 'fft_fans_' + o.game;
  try { const s = JSON.parse(localStorage.getItem(FKEY) || 'null'); if (s && s.day === DAY) for (const [k, v] of s.c) counts.set(k, v); } catch (e) { /* ignore */ }
  const saveFans = () => { try { localStorage.setItem(FKEY, JSON.stringify({ day: DAY, c: [...counts] })); } catch (e) { /* ignore */ } };
  renderFans();

  /* ---------- new subscriber banner ---------- */
  const subQ = [];
  let subBusy = false;
  const nextSub = () => {
    if (subBusy || !subQ.length) return;
    subBusy = true;
    const name = subQ.shift();
    sub.replaceChildren(el('span', 'lc-sub-bell', ''), el('span', 'lc-sub-k', 'NEW SUBSCRIBER: '), el('b', '', cleanName(name).toUpperCase()), el('span', 'lc-sub-t', ' — THANK YOU!'));
    sub.hidden = false; sub.classList.remove('in'); void sub.offsetWidth; sub.classList.add('in');
    o.sfx?.();
    setTimeout(() => { sub.hidden = true; subBusy = false; nextSub(); }, 6500);
  };

  /* ---------- robot host ---------- */
  const queue = [];
  let speaking = null, speakUntil = 0, pauseUntil = 0;
  const say = (parts, prio = 1) => {
    if (!cfg.robot) return;
    queue.push({ parts, prio, t: performance.now() });
    queue.sort((a, b) => b.prio - a.prio || a.t - b.t);
    if (queue.length > 5) queue.length = 5;          // never fall far behind the chat
  };
  const show = (line) => {
    rbName.textContent = cfg.name || 'ROBO HOST';
    rbText.replaceChildren();
    let len = 0;
    for (const p of line.parts) {
      if (typeof p === 'string') { rbText.append(document.createTextNode(p)); len += p.length; }
      else if (p.name) { rbText.append(el('em', '', cleanName(p.name).toUpperCase())); len += 10; }
      else if (p.node) rbText.append(p.node);
    }
    rbText.classList.toggle('long', len > 70);
    robot.hidden = false; robot.classList.remove('in'); void robot.offsetWidth; robot.classList.add('in', 'talk');
    speaking = line; speakUntil = performance.now() + Math.min(9000, 4200 + len * 45);
  };
  const tick = () => {
    const now = performance.now();
    if (speaking) {
      if (o.busy && o.busy()) { robot.hidden = true; speaking = null; pauseUntil = now + 800; } // winner screen: give way
      else if (now > speakUntil) { robot.hidden = true; robot.classList.remove('talk'); speaking = null; pauseUntil = now + 900; }
      else if (now > speakUntil - 1500) robot.classList.remove('talk');
    }
    if (!speaking && queue.length && now > pauseUntil && !(o.busy && o.busy())) {
      const line = queue.shift();
      if (now - line.t < 45000 || line.prio >= 3) show(line);
    }
    // quiet chat: ask people to join in
    if (cfg.idle && cfg.robot && !speaking && !queue.length && now - lastMsg > 70000 && now - lastIdle > 95000) {
      lastIdle = now;
      say(pick(o.idleLines).map((p) => p), 0);
    }
  };
  setInterval(tick, 250);

  /* ---------- one chat event ---------- */
  const handle = (ev) => {
    const name = ev.a;
    if (ev.k === 'sub') {
      if (cfg.subs) { subQ.push(name); nextSub(); }
      say([pick(['THANK YOU ', 'WELCOME TO THE FAMILY, ', 'BIG THANKS ']), { name }, pick([' FOR SUBSCRIBING!', '! YOU JUST SUBSCRIBED — YOU ARE AWESOME!', ' FOR THE SUBSCRIBE!'])], 3);
      return;
    }
    const text = ev.t || '';
    if (!ev.old) lastMsg = performance.now();
    const key = o.match ? o.match(text) : null;
    if (cfg.box) { addRow(name, text || (ev.k === 'member' ? 'joined as a member!' : ''), key); box.hidden = false; }
    if (ev.old) return;

    if (key) {
      counts.set(key, (counts.get(key) || 0) + 1); saveFans(); renderFans();
      o.onFan && o.onFan(key, cleanName(name));
    }
    const author = name.toLowerCase();
    const first = !seen.has(author);
    seen.add(author);
    const recent = performance.now() - (lastSaid.get(author) || -1e9) < 60000;

    let line = null, prio = 1;
    if (ev.k === 'super') { line = ['WOW! THANK YOU ', { name }, ` FOR THE SUPER CHAT${ev.amt ? ' ' + ev.amt : ''}! YOU ARE A LEGEND!`]; prio = 3; }
    else if (ev.k === 'member') { line = ['WELCOME TO THE CLUB, ', { name }, '! THANKS FOR BECOMING A MEMBER!']; prio = 3; }
    else if (first) { line = o.welcome(name, key, text); prio = 2; }
    else if (!recent) line = o.reply(name, key, text);
    if (line) { lastSaid.set(author, performance.now()); say(line, prio); }
  };

  /* ---------- polling ---------- */
  let after = 0, failures = 0;
  const poll = async () => {
    let wait = 2000;
    try {
      const r = await fetch(`/api/chat.php?stream=${encodeURIComponent(stream)}&sig=${encodeURIComponent(sig)}&after=${after}`, { cache: 'no-store' });
      if (r.ok) {
        const j = await r.json();
        cfg = { ...cfg, ...(j.cfg || {}) };
        root.hidden = !cfg.enabled;
        if (!cfg.box) box.hidden = true;
        if (!cfg.enabled) wait = 20000;
        for (const ev of j.events || []) handle(ev);
        after = j.last || after;
        failures = 0;
      } else wait = 15000;
    } catch (e) { failures++; wait = Math.min(30000, 2000 * failures); }
    setTimeout(poll, wait);
  };

  if (demo) {
    box.hidden = false;
    const names = ['Rahim Ahmed', 'Arjun', 'Lucas', 'Sara', '@TasinFan-7x2', 'Ayesha', 'Mohammed', 'John', 'Fatima', 'Kenji', 'Ali Khan', 'Maria'];
    let n = 0;
    const fire = () => {
      n++;
      if (n % 9 === 0) handle({ k: 'sub', a: pick(names), t: '' });
      else if (n % 13 === 0) handle({ k: 'super', a: pick(names), t: pick(o.demoTexts), amt: '$5.00' });
      else handle({ k: 'chat', a: pick(names), t: pick(o.demoTexts) });
      setTimeout(fire, 2500 + Math.random() * 3000);
    };
    setTimeout(fire, 1200);
  } else poll();

  return { active: true, say, counts };
}
