<?php
/**
 * Device access for the private website: a new phone or PC asks for access, the owner approves it
 * in Admin → Device Access. The device is remembered by a cookie (not by IP: mobile IPs change all the time).
 */
declare(strict_types=1);

const DEV_COOKIE = 'fft_dev';
const DEV_ASKS_PER_HOUR = 3;     // requests from one IP in an hour
const DEV_MAX_PENDING   = 30;    // waiting requests in total

function dev_cookie(): string
{
    $c = (string) ($_COOKIE[DEV_COOKIE] ?? '');
    return preg_match('/^[a-f0-9]{48}$/', $c) ? $c : '';
}

function dev_row(): ?array
{
    static $row = false;
    if ($row !== false) return $row;
    $tok = dev_cookie();
    if ($tok === '') return $row = null;
    $st = db()->prepare('SELECT * FROM devices WHERE token_hash = ?');
    $st->execute([hash('sha256', $tok)]);
    return $row = ($st->fetch() ?: null);
}

/** This browser was approved by the owner (also notes when it was last seen). */
function dev_approved(): bool
{
    $r = dev_row();
    if (!$r || $r['status'] !== 'approved') return false;
    if (time() - (int) $r['last_seen'] > 60) {
        db()->prepare('UPDATE devices SET last_seen = ?, ip = ? WHERE id = ?')->execute([time(), client_ip(), (int) $r['id']]);
    }
    return true;
}

function dev_pending_count(): int
{
    try {
        return (int) db()->query("SELECT COUNT(*) FROM devices WHERE status = 'pending'")->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function dev_clean(string $s, int $max): string
{
    $s = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $s) ?? '';
    return mb_substr(trim(preg_replace('/\s+/u', ' ', $s) ?? ''), 0, $max);
}

function dev_set_cookie(string $tok): void
{
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    setcookie(DEV_COOKIE, $tok, ['expires' => time() + 5 * 365 * 86400, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
}

/** "Request access" from the page. Returns [status, code, error]. */
function dev_request(string $name): array
{
    $name = dev_clean($name, 60);
    $ua = dev_clean((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 300);
    $ip = client_ip();
    $r = dev_row();
    if ($r && $r['status'] === 'approved') return ['approved', $r['code'], ''];
    if ($r && $r['status'] === 'pending') {
        if ($name !== '') db()->prepare('UPDATE devices SET name = ? WHERE id = ?')->execute([$name, (int) $r['id']]);
        return ['pending', $r['code'], ''];
    }
    $st = db()->prepare('SELECT COUNT(*) FROM devices WHERE ip = ? AND asked_at > ?');
    $st->execute([$ip, time() - 3600]);
    if ((int) $st->fetchColumn() >= DEV_ASKS_PER_HOUR) return ['', '', 'Too many requests. Please try again in an hour.'];
    if (dev_pending_count() >= DEV_MAX_PENDING) return ['', '', 'Too many requests are waiting. Please try again later.'];

    if ($r) {   // was denied before: ask again
        db()->prepare("UPDATE devices SET status = 'pending', name = ?, ua = ?, ip = ?, asked_at = ? WHERE id = ?")
            ->execute([$name, $ua, $ip, time(), (int) $r['id']]);
        log_activity("Device Access: “" . ($name ?: 'No name') . "” asked again (ID {$r['code']})", 'warn');
        return ['pending', $r['code'], ''];
    }
    $tok = bin2hex(random_bytes(24));
    $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < 4; $i++) $code .= $abc[random_int(0, strlen($abc) - 1)];
    db()->prepare("INSERT INTO devices (token_hash, code, name, ua, ip, status, created_at, asked_at) VALUES (?,?,?,?,?,'pending',?,?)")
        ->execute([hash('sha256', $tok), $code, $name, $ua, $ip, time(), time()]);
    dev_set_cookie($tok);
    log_activity("Device Access: new request from “" . ($name ?: 'No name') . "” (ID {$code}) — approve it in Device Access", 'warn');
    return ['pending', $code, ''];
}

/** Short description of a browser for the admin list: [icon, "Android · Chrome"]. */
function dev_kind(string $ua): array
{
    $os = 'Device';
    if (preg_match('/iPhone/i', $ua)) $os = 'iPhone';
    elseif (preg_match('/iPad/i', $ua)) $os = 'iPad';
    elseif (preg_match('/Android/i', $ua)) $os = 'Android';
    elseif (preg_match('/Windows/i', $ua)) $os = 'Windows';
    elseif (preg_match('/Mac OS X|Macintosh/i', $ua)) $os = 'Mac';
    elseif (preg_match('/Linux/i', $ua)) $os = 'Linux';
    $br = 'Browser';
    if (preg_match('/SamsungBrowser/i', $ua)) $br = 'Samsung Internet';
    elseif (preg_match('/Edg\//i', $ua)) $br = 'Edge';
    elseif (preg_match('/OPR\/|Opera/i', $ua)) $br = 'Opera';
    elseif (preg_match('/Firefox|FxiOS/i', $ua)) $br = 'Firefox';
    elseif (preg_match('/Chrome|CriOS/i', $ua)) $br = 'Chrome';
    elseif (preg_match('/Safari/i', $ua)) $br = 'Safari';
    $mobile = in_array($os, ['iPhone', 'Android'], true) || preg_match('/Mobile/i', $ua);
    return [$mobile ? '📱' : ($os === 'iPad' ? '📲' : '💻'), $os . ' · ' . $br];
}

/** The page a visitor without access sees (instead of the website or a game). */
function access_page(): void
{
    http_response_code(403);
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    header('Content-Type: text/html; charset=utf-8');
    $r = dev_row();
    $state = $r ? $r['status'] : '';
    $code = $r ? $r['code'] : '';
    ?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<meta name="theme-color" content="#0d1230"><title>Private</title>
<style>
*{box-sizing:border-box}html,body{margin:0;min-height:100%}
body{font:15px/1.45 Inter,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#e8eefc;min-height:100vh;display:flex;justify-content:center;
background:radial-gradient(120% 60% at 50% 0%,#2b1d6b 0%,#0d1230 55%,#070a18 100%) fixed}
body::before{content:"";position:fixed;inset:0;pointer-events:none;background:radial-gradient(circle at 12% 85%,#1f6bff2e,transparent 40%),radial-gradient(circle at 92% 60%,#ff4fa32e,transparent 35%)}
body::after{content:"";position:fixed;inset:0;pointer-events:none;opacity:.16;background-image:radial-gradient(#fff 1px,transparent 1px);background-size:22px 22px}
.in{position:relative;z-index:1;width:100%;max-width:400px;padding:64px 22px 40px;text-align:center}
.ring{width:118px;height:118px;margin:0 auto;border-radius:50%;padding:4px;background:conic-gradient(from 0deg,#7c5cff,#1fb6ff,#ff4fa3,#7c5cff);box-shadow:0 0 42px #7c5cff88;animation:spin 8s linear infinite}
.ring div{width:100%;height:100%;border-radius:50%;background:#0d1230;display:grid;place-items:center;font-size:48px;animation:spin 8s linear infinite reverse}
@keyframes spin{to{transform:rotate(360deg)}}
.tag{display:inline-block;margin-top:22px;font-size:11px;letter-spacing:.18em;font-weight:800;color:#9fb4ff;background:#ffffff12;border:1px solid #ffffff22;padding:5px 12px;border-radius:99px}
h1{font-size:30px;font-weight:900;margin:12px 0 6px;letter-spacing:-.01em}
.m{color:#a9b3cf;font-size:14px}
.card{margin-top:26px;text-align:left;background:#ffffff0d;border:1px solid #ffffff1f;border-radius:18px;padding:16px;backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px)}
label{font-size:12px;color:#9aa6c4;font-weight:600}
.inp{margin:7px 0 14px;display:flex;align-items:center;gap:8px;padding:0 14px;border-radius:12px;background:#0a0f26;border:1px solid #3a4572}
.inp span{font-size:18px}.inp input{flex:1;min-width:0;padding:13px 0;border:0;background:transparent;color:#fff;font:inherit;outline:none}
.inp:focus-within{border-color:#7c8cff;box-shadow:0 0 0 3px #7c5cff33}
button{width:100%;padding:14px;border:0;border-radius:13px;font-size:15px;font-weight:800;font-family:inherit;color:#fff;cursor:pointer;background:linear-gradient(90deg,#7c5cff,#1f8bff);box-shadow:0 8px 24px #3d6bff66}
button:disabled{opacity:.6;cursor:default}
.box{margin-top:14px;display:flex;gap:12px;align-items:center;padding:13px 14px;border-radius:14px;font-size:13px;text-align:left}
.wait{background:#ffc94d14;border:1px solid #ffc94d44;color:#ffd77a}
.bad{background:#ff4f6d14;border:1px solid #ff4f6d55;color:#ff9aac}
.okb{background:#22c55e17;border:1px solid #22c55e55;color:#86efac}
.sp{width:22px;height:22px;border-radius:50%;border:3px solid #ffd77a44;border-top-color:#ffd77a;flex:none;animation:spin 1s linear infinite}
.foot{margin-top:26px;font-size:12px;color:#6d7799}
[hidden]{display:none!important}#f[hidden]+.box{margin-top:0}
</style></head><body>
<div class="in">
  <div class="ring"><div>🔒</div></div>
  <div class="tag">PRIVATE ARENA</div>
  <h1>Members only</h1>
  <div class="m">This website is private.<br>Send a request — the owner will let you in.</div>
  <div class="card">
    <form id="f">
      <label for="nm">Name this device</label>
      <div class="inp"><span id="ic">📱</span><input id="nm" maxlength="60" autocomplete="off" placeholder="My phone"></div>
      <button id="go" type="submit">Request access →</button>
    </form>
    <div class="box wait" id="wait" hidden><div class="sp"></div><div><b>Request sent!</b><br>Waiting for approval — this page opens by itself.</div></div>
    <div class="box bad" id="bad" hidden><div>✖</div><div id="badt"></div></div>
    <div class="box okb" id="ok" hidden><div>✔</div><div><b>Approved!</b> Opening…</div></div>
  </div>
  <div class="foot" id="foot"<?= $code === '' ? ' hidden' : '' ?>>Request ID · <b id="code"><?= htmlspecialchars($code) ?></b></div>
</div>
<script>
(() => {
  const $ = (id) => document.getElementById(id);
  let state = <?= json_encode($state) ?>, timer = 0;
  const ua = navigator.userAgent;
  const browser = () => /SamsungBrowser/.test(ua) ? 'Samsung Internet' : /Edg\//.test(ua) ? 'Edge' : /OPR\//.test(ua) ? 'Opera'
    : /Firefox|FxiOS/.test(ua) ? 'Firefox' : /Chrome|CriOS/.test(ua) ? 'Chrome' : /Safari/.test(ua) ? 'Safari' : 'Browser';
  const brandOf = (m) => /^SM-|^GT-|Galaxy/i.test(m) ? 'Samsung ' : /^Pixel/i.test(m) ? 'Google ' : /^(RMX)/i.test(m) ? 'Realme '
    : /^(CPH)/i.test(m) ? 'Oppo ' : /^(V2\d{3})/i.test(m) ? 'Vivo ' : /^(2\d{3}[A-Z0-9]{4,}|Redmi|M2\d)/i.test(m) ? 'Xiaomi ' : /^(TECNO)/i.test(m) ? '' : /^(Infinix)/i.test(m) ? '' : '';
  const guess = async () => {
    let name = '', mobile = /Mobi|Android|iPhone/.test(ua);
    try {
      if (navigator.userAgentData?.getHighEntropyValues) {
        const h = await navigator.userAgentData.getHighEntropyValues(['model', 'platformVersion']);
        const p = navigator.userAgentData.platform || '';
        if (p === 'Android') {
          const v = parseInt(h.platformVersion, 10);
          name = (h.model ? brandOf(h.model) + h.model : 'Android phone') + (v ? ' · Android ' + v : '');
        } else if (p === 'Windows') name = 'Windows PC';
        else if (p === 'macOS') name = 'Mac';
        else if (p) name = p;
        mobile = navigator.userAgentData.mobile || mobile;
      }
    } catch (e) { /* ignore */ }
    if (!name) {
      const ios = ua.match(/(iPhone|iPad)[^)]*OS (\d+)/);
      const and = ua.match(/Android (\d+)(?:[.\d]*)?;\s*([^;)]+?)(?: Build|\))/);
      if (ios) name = ios[1] + ' · iOS ' + ios[2];
      else if (and) name = (and[2] && and[2] !== 'K' ? brandOf(and[2]) + and[2] : 'Android phone') + ' · Android ' + and[1];
      else if (/Windows/.test(ua)) name = 'Windows PC';
      else if (/Macintosh/.test(ua)) name = 'Mac';
      else name = mobile ? 'Phone' : 'Computer';
    }
    $('ic').textContent = mobile ? '📱' : '💻';
    return (name + ' · ' + browser()).slice(0, 60);
  };
  const show = (st, code, err) => {
    state = st;
    if (code) { $('code').textContent = code; $('foot').hidden = false; }
    $('wait').hidden = st !== 'pending';
    $('ok').hidden = st !== 'approved';
    $('f').hidden = st === 'pending' || st === 'approved';
    $('bad').hidden = !(err || st === 'denied');
    $('badt').textContent = err || 'Your request was not approved. You can ask again.';
    clearTimeout(timer);
    if (st === 'pending') timer = setTimeout(check, 4000);
    if (st === 'approved') setTimeout(() => location.reload(), 700);
  };
  const api = (body) => fetch('/api/access.php', body ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body), credentials: 'same-origin' }
    : { cache: 'no-store', credentials: 'same-origin' }).then((r) => r.json());
  const check = () => api().then((j) => show(j.status || '', j.code || '')).catch(() => { timer = setTimeout(check, 8000); });
  $('f').addEventListener('submit', (e) => {
    e.preventDefault();
    $('go').disabled = true;
    api({ name: $('nm').value.trim() }).then((j) => show(j.status || '', j.code || '', j.error || ''))
      .catch(() => show('', '', 'Could not send. Check your internet and try again.')).finally(() => { $('go').disabled = false; });
  });
  guess().then((n) => { if (!$('nm').value) $('nm').value = n; });
  show(state, '');
})();
</script>
</body></html>
<?php
    exit;
}
