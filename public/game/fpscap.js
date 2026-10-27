// On the live stream (the VPS's Chrome): draw only as many frames as the stream sends (e.g. 24 a second)
// instead of 60. The games move by real time, so they play the same — the server just does ~60% less drawing.
(() => {
  const q = new URLSearchParams(location.search);
  if (!q.get('stream') || !q.get('sig')) return;           // normal visitors: untouched
  const fps = Math.max(10, Math.min(60, Number(q.get('fps')) || 30));
  if (fps >= 60) return;
  const raf = window.requestAnimationFrame.bind(window);
  const gap = 1000 / fps;
  let next = 0;
  window.requestAnimationFrame = (cb) => raf(function tick(t) {
    if (t + 2 >= next) { next = Math.max(next + gap, t); cb(t); } else raf(tick);
  });
})();
