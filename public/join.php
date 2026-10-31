<?php
// Client link (Admin → Client Links): a client connects his own YouTube channel from his own phone, in 3 steps:
//  1) name, channel, stream key and the game   2) his Google account (Gmail)   3) done — the owner starts the live.
// Opening the link again later shows whether his live is on.
require __DIR__ . '/_boot.php';

$tok = (string) ($_GET['t'] ?? '');
$link = cli_find($tok);
if (!$link) {
    inv_page('Link not valid', '<h1>This link does not work</h1><div class="p">It was cancelled, or it is older than ' . CLI_DAYS . ' days.<br>Ask the owner for a new link.</div>', 'bad');
}
$site = site_config()['name'];
$self = 'join.php?t=' . rawurlencode($tok);
$games = cli_games();
$css = '<style>html{background:#070a18}.f{text-align:left;margin-top:18px}.f label{display:block;font-size:13px;font-weight:700;margin-top:12px;color:#dfe6fb}'
    . '.f input{width:100%;margin-top:5px;padding:11px;border-radius:10px;border:1px solid #ffffff2e;background:#0b1024;color:#fff;font-size:15px}'
    . '.f small{display:block;font-size:12px;color:#8e98b8;font-weight:400;margin-top:4px;line-height:1.4}'
    . '.gm{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:6px}.gm label{margin:0;padding:10px 6px;border-radius:10px;background:#ffffff10;border:2px solid transparent;text-align:center;font-size:13px;cursor:pointer}'
    . '.gm input{display:none}.gm label:has(input:checked){border-color:#3b82f6;background:#1b2a6a}'
    . '.bt{display:block;width:100%;margin-top:18px;padding:14px;border:0;border-radius:12px;background:linear-gradient(90deg,#2563eb,#3b82f6);color:#fff;font-weight:800;font-size:16px;text-decoration:none;text-align:center}'
    . '.st{display:flex;gap:6px;margin:16px 0 0}.st i{flex:1;height:6px;border-radius:9px;background:#ffffff1f}.st i.on{background:#22c55e}'
    . '.er{margin-top:14px;padding:10px;border-radius:10px;background:#f59e0b22;border:1px solid #f59e0b66;color:#fcd34d;font-size:14px}'
    . '.sk{display:block;margin-top:14px;color:#8e98b8;font-size:13px}.inf{text-align:left;margin-top:16px;background:#ffffff0d;border:1px solid #ffffff1f;border-radius:14px;padding:12px 16px;font-size:14px;line-height:1.9}</style>';
$steps = fn (int $n) => '<div class="st">' . str_repeat('<i class="on"></i>', $n) . str_repeat('<i></i>', 3 - $n) . '</div>';

/* ---------------- step 1: the form ---------------- */
if ((int) $link['sid'] === 0) {
    $err = ''; $in = ['name' => $link['label'], 'channel' => '', 'key' => '', 'game' => 'flags'];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $from = strtolower((string) parse_url((string) ($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_HOST));
        $host = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
        $in = array_merge($in, array_map(fn ($v) => is_string($v) ? $v : '', array_intersect_key($_POST, $in)));
        // (the page sends no referrer, so the browser may say "null" here; the secret link itself is the protection)
        $err = $from !== '' && $from !== $host ? 'Please open the link again and try once more.' : cli_submit($link, $in);
        if ($err === '') { header('Location: ' . $self); exit; }
    }
    $g = '';
    foreach ($games as $k => $x) $g .= '<label><input type="radio" name="game" value="' . e($k) . '"' . ($in['game'] === $k ? ' checked' : '') . '>' . e($x['name']) . '</label>';
    inv_page('Connect your channel', $css . $steps(1)
        . '<h1>Connect your channel</h1><div class="p"><b>' . e($site) . '</b> will run a 24/7 game live on your YouTube channel.</div>'
        . ($err ? '<div class="er">' . e($err) . '</div>' : '')
        . '<form class="f" method="post" action="' . e($self) . '" autocomplete="off">'
        . '<label>Your name<input name="name" maxlength="40" required value="' . e($in['name']) . '"></label>'
        . '<label>YouTube channel link<input name="channel" maxlength="200" placeholder="youtube.com/@YourChannel" value="' . e($in['channel']) . '"></label>'
        . '<label>Stream key 🔒<input name="key" type="password" maxlength="80" placeholder="(not needed if you connect Google next)" value="' . e($in['key']) . '">'
        . '<small>YouTube Studio → <b>Create → Go live</b> → <b>Stream</b> → copy <b>Stream key</b>. Or leave it empty: after the next step we take it from your channel ourselves.</small></label>'
        . '<label>Which live do you want?</label><div class="gm">' . $g . '</div>'
        . '<button class="bt" type="submit">Next →</button></form>');
}

/* ---------------- step 2 and 3 ---------------- */
$s = cli_stream((int) $link['sid']);
if (isset($_GET['google'])) {
    if (!yt_ready()) inv_page('Not ready', $css . '<h1>Not ready yet</h1><div class="p">The owner has not finished the Google setup. Please try again later.</div>', 'bad');
    header('Location: ' . yt_auth_url(cli_state_new($tok)));
    exit;
}
$gOk = $s['yt_refresh'] !== '';
if (!$gOk && $s['stream_key'] !== '' && !isset($_GET['skip']) && !isset($_GET['connected'])) $show2 = true;
elseif (!$gOk && $s['stream_key'] === '') $show2 = true;
else $show2 = false;

if ($show2) {
    inv_page('Connect Google', $css . $steps(2)
        . '<h1>Connect your Google account</h1><div class="p">Choose the Gmail of your YouTube channel. Then the owner can also:</div>'
        . '<div class="lst">✅ Set the live title &amp; thumbnail<br>✅ Pin a message in the live chat<br>✅ Count chat &amp; gifts in the game<br>✅ Check the live is really on YouTube'
        . ($s['stream_key'] === '' ? '<br>✅ Take your stream key by itself' : '') . '<br>🔒 Your password is never shared</div>'
        . '<a class="g" href="' . e($self) . '&amp;google=1"><span class="gl"></span>Continue with Google</a>'
        . ($s['stream_key'] !== '' ? '<a class="sk" href="' . e($self) . '&amp;skip=1">Skip for now (only the stream key)</a>' : '')
        . '<div class="sm">“Access blocked”? Send your Gmail address to the owner first, then open this link again.<br>Google may say “Google hasn’t verified this app” — tap <b>Advanced</b> → <b>Go to …</b> → tick the box → <b>Continue</b>.<br>You can remove it any time at myaccount.google.com/permissions</div>');
}

$g = $games[game_of_url($s['page_url'])]['name'] ?? 'Game live';
$live = $s['desired'] === 'on' ? ($s['status'] === 'running' ? '<b style="color:#f87171">🔴 Your live is ON</b>' : '<b style="color:#fbbf24">⏳ Starting…</b>') : '<b>Off</b> — the owner will start it';
inv_page('All done', $css . $steps(3)
    . '<h1>All done!</h1><div class="p">Nothing more to do. You can open this link any time to see your live.</div>'
    . '<div class="inf">Channel: <b>' . e($gOk ? ($s['yt_channel'] ?: $s['channel_name']) : $s['channel_name']) . '</b><br>Live: <b>' . e($g) . '</b><br>'
    . 'Stream key: ' . ($s['stream_key'] !== '' ? '<b style="color:#4ade80">✓</b>' : '<b style="color:#fbbf24">not found yet — the owner will help</b>') . '<br>'
    . 'Google: ' . ($gOk ? '<b style="color:#4ade80">connected ✓</b>' : '<b>not connected</b> · <a style="color:#9fc0ff" href="' . e($self) . '&amp;google=1">connect now</a>') . '<br>'
    . 'Now: ' . $live . '</div>', 'ok');
