// On the live stream (the VPS's Chrome) only: draw as many frames as the stream sends (e.g. 20 a second),
// not 60. Without a graphics card every frame is drawn by the CPU, so this is most of the CPU a live uses.
//  - the games' animation loop runs at the stream's frame rate (they move by real time, so they play the same)
//  - CSS animations / transitions (banners, LUNA, bars) are stepped at the same rate instead of 60 a second
// Normal visitors are not touched.
(() => {
  const q = new URLSearchParams(location.search);
  if (!q.get('stream') || !q.get('sig')) return;
  const fps = Math.max(10, Math.min(60, Number(q.get('fps')) || 30));
  if (fps >= 60) return;
  // draw twice as often as the stream takes pictures: drawing exactly at the stream's rate, out of step
  // with the capture, makes the picture judder (a frame shown twice, the next one skipped)
  const gap = 1000 / Math.min(60, fps * 2);
  const diag = q.get('diag');
  if (diag) addEventListener('DOMContentLoaded', () => { const st = document.createElement('style');
    st.textContent = diag === 'hidecanvas' ? 'canvas{visibility:hidden!important}' : diag === 'hideall' ? 'html{visibility:hidden!important}' : diag === 'onlycanvas' ? 'body *:not(canvas){visibility:hidden!important} canvas{visibility:visible!important}' : '';
    document.head.appendChild(st); });

  // 1) requestAnimationFrame at the stream's rate
  if (diag === 'noraf') { window.requestAnimationFrame = () => 0; }
  const raf = diag === 'noraf' ? () => 0 : window.requestAnimationFrame.bind(window);
  let next = 0;
  window.requestAnimationFrame = (cb) => raf(function tick(t) {
    if (t + 2 >= next) { next = Math.max(next + gap, t); cb(t); } else raf(tick);
  });

  // 2) CSS animations and transitions: paused, and moved forward fps times a second
  if (q.get('css') === '0' || !document.getAnimations) return;
  const held = new Map();   // animation -> [time when held, its time then]
  const stepCss = () => {
    const now = performance.now();
    for (const a of document.getAnimations()) {
      let h = held.get(a);
      if (!h) {
        if (a.playState !== 'running') continue;
        h = [now, Number(a.currentTime) || 0];
        a.pause();
        held.set(a, h);
        a.addEventListener('cancel', () => held.delete(a), { once: true });
      }
      const t = h[1] + (now - h[0]) * (a.playbackRate || 1);
      const end = a.effect && a.effect.getComputedTiming ? a.effect.getComputedTiming().endTime : Infinity;
      if (t >= end && end !== Infinity) { held.delete(a); try { a.play(); a.finish(); } catch (e) { /* ignore */ } continue; }
      try { a.currentTime = t; } catch (e) { held.delete(a); }
    }
    for (const a of held.keys()) if (a.playState === 'idle') held.delete(a);
  };
  setInterval(stepCss, gap);
})();
