// FFTEdit admin — small helpers: live status refresh + copy buttons.
(function () {
  // Copy buttons (Servers page install line)
  document.querySelectorAll('[data-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var el = document.getElementById(btn.getAttribute('data-copy'));
      if (!el) return;
      var text = el.textContent.trim();
      var done = function () { btn.textContent = 'Copied!'; setTimeout(function () { btn.textContent = 'Copy'; }, 2000); };
      if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(done, function () { selectText(el); });
      } else {
        selectText(el);
      }
    });
  });
  function selectText(el) {
    var r = document.createRange(); r.selectNodeContents(el);
    var s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
  }

  // Live status refresh on the Streams page
  var list = document.getElementById('streams');
  if (!list) return;
  var desiredSeen = {};
  list.querySelectorAll('[data-id]').forEach(function (card) { desiredSeen[card.getAttribute('data-id')] = null; });

  function refresh() {
    fetch('status.php', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (!data || !data.streams) return;
        var needReload = false;
        data.streams.forEach(function (s) {
          var card = list.querySelector('[data-id="' + s.id + '"]');
          if (!card) { needReload = true; return; }
          var badge = card.querySelector('[data-field="badge"]');
          badge.className = 'badge badge-' + s.code;
          badge.textContent = s.label;
          card.querySelector('[data-field="uptime"]').textContent = s.uptime;
          card.querySelector('[data-field="fps"]').textContent = s.fps;
          card.querySelector('[data-field="speed"]').textContent = s.speed;
          var err = card.querySelector('[data-field="error"]');
          err.textContent = s.error;
          err.hidden = !s.error;
          var id = String(s.id);
          if (desiredSeen[id] !== null && desiredSeen[id] !== s.desired) needReload = true;
          desiredSeen[id] = s.desired;
        });
        var lc = document.getElementById('live-count');
        if (lc) lc.textContent = data.live + ' / ' + data.total;
        if (needReload) location.reload();
      })
      .catch(function () {});
  }
  refresh();
  setInterval(refresh, 5000);
})();
