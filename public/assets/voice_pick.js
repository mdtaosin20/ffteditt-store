// YouTube Manager → host voice: "Listen" asks the VPS to read a sample line in that voice.
// Phones only allow sound that starts from a tap, so the player is unlocked on the tap itself
// and, if the phone still blocks it, the button turns into "▶ Play" for one more tap.
(function () {
  var csrf = document.querySelector('input[name=csrf]');
  var SILENT = 'data:audio/mpeg;base64,SUQzBAAAAAAAI1RTU0UAAAAPAAADTGF2ZjYwLjE2LjEwMAAAAAAAAAAAAAAA//NwwAAAAAAAAAAAAEluZm8AAAAPAAAACAAAA/oAR0dHR0dHR0dHR0dHYmJiYmJiYmJiYmJifHx8fHx8fHx8fHx8fJaWlpaWlpaWlpaWlrGxsbGxsbGxsbGxsbHLy8vLy8vLy8vLy8vl5eXl5eXl5eXl5eXl////////////////AAAAAExhdmM2MC4zMQAAAAAAAAAAAAAAACQC1AAAAAAAAAP6yysejgAAAAAAAAAAAAAAAAD/80DEAAAAA0gAAAAATEFNRTMuMTAwVVVVVVVVVVVVVUxBTUUzLjEwMFVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVf/zQsRbAAADSAAAAABVVVVVVVVVVVVVVVVVVVVVVVVVVUxBTUUzLjEwMFVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVf/zQMSkAAADSAAAAABVVVVVVVVVVVVVVVVVVVVVVVVVTEFNRTMuMTAwVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVV//NCxKMAAANIAAAAAFVVVVVVVVVVVVVVVVVVVVVVVVVVTEFNRTMuMTAwVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVV//NAxKQAAANIAAAAAFVVVVVVVVVVVVVVVVVVVVVVVVVMQU1FMy4xMDBVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVX/80LEowAAA0gAAAAAVVVVVVVVVVVVVVVVVVVVVVVVVVVMQU1FMy4xMDBVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVX/80DEpAAAA0gAAAAAVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVf/zQsSjAAADSAAAAABVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVQ==';
  var current = null;
  document.querySelectorAll('[data-listen]').forEach(function (btn) {
    var note = btn.parentNode.querySelector('.vp-note');
    var audio = null, readyUrl = '';
    var setNote = function (t, warn) { note.textContent = t; note.className = 'vp-note small ' + (warn ? 'warn-text' : 'muted'); };
    var stopOthers = function () { if (current && current !== audio) { current.pause(); } };
    var playNow = function () {
      stopOthers();
      audio.src = readyUrl; audio.currentTime = 0;
      current = audio;
      var p = audio.play();
      btn.textContent = '■ Stop'; btn.disabled = false; setNote('');
      if (p && p.catch) p.catch(function () { btn.textContent = '▶ Play'; setNote('Ready! Tap ▶ Play to hear it.'); });
      audio.onended = function () { btn.textContent = '▶ Listen'; };
    };
    btn.addEventListener('click', function () {
      if (!audio) audio = new Audio();
      if (readyUrl) {                       // already made: play straight from this tap
        if (!audio.paused && btn.textContent.indexOf('Stop') >= 0) { audio.pause(); btn.textContent = '▶ Listen'; return; }
        playNow(); return;
      }
      // unlock the player during the tap (phones), then ask the VPS for the sample
      audio.src = SILENT; var u = audio.play(); if (u && u.catch) u.catch(function () {});
      btn.disabled = true; setNote('The VPS is making it…');
      var t0 = Date.now();
      fetch('robot_preview.php', { method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ voice: btn.dataset.listen, csrf: csrf ? csrf.value : '' }) })
        .then(function (r) { return r.json(); })
        .then(function wait(j) {
          if (j.url) { readyUrl = j.url; playNow(); return; }
          if (j.failed || !j.h) throw new Error('fail');
          if (Date.now() - t0 > 95000) throw new Error('slow');
          if (Date.now() - t0 > 8000) setNote('First time: the VPS downloads this voice (about 1 minute)…');
          return new Promise(function (res) { setTimeout(res, 2000); })
            .then(function () { return fetch('robot_preview.php?h=' + j.h).then(function (r) { return r.json(); }); })
            .then(wait);
        })
        .catch(function () {
          btn.disabled = false;
          setNote('Not ready yet. The VPS makes one voice at a time — wait a minute and tap ▶ Listen again.', true);
        });
    });
  });
})();
