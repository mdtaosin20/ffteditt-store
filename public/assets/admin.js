// GameStream admin — menu, dropdowns, clock, live dashboard, copy buttons, stream status refresh.
(function () {
  var $ = function (id) { return document.getElementById(id); };

  /* ---------- side menu (phones / tablets) ---------- */
  var side = $('side'), shade = $('side-shade'), menuBtn = $('menu-btn');
  function setMenu(open) {
    if (!side) return;
    side.classList.toggle('open', open); shade.hidden = !open;
    menuBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
  }
  if (menuBtn) menuBtn.addEventListener('click', function () { setMenu(!side.classList.contains('open')); });
  if (shade) shade.addEventListener('click', function () { setMenu(false); });

  /* ---------- dropdowns (bell + user) ---------- */
  function dropdown(btnId, menuId) {
    var b = $(btnId), m = $(menuId);
    if (!b || !m) return;
    b.addEventListener('click', function (ev) {
      ev.stopPropagation();
      var open = m.hidden;
      document.querySelectorAll('.dropdown').forEach(function (d) { d.hidden = true; });
      m.hidden = !open; b.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    m.addEventListener('click', function (ev) { ev.stopPropagation(); });
  }
  dropdown('bell-btn', 'bell-menu'); dropdown('user-btn', 'user-menu');
  document.addEventListener('click', function () { document.querySelectorAll('.dropdown').forEach(function (d) { d.hidden = true; }); });
  document.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Escape') return;
    document.querySelectorAll('.dropdown').forEach(function (d) { d.hidden = true; });
    document.querySelectorAll('.modal').forEach(function (d) { d.hidden = true; });
    setMenu(false);
  });

  /* ---------- clock (Bangladesh time, from the server) ---------- */
  var clock = $('clock');
  if (clock) {
    var offset = Number(clock.getAttribute('data-ts')) * 1000 - Date.now();
    var tick = function () {
      var d = new Date(Date.now() + offset + 6 * 3600 * 1000); // UTC+6
      var h = d.getUTCHours(), m = d.getUTCMinutes(), s = d.getUTCSeconds();
      var ap = h >= 12 ? 'PM' : 'AM'; h = h % 12 || 12;
      var p = function (n) { return (n < 10 ? '0' : '') + n; };
      clock.textContent = p(h) + ':' + p(m) + ':' + p(s) + ' ' + ap + ' (BD)';
    };
    tick(); setInterval(tick, 1000);
  }

  /* ---------- small helpers ---------- */
  document.querySelectorAll('[data-autosubmit]').forEach(function (el) {
    el.addEventListener('change', function () { el.form.submit(); });
  });
  document.querySelectorAll('[data-open]').forEach(function (b) {
    b.addEventListener('click', function () { var m = $(b.getAttribute('data-open')); if (m) { m.hidden = false; var c = m.querySelector('[data-close]'); if (c) c.focus(); } });
  });
  document.querySelectorAll('[data-close]').forEach(function (b) {
    b.addEventListener('click', function () { b.closest('.modal').hidden = true; });
  });
  document.querySelectorAll('[data-days]').forEach(function (b) {
    b.addEventListener('click', function () {
      var want = b.getAttribute('data-days');
      document.querySelectorAll('.day-boxes input').forEach(function (i) { i.checked = want.indexOf(i.value) >= 0; });
    });
  });
  document.querySelectorAll('[data-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var el = $(btn.getAttribute('data-copy'));
      if (!el) return;
      var text = el.textContent.trim();
      var done = function () { btn.textContent = 'Copied!'; setTimeout(function () { btn.textContent = 'Copy'; }, 2000); };
      if (navigator.clipboard) navigator.clipboard.writeText(text).then(done, function () { selectText(el); });
      else selectText(el);
    });
  });
  function selectText(el) {
    var r = document.createRange(); r.selectNodeContents(el);
    var s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
  }

  /* ---------- live charts on the dashboard / VPS monitor ---------- */
  var COLORS = { cpu: '#3b82ff', ram: '#a46bff', disk: '#f5a524', up: '#22c55e', down: '#ec4899' };
  function drawChart(cv, values, color) {
    var dpr = Math.min(window.devicePixelRatio || 1, 2);
    var w = cv.clientWidth, h = cv.clientHeight;
    if (!w || !h) return;
    cv.width = Math.round(w * dpr); cv.height = Math.round(h * dpr);
    var c = cv.getContext('2d'); c.setTransform(dpr, 0, 0, dpr, 0, 0); c.clearRect(0, 0, w, h);
    c.strokeStyle = 'rgba(255,255,255,.06)'; c.lineWidth = 1;
    for (var g = 1; g < 3; g++) { c.beginPath(); c.moveTo(0, (h * g) / 3); c.lineTo(w, (h * g) / 3); c.stroke(); }
    var v = (values || []).filter(function (x) { return x !== null && !isNaN(x); });
    if (v.length < 2) { c.fillStyle = 'rgba(255,255,255,.35)'; c.font = '11px Inter, sans-serif'; c.fillText('Waiting for data…', w - 110, h - 8); return; }
    var max = Math.max.apply(null, v), min = 0;
    if (cv.getAttribute('data-unit') === '%') max = Math.max(100 * 0.6, max * 1.15); else max = Math.max(0.1, max * 1.25);
    var x = function (i) { return (i / (v.length - 1)) * w; };
    var y = function (val) { return h - 2 - ((val - min) / (max - min)) * (h - 6); };
    var grad = c.createLinearGradient(0, 0, 0, h);
    grad.addColorStop(0, color + '66'); grad.addColorStop(1, color + '00');
    c.beginPath(); c.moveTo(0, h);
    v.forEach(function (val, i) { c.lineTo(x(i), y(val)); });
    c.lineTo(w, h); c.closePath(); c.fillStyle = grad; c.fill();
    c.beginPath();
    v.forEach(function (val, i) { if (i) c.lineTo(x(i), y(val)); else c.moveTo(0, y(val)); });
    c.strokeStyle = color; c.lineWidth = 1.6; c.lineJoin = 'round'; c.stroke();
  }
  function fmtNow(k, val, unit) {
    if (val === null || val === undefined) return '—';
    return unit === '%' ? Math.round(val) + '%' : val + unit;
  }
  function paint(data) {
    if (!data) return;
    document.querySelectorAll('canvas[data-chart]').forEach(function (cv) {
      var k = cv.getAttribute('data-chart');
      drawChart(cv, data.hist ? data.hist[k] : [], COLORS[k]);
    });
    document.querySelectorAll('[data-now]').forEach(function (el) {
      var k = el.getAttribute('data-now').replace('-r', '');
      var cv = document.querySelector('canvas[data-chart="' + k + '"]');
      var unit = cv ? cv.getAttribute('data-unit') : '';
      var t = fmtNow(k, data.now ? data.now[k] : null, unit);
      if (el.getAttribute('data-now').slice(-2) === '-r') t = data.now && data.now[k] !== null ? (unit === '%' ? Math.round(data.now[k]) + '%' : data.now[k] + unit) : '—';
      el.textContent = t;
    });
  }
  var dash = $('dash'), dd = $('dash-data');
  if (dash && dd) {
    var data = null;
    try { data = JSON.parse(dd.textContent); } catch (e) { /* ignore */ }
    paint(data);
    window.addEventListener('resize', function () { paint(data); });
    var sid = dash.getAttribute('data-server');
    setInterval(function () {
      if (document.hidden) return;
      fetch('dash_api.php?server=' + encodeURIComponent(sid), { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (d) {
          if (!d) return;
          data = d; paint(d);
          Object.keys(d.html || {}).forEach(function (k) {
            var el = document.querySelector('[data-live="' + k + '"]');
            if (el && el.innerHTML !== d.html[k]) el.innerHTML = d.html[k];
          });
          var bell = $('bell-btn');
          if (bell) {
            var dot = bell.querySelector('.dot-count');
            if (d.alerts > 0) {
              if (!dot) { dot = document.createElement('span'); dot.className = 'dot-count'; bell.appendChild(dot); }
              dot.textContent = d.alerts > 9 ? '9+' : d.alerts;
            } else if (dot) dot.remove();
          }
        })
        .catch(function () {});
    }, 5000);
  }

  /* ---------- live status on the Live Stream Manager cards ---------- */
  var list = $('streams');
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
          err.textContent = s.error; err.hidden = !s.error;
          var id = String(s.id);
          if (desiredSeen[id] !== null && desiredSeen[id] !== s.desired) needReload = true;
          desiredSeen[id] = s.desired;
        });
        var lc = $('live-count');
        if (lc) lc.textContent = data.live + ' / ' + data.total;
        if (needReload) location.reload();
      })
      .catch(function () {});
  }
  refresh();
  setInterval(refresh, 5000);
})();
