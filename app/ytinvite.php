<?php
/**
 * Channel owner links: a link you send to a friend whose YouTube channel runs one of your streams.
 * The friend opens it, logs in with Google, and his channel is connected to that stream
 * (title, thumbnail, pinned message) — without your admin login and without his password.
 */
declare(strict_types=1);

const INV_DAYS = 7;

function inv_get(int $sid): ?array
{
    $v = json_decode((string) (setting_get('yt_inv_' . $sid) ?? ''), true);
    if (!is_array($v) || empty($v['t'])) return null;
    if ((int) $v['exp'] < time()) { inv_cancel($sid); return null; }
    return $v;
}

function inv_url(string $tok): string
{
    $host = preg_replace('/[^a-z0-9.\-:]/i', '', (string) ($_SERVER['HTTP_HOST'] ?? 'ffteditt.store'));
    return 'https://' . $host . '/connect.php?t=' . $tok;
}

function inv_create(int $sid): array
{
    inv_cancel($sid);
    $tok = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
    $v = ['t' => $tok, 'exp' => time() + INV_DAYS * 86400, 'made' => time()];
    setting_set('yt_inv_' . $sid, json_encode($v));
    setting_set('yt_invtok_' . hash('sha256', $tok), (string) $sid);
    return $v;
}

function inv_cancel(int $sid): void
{
    $v = json_decode((string) (setting_get('yt_inv_' . $sid) ?? ''), true);
    if (is_array($v) && !empty($v['t'])) setting_set('yt_invtok_' . hash('sha256', (string) $v['t']), '');
    setting_set('yt_inv_' . $sid, '');
}

/** The stream a link belongs to, or null if the link is wrong, used or old. */
function inv_stream(string $tok): ?array
{
    if (!preg_match('/^[A-Za-z0-9_-]{20,40}$/', $tok)) return null;
    $sid = (int) (setting_get('yt_invtok_' . hash('sha256', $tok)) ?? 0);
    if ($sid <= 0) return null;
    $v = inv_get($sid);
    if (!$v || !hash_equals((string) $v['t'], $tok)) return null;
    $st = db()->prepare('SELECT * FROM streams WHERE id = ?');
    $st->execute([$sid]);
    return $st->fetch() ?: null;
}

/** Remembers a Google trip started from a link (the friend has no admin login). */
function inv_state_new(int $sid, string $tok): string
{
    $state = 'inv' . bin2hex(random_bytes(16));
    setting_set('yt_invstate_' . $state, json_encode(['sid' => $sid, 't' => $tok, 'ts' => time()]));
    return $state;
}

function inv_state_take(string $state): ?array
{
    if (!preg_match('/^inv[a-f0-9]{32}$/', $state)) return null;
    $v = json_decode((string) (setting_get('yt_invstate_' . $state) ?? ''), true);
    setting_set('yt_invstate_' . $state, '');
    if (!is_array($v) || time() - (int) $v['ts'] > 1800) return null;
    return $v;
}

/** Simple page for the friend (outside the admin). */
function inv_page(string $title, string $html, string $kind = 'yt'): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    header('Referrer-Policy: no-referrer');
    $logo = $kind === 'ok' ? '<div class="lg ok">✓</div>' : ($kind === 'bad' ? '<div class="lg bad">!</div>' : '<div class="lg"><i></i></div>');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">'
        . '<title>' . e($title) . '</title><style>'
        . '*{box-sizing:border-box}body{margin:0;min-height:100vh;font:15px/1.5 Inter,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#e8eefc;display:flex;justify-content:center;'
        . 'background:radial-gradient(120% 60% at 50% 0%,#3a1030 0%,#130d26 55%,#070a18 100%) fixed}'
        . '.in{width:100%;max-width:420px;padding:60px 22px 40px;text-align:center}'
        . '.lg{width:96px;height:96px;margin:0 auto;border-radius:26px;background:#ff0033;display:grid;place-items:center;box-shadow:0 0 40px #ff003377;font-size:52px;font-weight:900;color:#fff}'
        . '.lg i{width:0;height:0;border-left:30px solid #fff;border-top:18px solid transparent;border-bottom:18px solid transparent;margin-left:8px}'
        . '.lg.ok{background:#16a34a;box-shadow:0 0 40px #16a34a77}.lg.bad{background:#f59e0b;box-shadow:0 0 40px #f59e0b66}'
        . 'h1{font-size:26px;font-weight:900;margin:20px 0 6px}.p{color:#b3bdd6;font-size:14px}'
        . '.lst{text-align:left;margin:20px 0;background:#ffffff0d;border:1px solid #ffffff1f;border-radius:14px;padding:12px 16px;font-size:14px;line-height:2}'
        . '.g{display:flex;align-items:center;justify-content:center;gap:10px;background:#fff;color:#1f1f1f;border-radius:12px;padding:14px;font-weight:700;text-decoration:none;font-size:15px}'
        . '.gl{width:20px;height:20px;border-radius:50%;background:conic-gradient(#ea4335 0 25%,#fbbc05 0 50%,#34a853 0 75%,#4285f4 0)}'
        . '.sm{font-size:12px;color:#7f89a8;margin-top:14px}.okb{margin-top:18px;padding:12px;border-radius:12px;background:#22c55e17;border:1px solid #22c55e55;color:#86efac;font-size:14px}'
        . '</style></head><body><div class="in">' . $logo . $html . '</div></body></html>';
    exit;
}
