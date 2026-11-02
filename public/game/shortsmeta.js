// 📝 Title · Description · Thumbnail for the Shorts games (Cup Kick, Action Kick, Player Kick).
// When Auto record has made a video, this box appears under "Video ready". It knows the play (who played, who won,
// how hard it was, how long the video is), so it writes 4 hook titles that were never used before on this phone,
// a description with a comment question and hashtags, and a 9:16 thumbnail (1080×1920) from the moment just before
// the cup is hit — a red circle on the cup, a big hook and the question. ✨ AI (Admin → Settings key) is optional.
const css = `
.smeta{margin-top:12px;text-align:left;background:#0b1230;border:1px solid #2a4a9a;border-radius:14px;padding:12px}
.smeta h4{font-size:15px;color:#fff;margin:0 0 6px}
.smeta .an{font-size:12px;color:#9fb0d6;line-height:1.5;background:#121d42;border-radius:9px;padding:6px 8px}
.smeta .an b{color:#ffd84a}
.smeta .lb{display:flex;align-items:center;gap:6px;margin-top:10px;font-size:13px;font-weight:800;color:#9fd0ff}
.smeta .lb span{margin-left:auto;display:flex;gap:4px}
.smeta .lb button,.smeta .ti button{width:auto!important;margin:0!important;padding:4px 8px!important;border-radius:7px;background:#2a3a7a!important;color:#fff;font-size:12px!important;flex:0 0 auto}
.smeta .ti{display:flex;align-items:center;gap:6px;margin-top:6px;background:#0d1838;border:1px solid #2a3a6a;border-radius:9px;padding:7px 8px;font:600 13px/1.3 Arial,sans-serif;color:#fff}
.smeta .ti.ok{border-color:#22c55e;background:#0f2a22}
.smeta .ti div{flex:1;min-width:0}
.smeta textarea{width:100%;min-height:150px;margin-top:6px;box-sizing:border-box;border-radius:9px;border:1px solid #2a3a6a;background:#0d1838;color:#dfe6fb;font:12.5px/1.45 Arial,sans-serif;padding:8px;resize:vertical}
.smeta .thw{display:flex;gap:10px;align-items:flex-start;margin-top:8px}
.smeta .thw img{width:120px;height:213px;border-radius:10px;border:2px solid #ffd23a;object-fit:cover;background:#000;flex:0 0 auto}
.smeta .thw .bt{display:flex;flex-direction:column;gap:6px;flex:1}
.smeta .thw .bt button{margin:0!important;font-size:13px!important;padding:9px 6px!important}
.smeta .msg{font-size:12px;color:#fbbf24;margin-top:4px;min-height:14px}
`;
const pick = (a) => a[Math.floor(Math.random() * a.length)];
const shuffle = (a) => { a = [...a]; for (let i = a.length - 1; i > 0; i--) { const j = Math.floor(Math.random() * (i + 1)); [a[i], a[j]] = [a[j], a[i]]; } return a; };
const cap = (s) => String(s).toLowerCase().replace(/(^|[\s\-.'])\p{L}/gu, (m) => m.toUpperCase());
const USED = 'fft_meta_used';
const used = () => { try { return new Set(JSON.parse(localStorage.getItem(USED) || '[]')); } catch (e) { return new Set(); } };
const norm = (t) => t.toLowerCase().replace(/[^\p{L}\p{N}]+/gu, ' ').trim();
const markUsed = (t) => { try { const u = [...used()]; u.push(norm(t)); localStorage.setItem(USED, JSON.stringify(u.slice(-3000))); } catch (e) { /* ignore */ } };

/* ---------- the words ---------- */
const WHO = { country: ['Which Country', 'Which Flag', 'Which Team'], sign: ['Which One', 'Which Button', 'Which Sign'], player: ['Which Player', 'Who', 'Which Star'] };
const THING = ['The Cup', 'The Trophy', 'The Golden Cup', 'The Golden Trophy'];
const HIT = ['Hit', 'Touched', 'Knocked Down', 'Tipped Over'];
const TITLE_T = [
  'Only 1% Can See {who} {hit} {thing} 👀🏆', '99% Get This Wrong… {who} {hit} {thing}? 🤯', 'I Watched It 5 Times And Still Got It Wrong 😳 {who} {hit} {thing}?',
  'Watch Closely… {who} {hit} {thing}? 👀', '{a}, {b} or {c}? Watch {thing} Closely ⚽🔥', 'Bet You Can\'t Tell {who} {hit} {thing} 😏🏆',
  'Your Eyes Will Lie To You 👀 {who} {hit} {thing}?', 'Nobody Gets This Right The First Time 🤯 {who} {hit} {thing}?', 'Pause At The Right Moment ⏸️ {who} {hit} {thing}?',
  'This Is Harder Than It Looks 😵 {who} {hit} {thing}?', '{a} vs {b} vs {c} — {who} {hit} {thing}? 🏆', 'Can You Spot It? 🔍 {who} {hit} {thing}',
  'Even Slow Motion Won\'t Help 😅 {who} {hit} {thing}?', 'If You Get This Right You Have Eagle Eyes 🦅 {who} {hit} {thing}?', 'Look Again… {who} Really {hit} {thing}? 👀',
  'The Answer Will Surprise You 😱 {who} {hit} {thing}?', '{n} Balls, 1 {thingS}… {who} {hit} It? ⚽', 'Don\'t Blink 👁️ {who} {hit} {thing}?',
  'Test Your Eyes ⚽👀 {who} {hit} {thing}?', 'Only Real Fans See It 🔥 {who} {hit} {thing}?', 'Everyone Picks The Wrong One 🤦 {who} {hit} {thing}?',
  'Watch Till The End 😳 {who} {hit} {thing}?', '{a} Fans, Prove Me Wrong 😤 {who} {hit} {thing}?', 'Impossible Football Challenge 🤯 {who} {hit} {thing}?',
];
const HOOK = ['ONLY 1%\nSEE IT 👀', '99% FAIL\nTHIS 😱', 'WATCH\nCLOSELY 👀', 'IMPOSSIBLE? 🤯', 'DON\'T\nBLINK 👁️', 'EAGLE EYES\nONLY 🦅', 'CAN YOU\nSEE IT? 🔍', 'NOBODY\nGETS IT 😳'];
const QUESTION = { country: ['WHICH COUNTRY\nHIT THE CUP? 🏆', 'WHICH FLAG\nWON? 🏆'], sign: ['WHICH ONE\nHIT THE CUP? 🏆'], player: ['WHO HIT\nTHE CUP? 🏆', 'WHICH PLAYER\nWON? 🏆'] };
const TAGS = { country: ['#countries', '#flags', '#worldcup'], sign: ['#subscribe', '#like', '#challenge'], player: ['#messi', '#ronaldo', '#footballplayers'] };
const THEMES = [['#ffd400', '#ff1f3d'], ['#00e5ff', '#ff2bd6'], ['#a3ff12', '#ff6a00'], ['#ffffff', '#ff1f3d']];

const makeTitles = (info, n = 4) => {
  const u = used(), who = WHO[info.kind] || WHO.country, names = shuffle(info.names).map(cap), out = [];
  for (let tries = 0; tries < 400 && out.length < n; tries++) {
    const t = pick(TITLE_T).replaceAll('{who}', pick(who)).replaceAll('{hit}', pick(HIT)).replaceAll('{thing}', pick(THING)).replaceAll('{thingS}', pick(['Cup', 'Trophy']))
      .replaceAll('{a}', names[0] || '').replaceAll('{b}', names[1] || '').replaceAll('{c}', names[2] || '').replaceAll('{n}', String(info.names.length));
    const k = norm(t);
    if (t.length > 100 || u.has(k) || out.some((x) => norm(x) === k) || out.some((x) => x.slice(0, 14) === t.slice(0, 14))) continue;
    out.push(t);
  }
  return out;
};
const makeDesc = (info) => {
  const what = { country: `${info.names.length} countries kick at the golden cup`, sign: `${info.names.length} players with SUBSCRIBE, LIKE, COMMENT, SHARE… kick at the golden cup`, player: `${info.names.length} football stars kick at the golden cup` }[info.kind];
  const list = info.names.map((x, i) => `${info.emojis[i] || '⚽'} ${cap(x)}`).join('  ');
  const first = pick([`${what} — only ONE ball touches it 🏆`, `${what}… but only one ball really touches it 🏆`, `One cup, ${info.names.length} balls, only one winner 🏆`]);
  const second = pick(['Watch closely… then watch again 👀 Did you see it the first time?', 'Most people need 3 tries. How many did you need? 👀', 'Slow down the video if you have to 😅 Did you get it right?']);
  const third = pick(['👇 Comment your answer:', '👇 Who was it? Comment below:', '👇 Write your answer in the comments:']);
  const tags = ['#shorts', '#football', '#challenge', ...TAGS[info.kind], ...shuffle(info.names).slice(0, 3).map((x) => '#' + String(x).toLowerCase().replace(/[^\p{L}\p{N}]/gu, ''))];
  return `${first}\n${second}\n${third}\n${list}\n${info.level >= 4 ? '🔥 Level: EXTREME — only eagle eyes get this one!\n' : ''}🔔 Subscribe for a new challenge every day!\n\n${[...new Set(tags)].join(' ')}`;
};

/* ---------- the thumbnail ---------- */
const makeThumb = (snap, info) => {
  const W = 1080, H = 1920, c = document.createElement('canvas'); c.width = W; c.height = H;
  const g = c.getContext('2d'), [main, accent] = pick(THEMES);
  g.fillStyle = '#0b1024'; g.fillRect(0, 0, W, H);
  // zoom into the picture around the cup (the cup a little under the middle)
  const cup = snap.cup || { x: 0.5, y: 0.7 }, z = 1.7 + Math.random() * 0.3;
  const sw = snap.c.width / z, sh = snap.c.height / z;
  const sx = Math.max(0, Math.min(snap.c.width - sw, cup.x * snap.c.width - sw / 2)), sy = Math.max(0, Math.min(snap.c.height - sh, cup.y * snap.c.height - sh * 0.56));
  g.filter = 'saturate(1.25) contrast(1.08)'; g.drawImage(snap.c, sx, sy, sw, sh, 0, 0, W, H); g.filter = 'none';
  const cx = (cup.x * snap.c.width - sx) / sw * W, cy = (cup.y * snap.c.height - sy) / sh * H;
  let gr = g.createLinearGradient(0, 0, 0, H); gr.addColorStop(0, 'rgba(0,0,0,.7)'); gr.addColorStop(0.3, 'rgba(0,0,0,0)'); gr.addColorStop(0.62, 'rgba(0,0,0,0)'); gr.addColorStop(1, 'rgba(0,0,0,.8)');
  g.fillStyle = gr; g.fillRect(0, 0, W, H);
  // red circle around the cup and an arrow
  const rx = 150 * z / 1.8, ry = 200 * z / 1.8;
  g.save(); g.shadowColor = accent; g.shadowBlur = 30; g.strokeStyle = accent; g.lineWidth = 16; g.beginPath(); g.ellipse(cx, cy - ry * 0.25, rx, ry, 0, 0, 7); g.stroke(); g.restore();
  const ax = cx - rx - 40, ay = cy - ry - 30;
  g.save(); g.translate(ax, ay); g.rotate(Math.PI / 4); g.fillStyle = accent; g.shadowColor = '#000'; g.shadowBlur = 16;
  g.fillRect(-120, -16, 110, 32); g.beginPath(); g.moveTo(0, -46); g.lineTo(56, 0); g.lineTo(0, 46); g.closePath(); g.fill(); g.restore();
  // the "?" badge
  const bx = Math.min(W - 130, cx + rx + 90), by = Math.max(560, cy - ry - 140);
  g.save(); g.translate(bx, by); g.rotate(0.2); g.fillStyle = '#111'; g.beginPath(); g.arc(0, 0, 104, 0, 7); g.fill();
  g.fillStyle = main; g.beginPath(); g.arc(0, 0, 90, 0, 7); g.fill(); g.fillStyle = '#111'; g.font = '900 130px Impact, "Arial Black", sans-serif'; g.textAlign = 'center'; g.textBaseline = 'middle'; g.fillText('?', 0, 8); g.restore();
  // big words: the hook at the top, the question at the bottom
  const words = (text, y, size, col, up) => {
    const lines = text.split('\n'); g.textAlign = 'center'; g.textBaseline = 'middle'; g.lineJoin = 'round';
    lines.forEach((ln, i) => {
      let fs = size; g.font = `900 ${fs}px Impact, "Arial Black", sans-serif`; while (g.measureText(ln).width > W - 90 && fs > 50) { fs -= 4; g.font = `900 ${fs}px Impact, "Arial Black", sans-serif`; }
      const yy = up ? y - (lines.length - 1 - i) * size * 1.08 : y + i * size * 1.08;
      g.lineWidth = fs * 0.16; g.strokeStyle = '#000'; g.strokeText(ln, W / 2, yy); g.fillStyle = col; g.fillText(ln, W / 2, yy);
    });
  };
  words(pick(HOOK), 170, 150, '#ffffff', false);
  words(pick(QUESTION[info.kind] || QUESTION.country), H - 260, 112, main, true);
  // a pill at the bottom
  const pill = pick(['WATCH 3 TIMES 🔁', 'ANSWER IN COMMENTS 👇', info.level >= 4 ? 'EXTREME LEVEL 🔥' : 'HARD LEVEL 🔥']);
  g.font = '800 54px Arial, sans-serif'; const pw = g.measureText(pill).width + 70;
  g.fillStyle = accent; g.beginPath(); g.roundRect(W / 2 - pw / 2, H - 150, pw, 92, 46); g.fill(); g.fillStyle = '#fff'; g.fillText(pill, W / 2, H - 103);
  return c;
};

/** box(bx, info, snap): put the box into the "Video ready" card. info = { kind, names, emojis, winner, level, secs, loop, crowd } */
export function setupMeta() {
  const st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);
  return (bx, info, snap) => {
    if (!info || !info.names || !info.names.length) return;
    const el = document.createElement('div'); el.className = 'smeta';
    el.innerHTML = `<h4>📝 Title · Description · Thumbnail</h4>
      <div class="an">${info.names.length} ${info.kind === 'player' ? 'players' : info.kind === 'sign' ? 'signs' : 'countries'}: ${info.emojis.join(' ')} · answer: <b>${String(info.winner).replace(/</g, '')}</b> (never in the title)<br>
      Level: <b>${['Calm', 'Normal', 'Wild', 'Very hard', 'Extreme 🔥'][info.level] || info.level}</b> · ${info.secs.toFixed(1)} s${info.loop ? ' · 🔁 loop' : ''}</div>
      <div class="lb">🏷 Titles (never used before)<span><button type="button" data-a="more">🔄 New</button><button type="button" data-a="ai">✨ AI</button></span></div><div class="tl"></div>
      <div class="lb">📄 Description<span><button type="button" data-a="desc">🔄 New</button><button type="button" data-a="cdesc">📋 Copy</button></span></div><textarea spellcheck="false"></textarea>
      <div class="lb">🖼 Thumbnail 9:16 (1080×1920)</div>
      <div class="thw"><img alt="Thumbnail"><div class="bt"><button type="button" data-a="save">💾 Save thumbnail</button><button type="button" class="ghost" data-a="thumb" style="background:#1f2b5c">🎲 New thumbnail</button></div></div>
      <div class="msg"></div>`;
    bx.appendChild(el);
    const tl = el.querySelector('.tl'), ta = el.querySelector('textarea'), img = el.querySelector('img'), msg = el.querySelector('.msg');
    const say = (t) => { msg.textContent = t; };
    const copy = async (t) => { try { await navigator.clipboard.writeText(t); return true; } catch (e) { const x = document.createElement('textarea'); x.value = t; document.body.appendChild(x); x.select(); try { document.execCommand('copy'); } catch (e2) { /* ignore */ } x.remove(); return true; } };
    const showTitles = (list) => {
      tl.innerHTML = '';
      list.forEach((t) => {
        const d = document.createElement('div'); d.className = 'ti'; const s = document.createElement('div'); s.textContent = t;
        const b = document.createElement('button'); b.type = 'button'; b.textContent = '📋 Copy';
        b.onclick = async () => { await copy(t); markUsed(t); d.classList.add('ok'); b.textContent = '✓ Copied'; say('Title copied — it will not be suggested again.'); };
        d.append(s, b); tl.appendChild(d);
      });
      if (!list.length) tl.innerHTML = '<div class="ti">All ideas were used — press ✨ AI for new ones.</div>';
    };
    showTitles(makeTitles(info));
    ta.value = makeDesc(info);
    let thumb = null, url = '';
    const newThumb = () => {
      if (!snap || !snap.c) { img.hidden = true; el.querySelector('[data-a=save]').hidden = true; el.querySelector('[data-a=thumb]').hidden = true; return; }
      thumb = makeThumb(snap, info);
      thumb.toBlob((b) => { if (url) URL.revokeObjectURL(url); url = URL.createObjectURL(b); img.src = url; }, 'image/jpeg', 0.92);
    };
    newThumb();
    el.addEventListener('click', async (e) => {
      const a = e.target.closest('[data-a]'); if (!a) return;
      const k = a.dataset.a;
      if (k === 'more') showTitles(makeTitles(info));
      if (k === 'desc') ta.value = makeDesc(info);
      if (k === 'cdesc') { await copy(ta.value); say('Description copied.'); }
      if (k === 'thumb') newThumb();
      if (k === 'save' && url) { const l = document.createElement('a'); l.href = url; l.download = `thumbnail-${Date.now()}.jpg`; document.body.appendChild(l); l.click(); l.remove(); say('Thumbnail saved (look in Downloads). Upload it in YouTube Studio on a computer.'); }
      if (k === 'ai') {
        a.disabled = true; say('✨ Asking the AI…');
        try {
          const r = await fetch('/api/shorts_meta.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ info: { ...info, winner: undefined }, used: [...used()].slice(-60) }) });
          const j = await r.json();
          if (j.error) say('⚠️ ' + j.error);
          else { const u = used(); const list = (j.titles || []).filter((t) => t && !u.has(norm(t))).slice(0, 4); if (list.length) showTitles(list); if (j.description) ta.value = j.description; say('✨ New titles and description from the AI.'); }
        } catch (err) { say('⚠️ Could not reach the AI. Check the internet and try again.'); }
        a.disabled = false;
      }
    });
  };
}

/** a country code → its flag emoji (gb → 🇬🇧) */
export const flagEmoji = (code) => (/^[a-z]{2}$/.test(code || '') ? String.fromCodePoint(...[...code.toUpperCase()].map((ch) => 0x1F1E6 + ch.charCodeAt(0) - 65)) : '⚽');
