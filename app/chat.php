<?php
/**
 * Live chat on the stream: the VPS worker reads the YouTube live chat and sends it here;
 * the game page (running in the stream browser) asks /api/chat.php for new messages and shows
 * them (chat box, names on flags, top fans, robot host). New subscribers come from app/youtube.php.
 */
declare(strict_types=1);

function chat_defaults(): array
{
    return [
        'enabled' => true,          // read the live chat
        'robot'   => true,          // robot host answers on screen
        'voice'   => true,          // robot speaks out loud (replaces the old commentator voice)
        'robot_name' => 'LUNA',
        'host_voice' => 'jenny',    // see robot_voices()
        'look'       => 'girl',     // girl (LUNA) or robot
        'box'     => true,          // show the chat box
        'subs'    => true,          // new subscriber banner
        'idle'    => true,          // robot asks people to comment when the chat is quiet
    ];
}

function chat_cfg(): array
{
    static $c = null;
    if ($c === null) {
        $saved = json_decode((string) (setting_get('chat_cfg') ?? ''), true);
        $c = array_merge(chat_defaults(), is_array($saved) ? $saved : []);
        // one time: switch to the girl host LUNA with the Jenny voice (chosen by the owner)
        if (setting_get('mig_host_luna') === null) {
            $c['host_voice'] = 'jenny';
            $c['look'] = 'girl';
            if (in_array(strtoupper((string) $c['robot_name']), ['ROBO HOST', 'ROBO TASIN', ''], true)) $c['robot_name'] = 'LUNA';
            setting_set('chat_cfg', json_encode($c));
            setting_set('mig_host_luna', '1');
        }
    }
    return $c;
}

function chat_save(array $c): void
{
    setting_set('chat_cfg', json_encode(array_intersect_key(array_merge(chat_defaults(), $c), chat_defaults())));
}

/** Clean text from YouTube (shown on stream with textContent, never as HTML). */
function chat_clean(string $s, int $max): string
{
    $s = preg_replace('/[\x00-\x1F\x7F\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', ' ', $s) ?? '';
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    return mb_substr($s, 0, $max);
}

function chat_add(int $sid, array $it): void
{
    $kind = in_array($it['k'] ?? '', ['chat', 'super', 'member', 'sub'], true) ? $it['k'] : 'chat';
    $author = ltrim(chat_clean((string) ($it['a'] ?? ''), 40), '@');
    if ($author === '') return;
    $msgId = substr(preg_replace('/[^A-Za-z0-9_:.%=-]/', '', (string) ($it['id'] ?? '')), 0, 120);
    if ($msgId === '') $msgId = 'x:' . md5($author . ($it['t'] ?? '') . microtime());
    db()->prepare('INSERT OR IGNORE INTO chat_events (stream_id, ts, kind, msg_id, author, text, amount) VALUES (?,?,?,?,?,?,?)')
        ->execute([$sid, time(), $kind, $msgId, $author, chat_clean((string) ($it['t'] ?? ''), 200), chat_clean((string) ($it['amt'] ?? ''), 20)]);
}

/** Messages the worker read since the last check-in. */
function chat_ingest(array $mine, array $batches): void
{
    foreach ($batches as $b) {
        if (!is_array($b)) continue;
        $sid = (int) ($b['id'] ?? 0);
        if (!isset($mine[$sid])) continue;
        $items = array_slice((array) ($b['items'] ?? []), 0, 200);
        if ($items) {
            db()->beginTransaction();
            foreach ($items as $it) {
                if (is_array($it)) chat_add($sid, $it);
            }
            db()->commit();
        }
        $n = count($items);
        db()->prepare('UPDATE streams SET chat_status = ?, chat_count = chat_count + ? WHERE id = ?')
            ->execute([chat_clean((string) ($b['status'] ?? ''), 160), $n, $sid]);
        if ($n > 0) {
            db()->prepare('UPDATE streams SET chat_seen = ? WHERE id = ?')->execute([time(), $sid]);
        }
    }
    // keep the table small: last 400 events per stream, nothing older than a day
    if (random_int(1, 20) === 1) {
        db()->exec('DELETE FROM chat_events WHERE ts < ' . (time() - 86400));
        foreach (db()->query('SELECT DISTINCT stream_id FROM chat_events')->fetchAll(PDO::FETCH_COLUMN) as $sid) {
            db()->exec('DELETE FROM chat_events WHERE stream_id = ' . (int) $sid . ' AND id NOT IN
                        (SELECT id FROM chat_events WHERE stream_id = ' . (int) $sid . ' ORDER BY id DESC LIMIT 400)');
        }
    }
}

/** What the worker needs to read this stream's chat (added to its run list; does not restart the stream). */
function chat_target(array $row): ?array
{
    if (!chat_cfg()['enabled']) return null;
    $started = (int) ($row['started_at'] ?? 0);
    $video = ($row['yt_video'] ?? '') !== '' && $started > 0 && (int) ($row['yt_video_start'] ?? 0) === $started ? $row['yt_video'] : '';
    $channel = (string) ($row['channel_url'] ?? '');
    if ($video === '' && $channel === '') return null;
    // with several streams on one channel the channel's /live page could show the wrong one, so wait for the video
    if ($video === '' && ($row['yt_refresh'] ?? '') !== '') return null;
    return ['video' => $video, 'channel' => $channel];
}

/** New events for the game page. First call (after = 0): the last few messages, marked old. */
function chat_feed(int $sid, int $after): array
{
    $cfg = chat_cfg();
    $out = ['cfg' => [
        'enabled' => $cfg['enabled'], 'robot' => $cfg['robot'], 'voice' => $cfg['robot'] && $cfg['voice'], 'name' => $cfg['robot_name'], 'look' => $cfg['look'],
        'box' => $cfg['box'], 'subs' => $cfg['subs'], 'idle' => $cfg['idle'],
    ], 'events' => [], 'last' => $after];
    if (!$cfg['enabled']) return $out;
    if ($after <= 0) {
        $st = db()->prepare('SELECT * FROM chat_events WHERE stream_id = ? AND kind = \'chat\' ORDER BY id DESC LIMIT 4');
        $st->execute([$sid]);
        $rows = array_reverse($st->fetchAll());
        $old = true;
        $last = (int) db()->query('SELECT COALESCE(MAX(id), 0) FROM chat_events WHERE stream_id = ' . $sid)->fetchColumn();
    } else {
        $st = db()->prepare('SELECT * FROM chat_events WHERE stream_id = ? AND id > ? ORDER BY id LIMIT 40');
        $st->execute([$sid, $after]);
        $rows = $st->fetchAll();
        $old = false;
        $last = $rows ? (int) end($rows)['id'] : $after;
    }
    foreach ($rows as $r) {
        $out['events'][] = ['id' => (int) $r['id'], 'k' => $r['kind'], 'a' => $r['author'], 't' => $r['text'],
                            'amt' => $r['amount'], 'old' => $old || (int) $r['ts'] < time() - 90];
    }
    $out['last'] = $last;
    return $out;
}
