<?php
/**
 * Song files for the games' background music (NCS songs etc.). They live in the private app
 * folder and are served only to the signed stream pages (/api/music.php), never to the public.
 * app/music/tracks.json: [{"file": "fearless2.mp3", "title": "Lost Sky - Fearless pt.II (feat. Chris Linton)", "credit": "..."}]
 */
declare(strict_types=1);

function music_dir(): string
{
    return APP_DIR . '/music';
}

/** Songs that are really there. */
function music_tracks(): array
{
    $list = json_decode((string) @file_get_contents(music_dir() . '/tracks.json'), true);
    $out = [];
    foreach (is_array($list) ? $list : [] as $t) {
        $f = basename((string) ($t['file'] ?? ''));
        if ($f !== '' && preg_match('/^[a-z0-9._-]+\.mp3$/i', $f) && is_file(music_dir() . '/' . $f)) {
            $out[] = ['file' => $f, 'title' => (string) ($t['title'] ?? $f), 'credit' => (string) ($t['credit'] ?? '')];
        }
    }
    return $out;
}

/** Lines for the YouTube description (NCS asks for this credit in livestreams). */
function music_credit_text(): string
{
    $tracks = music_tracks();
    if (!$tracks) return '';
    $t = "🎵 Music provided by NoCopyrightSounds: http://spoti.fi/NCS\n";
    foreach ($tracks as $s) $t .= '• ' . $s['title'] . ($s['credit'] !== '' ? ' — ' . $s['credit'] : '') . "\n";
    return $t;
}
