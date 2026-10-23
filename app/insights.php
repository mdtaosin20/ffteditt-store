<?php
/**
 * Audience Insights: what the live audience asks for, and why a live is not growing.
 * Reads the chat the worker collects (chat_events, last 3 days) and the YouTube viewers/likes
 * history (audience, one row a minute while live). Everything is worked out here, no API calls.
 */
declare(strict_types=1);

const INSIGHT_STOP = ['the', 'and', 'you', 'for', 'are', 'this', 'that', 'with', 'have', 'was', 'not', 'but', 'what', 'all', 'can', 'your',
    'its', 'his', 'her', 'they', 'just', 'like', 'from', 'one', 'out', 'yes', 'get', 'got', 'how', 'who', 'why', 'when', 'will', 'there',
    'lol', 'ami', 'tumi', 'ki', 'na', 'hai', 'kya', 'nhi', 'nahi', 'bhai', 'bro', 'and', 'too', 'very', 'now', 'pls', 'plz', 'please',
    'game', 'live', 'chat', 'hello', 'hii', 'hey', 'nice', 'good', 'ok', 'okay', 'win', 'go'];

/** Country code => lower-case names people type (from the flag list, plus common short forms). */
function insight_country_words(): array
{
    static $w = null;
    if ($w !== null) return $w;
    $w = [];
    foreach (war_known_codes() as $code => $name) {
        $w[strtolower($name)] = $code;
    }
    $extra = ['bd' => 'bd', 'bangla' => 'bd', 'বাংলাদেশ' => 'bd', 'ind' => 'in', 'bharat' => 'in', 'pak' => 'pk', 'usa' => 'us', 'america' => 'us',
        'uk' => 'gb', 'england' => 'gb', 'ksa' => 'sa', 'saudi' => 'sa', 'uae' => 'ae', 'dubai' => 'ae', 'turkiye' => 'tr', 'türkiye' => 'tr',
        'brasil' => 'br', 'indo' => 'id', 'pinas' => 'ph', 'russia' => 'ru', 'korea' => 'kr', 'nepal' => 'np', 'নেপাল' => 'np', 'ভারত' => 'in'];
    foreach ($extra as $k => $v) $w[$k] = $v;
    return $w;
}

function insight_country_of(string $text): ?string
{
    $t = ' ' . mb_strtolower(preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text)) . ' ';
    if (preg_match('/([\x{1F1E6}-\x{1F1FF}])([\x{1F1E6}-\x{1F1FF}])/u', $text, $m)) {
        $code = chr(mb_ord($m[1]) - 0x1F1E6 + 97) . chr(mb_ord($m[2]) - 0x1F1E6 + 97);
        if (isset(war_known_codes()[$code])) return $code;
    }
    $best = null; $len = 0;
    foreach (insight_country_words() as $word => $code) {
        if (mb_strlen($word) > $len && str_contains($t, ' ' . $word . ' ')) { $best = $code; $len = mb_strlen($word); }
    }
    return $best;
}

/** Which writing the message uses — a rough idea of where viewers come from. */
function insight_script(string $t): string
{
    if (preg_match('/\p{Bengali}/u', $t)) return 'Bangla';
    if (preg_match('/\p{Devanagari}/u', $t)) return 'Hindi';
    if (preg_match('/\p{Arabic}/u', $t)) return 'Arabic / Urdu';
    if (preg_match('/\p{Thai}/u', $t)) return 'Thai';
    if (preg_match('/\p{Han}|\p{Hiragana}|\p{Katakana}|\p{Hangul}/u', $t)) return 'East Asian';
    if (preg_match('/\p{Cyrillic}/u', $t)) return 'Russian';
    if (preg_match('/\p{Latin}/u', $t)) return 'English / Latin letters';
    return 'Emoji only';
}

/** Messages that ask for something (a game, a change, a country) — the audience's wishes. */
function insight_is_request(string $t): bool
{
    return (bool) preg_match('/\b(add|please|plz|pls|can you|could you|make|want|wish|next|new game|more|why|when|how|kab|kobe|keno|dao|den|koro|chai|chahiye|another|change|remove|fix|unfair|cheat|bug|slow|boring|lag)\b|\?|চাই|কেন|কবে|দাও|করো|করুন|অ্যাড/iu', $t);
}

/** Everything the page and the report need, for streams that were live in the last $hours. */
function insights(int $hours = 24): array
{
    $since = time() - $hours * 3600;
    $streams = db()->query('SELECT * FROM streams ORDER BY id')->fetchAll();
    $out = ['hours' => $hours, 'streams' => [], 'tips' => [], 'byHour' => array_fill(0, 24, [0, 0])];
    $channels = [];
    foreach ($streams as $s) {
        $aq = db()->prepare('SELECT ts, video, viewers, likes FROM audience WHERE stream_id = ? AND ts >= ? ORDER BY ts');
        $aq->execute([$s['id'], $since]);
        $aud = $aq->fetchAll();
        $cq = db()->prepare("SELECT ts, kind, author, text FROM chat_events WHERE stream_id = ? AND ts >= ? ORDER BY id");
        $cq->execute([$s['id'], $since]);
        $chat = $cq->fetchAll();
        if (!$aud && !$chat && $s['status'] !== 'live') continue;

        $videos = [];
        foreach ($aud as $a) {
            $v = $a['video'];
            if (!isset($videos[$v])) $videos[$v] = ['first' => (int) $a['ts'], 'last' => (int) $a['ts'], 'peak' => 0, 'sum' => 0, 'n' => 0, 'likes0' => (int) $a['likes'], 'likes1' => (int) $a['likes']];
            $x = &$videos[$v];
            $x['last'] = (int) $a['ts']; $x['peak'] = max($x['peak'], (int) $a['viewers']); $x['sum'] += (int) $a['viewers']; $x['n']++; $x['likes1'] = (int) $a['likes'];
            unset($x);
            $h = (int) date('G', (int) $a['ts']);
            $out['byHour'][$h][0] += (int) $a['viewers']; $out['byHour'][$h][1]++;
        }
        $viewers = array_map(fn ($a) => (int) $a['viewers'], $aud);
        $mins = count($aud);
        $avg = $mins ? array_sum($viewers) / $mins : 0;
        $peak = $viewers ? max($viewers) : 0;
        $now = $viewers ? end($viewers) : 0;
        // trend: last 60 minutes vs the 60 before
        $last = array_slice($viewers, -60); $prev = array_slice($viewers, -120, 60);
        $trend = ($last && $prev) ? (array_sum($last) / count($last)) - (array_sum($prev) / count($prev)) : null;
        $likes = 0; foreach ($videos as $v) $likes += max(0, $v['likes1'] - $v['likes0']);

        // what the game really did with "share" / "subscribed" / chat revives
        $gq = db()->prepare('SELECT ts, kind, author, country, result FROM gifts WHERE stream_id = ? AND ts >= ? ORDER BY id');
        $gq->execute([$s['id'], $since]);
        $giftRows = $gq->fetchAll();
        $gifts = ['revive' => 0, 'flag' => 0, 'other' => []];
        foreach ($giftRows as $g) {
            if ($g['result'] === 'revive' || $g['result'] === 'flag') $gifts[$g['result']]++;
            else $gifts['other'][$g['result']] = ($gifts['other'][$g['result']] ?? 0) + 1;
        }
        $askShare = 0; $askSub = 0;
        $msgs = 0; $subs = 0; $supers = 0; $seenReq = []; $authors = []; $countries = []; $scripts = []; $words = []; $requests = [];
        foreach ($chat as $c) {
            if ($c['kind'] === 'sub') { $subs++; continue; }
            if ($c['kind'] === 'super') $supers++;
            $msgs++;
            $a = mb_strtolower($c['author']); $authors[$a] = ($authors[$a] ?? 0) + 1;
            $t = (string) $c['text'];
            if (preg_match('/\bshar(e|ed|ing)\b|শেয়ার|শেয়ার|शेयर/iu', $t)) $askShare++;
            if (preg_match('/\bsubscribed\b|\bsub(bed|\s*done)\b|subscribe\s*(done|korsi|korlam|korechi|dilam)|সাবস্ক্রাইব|সাবস্ক্রাইব/iu', $t)) $askSub++;
            if ($cc = insight_country_of($t)) $countries[$cc] = ($countries[$cc] ?? 0) + 1;
            $sc = insight_script($t); $scripts[$sc] = ($scripts[$sc] ?? 0) + 1;
            foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($t)) ?: [] as $wd) {
                if (mb_strlen($wd) < 3 || in_array($wd, INSIGHT_STOP, true) || isset(insight_country_words()[$wd])) continue;
                $words[$wd] = ($words[$wd] ?? 0) + 1;
            }
            $key = mb_strtolower(trim($t));
            $justCountry = insight_country_of($t) && count(preg_split('/\s+/u', trim($t))) <= 2 && !str_contains($t, '?');
            if (insight_is_request($t) && !$justCountry && mb_strlen(trim($t)) > 3 && !isset($seenReq[$key])) { $seenReq[$key] = 1; $requests[] = ['ts' => (int) $c['ts'], 'a' => $c['author'], 't' => mb_substr($t, 0, 140)]; }
        }
        arsort($countries); arsort($scripts); arsort($words); arsort($authors);
        $repeat = count(array_filter($authors, fn ($n) => $n >= 3));
        $runMin = $s['status'] === 'live' && $s['started_at'] ? (int) round((time() - (int) $s['started_at']) / 60) : 0;
        $row = [
            'id' => (int) $s['id'], 'name' => $s['channel_name'] ?: $s['name'], 'title' => $s['yt_title'] ?: $s['name'], 'game' => game_of_url($s['page_url']),
            'live' => $s['status'] === 'live', 'run_min' => $runMin, 'videos' => count($videos), 'video_list' => $videos,
            'now' => $now, 'avg' => round($avg, 1), 'peak' => $peak, 'trend' => $trend === null ? null : round($trend, 1), 'mins' => $mins,
            'likes' => $likes, 'msgs' => $msgs, 'chatters' => count($authors), 'repeat' => $repeat, 'subs' => $subs, 'supers' => $supers,
            'countries' => array_slice($countries, 0, 10, true), 'scripts' => $scripts, 'words' => array_slice($words, 0, 15, true),
            'requests' => array_slice(array_reverse($requests), 0, 15), 'spark' => array_slice($viewers, -180),
            'channel' => $s['yt_channel'] ?: $s['channel_url'],
            'ask_share' => $askShare, 'ask_sub' => $askSub, 'gifts' => $gifts, 'gift_list' => array_slice(array_reverse($giftRows), 0, 20),
        ];
        $out['streams'][] = $row;
        if ($row['live'] && $row['channel'] !== '') $channels[$row['channel']][] = $row['name'];
    }

    // Why is it not growing? Plain rules from what we measured.
    $tips = [];
    foreach ($out['streams'] as $r) {
        $n = $r['name'] . ' (' . $r['game'] . ')';
        if ($r['videos'] >= 3) $tips[] = ['bad', "{$n}: {$r['videos']} different live videos in {$hours} h. Every Start/Restart opens a NEW live video at 0 viewers — YouTube recommends lives that run long. Avoid restarts; updates now arrive by Deploy alone."];
        if ($r['live'] && $r['run_min'] > 0 && $r['run_min'] < 120) $tips[] = ['warn', "{$n}: this live started only {$r['run_min']} min ago. Lives usually grow after 2–3 hours of running without a break."];
        if ($r['mins'] >= 30 && $r['avg'] > 0) {
            $chatPerViewerHour = $r['msgs'] / max(1, $r['avg']) / max(1, $r['mins'] / 60);
            if ($chatPerViewerHour < 0.5) $tips[] = ['warn', "{$n}: few comments for the viewers (" . round($chatPerViewerHour, 2) . " per viewer per hour). Comments push a live up in YouTube — a clearer \"type your country\" hook on screen and in the title helps."];
            $likeRate = $r['likes'] / max(1, $r['avg']);
            if ($likeRate < 0.05 && $r['mins'] >= 60) $tips[] = ['warn', "{$n}: very few new likes ({$r['likes']}) for {$r['avg']} average viewers. Ask for likes with a goal on screen (\"every 20 likes = …\")."];
        }
        if ($r['trend'] !== null && $r['trend'] < -2) $tips[] = ['warn', "{$n}: viewers fell by about " . abs($r['trend']) . " in the last hour. Look at what happened on screen (long round, nothing changing, a stuck screen)."];
        if ($r['trend'] !== null && $r['trend'] > 2) $tips[] = ['good', "{$n}: viewers grew by about {$r['trend']} in the last hour — keep it running."];
        if ($r['chatters'] >= 5 && $r['repeat'] / max(1, $r['chatters']) < 0.2) $tips[] = ['warn', "{$n}: most chatters write once and leave ({$r['repeat']} of {$r['chatters']} wrote 3+ times). Rewards for repeated chats (balls, revives, shields) keep them."];
        if ($r['mins'] >= 30 && $r['peak'] > 0 && $r['avg'] < $r['peak'] * 0.35) $tips[] = ['warn', "{$n}: a short peak of {$r['peak']} but the average is only {$r['avg']}. People came (maybe from a notification) but did not stay — the first 30 seconds of the screen matter most."];
    }
    foreach ($channels as $ch => $names) {
        if (count($names) > 1) $tips[] = ['bad', 'These lives run on the SAME YouTube channel at once: ' . implode(', ', $names) . '. They split the same audience and YouTube usually pushes only one of them.'];
    }
    $best = [];
    foreach ($out['byHour'] as $h => [$sum, $n]) if ($n >= 10) $best[$h] = $sum / $n;
    arsort($best);
    if (count($best) >= 4) {
        $top = array_slice(array_keys($best), 0, 3);
        $tips[] = ['info', 'Most viewers (Bangladesh time) come around: ' . implode(', ', array_map(fn ($h) => date('g A', mktime($h, 0)), $top)) . '. Have your best live running then.'];
    }
    if (!$out['streams']) $tips[] = ['info', 'No live data yet. Viewers and likes are saved once a minute while a live runs with its YouTube channel connected.'];
    $out['tips'] = $tips;
    return $out;
}

/** The same in plain text, to paste to Claude (or anyone helping). */
function insights_report(array $in): string
{
    $L = ['FFTEdit live audience report — last ' . $in['hours'] . ' hours — ' . date('Y-m-d H:i') . ' (Bangladesh time)', ''];
    foreach ($in['streams'] as $r) {
        $L[] = "== {$r['name']} · game: {$r['game']} · " . ($r['live'] ? "LIVE for {$r['run_min']} min" : 'not live now');
        $L[] = "Title: {$r['title']}";
        $L[] = "Viewers: now {$r['now']}, average {$r['avg']}, peak {$r['peak']}" . ($r['trend'] !== null ? ", last hour change " . ($r['trend'] >= 0 ? '+' : '') . $r['trend'] : '') . " · measured {$r['mins']} min";
        $L[] = "Live videos in this time: {$r['videos']} (each Start/Restart = a new video)";
        foreach ($r['video_list'] as $vid => $v) {
            $L[] = "  - video {$vid}: " . date('H:i', $v['first']) . '–' . date('H:i', $v['last']) . ", peak {$v['peak']}, avg " . ($v['n'] ? round($v['sum'] / $v['n'], 1) : 0) . ', +' . max(0, $v['likes1'] - $v['likes0']) . ' likes';
        }
        $L[] = "Chat: {$r['msgs']} messages from {$r['chatters']} people ({$r['repeat']} wrote 3+ times) · new subscribers seen {$r['subs']} · Super Chats {$r['supers']} · new likes {$r['likes']}";
        if ($r['ask_share'] || $r['ask_sub'] || $r['gift_list']) {
            $o = implode(', ', array_map(fn ($k, $n) => "{$k} {$n}", array_keys($r['gifts']['other']), $r['gifts']['other']));
            $L[] = "Share/subscribe in chat: {$r['ask_share']} share messages, {$r['ask_sub']} subscribed messages → game gave {$r['gifts']['revive']} revives + {$r['gifts']['flag']} extra flags" . ($o ? " (no gift: {$o})" : '');
            foreach (array_slice($r['gift_list'], 0, 10) as $g) $L[] = '  - ' . date('H:i', (int) $g['ts']) . " {$g['author']} · {$g['kind']} · " . strtoupper($g['country']) . " → {$g['result']}";
        }
        if ($r['countries']) $L[] = 'Countries typed: ' . implode(', ', array_map(fn ($c, $n) => strtoupper($c) . " {$n}", array_keys($r['countries']), $r['countries']));
        if ($r['scripts']) $L[] = 'Writing: ' . implode(', ', array_map(fn ($c, $n) => "{$c} {$n}", array_keys($r['scripts']), $r['scripts']));
        if ($r['words']) $L[] = 'Common words: ' . implode(', ', array_map(fn ($c, $n) => "{$c} ({$n})", array_keys($r['words']), $r['words']));
        if ($r['requests']) {
            $L[] = 'Questions / wishes from viewers:';
            foreach ($r['requests'] as $q) $L[] = '  - ' . date('H:i', $q['ts']) . ' ' . $q['a'] . ': ' . $q['t'];
        }
        $L[] = '';
    }
    $vr = ytv_results(time() - 14 * 86400);
    if ($vr) {
        $L[] = '== Title / thumbnail versions (last 14 days; avg viewers, peak, new likes, minutes measured)';
        foreach ($vr as $sid => $list) {
            $nm = (string) (db()->query('SELECT COALESCE(NULLIF(channel_name, \'\'), name) FROM streams WHERE id = ' . (int) $sid)->fetchColumn());
            $L[] = "-- {$nm}";
            foreach (array_slice($list, -12) as $v) $L[] = "  {$v['letter']}" . ($v['best'] ? ' (BEST)' : '') . ' ' . date('m-d H:i', $v['ts']) . " · avg {$v['avg']}, peak {$v['peak']}, +{$v['likes']} likes, {$v['mins']} min · \"{$v['title']}\" · first line: {$v['hook']}";
        }
        $L[] = '';
    }
    $u = ai_usage(); $ac = ai_cfg();
    $L[] = '== AI answers today: ' . ($ac['on'] ? 'ON' : 'OFF') . ', key ' . (ai_key() !== '' ? 'saved' : 'NOT set') . ", provider {$ac['provider']} " . ai_model($ac)
        . " · asked {$u['asked']}, answered {$u['calls']}, failed {$u['fail']}" . ($u['last_error'] !== '' ? " (last error: {$u['last_error']})" : '')
        . ($u['skip'] ? ' · not answered: ' . implode(', ', array_map(fn ($k, $n) => "{$k} {$n}", array_keys($u['skip']), $u['skip'])) : '')
        . ' · cost about $' . number_format((float) $u['usd'], 3);
    $L[] = '';
    $L[] = '== What the panel thinks';
    foreach ($in['tips'] as [$k, $t]) $L[] = '- [' . strtoupper($k) . '] ' . $t;
    return implode("\n", $L);
}
