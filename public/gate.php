<?php
// Game pages go through here (see .htaccess): only the owner and the stream browsers may open them while the website is private.
require __DIR__ . '/_boot.php';

$g = (string) ($_GET['g'] ?? '');
$allowed = ['ball', 'cupkick', 'flags', 'game', 'keeper', 'lastflag', 'race', 'reactor', 'tug', 'war'];
if (!in_array($g, $allowed, true) || !is_file(__DIR__ . '/' . $g . '/index.html')) site_closed_page();
if (!site_may_view()) site_closed_page();
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
if (site_private()) header('X-Robots-Tag: noindex, nofollow');
readfile(__DIR__ . '/' . $g . '/index.html');
