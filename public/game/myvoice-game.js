// 🗣 My Voice in the Shorts games (Cup Kick, Action Kick).
// The owner pastes a new script every time (3 lines: start / when the ball hits the cup / end).
// "Make voices" turns each line into HIS cloned voice (Admin → My Voice; it uses the same settings, e.g. 🎲 Natural),
// and the game plays them at those three moments — also inside Auto record videos.
// Works only on the owner's phone (logged in to the admin panel once).
const css = `
.mvg{margin-top:14px;padding:12px 14px;border:1px solid #7c5cff99;border-radius:12px;background:linear-gradient(180deg,#1d1440,#120d26)}
.mvg-h{display:flex;align-items:center;gap:8px;font-weight:800;color:#fff;font-size:15px;cursor:pointer}
.mvg-h input{position:absolute;opacity:0;width:1px;height:1px}
.mvg-sw{margin-left:auto;width:44px;height:24px;border-radius:99px;background:#3a4470;position:relative;transition:background .2s;flex:0 0 auto}
.mvg-sw:after{content:'';position:absolute;left:3px;top:3px;width:18px;height:18px;border-radius:50%;background:#fff;transition:left .2s}
.mvg-h input:checked+.mvg-sw{background:#22c55e}.mvg-h input:checked+.mvg-sw:after{left:23px}
.mvg-b[hidden]{display:none}
.mvg p{font-size:12px;color:#a9b4dc;margin-top:8px;line-height:1.4}
.mvg label.l{display:block;font-size:12px;font-weight:700;color:#cdd6ff;margin-top:10px}
.mvg textarea{width:100%;min-height:52px;margin-top:4px;padding:8px;border-radius:9px;border:1px solid #3a4470;background:#0d1230;color:#fff;font:14px/1.35 system-ui,sans-serif;resize:vertical;box-sizing:border-box}
.mvg select{width:100%;margin-top:4px;padding:8px;border-radius:9px;border:1px solid #3a4470;background:#0d1230;color:#fff}
.mvg .st{display:flex;gap:6px;align-items:center;margin-top:4px;font-size:12px;color:#9fb0d6;min-height:22px}
.mvg .st button{flex:none;width:auto;padding:4px 10px;border-radius:8px;background:#2a3370;color:#fff;font-size:12px}
.mvg .go{width:100%;margin-top:12px;padding:11px;border-radius:10px;background:linear-gradient(90deg,#7c5cff,#c04cff);color:#fff;font-weight:800;font-size:15px}
.mvg .go:disabled{opacity:.6}
.mvg .ok{color:#4ade80}.mvg .bad{color:#ff8a8a}
`;
const KEYS = [['start', '▶ Start (when the kick-off begins)'], ['hit', '🏆 Ball hits the cup'], ['end', '👋 End']];
const LANGS = { en: 'English', bn: 'বাংলা', hi: 'हिन्दी', ur: 'اردو', ar: 'العربية', es: 'Español', pt: 'Português', fr: 'Français', de: 'Deutsch', tr: 'Türkçe', id: 'Bahasa Indonesia', ru: 'Русский' };
const store = (k, v) => { try { if (v === undefined) return JSON.parse(localStorage.getItem(k) || 'null'); localStorage.setItem(k, JSON.stringify(v)); } catch (e) { return null; } };

export function setupMyVoice({ game, after }) {
  const st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);
  const K = 'fft_mvg_' + game, saved = store(K) || {};
  const S = { on: !!saved.on, lang: saved.lang || 'en', text: saved.text || {}, made: saved.made || {} };   // made[k] = {text, lang, url, en}
  const bufs = {};
  const save = () => store(K, S);

  const box = document.createElement('div'); box.className = 'mvg';
  box.innerHTML = `<label class="mvg-h">🗣 My Voice <input type="checkbox" id="mvg-on"><span class="mvg-sw"></span></label>
  <div class="mvg-b" hidden>
    <p>Paste your new script below (one line per box, any language). Press <b>Make voices</b> — it comes out in your own voice and plays in the game and in the recorded video. Leave a box empty to skip it.</p>
    <label class="l">Speak in<select id="mvg-lang">${Object.entries(LANGS).map(([k, v]) => `<option value="${k}">${v}</option>`).join('')}</select></label>
    ${KEYS.map(([k, l]) => `<label class="l">${l}<textarea id="mvg-${k}" maxlength="400" placeholder="(empty = no voice here)"></textarea></label><div class="st" id="mvg-s-${k}"></div>`).join('')}
    <button class="go" id="mvg-go" type="button">🎙 Make voices</button>
    <p>A line you already made is used again for free (only new or changed text uses credits). Want it said a bit differently? Press 🔄 next to the line (uses credits).</p>
  </div>`;
  after.insertAdjacentElement('afterend', box);
  const $ = (id) => box.querySelector('#' + id), body = box.querySelector('.mvg-b');
  $('mvg-on').checked = S.on; body.hidden = !S.on; $('mvg-lang').value = S.lang;
  $('mvg-on').addEventListener('change', () => { S.on = $('mvg-on').checked; body.hidden = !S.on; save(); });
  $('mvg-lang').addEventListener('change', () => { S.lang = $('mvg-lang').value; save(); KEYS.forEach(([k]) => show(k)); });

  const fresh = (k) => { const m = S.made[k], t = (S.text[k] || '').trim(); return m && t && m.text === t && m.lang === S.lang; };
  const load = async (k) => {
    bufs[k] = null;
    if (!fresh(k)) return;
    try {
      const ab = await (await fetch(S.made[k].url)).arrayBuffer();
      bufs[k] = await new OfflineAudioContext(1, 1, 44100).decodeAudioData(ab);   // an AudioBuffer works in any audio context
    } catch (e) { bufs[k] = null; }
    show(k);
  };
  const show = (k, msg, cls) => {
    const el = $('mvg-s-' + k);
    if (msg) { el.innerHTML = `<span class="${cls || ''}">${msg}</span>`; return; }
    if (!(S.text[k] || '').trim()) { el.textContent = ''; return; }
    if (!fresh(k)) { el.innerHTML = '<span>Not made yet</span>'; return; }
    el.innerHTML = `<span class="ok">✓ Ready${bufs[k] ? ' · ' + bufs[k].duration.toFixed(1) + ' s' : ''}</span><button type="button" data-p>▶ Listen</button><button type="button" data-r title="Make it again, said a bit differently">🔄</button>`;
    el.querySelector('[data-p]').onclick = () => new Audio(S.made[k].url).play();
    el.querySelector('[data-r]').onclick = () => make(k, true);
  };
  KEYS.forEach(([k]) => {
    const ta = $('mvg-' + k); ta.value = S.text[k] || '';
    ta.addEventListener('input', () => {
      // a whole 3-line script pasted into the first box → split it into the three boxes
      if (k === 'start' && /\n/.test(ta.value) && !$('mvg-hit').value && !$('mvg-end').value) {
        const ls = ta.value.split(/\n+/).map((s) => s.trim()).filter(Boolean);
        if (ls.length >= 2) { KEYS.forEach(([kk], i) => { $('mvg-' + kk).value = i < 2 ? (ls[i] || '') : ls.slice(2).join(' '); S.text[kk] = $('mvg-' + kk).value; show(kk); }); save(); return; }
      }
      S.text[k] = ta.value; save(); show(k);
    });
    show(k); load(k);
  });

  const make = async (k, again) => {
    const t = (S.text[k] || '').trim();
    if (!t) return true;
    if (!again && fresh(k)) { if (!bufs[k]) await load(k); return true; }
    show(k, '⏳ Making…');
    try {
      const r = await fetch('/api/myvoice.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify({ text: t, lang: S.lang }) });
      const j = await r.json().catch(() => ({ error: 'The server did not answer (' + r.status + ').' }));
      if (j.error) { show(k, '✗ ' + j.error, 'bad'); return false; }
      S.made[k] = { text: t, lang: S.lang, url: j.url, en: j.en }; save();
      await load(k); return true;
    } catch (e) { show(k, '✗ No internet?', 'bad'); return false; }
  };
  $('mvg-go').addEventListener('click', async () => {
    const b = $('mvg-go'); b.disabled = true; b.textContent = '⏳ Making…';
    let ok = true; for (const [k] of KEYS) ok = (await make(k)) && ok;
    b.disabled = false; b.textContent = ok ? '✓ Done — press START' : '🎙 Make voices';
    setTimeout(() => { b.textContent = '🎙 Make voices'; }, 3000);
  });

  return {
    on: () => S.on,
    buf: (k) => (S.on && fresh(k) ? bufs[k] || null : null),
    dur: (k) => { const b = S.on && fresh(k) ? bufs[k] : null; return b ? b.duration : 0; },
  };
}

// When to say the three lines. Times are counted in real seconds of the play (also correct in ⚡ Perfect recording).
// The lines never talk over each other: "hit" waits for "start" to finish, "end" comes after "hit".
export function voiceRun(mv, snd) {
  let T = 0, said = {}, at = {};
  const say = (k) => { said[k] = true; at[k] = T; const b = mv.buf(k); if (b) snd.clip(b); };
  return {
    reset() { T = 0; said = {}; at = {}; },
    tick(real, hit) {   // hit = the ball has hit the cup
      if (!mv.on()) return;
      T += real;
      if (!said.start && T >= 0.05) say('start');
      const freeAfterStart = at.start + mv.dur('start') + 0.15;
      if (!said.hit && hit && T >= freeAfterStart) say('hit');
      if (said.hit && !said.end && T >= at.hit + mv.dur('hit') + 0.3) say('end');
    },
    // the play may stop only after the last line has been said
    done() { if (!mv.on()) return true; return said.end ? T >= at.end + mv.dur('end') + 0.4 : !mv.buf('end') && said.hit && T >= at.hit + mv.dur('hit') + 0.2; },
    extra() { return mv.on() ? mv.dur('hit') + mv.dur('end') + 0.8 : 0; },   // seconds to add after the hit
  };
}
