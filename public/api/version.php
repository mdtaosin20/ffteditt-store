<?php
// Which version of a game's files is on the site now. The game page in the stream checks this
// and reloads itself after a deploy, keeping its game where it was (no Restart, same live video).
// No secrets here: only a short fingerprint of file times and sizes.
declare(strict_types=1);

header('Content-Type: application/json');
header('Cache-Control: no-store');

$game = preg_replace('/[^a-z]/', '', (string) ($_GET['g'] ?? ''));
$dirs = [__DIR__ . '/../game'];
if ($game !== '' && is_dir(__DIR__ . '/../' . $game)) $dirs[] = __DIR__ . '/../' . $game;
$parts = [];
foreach ($dirs as $d) {
    foreach (glob($d . '/*.{js,css,html,json}', GLOB_BRACE) ?: [] as $f) {
        $parts[] = basename($d) . '/' . basename($f) . ':' . filemtime($f) . ':' . filesize($f);
    }
}
sort($parts);
echo json_encode(['v' => substr(md5(implode('|', $parts)), 0, 12)]);
