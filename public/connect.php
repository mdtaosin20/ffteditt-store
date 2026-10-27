<?php
// Channel owner link: a friend connects his YouTube channel to one of the streams (see Admin → Channel Owner Links).
require __DIR__ . '/_boot.php';

$tok = (string) ($_GET['t'] ?? '');
$s = inv_stream($tok);
$site = site_config()['name'];
if (!$s) {
    inv_page('Link not valid', '<h1>This link does not work</h1><div class="p">It was already used, cancelled, or it is older than ' . INV_DAYS . ' days.<br>Ask for a new link.</div>', 'bad');
}
if (!yt_ready()) {
    inv_page('Not ready', '<h1>Not ready yet</h1><div class="p">The owner has not finished the YouTube setup. Please try again later.</div>', 'bad');
}
if (isset($_GET['go'])) {
    header('Location: ' . yt_auth_url(inv_state_new((int) $s['id'], $tok)));
    exit;
}
inv_page('Connect your channel',
    '<h1>Connect your channel</h1>'
    . '<div class="p"><b>' . e($site) . '</b> invited you to run a 24/7 game live on your YouTube channel.</div>'
    . '<div class="lst">✅ Set the live title &amp; thumbnail<br>✅ Pin a message in the live chat<br>✅ Start the live on time<br>🔒 Your password is never shared</div>'
    . '<a class="g" href="connect.php?t=' . e($tok) . '&amp;go=1"><span class="gl"></span>Continue with Google</a>'
    . '<div class="sm">Pick the Google account of your YouTube channel. Google may say “Google hasn’t verified this app” — tap <b>Advanced</b> → <b>Go to …</b> → tick the box → <b>Continue</b>.<br><br>You can remove it any time at myaccount.google.com/permissions</div>');
