// Games website: menu, search, category filters, favorites, live refresh.
(function () {
  var $ = function (id) { return document.getElementById(id); };
  var side = $('side'), shade = $('shade'), menu = $('menu-btn');
  function setMenu(open) {
    side.classList.toggle('open', open); shade.hidden = !open;
    menu.setAttribute('aria-expanded', open ? 'true' : 'false');
  }
  menu.addEventListener('click', function () { setMenu(!side.classList.contains('open')); });
  shade.addEventListener('click', function () { setMenu(false); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setMenu(false); });

  // favorites (kept in this browser)
  var favs = [];
  try { favs = JSON.parse(localStorage.getItem('fft_favs') || '[]'); } catch (e) { favs = []; }
  var saveFavs = function () { try { localStorage.setItem('fft_favs', JSON.stringify(favs)); } catch (e) { /* private mode */ } };
  var cards = Array.prototype.slice.call(document.querySelectorAll('.card'));
  cards.forEach(function (c) {
    var b = c.querySelector('.fav'); if (!b) return;
    var g = c.getAttribute('data-game');
    b.setAttribute('aria-pressed', favs.indexOf(g) >= 0 ? 'true' : 'false');
    b.addEventListener('click', function () {
      var i = favs.indexOf(g);
      if (i >= 0) favs.splice(i, 1); else favs.push(g);
      b.setAttribute('aria-pressed', i >= 0 ? 'false' : 'true');
      saveFavs(); apply();
    });
  });

  // filters + search
  var filter = 'all', q = $('q');
  function apply() {
    var text = (q && q.value || '').trim().toLowerCase(), shown = 0;
    cards.forEach(function (c) {
      var tags = c.getAttribute('data-tags') || '', g = c.getAttribute('data-game');
      var ok = filter === 'all' || (filter === 'fav' ? favs.indexOf(g) >= 0 : tags.split(' ').indexOf(filter) >= 0);
      if (text) ok = ok && (c.getAttribute('data-search') || '').indexOf(text) >= 0;
      c.hidden = !ok; if (ok) shown++;
    });
    $('none').hidden = shown > 0;
    $('none').textContent = filter === 'fav' && !text ? 'No favorites yet — tap the ♥ on a game.' : 'No games found.';
    document.querySelectorAll('.chip').forEach(function (ch) { ch.classList.toggle('on', ch.getAttribute('data-filter') === filter); });
  }
  document.querySelectorAll('[data-filter]').forEach(function (el) {
    el.addEventListener('click', function () {
      filter = el.getAttribute('data-filter') || 'all';
      if (el.closest('.nav')) {
        document.querySelectorAll('.nav a').forEach(function (a) { a.classList.toggle('on', a === el); });
      }
      setMenu(false); apply();
    });
  });
  document.querySelectorAll('.nav a:not([data-filter])').forEach(function (a) {
    a.addEventListener('click', function () {
      document.querySelectorAll('.nav a').forEach(function (x) { x.classList.toggle('on', x === a); });
      setMenu(false);
    });
  });
  if (q) q.addEventListener('input', function () {
    if (q.value && filter !== 'all') filter = 'all';
    apply();
    if (q.value) $('games').scrollIntoView({ block: 'start' });
  });

  // live numbers every 30 seconds
  setInterval(function () {
    if (document.hidden) return;
    fetch('/api/site.php', { cache: 'no-store' }).then(function (r) { return r.ok ? r.json() : null; }).then(function (d) {
      if (!d) return;
      Object.keys(d).forEach(function (k) {
        var el = document.querySelector('[data-live="' + k + '"]');
        if (el && el.innerHTML !== d[k]) el.innerHTML = d[k];
      });
    }).catch(function () {});
  }, 30000);
})();
