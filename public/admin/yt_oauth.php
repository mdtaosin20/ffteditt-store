<?php
/**
 * Connect a stream's YouTube channel.
 *  POST (from the panel)  -> sends you to Google's "Allow" page
 *  GET ?code=&state=      -> Google sends you back here; we save the connection
 */
require __DIR__ . '/../_boot.php';

// The login cookie is "same-site only", so the browser does not send it on the jump back from Google.
// Hop once inside this site (the browser then sends it) before checking the login.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['go'])) {
    $next = 'yt_oauth.php?' . http_build_query(['go' => 1] + array_intersect_key($_GET, array_flip(['code', 'state', 'error'])));
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    echo '<!doctype html><meta charset="utf-8"><meta name="robots" content="noindex"><title>Connecting…</title>'
        . '<body style="font:16px system-ui;background:#0b1424;color:#dfe8f7;padding:40px">Connecting to YouTube… '
        . '<a style="color:#7fb0ff" id="go" href="' . e($next) . '">Continue</a>'
        . '<script>location.replace(document.getElementById("go").href)</script>';
    exit;
}

// Back from Google after a Client Link (join.php, step 2 — the client has no admin login)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && str_starts_with((string) ($_GET['state'] ?? ''), 'cli')) {
    $cs = cli_state_take((string) $_GET['state']);
    $link = $cs ? cli_find((string) $cs['t']) : null;
    if (!$link || (int) $link['sid'] === 0) {
        inv_page('Link not valid', '<h1>This link does not work any more</h1><div class="p">Ask the owner for a new link.</div>', 'bad');
    }
    $back = '/join.php?t=' . rawurlencode((string) $cs['t']);
    $again = '<br><br><a style="color:#9fc0ff" href="' . e($back) . '">Back</a>';
    if (isset($_GET['error'])) {
        inv_page('Cancelled', '<h1>Google was not connected</h1><div class="p">' . ($_GET['error'] === 'access_denied'
            ? 'Cancel was pressed — or Google blocked the login. Then send your Gmail address to the owner (he must add it first) and try again.' : 'Google said: ' . e(substr((string) $_GET['error'], 0, 80))) . $again . '</div>', 'bad');
    }
    try {
        $tok = yt_exchange((string) ($_GET['code'] ?? ''));
        $refresh = (string) ($tok['refresh_token'] ?? '');
        if ($refresh === '') throw new RuntimeException('Google did not give a long-term key. Remove this app at myaccount.google.com/permissions and try again.');
        if (!str_contains((string) ($tok['scope'] ?? YT_SCOPE), 'youtube')) throw new RuntimeException('Please tick the YouTube permission box on the Google page.');
        try { $channel = yt_channel_title((string) $tok['access_token']); } catch (Throwable $e) { $channel = 'YouTube channel'; }
        $sid = (int) $link['sid'];
        db()->prepare("UPDATE streams SET yt_refresh = ?, yt_channel = ?, yt_msg = '', yt_done_start = 0, yt_fails = 0 WHERE id = ?")->execute([$refresh, $channel, $sid]);
        setting_set('yt_at_' . $sid, json_encode(['t' => $tok['access_token'], 'exp' => time() + (int) ($tok['expires_in'] ?? 3600), 'rh' => md5($refresh)]));
        $key = cli_fetch_key(cli_stream($sid));
        log_activity("👥 Client “{$link['label']}” connected Google: “{$channel}”" . ($key !== '' ? ' · stream key ✓ — ready to start' : ' · no stream key yet'), 'ok');
        header('Location: ' . $back . '&connected=1');
        exit;
    } catch (Throwable $e) {
        inv_page('Not connected', '<h1>Google was not connected</h1><div class="p">' . e($e->getMessage()) . $again . '</div>', 'bad');
    }
}

// Back from Google after a channel owner link (the friend has no admin login)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && str_starts_with((string) ($_GET['state'] ?? ''), 'inv')) {
    $inv = inv_state_take((string) $_GET['state']);
    $again = $inv ? '<br><br><a style="color:#9fc0ff" href="/connect.php?t=' . e($inv['t']) . '">Try again</a>' : '';
    if (!$inv || !inv_stream((string) $inv['t'])) {
        inv_page('Link not valid', '<h1>This link does not work any more</h1><div class="p">Ask for a new link.</div>', 'bad');
    }
    if (isset($_GET['error'])) {
        inv_page('Cancelled', '<h1>Nothing was connected</h1><div class="p">' . ($_GET['error'] === 'access_denied'
            ? 'Cancel was pressed on Google — or Google blocked the login (then the owner must add your Gmail as a test user).' : 'Google said: ' . e(substr((string) $_GET['error'], 0, 80))) . $again . '</div>', 'bad');
    }
    try {
        $tok = yt_exchange((string) ($_GET['code'] ?? ''));
        $refresh = (string) ($tok['refresh_token'] ?? '');
        if ($refresh === '') throw new RuntimeException('Google did not give a long-term key. Remove this app at myaccount.google.com/permissions and open the link again.');
        if (!str_contains((string) ($tok['scope'] ?? YT_SCOPE), 'youtube')) throw new RuntimeException('Please tick the YouTube permission box on the Google page.');
        try { $channel = yt_channel_title((string) $tok['access_token']); } catch (Throwable $e) { $channel = 'YouTube channel'; }
        $sid = (int) $inv['sid'];
        db()->prepare("UPDATE streams SET yt_refresh = ?, yt_channel = ?, yt_msg = '', yt_done_start = 0, yt_fails = 0 WHERE id = ?")->execute([$refresh, $channel, $sid]);
        setting_set('yt_at_' . $sid, json_encode(['t' => $tok['access_token'], 'exp' => time() + (int) ($tok['expires_in'] ?? 3600), 'rh' => md5($refresh)]));
        inv_cancel($sid);   // a link works once
        $nm = (string) db()->query('SELECT name FROM streams WHERE id = ' . $sid)->fetchColumn();
        log_activity("Channel owner link used: “{$channel}” connected to {$nm}", 'ok');
        inv_page('Connected', '<h1>Connected!</h1><div class="p">Your channel <b>“' . e($channel) . '”</b> is now connected.<br>You can close this page.</div>'
            . '<div class="okb">' . e(site_config()['name']) . ' can now set the title, thumbnail and pinned message of your lives.</div>', 'ok');
    } catch (Throwable $e) {
        inv_page('Not connected', '<h1>Not connected</h1><div class="p">' . e($e->getMessage()) . $again . '</div>', 'bad');
    }
}

$admin = require_login();
start_session();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $st = db()->prepare('SELECT id, yt_refresh FROM streams WHERE id = ?');
    $st->execute([$id]);
    $s = $st->fetch();
    if (!$s) {
        flash('Channel not found.', 'error');
        redirect('youtube.php');
    }
    if (($_POST['do'] ?? '') === 'disconnect') {
        yt_revoke($s['yt_refresh']);
        db()->prepare("UPDATE streams SET yt_refresh = '', yt_channel = '', yt_msg = '' WHERE id = ?")->execute([$id]);
        setting_set('yt_at_' . $id, '');
        log_activity('YouTube disconnected from a channel', 'warn');
        flash('Disconnected from YouTube.');
        redirect('youtube.php');
    }
    if (!yt_ready()) {
        flash('First save the Google Client ID and Client Secret (step 1 below).', 'error');
        redirect('youtube.php');
    }
    $state = bin2hex(random_bytes(16));
    $_SESSION['yt_oauth'] = ['state' => $state, 'id' => $id, 'ts' => time()];
    header('Location: ' . yt_auth_url($state));
    exit;
}

// Back from Google
$want = $_SESSION['yt_oauth'] ?? null;
unset($_SESSION['yt_oauth']);
if (!$want || !hash_equals((string) $want['state'], (string) ($_GET['state'] ?? '')) || time() - (int) $want['ts'] > 900) {
    flash('That YouTube connection link was old or not from this panel. Press "Connect YouTube" again.', 'error');
    redirect('youtube.php');
}
if (isset($_GET['error'])) {
    flash($_GET['error'] === 'access_denied' ? 'You pressed Cancel on Google, so nothing was connected.' : 'Google said: ' . substr((string) $_GET['error'], 0, 80), 'error');
    redirect('youtube.php');
}

try {
    $tok = yt_exchange((string) ($_GET['code'] ?? ''));
    $refresh = (string) ($tok['refresh_token'] ?? '');
    if ($refresh === '') {
        throw new RuntimeException('Google did not give a long-term key. Remove this app at myaccount.google.com/permissions and connect again.');
    }
    if (!str_contains((string) ($tok['scope'] ?? YT_SCOPE), 'youtube')) {
        throw new RuntimeException('Please tick the YouTube permission box on the Google page, then connect again.');
    }
    // The channel name costs 1 unit of the daily YouTube limit. If the limit is used up, connect anyway.
    try {
        $channel = yt_channel_title((string) $tok['access_token']);
    } catch (Throwable $e) {
        $channel = 'YouTube channel';
    }
    $id = (int) $want['id'];
    db()->prepare("UPDATE streams SET yt_refresh = ?, yt_channel = ?, yt_msg = '', yt_done_start = 0, yt_fails = 0 WHERE id = ?")
        ->execute([$refresh, $channel, $id]);
    setting_set('yt_at_' . $id, json_encode(['t' => $tok['access_token'], 'exp' => time() + (int) ($tok['expires_in'] ?? 3600), 'rh' => md5($refresh)]));
    log_activity("YouTube connected: {$channel}", 'ok');
    flash("Connected to YouTube channel \"{$channel}\".");
    redirect('yt_live.php?id=' . $id);
} catch (Throwable $e) {
    flash($e->getMessage(), 'error');
    redirect('youtube.php');
}
