<?php
/**
 * Robot host voice: the game page asks for a spoken line, the VPS worker makes it with Piper
 * (offline AI voice), the panel keeps the mp3 (same text = same file, made once).
 */
declare(strict_types=1);

/** Only what the voice can read: Latin letters, digits and simple punctuation. */
function robot_text(string $t): string
{
    $t = preg_replace("/[^A-Za-z0-9 .,!?'’:;&\-]/u", ' ', $t) ?? '';
    $t = trim(preg_replace('/\s+/', ' ', $t) ?? '');
    return mb_substr($t, 0, 220);
}

function robot_hash(string $t): string
{
    return substr(hash('sha256', 'robot1|' . $t), 0, 24);
}

function robot_file(string $h): string
{
    return $h . '.mp3';
}

/** Ask for a line. Returns ['h' => hash, 'url' => '' while it is being made]. */
function robot_request(string $text): array
{
    $t = robot_text($text);
    if (mb_strlen($t) < 2) return ['h' => '', 'url' => ''];
    $h = robot_hash($t);
    if (is_file(upload_dir() . '/' . robot_file($h))) return ['h' => $h, 'url' => upload_url(robot_file($h))];
    db()->prepare("INSERT OR IGNORE INTO robot_tts (h, text, status, created) VALUES (?, ?, 'wait', ?)")->execute([$h, $t, time()]);
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
    db()->prepare("UPDATE robot_tts SET status = 'fail' WHERE status IN ('wait', 'sent') AND created < ?")->execute([$now - 40]);
    $st = db()->prepare("SELECT h, text FROM robot_tts WHERE status = 'wait' OR (status = 'sent' AND sent_at < ?) ORDER BY created DESC LIMIT 4");
    $st->execute([$now - 15]);
    $jobs = $st->fetchAll();
    $mark = db()->prepare("UPDATE robot_tts SET status = 'sent', sent_at = ? WHERE h = ?");
    foreach ($jobs as $j) $mark->execute([$now, $j['h']]);
    return array_map(fn ($j) => ['h' => $j['h'], 'text' => $j['text']], $jobs);
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
        $old = db()->query("SELECT h FROM robot_tts ORDER BY created DESC LIMIT -1 OFFSET 600")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($old as $o) {
            @unlink(upload_dir() . '/' . robot_file($o));
        }
        db()->exec("DELETE FROM robot_tts WHERE h NOT IN (SELECT h FROM robot_tts ORDER BY created DESC LIMIT 600)");
    }
}
