<?php
/**
 * Robot host voice: the game page asks for a spoken line, the VPS worker makes it with Piper
 * (offline AI voice), the panel keeps the mp3 (same text = same file, made once).
 */
declare(strict_types=1);

/** Voices the host can use (free Piper voices made on the VPS). key => [label, description] */
function robot_voices(): array
{
    return [
        'lessac'  => ['Lessac', 'Woman · American · soft and warm'],
        'amy'     => ['Amy', 'Woman · American · bright and lively'],
        'kristin' => ['Kristin', 'Woman · American · clear and friendly'],
        'hfc'     => ['Hannah', 'Woman · American · young and cheerful'],
        'jenny'   => ['Jenny', 'Woman · British · sweet and calm'],
        'cori'    => ['Cori', 'Woman · British · gentle'],
        'lj'        => ['Linda (HD)', 'NEW · Woman · American · clear, natural, like a news reader'],
        'lessac_hd' => ['Lessac (HD)', 'NEW · Woman · American · soft and warm, better quality'],
        'cori_hd'   => ['Cori (HD)', 'NEW · Woman · British · gentle, better quality'],
        'alba'      => ['Alba', 'NEW · Woman · Scottish · friendly'],
        'southern'  => ['Sophie', 'NEW · Woman · British · calm'],
        'ryan'    => ['Ryan', 'Man · American (the robot voice until now)'],
    ];
}

function robot_voice(): string
{
    $v = (string) (chat_cfg()['host_voice'] ?? 'lessac');
    return isset(robot_voices()[$v]) ? $v : 'lessac';
}

/** Only what the voice can read: Latin letters, digits and simple punctuation. */
function robot_text(string $t): string
{
    $t = preg_replace("/[^A-Za-z0-9 .,!?'’:;&\-]/u", ' ', $t) ?? '';
    $t = trim(preg_replace('/\s+/', ' ', $t) ?? '');
    return mb_substr($t, 0, 220);
}

function robot_hash(string $t, string $voice): string
{
    return substr(hash('sha256', 'robot2|' . $voice . '|' . $t), 0, 24);
}

function robot_file(string $h): string
{
    return $h . '.mp3';
}

/** Ask for a line. Returns ['h' => hash, 'url' => '' while it is being made]. */
/** keep = an announcer line used again every round (never cleaned away). */
function robot_request(string $text, ?string $voice = null, bool $keep = false): array
{
    $voice = $voice !== null && isset(robot_voices()[$voice]) ? $voice : robot_voice();
    $t = robot_text($text);
    if (mb_strlen($t) < 2) return ['h' => '', 'url' => ''];
    $h = robot_hash($t, $voice);
    if ($keep) db()->prepare('UPDATE robot_tts SET keep = 1 WHERE h = ? AND keep = 0')->execute([$h]);
    if (is_file(upload_dir() . '/' . robot_file($h))) return ['h' => $h, 'url' => upload_url(robot_file($h))];
    db()->prepare("INSERT OR IGNORE INTO robot_tts (h, text, voice, status, created, keep) VALUES (?, ?, ?, 'wait', ?, ?)")->execute([$h, $t, $voice, time(), $keep ? 1 : 0]);
    // a line that failed earlier may be asked again
    db()->prepare("UPDATE robot_tts SET status = 'wait', created = ? WHERE h = ? AND status = 'fail'")->execute([time(), $h]);
    return ['h' => $h, 'url' => ''];
}

function robot_status(string $h): array
{
    if (!preg_match('/^[a-f0-9]{24}$/', $h)) return ['h' => '', 'url' => '', 'failed' => true];
    if (is_file(upload_dir() . '/' . robot_file($h))) return ['h' => $h, 'url' => upload_url(robot_file($h))];
    $st = db()->prepare('SELECT status FROM robot_tts WHERE h = ?');
    $st->execute([$h]);
    $s = $st->fetchColumn();
    return ['h' => $h, 'url' => '', 'failed' => $s === false || $s === 'fail'];
}

/** Lines for the worker to make now (the newest first; old ones are given up). */
function robot_jobs(): array
{
    $now = time();
    db()->prepare("UPDATE robot_tts SET status = 'fail' WHERE status IN ('wait', 'sent') AND created < ?")->execute([$now - 90]);
    $st = db()->prepare("SELECT h, text, voice FROM robot_tts WHERE status = 'wait' OR (status = 'sent' AND sent_at < ?) ORDER BY created DESC LIMIT 4");
    $st->execute([$now - 15]);
    $jobs = $st->fetchAll();
    $mark = db()->prepare("UPDATE robot_tts SET status = 'sent', sent_at = ? WHERE h = ?");
    foreach ($jobs as $j) $mark->execute([$now, $j['h']]);
    return array_map(fn ($j) => ['h' => $j['h'], 'text' => $j['text'], 'voice' => $j['voice']], $jobs);
}

function robot_save(string $h, string $mp3): void
{
    if (!preg_match('/^[a-f0-9]{24}$/', $h) || strlen($mp3) < 200 || strlen($mp3) > 1024 * 1024) return;
    $isMp3 = substr($mp3, 0, 3) === 'ID3' || (ord($mp3[0]) === 0xFF && (ord($mp3[1]) & 0xE0) === 0xE0);
    $st = db()->prepare('SELECT 1 FROM robot_tts WHERE h = ?');
    $st->execute([$h]);
    if (!$isMp3 || !$st->fetchColumn()) return;
    file_put_contents(upload_dir() . '/' . robot_file($h), $mp3);
    db()->prepare("UPDATE robot_tts SET status = 'done', created = ? WHERE h = ?")->execute([time(), $h]);

    // keep the newest 600 lines
    if (random_int(1, 25) === 1) {
        $old = db()->query("SELECT h FROM robot_tts WHERE keep = 0 ORDER BY created DESC LIMIT -1 OFFSET 600")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($old as $o) {
            @unlink(upload_dir() . '/' . robot_file($o));
        }
        db()->exec("DELETE FROM robot_tts WHERE keep = 0 AND h NOT IN (SELECT h FROM robot_tts WHERE keep = 0 ORDER BY created DESC LIMIT 600)");
    }
}
