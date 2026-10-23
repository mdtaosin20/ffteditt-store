<?php
/**
 * A little different on every live: when a stream starts, the panel puts a slightly changed title,
 * description (first line), tags and thumbnail (a small badge, zoom or frame) on the new live video,
 * and remembers which version went where. Audience Insights then compares the versions, so you can
 * see which title and thumbnail bring the most viewers.
 */
declare(strict_types=1);

const YTV_HOOKS = [
    '🔴 LIVE NOW — type your country in the chat!',
    '🌍 Which country wins today? Comment below!',
    '🔥 New round — your country needs you!',
    '🏆 Every chat helps your country win!',
    '👋 Welcome! Type your country and join the battle!',
    '⚡ Live 24/7 — your comment changes the game!',
];
const YTV_BADGES = ['* LIVE NOW', 'WHO WINS TODAY?', '24/7', 'NEW ROUND', 'TYPE YOUR COUNTRY', 'JOIN THE BATTLE'];
const YTV_STYLES = [ // badge colour, text colour, zoom, frame colour (null = none)
    [[230, 30, 50], [255, 255, 255], 1.00, null],
    [[255, 201, 58], [20, 20, 30], 1.05, null],
    [[37, 99, 235], [255, 255, 255], 1.08, [255, 201, 58]],
    [[34, 197, 94], [255, 255, 255], 1.03, null],
    [[147, 51, 234], [255, 255, 255], 1.06, [255, 255, 255]],
];
const YTV_TAG_POOL = ['live game', 'country battle', 'flags', 'chat game', 'interactive live', 'countryballs', '24/7 live', 'which country wins'];

/** Letter shown in the panel for version number n (A, B, C … Z, then A2 …). */
function ytv_letter(int $n): string
{
    return chr(65 + $n % 26) . ($n >= 26 ? (string) (intdiv($n, 26) + 1) : '');
}

function ytv_badges(array $s): array
{
    $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) ($s['yt_badges'] ?? '')) ?: [])));
    return $lines ?: YTV_BADGES;
}

/** Title without the emojis at its start. */
function ytv_core(string $t): string
{
    return trim(preg_replace('/^[\s\x{2190}-\x{2BFF}\x{1F000}-\x{1FAFF}\x{FE0F}\x{200D}|·:!-]+/u', '', $t) ?? $t);
}

/** A small, rule-made change of the title (used when AI is off). */
function ytv_title_rule(string $t, int $n): string
{
    $core = ytv_core($t);
    $em = ['🔥', '🌍', '🔴', '🏆', '⚡', '🎮'][$n % 6];
    switch ($n % 5) {
        case 0: $v = $t; break;
        case 1: $v = $em . ' ' . $core; break;
        case 2:
            $v = preg_match('/^(.{8,}?)\s*[|·]\s*(.{8,})$/u', $core, $m) ? $em . ' ' . trim($m[2]) . ' | ' . trim($m[1]) : $core . ' ' . $em;
            break;
        case 3: $v = stripos($core, 'live') === false ? '🔴 LIVE: ' . $core : '🔴 ' . preg_replace('/\blive\b/i', 'LIVE NOW', $core, 1); break;
        default: $v = $core . (mb_strlen($core) < 80 ? ' — Who Wins? 🏆' : ''); break;
    }
    return mb_strlen($v) > 100 ? rtrim(mb_substr($v, 0, 99)) : $v;
}

/** A small change made by the AI (if it is on); null = use the rule. */
function ytv_title_ai(string $t, array $before): ?string
{
    if (!ai_ready()) return null;
    $c = ai_cfg();
    if (ai_usage()['usd'] >= (float) $c['daily']) return null;
    $system = 'You write YouTube live stream titles. Make a SMALL variation of the given title: keep its language, its main words and its meaning, '
        . 'you may change the order, the emojis or a few words. It must stay true to the stream (no fake prizes, no clickbait lies, no other people\'s names). '
        . 'At most 95 characters. Answer with the new title only.';
    $user = "Title: {$t}" . ($before ? "\nDo not repeat any of these earlier versions:\n- " . implode("\n- ", array_slice($before, 0, 6)) : '');
    $out = ai_call($system, $user, 60, 10);
    if ($out === null) return null;
    $out = trim(preg_replace('/\s+/', ' ', strip_tags($out)) ?? '', " \"'\n");
    if (mb_strlen($out) < 10 || mb_strlen($out) > 100 || ai_bad($out) || in_array($out, $before, true)) return null;
    return $out;
}

/** The thumbnail with badge / zoom / frame number n, as a 1280×720 JPG. null = could not make it. */
function ytv_thumb(string $src, int $n, array $badges, string $out): bool
{
    if (!function_exists('imagecreatetruecolor')) return false;
    $info = @getimagesize($src);
    if (!$info) return false;
    $im = match ($info[2]) { IMAGETYPE_JPEG => @imagecreatefromjpeg($src), IMAGETYPE_PNG => @imagecreatefrompng($src), IMAGETYPE_WEBP => @imagecreatefromwebp($src), default => false };
    if (!$im) return false;
    [$bg, $fg, $zoom, $frame] = YTV_STYLES[$n % count(YTV_STYLES)];
    $W = 1280; $H = 720; $w = imagesx($im); $h = imagesy($im);
    // fill 16:9, then zoom in a little from the middle
    $scale = max($W / $w, $H / $h) * $zoom;
    $sw = $W / $scale; $sh = $H / $scale;
    $c = imagecreatetruecolor($W, $H);
    imagecopyresampled($c, $im, 0, 0, (int) (($w - $sw) / 2), (int) (($h - $sh) / 2), $W, $H, (int) $sw, (int) $sh);
    if ($frame) { $fc = imagecolorallocate($c, ...$frame); for ($i = 0; $i < 12; $i++) imagerectangle($c, $i, $i, $W - 1 - $i, $H - 1 - $i, $fc); }
    $font = __DIR__ . '/fonts/Poppins-Bold.ttf';
    $text = $badges[$n % count($badges)] ?? '';
    if ($text !== '' && function_exists('imagettftext') && is_file($font)) {
        $dot = str_starts_with($text, '* '); if ($dot) $text = substr($text, 2);
        $text = mb_strtoupper(mb_substr($text, 0, 24));
        $size = 40; $pad = 22;
        $b = imagettfbbox($size, 0, $font, $text);
        $tw = $b[2] - $b[0] + ($dot ? 44 : 0); $bw = $tw + $pad * 2; $bh = $size + $pad * 2;
        $x = $W - 34 - $bw - ($frame ? 8 : 0); $y = 30 + ($frame ? 8 : 0);
        imagefilledrectangle($c, $x + 6, $y + 8, $x + $bw + 6, $y + $bh + 8, imagecolorallocatealpha($c, 0, 0, 0, 60));
        imagefilledrectangle($c, $x, $y, $x + $bw, $y + $bh, imagecolorallocate($c, ...$bg));
        $fc = imagecolorallocate($c, ...$fg); $tx = $x + $pad;
        if ($dot) { imagefilledellipse($c, $tx + 16, (int) ($y + $bh / 2), 28, 28, $fc); $tx += 44; }
        imagettftext($c, $size, 0, $tx, $y + $pad + $size, $fc, $font, $text);
    }
    $ok = imagejpeg($c, $out, 88);
    if ($ok && filesize($out) > 2 * 1024 * 1024) $ok = imagejpeg($c, $out, 75);
    return $ok;
}

/**
 * The version for this live video (made once per video, then reused if the panel applies it again).
 * Returns ['title', 'desc', 'tags' => [], 'thumb' => [file, mime] | null, 'letter'].
 */
function ytv_for_video(array $s, array $t, string $video, ?array $thumb): array
{
    $sid = (int) $s['id'];
    $base = md5($t['title'] . "\n" . $t['desc'] . "\n" . ($thumb[0] ?? '') . (is_file($thumb[0] ?? '') ? filemtime($thumb[0]) : '') . "\n" . $s['yt_badges']);
    $q = db()->prepare('SELECT * FROM yt_variants WHERE stream_id = ? AND video = ? AND base = ? ORDER BY id DESC LIMIT 1');
    $q->execute([$sid, $video, $base]);
    $row = $q->fetch();
    if (!$row) {
        $n = (int) db()->query('SELECT COUNT(*) FROM yt_variants WHERE stream_id = ' . $sid)->fetchColumn();
        $prev = db()->prepare('SELECT title FROM yt_variants WHERE stream_id = ? ORDER BY id DESC LIMIT 8');
        $prev->execute([$sid]);
        $before = array_column($prev->fetchAll(), 'title');
        $title = $n % 5 === 0 ? $t['title'] : (ytv_title_ai($t['title'], $before) ?? ytv_title_rule($t['title'], $n));
        $hook = YTV_HOOKS[$n % count(YTV_HOOKS)];
        // tags: the description's #words in a different order each time, plus two from the pool
        preg_match_all('/#([\p{L}\p{N}_]+)/u', $t['desc'], $m);
        $tags = array_values(array_unique($m[1]));
        if ($tags) { $k = $n % count($tags); $tags = array_merge(array_slice($tags, $k), array_slice($tags, 0, $k)); }
        $tags = array_slice($tags, 0, 13);
        $tags[] = YTV_TAG_POOL[$n % count(YTV_TAG_POOL)];
        $tags[] = YTV_TAG_POOL[($n + 3) % count(YTV_TAG_POOL)];
        db()->prepare('INSERT INTO yt_variants (stream_id, video, ts, n, base, title, hook, tags, thumb) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$sid, $video, time(), $n, $base, $title, $hook, json_encode(array_values(array_unique($tags))), $thumb ? ytv_letter($n) : '']);
        $q->execute([$sid, $video, $base]);
        $row = $q->fetch();
    }
    $n = (int) $row['n'];
    // description: our hook line first (a line from an earlier version is replaced)
    $desc = $t['desc'];
    foreach (YTV_HOOKS as $h) if (str_starts_with($desc, $h)) { $desc = ltrim(substr($desc, strlen($h))); break; }
    $desc = $row['hook'] . "\n\n" . $desc;
    if (strlen($desc) > 4900) $desc = substr($desc, 0, 4900);
    $file = null;
    if ($thumb) {
        $out = yt_thumb_dir() . "/var_{$sid}.jpg";
        $file = ytv_thumb($thumb[0], $n, ytv_badges($s), $out) ? [$out, 'image/jpeg'] : $thumb;
    }
    return ['title' => $row['title'], 'desc' => $desc, 'tags' => json_decode($row['tags'], true) ?: [], 'thumb' => $file, 'letter' => ytv_letter($n)];
}

/** Every version of every stream with what its live video did (for Audience Insights). */
function ytv_results(int $since): array
{
    $rows = db()->query('SELECT * FROM yt_variants WHERE ts >= ' . (int) $since . ' ORDER BY stream_id, id')->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $a = db()->prepare('SELECT COUNT(*) n, AVG(viewers) avg, MAX(viewers) peak, MIN(likes) l0, MAX(likes) l1 FROM audience WHERE stream_id = ? AND video = ?');
        $a->execute([$r['stream_id'], $r['video']]);
        $x = $a->fetch();
        $out[(int) $r['stream_id']][] = [
            'letter' => ytv_letter((int) $r['n']), 'video' => $r['video'], 'ts' => (int) $r['ts'], 'title' => $r['title'], 'hook' => $r['hook'],
            'thumb' => $r['thumb'], 'mins' => (int) $x['n'], 'avg' => round((float) $x['avg'], 1), 'peak' => (int) $x['peak'],
            'likes' => max(0, (int) $x['l1'] - (int) $x['l0']),
        ];
    }
    foreach ($out as &$list) { // the best version: highest average viewers among lives measured 30+ minutes
        $best = -1; $bestAvg = -1;
        foreach ($list as $i => $v) if ($v['mins'] >= 30 && $v['avg'] > $bestAvg) { $best = $i; $bestAvg = $v['avg']; }
        foreach ($list as $i => &$v) $v['best'] = $i === $best;
        unset($v);
    }
    unset($list);
    return $out;
}
