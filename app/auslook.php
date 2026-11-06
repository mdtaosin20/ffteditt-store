<?php
/**
 * 🎛 Auto Shorts → Video look: everything the game menu has (place, balls, trophy, camera, text, sounds …),
 * set once in the panel. Each video is made exactly like this; where several choices are ticked (and
 * "🎲 Make every video different" is on), every video gets another one of them.
 * Also 🗣 Voice-over (the owner's cloned voice — Admin → My Voice — saying a new script every video) and
 * 🎵 Background music (songs uploaded here, kept on the panel).
 * The VPS page asks for its look once (public/api/autoshorts.php?do=setup); the answer is kept in the job.
 */
declare(strict_types=1);

const AUS_PLACES = ['night' => '🌙 Night', 'day' => '☀️ Day', 'sunset' => '🌅 Sunset', 'beach' => '🏖 Beach', 'snow' => '❄️ Snow', 'street' => '🏙 Street'];
const AUS_BCOL = ['classic' => '⚪ Classic', 'red' => '🔴 Red & white', 'yellow' => '🟡 Yellow & blue', 'orange' => '🟠 Orange & black', 'blue' => '🔵 Blue & white',
                  'green' => '🟢 Neon green', 'purple' => '🟣 Purple & gold', 'pink' => '🩷 Pink & white', 'gold' => '🟤 Black & gold', 'sky' => '🩵 Sky & navy'];
const AUS_TSTYLE = ['classic' => 'Classic', 'A' => 'A · Gold 3D', 'B' => 'B · Glossy bubble', 'C' => 'C · E-sports neon', 'D' => 'D · Comic burst', 'E' => 'E · Stickers',
                    'F' => 'F · Fire 🔥', 'G' => 'G · Glass card', 'H' => 'H · Trophy gold', 'I' => 'I · Red button', 'J' => 'J · Rainbow 🌈', 'K' => 'K · Scoreboard',
                    'L' => 'L · Pink-purple', 'off' => 'No text'];
const AUS_NECK = ['A' => '💎 A Ring', 'B' => '🪽 B Wings', 'C' => '⭐ C Star', 'none' => 'Plain'];
const AUS_VLANG = ['en' => 'English', 'es' => 'Español', 'pt' => 'Português', 'fr' => 'Français', 'de' => 'Deutsch', 'it' => 'Italiano'];

function aus_look_game_def(string $g): array
{
    $q = ['cupkick' => 'Which country touches the cup? 🏆', 'actionkick' => 'Which one hits the cup? 🏆', 'playerkick' => 'Which player touches the cup? 🏆'][$g] ?? 'Which one touches the cup? 🏆';
    return [
        'np' => 6, 'speed' => 1.0, 'within' => 2.0, 'kvol' => 1.0, 'size' => 1.0, 'ball' => 0.45,
        'bcol' => ['rand'], 'places' => ['rand'], 'quality' => 'sharp', 'grass' => true, 'snd' => true,
        'tstyles' => ['classic', 'A', 'B', 'C', 'H'], 't1' => "Watch closely! 👀\nOnly 1 ball hits it! 👀\nDon't blink! 👀", 't2' => $q, 't3' => "Comment below 👇\nWhich one did YOU see? 👇",
        'chan' => '', 'cup' => true, 'necks' => ['A', 'B', 'C'], 'cz' => 0.0, 'cx' => 0.0, 'cs' => 1.0,
        'cam' => false, 'fov' => 50, 'camz' => 0.0, 'camy' => 0.0, 'gap' => 0.2,
        'voice' => false, 'vlang' => 'en', 'subs' => true,
        'music' => false, 'songs' => [], 'mvol' => 0.35, 'fade' => true,
    ];
}
function aus_look(): array
{
    $s = json_decode((string) (setting_get('aus_look') ?? ''), true);
    $s = is_array($s) ? $s : [];
    $out = ['same' => (bool) ($s['same'] ?? true), 'mix' => (bool) ($s['mix'] ?? true), 'g' => []];
    foreach (array_keys(aus_games()) as $g) $out['g'][$g] = array_merge(aus_look_game_def($g), is_array($s['g'][$g] ?? null) ? $s['g'][$g] : []);
    return $out;
}
function aus_look_save(array $l): void { setting_set('aus_look', json_encode($l, JSON_UNESCAPED_UNICODE)); }

/** One game's look from the form (POST lk[...]) — every value checked. */
function aus_look_from_post(array $p, string $g): array
{
    $d = aus_look_game_def($g);
    $num = fn ($k, $lo, $hi) => max($lo, min($hi, round((float) ($p[$k] ?? $d[$k]), 2)));
    $pick = fn ($k, array $ok) => array_values(array_intersect((array) ($p[$k] ?? []), $ok));
    $txt = fn ($k, $n) => implode("\n", array_slice(array_filter(array_map(fn ($x) => mb_substr(trim($x), 0, $n), explode("\n", str_replace("\r", '', (string) ($p[$k] ?? ''))))), 0, 30));
    return [
        'np' => (int) $num('np', 2, 6), 'speed' => $num('speed', 0.6, 1.6), 'within' => $num('within', 1, 6), 'kvol' => $num('kvol', 0, 3),
        'size' => in_array((string) ($p['size'] ?? ''), ['1', '1.4', '1.7'], true) ? (float) $p['size'] : 1.0,
        'ball' => in_array((string) ($p['ball'] ?? ''), ['0.38', '0.45', '0.52'], true) ? (float) $p['ball'] : 0.45,
        'bcol' => $pick('bcol', array_merge(['rand'], array_keys(AUS_BCOL))) ?: ['classic'],
        'places' => $pick('places', array_merge(['rand'], array_keys(AUS_PLACES))) ?: ['rand'],
        'quality' => ($p['quality'] ?? '') === 'normal' ? 'normal' : 'sharp', 'grass' => !empty($p['grass']), 'snd' => !empty($p['snd']),
        'tstyles' => $pick('tstyles', array_keys(AUS_TSTYLE)) ?: ['classic'],
        't1' => $txt('t1', 40), 't2' => $txt('t2', 44), 't3' => $txt('t3', 44),
        'chan' => mb_substr(trim((string) ($p['chan'] ?? '')), 0, 30),
        'cup' => !empty($p['cup']), 'necks' => $pick('necks', array_keys(AUS_NECK)) ?: ['A'],
        'cz' => $num('cz', -6, 2), 'cx' => $num('cx', -2.5, 2.5), 'cs' => $num('cs', 0.7, 2),
        'cam' => !empty($p['cam']), 'fov' => (int) $num('fov', 50, 72), 'camz' => $num('camz', -4, 6), 'camy' => $num('camy', -1.5, 3), 'gap' => $num('gap', 0.2, 1.4),
        'voice' => !empty($p['voice']), 'vlang' => isset(AUS_VLANG[$p['vlang'] ?? '']) ? (string) $p['vlang'] : 'en', 'subs' => !empty($p['subs']),
        'music' => !empty($p['music']), 'songs' => $pick('songs', array_column(aus_songs(), 'id')),
        'mvol' => $num('mvol', 0, 1.5), 'fade' => !empty($p['fade']),
    ];
}

/* ---------------------------------------------------------------- 🎵 songs kept on the panel */
function aus_song_dir(): string { $d = DATA_DIR . '/aus_music'; if (!is_dir($d)) @mkdir($d, 0700, true); return $d; }
function aus_songs(): array { return json_decode((string) (setting_get('aus_music') ?? ''), true) ?: []; }
function aus_songs_save(array $l): void { setting_set('aus_music', json_encode(array_values($l), JSON_UNESCAPED_UNICODE)); }
function aus_song_file(array $s): string { return aus_song_dir() . '/' . preg_replace('/[^a-z0-9]/', '', (string) $s['id']) . '.' . preg_replace('/[^a-z0-9]/', '', (string) $s['ext']); }
function aus_song_add(array $f): string
{
    if (($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) return 'The upload did not arrive (too big for the server? max about 25 MB).';
    $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['mp3', 'm4a', 'aac', 'wav', 'ogg'], true)) return 'Use an mp3, m4a, aac, wav or ogg file.';
    if ((int) $f['size'] > 25 * 1048576) return 'This file is too big (max 25 MB).';
    $s = ['id' => 's' . bin2hex(random_bytes(4)), 'ext' => $ext, 'name' => mb_substr(pathinfo((string) $f['name'], PATHINFO_FILENAME), 0, 40) ?: 'Song', 'from' => 0.0, 'to' => 30.0];
    if (!move_uploaded_file((string) $f['tmp_name'], aus_song_file($s))) return 'Could not keep the file.';
    $l = aus_songs(); $l[] = $s; aus_songs_save($l);
    return '';
}

/* ---------------------------------------------------------------- 🗣 a new voice-over script for each video */
function aus_script(string $game, string $lang, int $np = 6): array
{
    $used = json_decode((string) (setting_get('aus_scr_used') ?? ''), true) ?: [];
    $mark = function (string $k) use ($used): void { $used[] = $k; setting_set('aus_scr_used', json_encode(array_slice(array_values(array_unique($used)), -2000), JSON_UNESCAPED_UNICODE)); };
    $ok = fn ($s) => is_array($s) && trim((string) ($s['hook'] ?? '')) !== '';
    // 1) the owner's saved scripts in 🎬 Script Studio (this game or any), each used once
    foreach (json_decode((string) (setting_get('scr_saved') ?? ''), true) ?: [] as $s) {
        if (!$ok($s) || in_array('s:' . ($s['id'] ?? ''), $used, true) || (($s['game'] ?? $game) !== $game && ($s['game'] ?? '') !== '')) continue;
        if (($s['lang'] ?? 'en') !== $lang) continue;
        $all = implode(' ', [(string) $s['hook'], (string) ($s['build'] ?? ''), (string) ($s['hit'] ?? ''), (string) ($s['end'] ?? '')]);
        if (preg_match_all('/\b(two|three|four|five|six)\s+(balls|flags|players|signs|stars)\b/i', $all, $mm) && array_diff(array_map('strtolower', $mm[1]), [[2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five', 6 => 'six'][$np] ?? 'six'])) continue;   // made for another number of balls
        if (aus_has_names(implode('. ', [(string) $s['hook'], (string) ($s['build'] ?? ''), (string) ($s['hit'] ?? ''), (string) ($s['end'] ?? '')]))) continue;   // made for other names (e.g. “Germany or USA?”)
        $mark('s:' . ($s['id'] ?? ''));
        return $s;
    }
    // 2) ✨ a new one by the AI (the OpenAI key in YouTube Manager → AI Replies)
    if (ai_key() !== '') {
        $what = ['cupkick' => 'country flag balls', 'actionkick' => 'players holding SUBSCRIBE / LIKE / COMMENT / SHARE signs', 'playerkick' => 'famous football players'][$game] ?? 'balls';
        $sys = "You write a voice-over for a 9:16 YouTube Short of a football puzzle: {$what} kick balls at a golden trophy and only ONE touches it; viewers guess which. "
             . "There are exactly {$np} balls — if you say a number, say {$np}. "
             . 'Audience: USA and Europe, families and adults; friendly, no scary words, never mention kids. Never reveal the answer. Write in ' . (AUS_VLANG[$lang] ?? 'English') . '. '
             . 'Parts: hook (max 9 words, makes people stop scrolling), hit (max 5 words, said when the ball hits the trophy), end (max 10 words, asks to comment the answer). No emojis, no hashtags. '
             . 'Reply ONLY with JSON {"hook":"…","hit":"…","end":"…"}.';
        $avoid = array_slice(array_map(fn ($x) => substr((string) $x, 2), array_filter($used, fn ($x) => str_starts_with((string) $x, 'h:'))), -30);
        $t = ai_call($sys, 'Do not reuse these hooks: ' . implode(' | ', $avoid), 300, 30);
        if ($t !== null && preg_match('/\{.*\}/s', $t, $m) && is_array($r = json_decode($m[0], true)) && $ok($r)) {
            $mark('h:' . mb_substr((string) $r['hook'], 0, 120));
            return ['hook' => (string) $r['hook'], 'hit' => (string) ($r['hit'] ?? ''), 'end' => (string) ($r['end'] ?? '')];
        }
    }
    // 3) ready-made lines (English)
    $h = ['Only one ball touches the trophy. Can you see it?', 'Ninety percent of people get this wrong.', 'Watch closely, this one is really hard.', 'Bet you cannot spot the right one.', 'Keep your eyes on the golden trophy.'];
    $x = ['There it is!', 'Did you see it?', 'Boom, right there!', 'That one!'];
    $e = ['Comment which one you saw!', 'Tell me your answer in the comments!', 'Did you get it? Comment below!'];
    return ['hook' => $h[array_rand($h)], 'hit' => $x[array_rand($x)], 'end' => $e[array_rand($e)]];
}

/** A script that names countries / players fits only that video: a capital word inside a sentence (not its first word). */
function aus_has_names(string $t): bool
{
    $cn = 'usa|america|england|uk|britain|france|germany|spain|italy|portugal|netherlands|holland|brazil|argentina|mexico|canada|belgium|poland|sweden|norway|croatia|switzerland|denmark|uruguay|colombia|japan|morocco|senegal|nigeria|ghana|egypt|saudi|qatar|korea|australia|turkey|serbia|wales|scotland|ireland|austria|chile|peru|ecuador|messi|ronaldo|neymar|mbappe|mbappé|haaland|salah|kane|bellingham|vinicius|yamal';
    if (preg_match('/\b(' . $cn . ')\b/iu', $t)) return true;
    foreach (preg_split('/[.!?…:;—\n]+/u', $t) as $sen) {
        $w = preg_split('/[\s,"“”\'()]+/u', trim($sen), -1, PREG_SPLIT_NO_EMPTY);
        foreach (array_slice($w, 1) as $x) if (preg_match('/^\p{Lu}/u', $x) && !in_array($x, ['I', 'I\'m', 'I\'ll', 'OK', 'YOU', 'GOAL'], true) && !preg_match('/^\p{Lu}+$/u', $x)) return true;
    }
    return false;
}

/* ---------------------------------------------------------------- the look of one video (asked by the VPS page once) */
function aus_setup(array $job): array
{
    if (!empty($job['setup'])) return $job['setup'];   // the page was opened again: the same look
    $L = aus_look(); $g = (string) $job['game'];
    $lk = $L['g'][$L['same'] ? 'cupkick' : $g] ?? aus_look_game_def($g);
    if ($L['same'] && $g !== 'cupkick') $lk['t2'] = $L['g'][$g]['t2'] ?? $lk['t2'];   // the question fits the game
    $one = fn (array $a) => $a ? ($L['mix'] ? $a[array_rand($a)] : $a[0]) : '';
    $line = function (string $t) use ($one): string { return (string) $one(array_values(array_filter(array_map('trim', explode("\n", $t))))); };
    $ctlKey = ['cupkick' => 'fft_cupkick_ctl', 'actionkick' => 'fft_actkick_ctl', 'playerkick' => 'fft_playerkick_ctl'][$g] ?? 'fft_' . $g . '_ctl';
    $place = $one($lk['places']); $bcol = $one($lk['bcol']); $ts = $one($lk['tstyles']); $neck = $one($lk['necks']);
    $c = aus_cfg();
    $ls = [
        $ctlKey => ['speed' => $lk['speed'], 'within' => $lk['within'], 'mess' => (int) $c['level'], 'size' => $lk['size'], 'np' => $lk['np'], 'ball' => $lk['ball'],
                    'place' => $place, 'bcol' => $bcol, 'quality' => $lk['quality'], 'grass' => $lk['grass'], 'chan' => $lk['chan'], 'kvol' => $lk['kvol'], 'tstyle' => $ts],
        'fft_' . $g . '_cam' => ['on' => $lk['cam'], 'fov' => $lk['fov'], 'z' => $lk['camz'], 'y' => $lk['camy'], 'gap' => $lk['gap'], 'cup' => true,
                                 'cz' => $lk['cup'] ? $lk['cz'] : 0, 'cx' => $lk['cup'] ? $lk['cx'] : 0, 'cs' => $lk['cup'] ? $lk['cs'] : 1],
        'fft_' . $g . '_neck' => $neck,
        'fft_mvg_' . $g => ['on' => false],
    ];
    $sum = [($place === 'rand' ? '🎲 new stadium' : (AUS_PLACES[$place] ?? $place)), '🏆 ' . (AUS_NECK[$neck] ?? $neck), '🔤 ' . (AUS_TSTYLE[$ts] ?? $ts)];
    $out = ['ls' => $ls, 'np' => $lk['np'], 'dom' => ['t1' => $line($lk['t1']), 't2' => $line($lk['t2']), 't3' => $line($lk['t3']), 'snd' => $lk['snd']], 'music' => null, 'note' => ''];
    // 🗣 voice-over in the owner's cloned voice
    if ($lk['voice']) {
        if (!function_exists('mv_ready') || !mv_ready()) $out['note'] = 'Voice-over skipped: set up Admin → 🗣 My Voice first';
        else {
            @set_time_limit(150);
            $s = aus_script($g, $lk['vlang'], (int) $lk['np']);
            $lines = ['start' => trim((string) $s['hook']), 'hit' => trim((string) ($s['hit'] ?? '')), 'end' => trim((string) ($s['end'] ?? ''))];
            $made = []; $bad = '';
            foreach ($lines as $k => $t) {
                if ($t === '') continue;
                $r = mv_make(mb_substr($t, 0, 300), '', $lk['vlang'], true);
                if (isset($r['item'])) $made[$k] = ['text' => $t, 'lang' => $lk['vlang'], 'url' => $r['item']['url'], 'en' => $r['item']['en'] ?? '', 'words' => $r['words'] ?? []];
                else $bad = (string) ($r['error'] ?? 'error');
            }
            if ($made) {
                $out['ls']['fft_mvg_' . $g] = ['on' => true, 'lang' => $lk['vlang'], 'mode' => 'lines', 'subs' => $lk['subs'], 'text' => array_map(fn ($m) => $m['text'], $made), 'made' => $made];
                $sum[] = '🗣 “' . mb_substr($lines['start'], 0, 40) . '”';
            }
            if ($bad !== '') $out['note'] = 'Voice-over: ' . mb_substr($bad, 0, 120);
        }
    }
    // 🎵 background music from the panel's songs
    $songs = array_values(array_filter(aus_songs(), fn ($s) => (!$lk['songs'] || in_array($s['id'], $lk['songs'], true)) && is_file(aus_song_file($s))));
    if ($lk['music'] && $songs) {
        $s = $L['mix'] ? $songs[array_rand($songs)] : $songs[0];
        $url = '/api/autoshorts.php?' . http_build_query(['do' => 'song', 's' => $s['id'], 'job' => $job['id'], 'sig' => aus_sig($job['id'])]);
        $out['ls']['fft_bgm_' . $g] = ['on' => true, 'id' => 'url:' . $url, 'from' => (float) $s['from'], 'to' => max((float) $s['from'] + 1, (float) $s['to']), 'vol' => $lk['mvol'], 'fade' => $lk['fade']];
        $sum[] = '🎵 ' . $s['name'];
    } elseif ($lk['music']) $out['note'] = trim($out['note'] . ' · Music skipped: no song uploaded', ' ·');
    $out['sum'] = implode(' · ', $sum);
    aus_update($job['id'], ['setup' => $out, 'look' => $out['sum']] + ($out['note'] !== '' ? ['err' => $out['note']] : []));
    return $out;
}
