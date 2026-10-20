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

const GIRL_SVG = `<svg viewBox="0 0 200 220" aria-hidden="true"> <defs> <linearGradient id="hair" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#3a2216"/><stop offset="1" stop-color="#1c0f0a"/></linearGradient> <linearGradient id="hairHi" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#7a4a2e" stop-opacity=".9"/><stop offset="1" stop-color="#3a2216" stop-opacity="0"/></linearGradient> <radialGradient id="skin" cx="50%" cy="40%" r="60%"><stop offset="0" stop-color="#ffe3cf"/><stop offset="1" stop-color="#f3c3a3"/></radialGradient> <linearGradient id="top" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ff4fa3"/><stop offset="1" stop-color="#c0267a"/></linearGradient> <radialGradient id="iris" cx="50%" cy="40%" r="60%"><stop offset="0" stop-color="#8fd9ff"/><stop offset=".55" stop-color="#2f7fd6"/><stop offset="1" stop-color="#173f8a"/></radialGradient> </defs> <!-- back hair --> <path d="M38 92 C30 40 70 14 102 14 C138 14 172 40 164 96 C170 140 176 176 160 206 L42 206 C26 176 32 140 38 92 Z" fill="url(#hair)"/> <!-- shoulders / top --> <path d="M30 220 C34 186 60 172 100 172 C140 172 166 186 170 220 Z" fill="url(#top)"/> <path d="M78 174 C88 186 112 186 122 174 L118 170 L82 170 Z" fill="#ffd9c4"/> <!-- neck --> <path d="M86 140 L86 172 C94 180 106 180 114 172 L114 140 Z" fill="#f0bd9c"/> <!-- face --> <path d="M56 92 C56 58 76 40 100 40 C124 40 144 58 144 92 C144 124 126 150 100 154 C74 150 56 124 56 92 Z" fill="url(#skin)"/> <!-- ears + earrings --> <ellipse cx="56" cy="98" rx="7" ry="11" fill="#f3c3a3"/><ellipse cx="144" cy="98" rx="7" ry="11" fill="#f3c3a3"/> <circle cx="55" cy="114" r="3.5" fill="#ffd54a"/><circle cx="145" cy="114" r="3.5" fill="#ffd54a"/> <!-- blush --> <ellipse cx="76" cy="118" rx="11" ry="6" fill="#ff8fa8" opacity=".45"/><ellipse cx="124" cy="118" rx="11" ry="6" fill="#ff8fa8" opacity=".45"/> <!-- eyes --> <g class="gl-eyes"> <ellipse cx="80" cy="100" rx="10" ry="11.5" fill="#fff"/><ellipse cx="120" cy="100" rx="10" ry="11.5" fill="#fff"/> <circle cx="81" cy="102" r="8" fill="url(#iris)"/><circle cx="119" cy="102" r="8" fill="url(#iris)"/> <circle cx="81" cy="103" r="4" fill="#0b1a33"/><circle cx="119" cy="103" r="4" fill="#0b1a33"/> <circle cx="84" cy="98" r="2.6" fill="#fff"/><circle cx="122" cy="98" r="2.6" fill="#fff"/> <path d="M67 92 Q80 82 93 92" stroke="#1c0f0a" stroke-width="3.2" fill="none" stroke-linecap="round"/> <path d="M107 92 Q120 82 133 92" stroke="#1c0f0a" stroke-width="3.2" fill="none" stroke-linecap="round"/> <path d="M67 92 L62 88 M133 92 L138 88" stroke="#1c0f0a" stroke-width="2.4" stroke-linecap="round"/> </g> <!-- brows --> <path d="M69 84 Q80 78 91 83" stroke="#4a2c1c" stroke-width="2.4" fill="none" stroke-linecap="round"/> <path d="M109 83 Q120 78 131 84" stroke="#4a2c1c" stroke-width="2.4" fill="none" stroke-linecap="round"/> <!-- nose --> <path d="M100 108 Q98 116 101 119" stroke="#d99a7c" stroke-width="2" fill="none" stroke-linecap="round"/> <!-- mouth (smile; opens while talking) --> <path d="M86 126 Q100 144 114 126 Q100 130 86 126 Z" fill="#a8173f"/> <path d="M88 127 Q100 131 112 127 L111 130 Q100 133 89 130 Z" fill="#fff"/> <ellipse class="gl-mouth" cx="100" cy="131" rx="8" ry="4.5" fill="#7a0f2c"/><path d="M92 135 Q100 141 108 135 Q100 137 92 135 Z" fill="#ff7f9c"/> <!-- front hair / bangs --> <path d="M54 98 C46 50 76 30 102 30 C130 30 156 50 147 98 C144 86 140 76 134 70 C132 72 128 74 122 76 C122 70 118 64 112 60 C108 68 100 72 88 74 C92 70 92 64 90 60 C82 68 72 72 62 76 C58 84 56 92 54 98 Z" fill="url(#hair)"/> <path d="M74 44 C88 34 114 33 130 42" stroke="url(#hairHi)" stroke-width="6" fill="none" stroke-linecap="round"/> <!-- headset --> <path d="M48 98 C38 12 162 12 152 98" stroke="#e8eef8" stroke-width="7" fill="none" stroke-linecap="round"/> <rect x="42" y="86" width="14" height="26" rx="6" fill="#ff4fa3" stroke="#e8eef8" stroke-width="3"/> <rect x="144" y="86" width="14" height="26" rx="6" fill="#ff4fa3" stroke="#e8eef8" stroke-width="3"/> <path d="M48 110 C50 132 66 140 84 138" stroke="#e8eef8" stroke-width="3.5" fill="none" stroke-linecap="round"/> <circle cx="86" cy="138" r="4.5" fill="#2b2b33" stroke="#e8eef8" stroke-width="2"/> </svg> `;

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
  sa: ['saudi', 'ksa', 'saudi arabia', 'arabia', 'সৌদি'], tr: ['turkey', 'turkiye', 'türkiye', 'turkish'], br: ['brazil', 'brasil', 'bra'],
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
  const norm = (x) => String(x || '').replace(/ı/g, 'i').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
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
  const sorted = (w) => [...w].sort().join('');
  // Spelling mistakes (kids type fast): "malaysiq", "malqysia", "insia", "bandaglesh", "indonsia",
  // "malaysiamalaysia", "usausa". Only names of 5+ letters, so short words ("chat", "mail") never turn into a country.
  const dist = (a, b, max) => { // edit distance with swapped letters, stops early above max
    if (Math.abs(a.length - b.length) > max) return max + 1;
    let p2 = null, p = Array.from({ length: b.length + 1 }, (_, j) => j);
    for (let i = 1; i <= a.length; i++) {
      const c = [i]; let best = i;
      for (let j = 1; j <= b.length; j++) {
        let v = Math.min(p[j] + 1, c[j - 1] + 1, p[j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1));
        if (p2 && i > 1 && j > 1 && a[i - 1] === b[j - 2] && a[i - 2] === b[j - 1]) v = Math.min(v, p2[j - 2] + 1);
        c.push(v); if (v < best) best = v;
      }
      if (best > max) return max + 1;
      p2 = p; p = c;
    }
    return p[b.length];
  };
  const fuzzyWords = words.filter(([w]) => isLatin(w) && w.replace(/ /g, '').length >= 5).map(([w, code]) => [w.replace(/ /g, ''), code]);
  const squeeze = (x) => x.replace(/(.)\1+/g, '$1');
  const fuzzySq = fuzzyWords.map(([w, code]) => [w, code, squeeze(w)]);
  const shortList = [...short].filter(([w]) => /^[a-z]{2,3}$/.test(w));
  const SAFE2 = new Set(['bd', 'uk', 'pk', 'np', 'ph']);   // two letters that are never an English word
  const NOT_COUNTRY = new Set(['child', 'chill', 'chain', 'being', 'sedan', 'petal', 'mails', 'chats']);
  const fuzzy = (t) => {
    if (!isLatin(t)) return null;
    const all = t.split(' ');
    for (const w of all) { // a short code inside a message: "usa usa", "go bd", "IND!"
      if (w.length === 3 && short.has(w)) return short.get(w);
      if (w.length === 2 && short.has(w) && (SAFE2.has(w) || new RegExp('\\b' + w.toUpperCase() + '\\b').test(rawNow))) return short.get(w);
    }
    const toks = all.filter((x) => x.length >= 4 && !NOT_COUNTRY.has(x));
    const tries = toks.concat(all.length > 1 ? all.slice(0, -1).map((x, i) => x + all[i + 1]).filter((x) => x.length >= 6) : []);   // "new zeland" too
    // stretched letters: "turkeeyyy", "indiaaaa", "nepalll" -> squeeze the repeats
    for (const x of toks) { const q = x.replace(/(.)\1+/g, '$1'); if (q !== x && q.length >= 4) tries.push(q); }
    let best = null, bestD = 9;
    for (const tok of tries) {
      for (const [w, code] of fuzzyWords.concat(shortList)) { // the same name typed twice: "malaysiamalaysia", "usausa"
        if (tok.length >= w.length * 2 && tok.length % w.length === 0 && tok === w.repeat(tok.length / w.length)) return code;
      }
      if (tok.length < 5) continue;
      for (const [w, code] of fuzzyWords) {
        const max = w.length >= 10 ? 3 : w.length >= 8 ? 2 : 1;
        const d = dist(tok, w, max);
        if (d <= max && d < bestD) { best = code; bestD = d; }
      }
    }
    if (best) return best;
    // doubled or missing letters together ("morrco" = morocco): compare with repeats squeezed on both sides
    for (const tok of toks) {
      const q = squeeze(tok); if (q.length < 5) continue;
      for (const [w, code, wq] of fuzzySq) {
        const max = wq.length >= 8 ? 2 : 1, d = dist(q, wq, max);
        if (d <= max && d < bestD) { best = code; bestD = d; }
      }
    }
    return best;
  };
  let rawNow = '';
  return (text) => {
    const raw = String(text || ''); rawNow = raw;
    const fe = raw.match(/([\u{1F1E6}-\u{1F1FF}])([\u{1F1E6}-\u{1F1FF}])/u);
    if (fe) {
      const code = String.fromCharCode(fe[1].codePointAt(0) - 0x1F1E6 + 97, fe[2].codePointAt(0) - 0x1F1E6 + 97);
      if (codes.has(code)) return code;
    }
    const t = norm(raw);
    if (t.length <= 3) { // a short code typed alone: "bd", "IND"; plain two letters only in capitals ("NO" yes, "no" no)
      if (short.has(t)) return short.get(t);
      if (codes.has(t) && /^[A-Z]{2}$/.test(raw.trim())) return t;
      if (t.length === 3) { const k = sorted(t); for (const [w, code] of short) if (w.length === 3 && sorted(w) === k) return code; } // "uas" = usa
      return null;
    }
    const padded = ' ' + t + ' ';
    for (const [w, code] of words) if (isLatin(w) ? padded.includes(' ' + w + ' ') : t.includes(w)) return code;
    return fuzzy(t);
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
  const rbFace = el('div', 'lc-rb-face'); rbFace.innerHTML = GIRL_SVG;
  const rbName = el('div', 'lc-rb-name', 'LUNA');
  const rbWho = el('span', '', 'LUNA'), rbBars = el('i', 'lc-bars'); rbBars.innerHTML = '<b></b><b></b><b></b><b></b><b></b>';
  rbName.replaceChildren(rbWho, document.createTextNode(' · LIVE HOST '), rbBars);
  let look = '';
  const rbText = el('div', 'lc-rb-text');
  const rbBody = el('div', 'lc-rb-body'); rbBody.append(rbName, rbText);
  robot.append(rbFace, rbBody);

  let cfg = { enabled: true, robot: true, name: 'LUNA', look: 'girl', box: true, subs: true, idle: true };
  const seen = new Set();          // authors already welcomed (this session)
  const counts = new Map();        // fan key -> number of comments
  const lastSaid = new Map();      // author -> time of the last robot answer to them
  const authorKey = new Map();     // author -> the country (or side) they last cheered for
  // remembered across reloads and wars, so a viewer who once typed their country can later write only "share" / "subscribed"
  const WKEY = 'fft_who_' + (o.game || 'game');
  try { for (const [k, v] of JSON.parse(localStorage.getItem(WKEY) || '[]')) authorKey.set(k, v); } catch (e) { /* ignore */ }
  let whoT = 0;
  const saveWho = () => { clearTimeout(whoT); whoT = setTimeout(() => { try { localStorage.setItem(WKEY, JSON.stringify([...authorKey].slice(-3000))); } catch (e) { /* ignore */ } }, 2000); };
  let lastMsg = performance.now();

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
  if (o.alwaysOn) box.hidden = false;   // this game keeps the chat box and the host on screen all the time

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
  // Each line is shown with the robot's voice: the page asks the panel, the VPS makes the mp3 (a few seconds).
  const queue = [];
  let speaking = null, speakUntil = 0, pauseUntil = 0, audio = null, lastPromo = performance.now();
  const voiceOn = () => !!(cfg.robot && cfg.voice && stream && sig);
  const BAD = /fuck|shit|bitch|dick|pussy|nigg|cunt|whore|slut|porn|sex/i;
  const speakName = (n) => {
    const s = cleanName(n).replace(/[^A-Za-z0-9 ]/g, ' ').replace(/\s+/g, ' ').trim();
    return s.replace(/[^A-Za-z]/g, '').length < 2 || BAD.test(s) ? 'friend' : s;
  };
  const spoken = (parts) => {
    const t = parts.map((p) => (typeof p === 'string' ? p : p.name ? speakName(p.name) : (p.say || ''))).join('')
      .replace(/—/g, ', ').replace(/(\d)\s*[–-]\s*(\d)/g, '$1 to $2').replace(/\s+/g, ' ').trim().toLowerCase();
    return t.replace(/(^|[.!?]\s+)([a-z])/g, (m, a, b) => a + b.toUpperCase()).replace(/\bi\b/g, 'I');
  };
  const api = (q, body) => fetch('/api/robot.php' + q, body ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) } : { cache: 'no-store' })
    .then((r) => (r.ok ? r.json() : { failed: true }));
  const fetchVoice = async (line) => {
    line.voice = 'wait'; line.vt = performance.now();
    try {
      let j = await api('', { stream: Number(stream), sig, text: spoken(line.parts) });
      const t0 = performance.now();
      while (j && !j.url && !j.failed && j.h && performance.now() - t0 < 15000) {
        await new Promise((r) => setTimeout(r, 1200));
        j = await api(`?stream=${encodeURIComponent(stream)}&sig=${encodeURIComponent(sig)}&h=${j.h}`);
      }
      if (j && j.url) { line.url = j.url; line.voice = 'ready'; const a = new Audio(j.url); a.preload = 'auto'; line.audio = a; }
      else line.voice = 'none';
    } catch (e) { line.voice = 'none'; }
  };
  const wq = [];
  let wTimer = 0;
  const flushWelcome = () => {
    wTimer = 0;
    if (!wq.length) return;
    const list = wq.splice(0, wq.length);
    if (list.length === 1) { const w = list[0]; say(o.welcome(w.name, w.key, w.text), 2); return; }
    // everyone is named: groups of three, at most three lines (the newest people if even more came)
    const all = list.slice(-9);
    for (let g = 0; g < all.length; g += 3) {
      const shown = all.slice(g, g + 3), parts = ['WELCOME '];
      shown.forEach((w, i) => {
        parts.push({ name: w.name });
        if (w.key && o.badge) parts.push({ node: o.badge(w.key) });
        parts.push(i < shown.length - 2 ? ', ' : i === shown.length - 2 ? ' AND ' : '');
      });
      parts.push('! ' + (o.welcomeHint || 'TYPE YOUR COUNTRY IN CHAT!'));
      say(parts, 2);
    }
  };
  const say = (parts, prio = 1) => {
    if (!cfg.robot || !parts) return;
    const line = { parts, prio, t: performance.now() };
    queue.push(line);
    queue.sort((a, b) => b.prio - a.prio || a.t - b.t);
    if (queue.length > 4) queue.length = 4;          // never fall far behind the chat
    prefetch();
  };
  // Ask the VPS for a voice only for the next two lines (not for every line that may be dropped later),
  // so in a busy chat the voice keeps up instead of falling minutes behind.
  const prefetch = () => { if (voiceOn()) for (const l of queue.slice(0, 2)) if (!l.voice) fetchVoice(l); };
  const stopAudio = () => { if (audio) { audio.onended = audio.onerror = null; audio.pause(); audio = null; o.duck && o.duck(false); } };
  const show = (line) => {
    const wantLook = cfg.look === 'robot' ? 'robot' : 'girl';
    if (wantLook !== look) { look = wantLook; rbFace.innerHTML = look === 'robot' ? ROBOT_SVG : GIRL_SVG; robot.classList.toggle('girl', look === 'girl'); }
    rbWho.textContent = cfg.name || 'LUNA';
    rbText.replaceChildren();
    let len = 0;
    for (const p of line.parts) {
      if (typeof p === 'string') { rbText.append(document.createTextNode(p)); len += p.length; }
      else if (p.name) { rbText.append(el('em', '', cleanName(p.name).toUpperCase())); len += 10; }
      else if (p.node) rbText.append(p.node.cloneNode(true));
    }
    rbText.classList.toggle('long', len > 70);
    robot.hidden = false; robot.classList.remove('in'); void robot.offsetWidth; robot.classList.add('in', 'talk');
    speaking = line;
    const minShow = Math.min(9000, 3800 + len * 40);
    speakUntil = performance.now() + minShow;
    if (line.audio) {
      stopAudio();
      audio = line.audio; audio.volume = 1;
      o.duck && o.duck(true);
      const started = performance.now();
      speakUntil = started + 20000; // until the voice ends (safety limit)
      const end = () => { robot.classList.remove('talk'); o.duck && o.duck(false); audio = null; speakUntil = Math.max(performance.now() + 900, started + Math.min(minShow, 5000)); };
      audio.onended = end; audio.onerror = end;
      audio.play().catch(end);
    }
  };
  const tick = () => {
    const now = performance.now();
    if (speaking) {
      if (o.busy && o.busy()) { stopAudio(); robot.hidden = true; speaking = null; pauseUntil = now + 800; } // winner screen: give way
      else if (now > speakUntil) { if (!o.alwaysOn) robot.hidden = true; robot.classList.remove('talk'); speaking = null; pauseUntil = now + 700; }
      else if (!audio && now > speakUntil - 1500) robot.classList.remove('talk');
    }
    if (queue.length) prefetch();
    if (!speaking && queue.length && now > pauseUntil && !(o.busy && o.busy())) {
      const line = queue[0];
      // wait (up to 14 s) for the robot's voice, then show it with or without sound
      if (!(line.voice === 'wait' && now - (line.vt || line.t) < 14000)) {
        queue.shift();
        if (now - line.t < 45000 || line.prio >= 3) show(line);
      }
    }
    // ask for likes, subscribes and comments now and then (more often when the chat is quiet)
    const gap = o.alwaysOn ? (now - lastMsg > 30000 ? 14000 : 30000) : now - lastMsg > 60000 ? 50000 : 80000;
    if (cfg.idle && cfg.robot && !speaking && !queue.length && now - lastPromo > gap) {
      lastPromo = now;
      const live = o.idleLine && Math.random() < 0.6 ? o.idleLine() : null;   // the game's own news (e.g. chat standings)
      say(live || pick(o.idleLines.concat(PROMO)), 0);
    }
  };
  setInterval(tick, 250);
  const PROMO = [
    ['SMASH THAT LIKE BUTTON AND SUBSCRIBE — I WILL SAY YOUR NAME ON SCREEN!'],
    ['NEW HERE? HIT SUBSCRIBE AND TURN ON THE BELL SO YOU NEVER MISS A LIVE!'],
    ['IF YOU ARE ENJOYING THIS, PLEASE PRESS LIKE — IT HELPS THE STREAM A LOT!'],
    ['COMMENT ANYTHING — I READ EVERY SINGLE COMMENT AND I WILL ANSWER YOU!'],
    ['SHARE THIS LIVE WITH YOUR FRIENDS AND BRING THEM TO THE BATTLE!'],
  ];

  /* ---------- answers to any comment (the game's own answers come first) ---------- */
  const generic = (name, text, noDefault) => {
    const t = text.toLowerCase();
    if (/subscribed|\bsub(scribe)? ?done|subscribe kor|সাবস্ক্রাইব/.test(t)) return ['THANK YOU ', { name }, ' FOR SUBSCRIBING! YOU ARE PART OF THE FAMILY NOW!'];
    if (/\blike(d)? ?(done|dilam|diyechi)?\b|👍|লাইক/.test(t)) return ['THANKS FOR THE LIKE, ', { name }, '! YOU ARE THE BEST!'];
    if (/robot|\bbot\b|\bai\b|রোবট/.test(t)) return ['YES ', { name }, ', I AM YOUR ROBO HOST! I READ EVERY COMMENT LIVE!'];
    if (/haha|lol|lmao|😂|🤣|হাহা/.test(t)) return ['HAHA, ', { name }, '! THIS GAME IS CRAZY, RIGHT?'];
    if (/boring|slow|bekar/.test(t)) return ['STAY WITH US, ', { name }, '! THE BEST PART IS COMING!'];
    if (/nice|good|great|wow|awesome|amazing|love|best|cool|super|valo|bhalo|ভালো|সুন্দর|অসাধারণ|🔥|❤|😍|💯/.test(t)) return ['THANK YOU ', { name }, '! SO HAPPY YOU ENJOY IT — PLEASE PRESS LIKE!'];
    if (/where.*from|kothay|কোথা/.test(t)) return ['I LIVE INSIDE THIS STREAM, ', { name }, '! WHERE ARE YOU WATCHING FROM?'];
    if (/bye|good ?night|gn\b|আল্লাহ হাফেজ|allah hafez/.test(t)) return ['BYE ', { name }, '! THANKS FOR WATCHING — COME BACK SOON!'];
    if (t.includes('?') && !(noDefault && cfg.ai)) return ['GOOD QUESTION, ', { name }, '! KEEP WATCHING AND YOU WILL SEE!'];
    if (noDefault) return null;
    return [pick(['THANKS FOR YOUR COMMENT, ', 'I SEE YOU, ', 'GREAT TO HAVE YOU HERE, ', 'NICE ONE, ']), { name }, '! ' + pick(o.hints || ['DROP A LIKE IF YOU ARE ENJOYING THE GAME!'])];
  };

  /* ---------- AI answers (optional, set in the panel): only for messages the rules did not understand ---------- */
  let aiBusy = false;
  const aiWorth = (text) => {
    if (!cfg.ai || !stream || !sig || aiBusy) return false;
    const t = String(text || '').trim();
    const letters = (t.match(/\p{L}/gu) || []).length;
    return letters >= 6 && t.split(/\s+/).length >= 2 && !/(.)\1{5,}/.test(t);   // real words, not spam or "hiiiiii"
  };
  const askAI = async (name, key, text, fallback, pre = '') => {
    aiBusy = true;
    try {
      const info = pre + (o.aiInfo ? o.aiInfo(key) : (o.hints || []).join(' '));
      const r = await fetch('/api/robot_ai.php', { method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ stream: Number(stream), sig, game: o.game || '', name: cleanName(name), text, info }) });
      const j = r.ok ? await r.json() : null;
      if (j && j.text) say([{ name }, ', ' + String(j.text).toUpperCase()], 2);
      else { const l = fallback(); if (l) say(l, 1); }
    } catch (e) { const l = fallback(); if (l) say(l, 1); }
    finally { aiBusy = false; }
  };

  /* ---------- one chat event ---------- */
  const handle = (ev) => {
    const name = ev.a;
    if (ev.k === 'sub') {
      if (cfg.subs) { subQ.push(name); nextSub(); }
      if (!ev.old) o.onSub?.(name, authorKey.get(name.toLowerCase()) || null);
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
      const firstTime = !authorKey.has(name.toLowerCase());   // never named a country here before
      authorKey.delete(name.toLowerCase()); authorKey.set(name.toLowerCase(), key); saveWho();
      o.onFan && o.onFan(key, cleanName(name), name.toLowerCase(), firstTime);
    }
    if (ev.k === 'super') o.onSuper?.(key || authorKey.get(name.toLowerCase()) || null, cleanName(name));
    const author = name.toLowerCase();
    const special = ev.k === 'chat' && o.onText ? o.onText(cleanName(name), text, key || authorKey.get(author) || null, author) : null;
    if (special) { seen.add(author); lastSaid.set(author, performance.now()); say(special, 3); return; }
    const first = !seen.has(author);
    seen.add(author);
    const recent = performance.now() - (lastSaid.get(author) || -1e9) < 20000;

    let line = null, prio = 1;
    if (ev.k === 'super') { line = ['WOW! THANK YOU ', { name }, ` FOR THE SUPER CHAT${ev.amt ? ' ' + ev.amt : ''}! YOU ARE A LEGEND!`]; prio = 3; }
    else if (ev.k === 'member') { line = ['WELCOME TO THE CLUB, ', { name }, '! THANKS FOR BECOMING A MEMBER!']; prio = 3; }
    else if (first && o.understand && (line = o.understand(name, key, text))) { prio = 2; o.onWelcome?.(name, key); } // a new viewer asked something: answer it
    else if (first && aiWorth(text) && /\?|\b(how|why|what|who|when|where|can|is|are|do)\b/i.test(text)) { o.onWelcome?.(name, key); lastSaid.set(author, performance.now()); askAI(name, key, text, () => o.welcome(name, key, text), 'NEW VIEWER (say welcome). '); return; }
    else if (first) { // greet every new chatter: alone if LUNA is free, together with others if she is busy
      wq.push({ name, key, text }); o.onWelcome?.(name, key);
      if (!wTimer) wTimer = setTimeout(flushWelcome, queue.length || performance.now() < speakUntil ? 6000 : 300);
      lastSaid.set(author, performance.now());
      return;
    }
    else if (!recent) {
      line = o.reply(name, key, text) || generic(name, text, true);
      if (!line && aiWorth(text)) { lastSaid.set(author, performance.now()); askAI(name, key, text, () => generic(name, text)); return; }
      if (!line) line = generic(name, text);
    }
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
        if (j.stats) o.onStats?.(j.stats);
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

  // tell the panel what a reward really did (Admin → Audience Insights shows it)
  const log = (kind, author, country, result) => {
    if (!stream || !sig) return;
    fetch('/api/gift.php', { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ stream: Number(stream), sig, kind, author, country: country || '', result }) }).catch(() => {});
  };
  // the game can show the subscriber banner for a viewer who wrote "subscribed"
  const subBanner = (name) => { if (cfg.subs) { subQ.push(name); nextSub(); } };
  return { active: true, say, counts, voiceOn, log, subBanner };
}
