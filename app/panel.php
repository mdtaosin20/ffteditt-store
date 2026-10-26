<?php
/**
 * Dashboard helpers: panel options, VPS metrics, bandwidth, schedules, games list, alerts.
 */
declare(strict_types=1);

const CPU_ALERT_PCT = 85;

/* ---------------------------------------------------------------- options */

function panel_defaults(): array
{
    return [
        'auto_start'   => true,    // streams start again by themselves after the VPS reboots
        'auto_stop'    => true,    // schedules stop streams at their end time
        'notify'       => true,    // bell alerts
        'provider'     => 'InterServer',
        'location'     => '',
        'plan'         => '',
        'bw_limit_gb'  => 2000,
        'upgrade_url'  => 'https://www.interserver.net/vps/',
        'alerts_seen'  => 0,       // last activity id the admin has seen in the bell
        'cpu_alert_at' => 0,
    ];
}

function panel_opts(): array
{
    $d = panel_defaults();
    $c = json_decode(setting_get('panel') ?? '', true);
    return is_array($c) ? array_merge($d, array_intersect_key($c, $d)) : $d;
}

function panel_save(array $o): void
{
    setting_set('panel', json_encode(array_merge(panel_opts(), $o)));
}

/* ---------------------------------------------------------------- games */

/** The games this site has. Each stream plays the game whose page it opens. */
function games_list(): array
{
    $ball  = game_config();
    $flags = flags_config();
    $war   = war_config();
    $tug   = tug_config();
    $kp    = keeper_config();
    $race  = race_config();
    $lf    = lastflag_config();
    return [
        'ball'  => ['id' => 1, 'key' => 'ball',  'name' => 'Ball Drop',      'url' => '/ball/',  'settings' => 'game.php',
                    'title' => ucwords(strtolower($ball['players'][0]['name'])) . ' vs ' . ucwords(strtolower($ball['players'][1]['name'])), 'category' => 'football',
                    'tags' => ['football', 'battle', 'arcade'], 'thumb' => '/assets/site/ball.jpg',
                    'desc' => 'Two balls drop into the bowl — first into the hole scores. ' . ball_rules_text($ball),
                    'autoplay' => (bool) $ball['autoplay'],  'pace' => (string) $ball['pace'],
                    'detail' => $ball['players'][0]['name'] . ' vs ' . $ball['players'][1]['name']],
        'flags' => ['id' => 2, 'key' => 'flags', 'name' => 'Flag Arena', 'url' => '/flags/', 'settings' => 'flags.php',
                    'title' => 'Flag Arena', 'category' => 'countries',
                    'tags' => ['countries', 'battle', 'chat'], 'thumb' => '/assets/site/flags.jpg',
                    'desc' => 'Flags bounce in the arena and the black hole draws them one by one. Chat to shield or revive your country!',
                    'autoplay' => (bool) $flags['autoplay'], 'pace' => (string) $flags['pace'],
                    'detail' => (int) $flags['count'] . ' countries · hole every ' . (int) $flags['gap'] . ' s'],
        'war'   => ['id' => 3, 'key' => 'war', 'name' => 'Territory War', 'url' => '/war/', 'settings' => 'war.php',
                    'title' => 'Territory War', 'category' => 'countries',
                    'tags' => ['countries', 'battle', 'strategy'], 'thumb' => '/assets/site/war.jpg',
                    'desc' => 'Flag balls conquer the map square by square. The last country standing takes the world!',
                    'autoplay' => (bool) $war['autoplay'], 'pace' => (string) $war['pace'],
                    'detail' => count($war['teams']) . ' countries · map ' . (int) $war['grid']],
        'tug'   => ['id' => 4, 'key' => 'tug', 'name' => 'Tug of War', 'url' => '/tug/', 'settings' => 'tug.php',
                    'title' => 'Country Tug of War', 'category' => 'countries',
                    'tags' => ['countries', 'battle', 'chat'], 'thumb' => '/assets/site/tug.jpg',
                    'desc' => 'Two countries, one rope. Every comment is one pull — the country with more fans wins!',
                    'autoplay' => (bool) $tug['autoplay'], 'pace' => (string) $tug['pace'],
                    'detail' => count($tug['pairs']) . ' pairs · ' . intdiv((int) $tug['round'], 60) . ':' . str_pad((string) ((int) $tug['round'] % 60), 2, '0', STR_PAD_LEFT) . ' rounds'],
        'keeper' => ['id' => 5, 'key' => 'keeper', 'name' => 'Beat The Keeper', 'url' => '/keeper/', 'settings' => 'keeper.php',
                    'title' => 'Beat The Keeper · World Cup', 'category' => 'football',
                    'tags' => ['football', 'countries', 'chat'], 'thumb' => '/assets/site/keeper.jpg',
                    'desc' => 'Flag balls shoot at the goal and the keeper moves to stop them. A full World Cup: groups, quarterfinals, semifinals and the final!',
                    'autoplay' => (bool) $kp['autoplay'], 'pace' => (string) $kp['pace'],
                    'detail' => count($kp['teams']) . ' countries · first to ' . (int) $kp['goals'] . ' goals'],
        'reactor' => ['id' => 6, 'key' => 'reactor', 'name' => 'Country Reactor', 'url' => '/reactor/', 'settings' => 'reactor.php',
                    'title' => 'Country Reactor · World Energy League', 'category' => 'countries',
                    'tags' => ['countries', 'strategy', 'chat'], 'thumb' => '/assets/site/war.jpg',
                    'desc' => 'A new strategy arena: four countries power up reactor cores, deploy drones and defend their energy systems.',
                    'autoplay' => true, 'pace' => 'normal', 'detail' => '4 countries · energy reactor strategy'],
        'cupkick' => ['id' => 8, 'key' => 'cupkick', 'name' => 'Cup Kick', 'url' => '/cupkick/', 'settings' => '/cupkick/',
                    'title' => 'Cup Kick · Which Country Touches the Cup?', 'category' => 'football',
                    'tags' => ['football', 'countries', 'arcade'], 'thumb' => '/assets/site/cupkick.jpg',
                    'desc' => 'Six countries kick their balls at the cup in a 3D stadium — only one touches it. Can you see whose ball it was?',
                    'autoplay' => true, 'pace' => 'normal', 'detail' => '3D · 6 countries · for short videos (not for 24/7 lives)'],
        'race'  => ['id' => 7, 'key' => 'race', 'name' => 'Flag Race', 'url' => '/race/', 'settings' => 'race.php',
                    'title' => 'Flag Race', 'category' => 'countries',
                    'tags' => ['countries', 'race', 'chat'], 'thumb' => '/assets/site/race.jpg',
                    'desc' => 'Country flags race to the finish line — every chat is a boost. A new race every minute!',
                    'autoplay' => (bool) $race['autoplay'], 'pace' => (string) $race['pace'],
                    'detail' => (int) $race['racers'] . ' racers · ~' . (int) $race['secs'] . ' s races'],
        'lastflag' => ['id' => 9, 'key' => 'lastflag', 'name' => 'Last Flag Standing', 'url' => '/lastflag/', 'settings' => 'lastflag.php',
                    'title' => 'Last Flag Standing', 'category' => 'countries',
                    'tags' => ['countries', 'battle', 'chat'], 'thumb' => '/assets/site/lastflag.jpg',
                    'desc' => 'A neon arena with a turning bearing: the black hole pulls the flags in one by one. Chat to shield or revive your country — the last flag standing wins!',
                    'autoplay' => (bool) $lf['autoplay'], 'pace' => (string) $lf['pace'],
                    'detail' => (int) $lf['count'] . ' countries · hole every ' . (int) $lf['gap'] . ' s'],
    ];
}

/** "2-minute rounds, best of 3!" — or the no-timer text. */
function ball_rules_text(array $b): string
{
    if (empty($b['timer_on'])) {
        return 'Goals keep counting — pick your side!';
    }
    $t = (int) $b['round_secs'];
    $len = $t % 60 ? $t . '-second' : ($t % 3600 ? intdiv($t, 60) . '-minute' : intdiv($t, 3600) . '-hour');
    return $len . ' rounds' . ((int) $b['rounds'] > 1 ? ', best of ' . (int) $b['rounds'] : '') . '!';
}

function game_of_url(string $url): string
{
    $path = (string) parse_url($url, PHP_URL_PATH) . '?';
    if (preg_match('#/war/?(\?|$)#', $path)) return 'war';
    if (preg_match('#/tug/?(\?|$)#', $path)) return 'tug';
    if (preg_match('#/keeper/?(\?|$)#', $path)) return 'keeper';
    if (preg_match('#/reactor/?(\?|$)#', $path)) return 'reactor';
    if (preg_match('#/race/?(\?|$)#', $path)) return 'race';
    if (preg_match('#/lastflag/?(\?|$)#', $path)) return 'lastflag';
    if (preg_match('#/cupkick/?(\?|$)#', $path)) return 'cupkick';
    return preg_match('#/flags/?(\?|$)#', $path) ? 'flags' : 'ball';
}

function paces(): array
{
    return ['slow' => 'Slow', 'normal' => 'Normal', 'fast' => 'Fast'];
}

/* ---------------------------------------------------------------- metrics */

function server_info(array $server): array
{
    $i = json_decode($server['info_json'] ?: '{}', true);
    return is_array($i) ? $i : [];
}

/** Called by the worker API on every check-in. */
function record_metrics(int $sid, array $prevInfo, array $info): void
{
    $now = time();
    $st = db()->prepare('SELECT MAX(ts) FROM metrics WHERE server_id = ?');
    $st->execute([$sid]);
    if ((int) $st->fetchColumn() <= $now - 5) {
        db()->prepare('INSERT INTO metrics (server_id, ts, cpu, ram, disk, up_kbps, down_kbps) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$sid, $now, $info['cpu_pct'], pct($info['mem_used_mb'], $info['mem_total_mb']),
                       pct($info['disk_used_gb'], $info['disk_total_gb']), $info['up_kbps'], $info['down_kbps']]);
        if (random_int(1, 20) === 1) {
            db()->prepare('DELETE FROM metrics WHERE server_id = ? AND ts < ?')->execute([$sid, $now - 3 * 3600]);
        }
    }

    // monthly bandwidth from the network counters (they reset when the VPS reboots)
    $new = (int) $info['rx_bytes'] + (int) $info['tx_bytes'];
    $old = (int) ($prevInfo['rx_bytes'] ?? 0) + (int) ($prevInfo['tx_bytes'] ?? 0);
    if ($new > 0 && $old > 0) {
        $delta = $new >= $old ? $new - $old : $new;
        if ($delta > 0 && $delta < 50 * 1024 ** 3) {
            db()->prepare('INSERT INTO bandwidth (server_id, month, bytes) VALUES (?, ?, ?)
                           ON CONFLICT(server_id, month) DO UPDATE SET bytes = bytes + excluded.bytes')
                ->execute([$sid, date('Y-m'), $delta]);
        }
    }

    // high CPU warning, at most every 30 minutes
    if ($info['cpu_pct'] !== null && $info['cpu_pct'] >= CPU_ALERT_PCT) {
        $o = panel_opts();
        if ($o['cpu_alert_at'] < $now - 1800) {
            panel_save(['cpu_alert_at' => $now]);
            log_activity('High CPU usage detected (' . round($info['cpu_pct']) . '%)', 'warn');
        }
    }
}

function pct($a, $b): ?float
{
    return ($b ?? 0) > 0 && $a !== null ? round(100 * (float) $a / (float) $b, 1) : null;
}

function metrics_history(int $sid, int $seconds = 900): array
{
    $st = db()->prepare('SELECT ts, cpu, ram, disk, up_kbps, down_kbps FROM metrics WHERE server_id = ? AND ts >= ? ORDER BY ts');
    $st->execute([$sid, time() - $seconds]);
    $out = ['cpu' => [], 'ram' => [], 'disk' => [], 'up' => [], 'down' => []];
    foreach ($st->fetchAll() as $r) {
        $out['cpu'][]  = (float) $r['cpu'];
        $out['ram'][]  = (float) $r['ram'];
        $out['disk'][] = (float) $r['disk'];
        $out['up'][]   = round((float) $r['up_kbps'] / 1000, 2);
        $out['down'][] = round((float) $r['down_kbps'] / 1000, 2);
    }
    return $out;
}

function bandwidth_month(): array
{
    $st = db()->prepare('SELECT COALESCE(SUM(bytes), 0) FROM bandwidth WHERE month = ?');
    $st->execute([date('Y-m')]);
    $used  = (int) $st->fetchColumn() / 1024 ** 3;
    $limit = max(1, (float) panel_opts()['bw_limit_gb']);
    $day   = (int) date('j');
    $perDay = $used / max(1, $day);
    $daysLeft = $perDay > 0 ? (int) floor(($limit - $used) / $perDay) : null;
    return [
        'used' => round($used, 1), 'limit' => $limit, 'left' => round(max(0, $limit - $used), 1),
        'pct' => min(100, round(100 * $used / $limit)), 'days' => $daysLeft,
    ];
}

/* ---------------------------------------------------------------- schedules */

function days_label(string $days): string
{
    $map = ['1234567' => 'Daily', '123456' => 'Mon-Sat', '12345' => 'Mon-Fri', '67' => 'Sat-Sun'];
    if (isset($map[$days])) {
        return $map[$days];
    }
    $names = [1 => 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    return implode(', ', array_map(fn ($d) => $names[(int) $d], str_split($days)));
}

function schedule_in_window(array $s, int $now): bool
{
    [$sh, $sm] = array_map('intval', explode(':', $s['start_time']));
    [$eh, $em] = array_map('intval', explode(':', $s['end_time']));
    $start = $sh * 60 + $sm;
    $end   = $eh * 60 + $em;
    $t     = (int) date('G', $now) * 60 + (int) date('i', $now);
    $today = date('N', $now);
    $yday  = date('N', $now - 86400);
    $days  = (string) $s['days'];
    if ($start === $end) {
        return str_contains($days, $today);
    }
    if ($start < $end) {
        return str_contains($days, $today) && $t >= $start && $t < $end;
    }
    return (str_contains($days, $today) && $t >= $start) || (str_contains($days, $yday) && $t < $end);
}

/** Starts/stops streams when a schedule's start or end time arrives. Safe to call often. */
function schedule_tick(): void
{
    $now  = time();
    $opts = panel_opts();
    $rows = db()->query('SELECT sc.*, s.name, s.stream_key, s.server_id FROM schedules sc JOIN streams s ON s.id = sc.stream_id WHERE sc.enabled = 1')->fetchAll();
    foreach ($rows as $r) {
        $in = schedule_in_window($r, $now);
        if ($in && $r['last_state'] !== 'in') {
            if ($r['stream_key'] !== '' && $r['server_id']) {
                db()->prepare("UPDATE streams SET desired = 'on', error = NULL, updated_at = ?,
                               run_id = CASE WHEN desired = 'off' THEN ? ELSE run_id END WHERE id = ?")->execute([$now, $now, $r['stream_id']]);
                log_activity("Schedule started '{$r['name']}'", 'ok');
            } else {
                log_activity("Schedule could not start '{$r['name']}' (no stream key or server)", 'error');
            }
        } elseif (!$in && $r['last_state'] === 'in' && $opts['auto_stop']) {
            db()->prepare("UPDATE streams SET desired = 'off', updated_at = ? WHERE id = ?")->execute([$now, $r['stream_id']]);
            log_activity("Schedule stopped '{$r['name']}'", 'info');
        }
        if (($in ? 'in' : 'out') !== $r['last_state']) {
            db()->prepare('UPDATE schedules SET last_state = ? WHERE id = ?')->execute([$in ? 'in' : 'out', $r['id']]);
        }
    }
}

/* ---------------------------------------------------------------- alerts */

/** Logs "server offline" once per outage (nothing else notices when a VPS goes quiet). */
function check_offline_servers(): void
{
    $seen = json_decode(setting_get('offline_logged') ?? '', true) ?: [];
    $changed = false;
    foreach (all_servers() as $sid => $srv) {
        $last = (int) $srv['last_seen'];
        if ($last > 0 && !server_online($srv) && ($seen[$sid] ?? 0) !== $last) {
            log_activity("Server offline: {$srv['name']} (no check-in since " . date('H:i', $last) . ')', 'error');
            $seen[$sid] = $last;
            $changed = true;
        }
    }
    if ($changed) {
        setting_set('offline_logged', json_encode($seen));
    }
}

function alerts_unseen(): array
{
    $o = panel_opts();
    if (!$o['notify']) {
        return [];
    }
    $st = db()->prepare("SELECT id, ts, message, level FROM activity WHERE level IN ('warn','error') AND id > ? ORDER BY id DESC LIMIT 20");
    $st->execute([(int) $o['alerts_seen']]);
    return $st->fetchAll();
}

function recent_logs(int $n = 6): array
{
    return db()->query('SELECT id, ts, message, level FROM activity ORDER BY id DESC LIMIT ' . (int) $n)->fetchAll();
}

/* ---------------------------------------------------------------- formatting */

function fmt_uptime(int $s): string
{
    $d = intdiv($s, 86400);
    $h = intdiv($s % 86400, 3600);
    $m = intdiv($s % 3600, 60);
    if ($d > 0) return $d . ' day' . ($d > 1 ? 's' : '') . ', ' . $h . ' hr' . ($h === 1 ? '' : 's');
    if ($h > 0) return $h . ' hour' . ($h === 1 ? '' : 's') . ', ' . $m . ' min';
    return $m . ' min';
}

function hms(int $since): string
{
    $d = max(0, time() - $since);
    return sprintf('%02d:%02d:%02d', intdiv($d, 3600), intdiv($d % 3600, 60), $d % 60);
}

function fmt_mbps(?float $kbps): string
{
    return $kbps === null ? '—' : number_format($kbps / 1000, 1) . ' Mbps';
}

function channel_short(string $url): string
{
    if ($url === '') return '';
    if (preg_match('#youtube\.com/(@[^/?]+)#', $url, $m)) return $m[1];
    if (preg_match('#youtube\.com/channel/([^/?]+)#', $url, $m)) return 'ID: ' . (strlen($m[1]) > 10 ? substr($m[1], 0, 8) . '…' : $m[1]);
    return parse_url($url, PHP_URL_HOST) ?: $url;
}

function watch_url(string $url): string
{
    if ($url === '') return '';
    if (preg_match('#^https://(www\.)?youtube\.com/(@[^/?]+|channel/[^/?]+)/?$#', $url)) {
        return rtrim($url, '/') . '/live';
    }
    return $url;
}
