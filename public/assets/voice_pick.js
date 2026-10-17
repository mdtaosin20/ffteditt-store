// YouTube Manager → host voice: "Listen" asks the VPS to read a sample line in that voice.
(function () {
  var csrf = document.querySelector('input[name=csrf]');
  document.querySelectorAll('[data-listen]').forEach(function (btn) {
    var note = btn.parentNode.querySelector('.vp-note');
    var audio = null;
    btn.addEventListener('click', function () {
      if (audio && !audio.paused) { audio.pause(); btn.textContent = '▶ Listen'; return; }
      btn.disabled = true; note.textContent = 'The VPS is making it…'; note.className = 'vp-note muted small';
      var t0 = Date.now();
      fetch('robot_preview.php', { method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ voice: btn.dataset.listen, csrf: csrf ? csrf.value : '' }) })
        .then(function (r) { return r.json(); })
        .then(function wait(j) {
          if (j.url) return play(j.url);
          if (j.failed || !j.h) throw new Error('fail');
          if (Date.now() - t0 > 95000) throw new Error('slow');
          if (Date.now() - t0 > 8000) note.textContent = 'First time: the VPS downloads this voice (about 1 minute)…';
          return new Promise(function (res) { setTimeout(res, 2000); })
            .then(function () { return fetch('robot_preview.php?h=' + j.h).then(function (r) { return r.json(); }); })
            .then(wait);
        })
        .catch(function () {
          btn.disabled = false;
          note.textContent = 'Not ready. Is the VPS online with the newest worker (Web Terminal → Update worker)? Try again in a minute.';
          note.className = 'vp-note warn-text small';
        });
      function play(url) {
        btn.disabled = false; note.textContent = '';
        audio = new Audio(url); audio.play();
        btn.textContent = '■ Stop';
        audio.onended = function () { btn.textContent = '▶ Listen'; };
      }
    });
  });
})();
