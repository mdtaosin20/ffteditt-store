// FFTEdit · switch game on a running live without stopping it.
// Only on the stream's own page (?stream=…&sig=…). Every few seconds it asks the panel which game this
// stream should show; when the admin picked another one, it shows a 2-second "GAME UPDATE" card with the
// next game's name and picture, then opens the new game. The video to YouTube keeps going (same live).
(() => {
  const QS = new URLSearchParams(location.search), stream = QS.get('stream'), sig = QS.get('sig');
  const demo = QS.get('switchdemo');   // testing: ?switchdemo=1 shows the card once (without leaving)
  if (!(stream && sig) && !demo) return;
  const norm = (u) => {
    const x = new URL(u, location.origin);
    ['stream', 'sig', 'run', 'fresh', 'switchdemo'].forEach((k) => x.searchParams.delete(k));
    x.searchParams.sort();
    return x.pathname.replace(/\/index\.html$/, '/').replace(/\/?$/, '/') + (x.searchParams.toString() ? '?' + x.searchParams : '');
  };
  const here = norm(location.href);
  let going = false;

  const css = `
.gsw { position: absolute; inset: 0; z-index: 99999; overflow: hidden; font-family: "Rajdhani", "Oswald", system-ui, sans-serif; color: #fff; pointer-events: none; }
.gsw.fixed { position: fixed; }
.gsw-bg { position: absolute; inset: 0; background: radial-gradient(70% 45% at 50% 50%, rgba(40, 10, 80, .6), rgba(3, 2, 10, .93)); backdrop-filter: blur(14px) saturate(.6); -webkit-backdrop-filter: blur(14px) saturate(.6); animation: gsw-in .35s ease-out; }
.gsw-lines { position: absolute; inset: 0; background: repeating-linear-gradient(0deg, rgba(255, 255, 255, .035) 0 3px, transparent 3px 9px); }
.gsw-rays { position: absolute; left: 50%; top: 50%; width: 1800px; height: 1800px; margin: -900px 0 0 -900px; border-radius: 50%;
  background: conic-gradient(from 0deg, transparent 0 10deg, rgba(51, 240, 255, .09) 10deg 20deg, transparent 20deg 40deg, rgba(255, 43, 214, .09) 40deg 50deg, transparent 50deg 70deg); animation: gsw-spin 9s linear infinite; }
.gsw-card { position: absolute; left: 72px; right: 72px; top: 400px; padding: 6px; border-radius: 60px; background: linear-gradient(135deg, #ff2bd6, #7b3cff 45%, #33f0ff);
  box-shadow: 0 0 120px rgba(51, 240, 255, .55), 0 0 240px rgba(255, 43, 214, .35); animation: gsw-pop .55s cubic-bezier(.2, 1.4, .4, 1); }
.gsw-in { border-radius: 54px; background: linear-gradient(170deg, rgba(26, 13, 58, .98), rgba(9, 5, 26, .98)); padding: 44px 44px 40px; text-align: center; }
.gsw-pill { display: inline-flex; align-items: center; gap: 16px; padding: 12px 32px; border-radius: 999px; background: rgba(255, 210, 58, .14); border: 4px solid #ffd23a; color: #ffe680; font-weight: 700; font-size: 38px; letter-spacing: 6px; }
.gsw-dot { width: 20px; height: 20px; border-radius: 50%; background: #ff2d55; box-shadow: 0 0 20px #ff2d55; animation: gsw-blink .8s ease-in-out infinite alternate; }
.gsw-small { margin-top: 32px; font-size: 36px; letter-spacing: 10px; color: #b99cff; font-weight: 700; }
.gsw-name { font-family: "Anton", "Oswald", Impact, sans-serif; font-size: 112px; line-height: .95; margin-top: 8px; text-transform: uppercase; text-shadow: 0 0 44px #b14dff; }
.gsw-name em { font-style: normal; color: #33f0ff; text-shadow: 0 0 44px #33f0ff; }
.gsw-prev { position: relative; margin: 32px auto 0; width: 100%; aspect-ratio: 480 / 311; border-radius: 32px; background: #120a2a center / cover no-repeat; box-shadow: 0 0 0 4px rgba(255, 255, 255, .25), 0 20px 60px rgba(0, 0, 0, .6); }
.gsw-prev b { position: absolute; left: 20px; top: 20px; font-size: 28px; letter-spacing: 4px; padding: 6px 20px; border-radius: 16px; background: #ff2bd6; }
.gsw-row { display: flex; align-items: center; justify-content: center; gap: 28px; margin-top: 32px; }
.gsw-ring { position: relative; width: 132px; height: 132px; flex: 0 0 auto; }
.gsw-ring svg { width: 132px; height: 132px; transform: rotate(-90deg); }
.gsw-ring circle { fill: none; stroke-width: 10; }
.gsw-ring .a { stroke: #33f0ff; stroke-linecap: round; stroke-dasharray: 264; stroke-dashoffset: 264; animation: gsw-arc 2s linear forwards; }
.gsw-ring b { position: absolute; inset: 0; display: grid; place-items: center; font-family: "Anton", "Oswald", Impact, sans-serif; font-size: 64px; font-weight: 400; }
.gsw-msg { text-align: left; font-size: 50px; font-weight: 700; line-height: 1.1; }
.gsw-msg small { display: block; font-size: 32px; color: #9df7ff; font-weight: 600; letter-spacing: 2px; margin-top: 6px; }
.gsw-bar { margin-top: 32px; height: 20px; border-radius: 12px; background: #2a1d4d; overflow: hidden; }
.gsw-bar i { display: block; height: 100%; width: 0; background: linear-gradient(90deg, #ff2bd6, #33f0ff); box-shadow: 0 0 24px #33f0ff; animation: gsw-bar 2s linear forwards; }
.gsw-fl { position: absolute; width: 68px; height: 68px; border-radius: 50%; background: url(/flags/flags.png?v=2) no-repeat; box-shadow: 0 0 0 4px #fff; opacity: .85; animation: gsw-float 3s ease-in-out infinite alternate; }
@keyframes gsw-in { from { opacity: 0; } to { opacity: 1; } }
@keyframes gsw-pop { from { transform: scale(.4); opacity: 0; } to { transform: none; opacity: 1; } }
@keyframes gsw-spin { to { transform: rotate(360deg); } }
@keyframes gsw-blink { to { opacity: .35; } }
@keyframes gsw-arc { to { stroke-dashoffset: 0; } }
@keyframes gsw-bar { to { width: 100%; } }
@keyframes gsw-float { to { transform: translateY(-36px) rotate(18deg); } }
.gpr { position: absolute; left: 48px; right: 48px; top: 636px; z-index: 99998; padding: 6px; border-radius: 52px; pointer-events: none; font-family: "Rajdhani", "Oswald", system-ui, sans-serif; color: #fff;
  background: linear-gradient(135deg, #ff2d55, #ff2bd6 40%, #33f0ff); box-shadow: 0 0 100px rgba(255, 43, 214, .55); animation: gsw-pop .5s cubic-bezier(.2, 1.4, .4, 1); }
.gpr.fixed { position: fixed; }
.gpr.out { animation: gpr-out .45s ease-in forwards; }
.gpr-in { border-radius: 46px; background: rgba(12, 6, 30, .96); padding: 28px 32px 26px; }
.gpr h2 { font-family: "Anton", "Oswald", Impact, sans-serif; font-weight: 400; font-size: 70px; text-align: center; letter-spacing: 2px; line-height: 1; margin: 0; }
.gpr h2 em { font-style: normal; color: #ffd23a; }
.gpr-sub { text-align: center; font-size: 28px; color: #b99cff; letter-spacing: 2px; font-weight: 700; margin-top: 6px; }
.gpr-r { display: flex; align-items: center; gap: 24px; margin-top: 20px; padding: 16px 20px; border-radius: 28px; background: rgba(255, 255, 255, .05); border: 3px solid; }
.gpr-i { width: 92px; height: 92px; border-radius: 24px; display: grid; place-items: center; font-size: 52px; flex: 0 0 auto; }
.gpr-r b { font-size: 46px; letter-spacing: 1px; display: block; line-height: 1.05; }
.gpr-r small { display: block; font-size: 30px; opacity: .88; font-weight: 600; line-height: 1.15; }
.gpr-eq { margin-left: auto; font-family: "Anton", "Oswald", Impact, sans-serif; font-size: 44px; white-space: nowrap; }
.gpr-t { margin-top: 18px; height: 12px; border-radius: 8px; background: #2a1d4d; overflow: hidden; }
.gpr-t i { display: block; height: 100%; width: 100%; background: linear-gradient(90deg, #ff2bd6, #33f0ff); transform-origin: left; }
@keyframes gpr-out { to { transform: translateY(-40px) scale(.9); opacity: 0; } }
@keyframes gpr-time { to { transform: scaleX(0); } }`;

  const flagsHTML = async () => {
    try {
      const J = await (await fetch('/flags/flags.json?v=2')).json(), cols = J.cols, rows = Math.ceil(J.countries.length / cols), idx = new Map(J.countries.map((c, i) => [c.code, i]));
      const pos = [[80, 220, 'bd'], [940, 280, 'br'], [60, 1520, 'in'], [960, 1600, 'us'], [500, 1760, 'tr'], [240, 120, 'fr'], [800, 120, 'pk'], [120, 1800, 'eg'], [1000, 1040, 'ar'], [20, 960, 'ng']];
      return pos.map(([x, y, c], k) => { const i = idx.get(c), s = 68, w = s * 4 / 3; if (i === undefined) return '';
        return `<span class="gsw-fl" style="left:${x}px;top:${y}px;animation-delay:${-k * 0.4}s;background-size:${cols * w}px ${rows * s}px;background-position:${-(i % cols) * w - (w - s) / 2}px ${-Math.floor(i / cols) * s}px"></span>`; }).join('');
    } catch (e) { return ''; }
  };

  const show = async (info) => {
    if (!document.getElementById('gsw-css')) { const st = document.createElement('style'); st.id = 'gsw-css'; st.textContent = css; document.head.append(st); }
    const stage = document.getElementById('stage');
    const el = document.createElement('div'); el.className = 'gsw' + (stage ? '' : ' fixed');
    const words = String(info.name || 'New game').trim().split(/\s+/), last = words.length > 1 ? words.pop() : '';
    const esc = (t) => String(t).replace(/[&<>"]/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m]));
    el.innerHTML = `<div class="gsw-bg"></div><div class="gsw-rays"></div><div class="gsw-lines"></div>${await flagsHTML()}
      <div class="gsw-card"><div class="gsw-in">
        <span class="gsw-pill"><span class="gsw-dot"></span>GAME UPDATE</span>
        <div class="gsw-small">NEXT GAME</div>
        <div class="gsw-name">${esc(words.join(' '))}${last ? ` <em>${esc(last)}</em>` : ''}</div>
        <div class="gsw-prev" style="background-image:url('${esc(info.thumb || '')}')"><b>NEXT</b></div>
        <div class="gsw-row"><div class="gsw-ring"><svg viewBox="0 0 100 100"><circle cx="50" cy="50" r="42" stroke="#2a1d4d"/><circle class="a" cx="50" cy="50" r="42"/></svg><b>2</b></div>
          <div class="gsw-msg">Continue for now —<br>stay tuned! 👀<small>SAME LIVE · NEW ROUND STARTS</small></div></div>
        <div class="gsw-bar"><i></i></div>
      </div></div>`;
    if (!stage) { const k = Math.min(innerWidth / 1080, innerHeight / 1920); el.style.cssText = `width:1080px;height:1920px;left:50%;top:50%;transform:translate(-50%,-50%) scale(${k});`; }
    (stage || document.body).append(el);
    const n = el.querySelector('.gsw-ring b');
    setTimeout(() => { n.textContent = '1'; }, 1000);
    try { const a = new (window.AudioContext || window.webkitAudioContext)(), t = a.currentTime;   // a short "whoosh-ding"
      [[440, 0], [660, 0.12], [990, 0.24]].forEach(([f, d]) => { const o = a.createOscillator(), g = a.createGain(); o.type = 'sine'; o.frequency.value = f;
        g.gain.setValueAtTime(0.0001, t + d); g.gain.exponentialRampToValueAtTime(0.12, t + d + 0.02); g.gain.exponentialRampToValueAtTime(0.0001, t + d + 0.5); o.connect(g).connect(a.destination); o.start(t + d); o.stop(t + d + 0.55); });
    } catch (e) { /* no sound */ }
    return el;
  };

  // ---- the "subscribe / share / like" card (Admin → Screen Notification)
  let promoOn = false;
  const showPromo = (p) => {
    if (going || promoOn || !p) return;
    if (!document.getElementById('gsw-css')) { const st = document.createElement('style'); st.id = 'gsw-css'; st.textContent = css; document.head.append(st); }
    const esc = (t) => String(t ?? '').replace(/[&<>"]/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m]));
    const stage = document.getElementById('stage');
    const el = document.createElement('div'); el.className = 'gpr' + (stage ? '' : ' fixed');
    const t = String(p.title || '').trim().split(/\s+/), last = t.length > 1 ? t.pop() : '';
    const secs = Math.max(1, Math.min(3600, Number(p.secs) || 7));
    el.innerHTML = `<div class="gpr-in"><h2>${esc(t.join(' '))}${last ? ` <em>${esc(last)}</em>` : ''}</h2>${p.sub ? `<div class="gpr-sub">${esc(p.sub)}</div>` : ''}`
      + (p.lines || []).map((l) => `<div class="gpr-r" style="border-color:${esc(l.c)}"><span class="gpr-i" style="background:${esc(l.c)}">${esc(l.i)}</span><div><b>${esc(l.b)}</b><small>${esc(l.s)}</small></div><span class="gpr-eq" style="color:${esc(l.c)}">${esc(l.r)}</span></div>`).join('')
      + `<div class="gpr-t"><i style="animation:gpr-time ${secs}s linear forwards"></i></div></div>`;
    if (!stage) { const k = Math.min(innerWidth / 1080, innerHeight / 1920); el.style.cssText = `width:${1080 - 96}px;left:50%;top:33%;transform:translateX(-50%) scale(${k});transform-origin:top center;`; }
    (stage || document.body).append(el); promoOn = true;
    setTimeout(() => { el.classList.add('out'); setTimeout(() => { el.remove(); promoOn = false; }, 500); }, secs * 1000);
  };
  const PKEY = 'fft_promo_seen_' + stream;
  let lastAuto = performance.now();
  const promoTick = (p) => {
    if (!p) return;
    let seen = null; try { seen = localStorage.getItem(PKEY); } catch (e) { /* ignore */ }
    if (seen === null) { try { localStorage.setItem(PKEY, String(p.push || 0)); } catch (e) { /* ignore */ } seen = String(p.push || 0); }
    if ((p.push || 0) > Number(seen)) { try { localStorage.setItem(PKEY, String(p.push)); } catch (e) { /* ignore */ } lastAuto = performance.now(); showPromo(p); return; }
    if (p.every > 0 && performance.now() - lastAuto >= p.every * 60000) { lastAuto = performance.now(); showPromo(p); }
  };

  const check = async () => {
    if (going) return;
    try {
      const r = await fetch(`/api/page.php?stream=${encodeURIComponent(stream)}&sig=${encodeURIComponent(sig)}`, { cache: 'no-store' });
      if (!r.ok) return;
      const j = await r.json();
      if (!j.page || !j.url || norm(j.page) === here) { promoTick(j.promo); return; }
      going = true;
      await show(j);
      setTimeout(() => { location.href = j.url; }, 2100);
    } catch (e) { /* offline: try again */ }
  };
  if (demo === 'promo') { fetch('/admin/promo.php?json=1', { cache: 'no-store' }).then((r) => r.json()).then((p) => setTimeout(() => showPromo(p), 1500)).catch(() => {}); return; }
  if (demo) { setTimeout(() => show({ name: QS.get('name') || 'Last Flag Standing', thumb: QS.get('thumb') || '/assets/site/lastflag.jpg' }), 1500); return; }
  setTimeout(check, 4000);
  setInterval(check, 8000);
})();
