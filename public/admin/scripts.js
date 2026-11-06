// 🎬 Script Studio (Admin → Script Studio). Ready-made lines, mixed so every script is new; ✨ AI is optional.
(() => {
  const SS = window.SS, root = document.getElementById('ss');
  const pick = (a) => a[Math.floor(Math.random() * a.length)];
  const shuffle = (a) => { a = [...a]; for (let i = a.length - 1; i > 0; i--) { const j = Math.floor(Math.random() * (i + 1)); [a[i], a[j]] = [a[j], a[i]]; } return a; };
  const norm = (t) => String(t).toLowerCase().replace(/[^\p{L}\p{N}]+/gu, ' ').trim();
  const usedSet = new Set(SS.used);
  const esc = (t) => String(t).replace(/[&<>"]/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[m]);

  const GAMES = { cupkick: ['⚽ Cup Kick — countries', 'country', ['Brazil', 'Argentina', 'France', 'England', 'Spain', 'Portugal', 'Germany', 'USA']],
    actionkick: ['👍 Action Kick — Subscribe / Like / Comment…', 'one', ['Subscribe', 'Like', 'Comment', 'Share', 'Bell', 'Save']],
    playerkick: ['⭐ Player Kick — football stars', 'player', ['Messi', 'Ronaldo', 'Mbappé', 'Haaland', 'Neymar', 'Vinícius']] };
  const STYLES = { challenge: '🎯 Challenge', shock: '😱 Shock', countdown: '⏳ Countdown', question: '❓ Question', story: '📖 Story', secret: '🤫 Secret', rivalry: '🔥 Rivalry', funny: '😂 Funny' };
  const LANGS = { en: 'English (USA)', es: 'Español ✨AI', pt: 'Português ✨AI', fr: 'Français ✨AI', de: 'Deutsch ✨AI', it: 'Italiano ✨AI' };
  // hooks: [power 1 soft … 4 crazy, text]; {who} = country / one / player, {A} {B} = names
  const HOOKS = {
    challenge: [[2, 'Can you spot which {who} hits the cup?'], [3, 'Stop scrolling — ninety-nine percent of people get this wrong.'], [3, 'Only one in a hundred people see it the first time.'],
      [4, 'If you get this right, you have the sharpest eyes on the internet.'], [2, "Let's test your eyes. Ready?"], [4, 'Nobody — and I mean nobody — gets this on the first try.'],
      [3, "I bet you can't tell which {who} hits the trophy."], [4, 'This is the hardest one yet. Prove me wrong.']],
    shock: [[3, 'I watched this five times and still got it wrong.'], [4, 'This one broke my brain. Watch closely.'], [2, 'Wait for it… this one is tricky.'],
      [4, 'Your eyes are going to trick you in three seconds.'], [3, 'I was so sure… and I was so wrong.'], [3, 'Everyone in my family picked a different answer.']],
    countdown: [[2, 'Ten seconds. One winner. Go!'], [3, 'You have ten seconds to find the winner.'], [4, 'Ten seconds. {N} balls. Zero mistakes allowed.'], [3, 'Three… two… one… eyes on the cup!']],
    question: [[2, 'Quick question — which {who} touches the golden cup?'], [3, 'Be honest… did you see it the first time?'], [3, 'Which ball hits the trophy? Most people pick wrong.'],
      [4, 'Can your eyes beat this? Only one ball touches the cup.']],
    story: [[2, '{N} balls, one dream — the golden cup.'], [3, 'My whole family argued about this one for ten minutes.'], [3, 'Yesterday nobody I showed this to got it right.'],
      [4, 'This video started the biggest argument in the comments.']],
    secret: [[3, "Here's a secret: don't watch the fastest ball."], [4, 'The trick is hiding in the last second. Watch.'], [2, 'Pro tip: keep your eyes on the trophy.'], [3, 'Here is how the pros do it — watch the cup, not the players.']],
    rivalry: [[3, '{A} fans versus {B} fans — who is right?'], [4, '{A} or {B}? This one decides it.'], [2, 'Team {A} or team {B}? Let us see.'], [4, '{A} fans always say they see it first. Do they?']],
    funny: [[2, "Okay, this one is actually hard. Don't laugh."], [3, 'Me thinking I am a genius… then this happens.'], [3, 'Grab your glasses — you are going to need them.'], [4, 'Even my grandma got this before me. Can you?']],
  };
  const BUILD = ['{N} balls, one golden trophy. Keep your eyes on it…', '{A}, {B}, {C}… they all kick at once.', 'Watch the {thing} — not the players.',
    'Every ball is fast, but only one is the winner.', 'Here they come… stay focused.', "Don't blink now…", 'Look how close they all get…', 'Some balls crash, some fly past… watch carefully.'];
  const HIT = ['THERE! Did you see it?', 'Boom — the {thing} is down!', 'Wait… which one was that?!', 'Look closely… right… THERE!', 'Did your eyes catch it?', 'Was that the one you picked?'];
  const END = ['Comment your answer — the first right one gets pinned.', 'Watch it again and tell me in the comments.', 'Too fast? Watch again. Answer below.',
    'Type the {who} you saw. Let us see who is right.', 'Follow for a new challenge every day!', 'Show this to a friend and see who wins.', 'Write your answer below — no cheating!'];

  const S = { game: 'cupkick', lang: 'en', secs: 12, np: 6, styles: new Set(['challenge', 'shock', 'rivalry']), power: 2, names: '', aud: 'all', tab: 'new' };
  try { Object.assign(S, JSON.parse(localStorage.getItem('fft_ss') || '{}')); S.styles = new Set(S.styles || ['challenge']); } catch (e) { /* ignore */ }
  const keep = () => { try { localStorage.setItem('fft_ss', JSON.stringify({ ...S, styles: [...S.styles] })); } catch (e) { /* ignore */ } };
  let list = [], saved = SS.saved || [];

  const post = async (data) => {
    const f = new FormData(); f.append('csrf', SS.csrf); Object.entries(data).forEach(([k, v]) => f.append(k, typeof v === 'string' ? v : JSON.stringify(v)));
    const r = await fetch('scripts.php', { method: 'POST', body: f, credentials: 'same-origin' }); return r.json();
  };
  const fill = (t, names) => {
    const g = GAMES[S.game], n = names.length ? names : shuffle(g[2]);
    return t.replaceAll('{who}', g[1]).replaceAll('{thing}', pick(['cup', 'trophy', 'golden cup'])).replaceAll('{N}', NUMW[S.np] || 'Six')
      .replaceAll('{A}', n[0] || 'Brazil').replaceAll('{B}', n[1] || 'Argentina').replaceAll('{C}', n[2] || 'France');
  };
  const NUMW = { 2: 'Two', 3: 'Three', 4: 'Four', 5: 'Five', 6: 'Six' };
  const words = (s) => [s.hook, s.build, s.hit, s.end].join(' ').split(/\s+/).filter(Boolean).length;
  const score = (s, p) => Math.min(99, 70 + p * 6 + (/\b(you|your)\b/i.test(s.hook) ? 4 : 0) + (/\d|one in|percent|nobody/i.test(s.hook) ? 4 : 0) + (/\?/.test(s.hook) ? 2 : 0) + Math.floor(Math.random() * 3));
  const make = () => {   // 6 new scripts from the ready-made lines — never longer than the length (about 2.5 words a second)
    const names = S.names.split(',').map((x) => x.trim()).filter(Boolean), styles = [...S.styles].length ? [...S.styles] : ['challenge'], target = Math.round(S.secs * 2.5), out = [];
    const nw = (t) => String(t).split(/\s+/).filter(Boolean).length;
    const short = (arr, max) => { const ok = shuffle(arr).map((x) => fill(x, names)).filter((x) => nw(x) <= max); return ok.length ? ok[0] : null; };
    for (let tries = 0; tries < 400 && out.length < 6; tries++) {
      const st = tries < 80 ? styles[out.length % styles.length] : pick(styles);
      const hs = HOOKS[st].filter(([p]) => Math.abs(p - (S.power + 1)) <= 1); if (!hs.length) continue;
      const [p, h] = pick(hs), hook = fill(h, names);
      if (usedSet.has(norm(hook)) || out.some((x) => x.hook === hook)) continue;
      const left = target - nw(hook); if (left < 6) continue;   // the hook alone is too long for this length
      const hit = short(HIT, Math.min(6, left - 4)); if (!hit) continue;
      const end = short(END, left - nw(hit)); if (!end) continue;
      const s = { id: 's' + Date.now().toString(36) + tries, style: st, hook, build: '', hit, end };
      const parts = [];
      for (const x of shuffle(BUILD).map((y) => fill(y, names))) { if (words({ ...s, build: [...parts, x].join(' ') }) > target) continue; parts.push(x); if (words({ ...s, build: parts.join(' ') }) >= target - 3) break; }
      s.build = parts.join(' '); s.score = score(s, p); out.push(s);   // (short videos may have no build-up: it does not fit)
    }
    return out.sort((a, b) => b.score - a.score);
  };

  const card = (s, i, isSaved) => `<div class="sc${i === 0 && !isSaved ? ' best' : ''}" data-id="${esc(s.id)}">
    <div class="sh">#${i + 1} ${esc(STYLES[s.style] || s.style)} ${s.score ? `<span class="s1">🪝 Hook ${s.score}/100</span>` : ''}</div>
    ${[['h', 'HOOK 0–2s', 'hook'], ['u', 'BUILD', 'build'], ['x', 'HIT ⚡', 'hit'], ['e', 'END', 'end']].map(([c, l, k]) => `<div class="pt ${c}"><b>${l}</b><div contenteditable="true" data-k="${k}">${esc(s[k])}</div></div>`).join('')}
    <div class="meta">⏱ about ${(words(s) / 2.5).toFixed(1)} s · ${words(s)} words · ${esc(LANGS[s.lang || S.lang] || 'English')}</div>
    <div class="bt"><button type="button" data-a="copy">📋 Copy</button><button type="button" class="v" data-a="voice">🗣 Use in My Voice</button><button type="button" class="st${saved.some((x) => x.id === s.id) ? ' on' : ''}" data-a="star" title="Save">⭐</button></div></div>`;
  const render = () => {
    const g = GAMES[S.game];
    root.innerHTML = `<div class="box">
      <div class="l">🎮 Game</div><select id="g">${Object.entries(GAMES).map(([k, v]) => `<option value="${k}">${v[0]}</option>`).join('')}</select>
      <div class="l">🌍 Language</div><select id="la">${Object.entries(LANGS).map(([k, v]) => `<option value="${k}">${v}</option>`).join('')}</select>
      <div class="l">👨‍👩‍👧 Audience</div><select id="au"><option value="all">Everyone — family-friendly (USA & Europe)</option><option value="adult">Teens & adults (USA & Europe)</option></select>
      <div class="l">⏱ Length</div><div class="seg" id="len">${[8, 12, 16, 20].map((x) => `<button type="button" data-v="${x}">${x} s</button>`).join('')}</div>
      <div class="l">👥 Players in the video <span style="font-weight:400;color:#8fa6d6">(balls / ${g[1] === 'player' ? 'players' : g[1] === 'one' ? 'signs' : 'flags'})</span></div><div class="seg" id="np" style="grid-template-columns:repeat(5,1fr)">${[2, 3, 4, 5, 6].map((x) => `<button type="button" data-v="${x}">${x}</button>`).join('')}</div>
      <div class="small" style="margin-top:4px">The scripts say the right number (“${NUMW[S.np]} flags, one golden cup…”) and fit the length.</div>
      <div class="l">🪝 Hook style (pick any)</div><div class="chips" id="sty">${Object.entries(STYLES).map(([k, v]) => `<button type="button" class="chip" data-v="${k}">${v}</button>`).join('')}</div>
      <div class="l">🔥 Hook power</div><div class="seg" id="pow">${['Soft', 'Strong', '200%', 'Crazy'].map((x, i) => `<button type="button" data-v="${i}">${x}</button>`).join('')}</div>
      <div class="l">🏳 ${g[1] === 'player' ? 'Players' : g[1] === 'one' ? 'Signs' : 'Countries'} in the video (optional, with commas)</div><input type="text" id="nm" maxlength="120" placeholder="${g[2].slice(0, 3).join(', ')}">
      <button type="button" class="go" id="mk">✨ Write 6 scripts</button>
      <button type="button" class="go go2" id="ai">🤖 6 new scripts with AI</button>
      <div class="small">• A hook is never suggested again after you copy or use it<br>• Other languages: use 🤖 AI (the ready-made lines are English)<br>• You can change any line — just tap it and type</div>
    </div>
    <div><div class="tabs"><button type="button" data-t="new">✨ New</button><button type="button" data-t="saved">⭐ Saved (${saved.length})</button></div><div class="note" id="note"></div><div class="list" id="list"></div></div>`;
    root.querySelector('#g').value = S.game; root.querySelector('#la').value = S.lang; root.querySelector('#au').value = S.aud; root.querySelector('#nm').value = S.names;
    root.querySelectorAll('#len button').forEach((b) => b.classList.toggle('on', Number(b.dataset.v) === S.secs));
    root.querySelectorAll('#np button').forEach((b) => b.classList.toggle('on', Number(b.dataset.v) === S.np));
    root.querySelectorAll('#pow button').forEach((b) => b.classList.toggle('on', Number(b.dataset.v) === S.power));
    root.querySelectorAll('#sty button').forEach((b) => b.classList.toggle('on', S.styles.has(b.dataset.v)));
    root.querySelectorAll('.tabs button').forEach((b) => b.classList.toggle('on', b.dataset.t === S.tab));
    showList();
  };
  const note = (t, ok) => { const n = root.querySelector('#note'); n.textContent = t; n.className = 'note' + (ok ? ' ok' : ''); };
  const showList = () => {
    const l = S.tab === 'saved' ? saved : list;
    root.querySelector('#list').innerHTML = l.length ? l.map((s, i) => card(s, i, S.tab === 'saved')).join('') : `<div class="small">${S.tab === 'saved' ? 'No saved scripts yet — press ⭐ on a script.' : 'Press ✨ Write 6 scripts.'}</div>`;
  };
  const read = (el) => { const s = { ...(S.tab === 'saved' ? saved : list).find((x) => x.id === el.dataset.id) }; el.querySelectorAll('[data-k]').forEach((d) => { s[d.dataset.k] = d.textContent.trim(); }); return s; };
  const markUsed = (s) => { usedSet.add(norm(s.hook)); post({ do: 'used', data: { key: norm(s.hook) } }).catch(() => {}); };
  const plain = (s) => [s.hook, s.build, s.hit, s.end].filter(Boolean).join('\n');

  root.addEventListener('change', (e) => {
    const t = e.target;
    if (t.id === 'g') { S.game = t.value; keep(); render(); }
    if (t.id === 'la') { S.lang = t.value; keep(); }
    if (t.id === 'au') { S.aud = t.value; keep(); }
    if (t.id === 'nm') { S.names = t.value; keep(); }
  });
  root.addEventListener('click', async (e) => {
    const b = e.target.closest('button'); if (!b) return;
    if (b.closest('#len')) { S.secs = Number(b.dataset.v); keep(); render(); return; }
    if (b.closest('#np')) { S.np = Number(b.dataset.v); keep(); render(); return; }
    if (b.closest('#pow')) { S.power = Number(b.dataset.v); keep(); render(); return; }
    if (b.closest('#sty')) { S.styles.has(b.dataset.v) ? S.styles.delete(b.dataset.v) : S.styles.add(b.dataset.v); keep(); b.classList.toggle('on'); return; }
    if (b.dataset.t) { S.tab = b.dataset.t; keep(); render(); return; }
    if (b.id === 'mk') {
      if (S.lang !== 'en') { note('The ready-made scripts are in English — for this language press 🤖 AI.'); return; }
      S.names = root.querySelector('#nm').value; keep(); S.tab = 'new'; list = make(); render();
      if (list.length < 6) note('Most ready-made hooks for these settings were used already — add more styles, change the power, or press 🤖 AI.');
      return;
    }
    if (b.id === 'ai') {
      S.names = root.querySelector('#nm').value; keep(); b.disabled = true; note('🤖 Writing… (about 10–20 seconds)');
      try {
        const r = await post({ do: 'ai', data: { game: S.game, lang: S.lang, secs: S.secs, np: S.np, styles: [...S.styles], power: S.power, names: S.names, aud: S.aud } });
        if (r.error) note('⚠️ ' + r.error);
        else { list = r.scripts.filter((s) => !usedSet.has(norm(s.hook))).map((s, i) => ({ ...s, id: 'a' + Date.now().toString(36) + i, lang: S.lang, score: score(s, S.power + 1) })).sort((x, y) => y.score - x.score); S.tab = 'new'; render(); note('🤖 New scripts from the AI.', true); }
      } catch (err) { note('⚠️ Could not reach the AI.'); }
      b.disabled = false; return;
    }
    const el = b.closest('.sc'); if (!el) return;
    const s = read(el);
    if (b.dataset.a === 'copy') {
      try { await navigator.clipboard.writeText(plain(s)); } catch (err) { /* ignore */ }
      markUsed(s); b.textContent = '✓ Copied'; note('Copied — this hook will not be suggested again.', true);
    }
    if (b.dataset.a === 'voice') {   // into the game's 🗣 My Voice (same website = same phone storage): start = hook + build, hit, end
      const K = 'fft_mvg_' + S.game; let mv = {};
      try { mv = JSON.parse(localStorage.getItem(K) || '{}') || {}; } catch (err) { mv = {}; }
      mv.on = true; mv.mode = 'lines'; mv.lang = ['en', 'es', 'pt', 'fr', 'de'].includes(S.lang) ? S.lang : 'en'; mv.text = { start: [s.hook, s.build].filter(Boolean).join(' '), hit: s.hit, end: s.end };
      try { localStorage.setItem(K, JSON.stringify(mv)); } catch (err) { note('⚠️ Could not save on this phone.'); return; }
      markUsed(s); b.textContent = '✓ In My Voice';
      note(`Ready in ${GAMES[S.game][0].replace(/ —.*/, '')} → 🗣 My Voice (on this phone / browser). Open the game and press 🎙 Make voice.`, true);
      const a = document.createElement('a'); a.href = '/' + S.game + '/'; a.target = '_blank'; a.rel = 'noopener'; a.textContent = ' Open the game ↗'; root.querySelector('#note').appendChild(a);
    }
    if (b.dataset.a === 'star') {
      const on = saved.some((x) => x.id === s.id);
      const r = await post({ do: on ? 'unsave' : 'save', data: { ...s, lang: s.lang || S.lang, game: S.game, secs: S.secs } }).catch(() => null);
      if (r && r.saved) { saved = r.saved; render(); note(on ? 'Removed from ⭐ Saved.' : 'Saved ⭐', true); }
    }
  });
  render();
})();
