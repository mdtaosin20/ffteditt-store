// FFTEdit · live update without Restart.
// The game page asks the site every 30 s which version of its files is there. After a deploy the
// version changes; when it is the same on two checks in a row (the deploy is finished) the page
// saves the game and reloads itself. The game goes on where it was, and the stream to YouTube
// never stops — the same live video keeps its viewers.
//
//   autoUpdate('war', () => save());
export function autoUpdate(game, beforeReload) {
  let first = null, seen = null;
  const check = async () => {
    try {
      const r = await fetch('/api/version.php?g=' + encodeURIComponent(game), { cache: 'no-store' });
      if (!r.ok) return;
      const { v } = await r.json();
      if (!v) return;
      if (first === null) { first = v; return; }
      if (v === first) { seen = null; return; }
      if (seen !== v) { seen = v; return; }      // wait one more check: the deploy may still be copying files
      try { beforeReload && beforeReload(); } catch (e) { /* reload anyway */ }
      location.reload();
    } catch (e) { /* offline: try again later */ }
  };
  check();
  setInterval(check, 30000);
}
