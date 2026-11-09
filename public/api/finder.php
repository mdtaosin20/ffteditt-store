<?php
// 🔎 Video Finder: one picture from the owner's video, for Google Lens (signed link, kept for a day).
require __DIR__ . '/../_boot.php';
$f = (string) ($_GET['f'] ?? '');
if (!preg_match('/^[a-z0-9_]{6,60}\.jpg$/', $f) || !hash_equals(vf_sig($f), (string) ($_GET['sig'] ?? '')) || !is_file($p = vf_dir() . '/' . $f)) { http_response_code(404); exit; }
header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($p));
header('Cache-Control: public, max-age=3600');
readfile($p);
