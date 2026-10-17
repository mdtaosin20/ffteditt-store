// FFTEdit admin — web terminal (commands run on the VPS through the worker).
(function () {
  var app = document.getElementById('term-app');
  if (!app) return;

  var csrf = app.getAttribute('data-csrf');
  var sel = document.getElementById('term-server');
  var out = document.getElementById('term-out');
  var form = document.getElementById('term-form');
  var input = document.getElementById('term-cmd');
  var state = document.getElementById('term-state');
  var prompt = document.getElementById('term-prompt');
  var clearBtn = document.getElementById('term-clear');

  var history = [];
  var histPos = -1;
  var timer = null;
  var lastSig = '';
  var busy = false;

  function server() { return sel.value; }

  function setState(cls, text) {
    state.className = 'badge badge-' + cls;
    state.textContent = text;
  }

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
  }

  function locked(msg) {
    setState('error', 'Locked');
    out.innerHTML = '';
    var p = el('p', 'term-note', msg || 'Terminal locked. ');
    var a = el('a', '', 'Unlock again');
    a.href = 'terminal.php?server=' + encodeURIComponent(server());
    p.appendChild(a);
    out.appendChild(p);
    input.disabled = true;
    stop();
  }

  function render(data) {
    prompt.textContent = 'root@' + (data.hostname || 'vps') + ':~#';
    if (!data.enabled) setState('warn', 'Terminal off');
    else if (data.online) setState('live', 'Connected');
    else setState('muted', 'Server offline');

    var sig = JSON.stringify(data.commands.map(function (c) { return [c.id, c.status]; }));
    if (sig === lastSig) return;
    lastSig = sig;

    var atBottom = out.scrollHeight - out.scrollTop - out.clientHeight < 40;
    out.innerHTML = '';
    if (!data.commands.length) {
      out.appendChild(el('p', 'term-note', data.online
        ? 'Connected. Type a command below or tap a button above.'
        : 'This server is offline. Commands will wait until it checks in again.'));
    }
    data.commands.forEach(function (c) {
      var block = el('div', 'term-block');
      var line = el('div', 'term-line');
      line.appendChild(el('span', 'term-p', 'root@' + (data.hostname || 'vps') + ':~# '));
      line.appendChild(el('span', '', c.cmd));
      block.appendChild(line);
      if (c.status === 'queued' || c.status === 'running') {
        block.appendChild(el('div', 'term-wait', c.status === 'queued' ? 'Waiting for the server…' : 'Running…'));
      } else {
        if (c.output) block.appendChild(el('pre', 'term-pre', c.output.replace(/\n+$/, '')));
        var meta = c.time;
        if (c.status === 'done') {
          meta += ' · ' + ((c.duration_ms || 0) / 1000).toFixed(1) + ' s';
          if (c.exit_code !== 0) meta += ' · exit code ' + c.exit_code;
        } else {
          meta += ' · ' + c.status;
        }
        block.appendChild(el('div', c.status === 'done' && c.exit_code === 0 ? 'term-meta' : 'term-meta term-bad', meta));
      }
      out.appendChild(block);
    });
    if (atBottom || busy) out.scrollTop = out.scrollHeight;
  }

  function pending(data) {
    return data.commands.some(function (c) { return c.status === 'queued' || c.status === 'running'; });
  }

  function poll() {
    stop();
    fetch('terminal_api.php?server_id=' + encodeURIComponent(server()), { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, status: r.status, body: j }; }); })
      .then(function (res) {
        if (res.status === 401) { location.href = 'index.php'; return; }
        if (res.status === 403) { locked(res.body.message + ' '); return; }
        if (!res.ok) { setState('error', 'Error'); schedule(5000); return; }
        render(res.body);
        busy = pending(res.body);
        schedule(busy ? 1200 : 5000);
      })
      .catch(function () { setState('error', 'No connection'); schedule(5000); });
  }

  function schedule(ms) { timer = setTimeout(poll, ms); }
  function stop() { if (timer) { clearTimeout(timer); timer = null; } }

  function post(params) {
    var body = new URLSearchParams(params);
    body.append('csrf', csrf);
    body.append('server_id', server());
    return fetch('terminal_api.php', { method: 'POST', credentials: 'same-origin', body: body })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, status: r.status, body: j }; }); });
  }

  function run(cmd) {
    cmd = (cmd || '').trim();
    if (!cmd) return;
    busy = true;
    post({ action: 'run', cmd: cmd }).then(function (res) {
      if (res.status === 403) { locked(res.body.message + ' '); return; }
      if (!res.ok) {
        var p = el('p', 'term-note term-bad', res.body.message || 'Could not send the command.');
        out.appendChild(p);
        out.scrollTop = out.scrollHeight;
        return;
      }
      lastSig = '';
      poll();
    }).catch(function () { setState('error', 'No connection'); });
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var cmd = input.value;
    if (!cmd.trim()) return;
    if (history[history.length - 1] !== cmd) history.push(cmd);
    histPos = history.length;
    input.value = '';
    run(cmd);
  });

  input.addEventListener('keydown', function (e) {
    if (e.key === 'ArrowUp' && history.length) {
      histPos = Math.max(0, histPos - 1);
      input.value = history[histPos];
      e.preventDefault();
    } else if (e.key === 'ArrowDown' && history.length) {
      histPos = Math.min(history.length, histPos + 1);
      input.value = history[histPos] || '';
      e.preventDefault();
    }
  });

  document.querySelectorAll('[data-cmd]').forEach(function (b) {
    b.addEventListener('click', function () {
      var c = b.getAttribute('data-confirm');
      if (c && !window.confirm(c)) return;
      run(b.getAttribute('data-cmd'));
    });
  });

  clearBtn.addEventListener('click', function () {
    post({ action: 'clear' }).then(function () { lastSig = ''; poll(); });
  });

  sel.addEventListener('change', function () {
    lastSig = '';
    history = [];
    histPos = -1;
    if (window.history.replaceState) window.history.replaceState(null, '', 'terminal.php?server=' + server());
    poll();
  });

  poll();
})();
