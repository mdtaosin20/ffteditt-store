<?php
/**
 * Commentator voice lines.
 * Text can use {P1} {P2} (player names) and {L1} {L2} (first letters).
 * Each line gets an "auto" voice made on the VPS; an uploaded recording replaces it.
 */
declare(strict_types=1);

const VOICE_MAX_BYTES = 3 * 1024 * 1024;

function voice_default_lines(): array
{
    return [
        'intro_1'    => ['Welcome', "Welcome to the ultimate showdown! {P1} versus {P2}! Who is the real GOAT? Tell us in the comments!"],
        'intro_2'    => ['Welcome', "Welcome back, football fans! It's {P1} against {P2}, live, all day long! Pick your side now!"],
        'call_1'     => ['Ask for comments', "Who wins this round? Comment now! {P1} fans, type {L1}! {P2} fans, type {L2}!"],
        'call_2'     => ['Ask for comments', "Drop your country in the comments! Let's see where the {P1} fans and the {P2} fans are watching from!"],
        'call_3'     => ['Ask for comments', "{P1} fans, make some noise in the chat!"],
        'call_4'     => ['Ask for comments', "{P2} fans, where are you? Let me see you in the comments!"],
        'call_5'     => ['Ask for comments', "Who is the greatest of all time? Type your answer in the chat right now!"],
        'call_6'     => ['Ask for comments', "Hit like if you think {P1} takes this one! Comment {P2} if you disagree!"],
        'sub_1'      => ['Subscribe', "Don't forget to like and subscribe for more {P1} versus {P2}, live every day!"],
        'sub_2'      => ['Subscribe', "New here? Subscribe and turn on notifications so you never miss a match!"],
        'drop_1'     => ['New round', "Here we go! The balls are dropping!"],
        'drop_2'     => ['New round', "Next round! Who gets there first?"],
        'drop_3'     => ['New round', "Balls are in! Place your bets in the chat!"],
        'goal_p1_1'  => ['Goal player 1', "Goal for {P1}! What a finish!"],
        'goal_p1_2'  => ['Goal player 1', "{P1} scores! The magic continues!"],
        'goal_p1_3'  => ['Goal player 1', "In it goes! Point to {P1}!"],
        'goal_p2_1'  => ['Goal player 2', "Goal for {P2}! Siuuu!"],
        'goal_p2_2'  => ['Goal player 2', "{P2} scores! What a strike!"],
        'goal_p2_3'  => ['Goal player 2', "And it's in! Point to {P2}!"],
        'lead_p1_1'  => ['Player 1 leads', "{P1} takes the lead! {P2} fans, you need to cheer louder!"],
        'lead_p2_1'  => ['Player 2 leads', "{P2} takes the lead! {P1} fans, are you still with us?"],
        'tie_1'      => ['Scores level', "It's all square! This is anyone's game!"],
        'tie_2'      => ['Scores level', "Level again! Who breaks the tie? Comment now!"],
        'close_1'    => ['Match point', "Match point! Just one more goal to win the game!"],
        'close_2'    => ['Match point', "One more and it's over! Who is it going to be?"],
        'win_p1_1'   => ['Player 1 wins', "It's over! {P1} wins the game! {P1} fans, celebrate in the chat!"],
        'win_p2_1'   => ['Player 2 wins', "It's over! {P2} wins the game! {P2} fans, let's hear you!"],
        'new_game_1' => ['New game', "Brand new game starting now! Scores back to zero. Pick your side in the comments!"],
    ];
}

/** Stored state: enabled, volume, edited texts, auto/uploaded audio per line. */
function voice_state(): array
{
    $s = json_decode(setting_get('voice') ?? '', true);
    $s = is_array($s) ? $s : [];
    return [
        'enabled' => (bool) ($s['enabled'] ?? true),
        'volume'  => (int) ($s['volume'] ?? 95),
        'texts'   => (array) ($s['texts'] ?? []),
        'auto'    => (array) ($s['auto'] ?? []),    // id => ['file' => ..., 'hash' => ...]
        'upload'  => (array) ($s['upload'] ?? []),  // id => file
        'error'   => (string) ($s['error'] ?? ''),
    ];
}

function voice_save(array $s): void
{
    setting_set('voice', json_encode($s));
}

function voice_name(string $n): string
{
    return ucwords(strtolower($n));
}

/** Final text of every line with the current player names filled in. */
function voice_texts(array $state, array $game): array
{
    $p1 = voice_name($game['players'][0]['name']);
    $p2 = voice_name($game['players'][1]['name']);
    $rep = ['{P1}' => $p1, '{P2}' => $p2, '{L1}' => substr($p1, 0, 1), '{L2}' => substr($p2, 0, 1)];
    $out = [];
    foreach (voice_default_lines() as $id => [$label, $text]) {
        $raw = trim((string) ($state['texts'][$id] ?? '')) ?: $text;
        $out[$id] = ['label' => $label, 'raw' => $raw, 'text' => strtr($raw, $rep)];
    }
    return $out;
}

function voice_hash(string $text): string
{
    return substr(sha1('v1|' . $text), 0, 12);
}

/** Lines that still need an automatic voice (no upload, or the text changed). */
function voice_missing(array $state, array $game): array
{
    $todo = [];
    if (!$state['enabled']) {
        return $todo;
    }
    foreach (voice_texts($state, $game) as $id => $l) {
        if (!empty($state['upload'][$id])) {
            continue;
        }
        $a = $state['auto'][$id] ?? null;
        if (!$a || ($a['hash'] ?? '') !== voice_hash($l['text'])) {
            $todo[] = ['id' => $id, 'text' => $l['text'], 'hash' => voice_hash($l['text'])];
        }
    }
    return $todo;
}

/** Public data for the game: text + audio link of each line. */
function voice_public(array $game): array
{
    $s = voice_state();
    $lines = [];
    foreach (voice_texts($s, $game) as $id => $l) {
        $file = (string) ($s['upload'][$id] ?? '');
        if ($file === '') {
            $a = $s['auto'][$id] ?? null;
            if ($a && ($a['hash'] ?? '') === voice_hash($l['text'])) {
                $file = (string) $a['file'];
            }
        }
        $lines[$id] = ['text' => $l['text'], 'url' => upload_url($file)];
    }
    return ['enabled' => $s['enabled'], 'volume' => max(0, min(100, $s['volume'])) / 100, 'lines' => $lines];
}

/** Save an uploaded recording (mp3, wav, ogg, m4a). */
function save_audio_upload(array $f): string
{
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed. Try a smaller file (max 3 MB).');
    }
    if ($f['size'] > VOICE_MAX_BYTES) {
        throw new RuntimeException('File is too big (max 3 MB).');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';
    $types = [
        'audio/mpeg' => 'mp3', 'audio/mp3' => 'mp3', 'audio/x-wav' => 'wav', 'audio/wav' => 'wav', 'audio/vnd.wave' => 'wav',
        'audio/ogg' => 'ogg', 'application/ogg' => 'ogg', 'audio/mp4' => 'm4a', 'audio/x-m4a' => 'm4a', 'video/mp4' => 'm4a',
    ];
    if (!isset($types[$mime])) {
        throw new RuntimeException('Please upload an MP3, WAV, OGG or M4A file.');
    }
    $name = bin2hex(random_bytes(12)) . '.' . $types[$mime];
    if (!move_uploaded_file($f['tmp_name'], upload_dir() . '/' . $name)) {
        throw new RuntimeException('Could not save the file on the server.');
    }
    return $name;
}

/** Save an automatic voice sent by the VPS (mp3 bytes). */
function save_auto_voice(string $id, string $hash, string $mp3): bool
{
    if (!isset(voice_default_lines()[$id]) || !preg_match('/^[a-f0-9]{12}$/', $hash)) {
        return false;
    }
    if (strlen($mp3) < 200 || strlen($mp3) > VOICE_MAX_BYTES) {
        return false;
    }
    $isMp3 = substr($mp3, 0, 3) === 'ID3' || (ord($mp3[0]) === 0xFF && (ord($mp3[1]) & 0xE0) === 0xE0);
    if (!$isMp3) {
        return false;
    }
    $s = voice_state();
    $want = voice_missing($s, game_config());
    $ok = false;
    foreach ($want as $w) {
        if ($w['id'] === $id && $w['hash'] === $hash) {
            $ok = true;
        }
    }
    if (!$ok) {
        return false; // text changed meanwhile, or an upload exists
    }
    $name = bin2hex(random_bytes(12)) . '.mp3';
    file_put_contents(upload_dir() . '/' . $name, $mp3);
    if (!empty($s['auto'][$id]['file'])) {
        delete_upload($s['auto'][$id]['file']);
    }
    $s['auto'][$id] = ['file' => $name, 'hash' => $hash];
    $s['error'] = '';
    voice_save($s);
    return true;
}
