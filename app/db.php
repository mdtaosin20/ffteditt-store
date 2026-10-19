<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!is_dir(DATA_DIR)) {
        @mkdir(DATA_DIR, 0700, true);
    }
    $file = DATA_DIR . '/ffteditt.sqlite';

    $pdo = new PDO('sqlite:' . $file, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA journal_mode = WAL');

    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admins (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            username   TEXT NOT NULL UNIQUE,
            pass_hash  TEXT NOT NULL,
            created_at INTEGER NOT NULL
        );

        CREATE TABLE IF NOT EXISTS login_attempts (
            ip           TEXT PRIMARY KEY,
            attempts     INTEGER NOT NULL DEFAULT 0,
            locked_until INTEGER NOT NULL DEFAULT 0
        );

        CREATE TABLE IF NOT EXISTS servers (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            name       TEXT NOT NULL,
            token_hash TEXT NOT NULL UNIQUE,
            created_at INTEGER NOT NULL,
            last_seen  INTEGER NOT NULL DEFAULT 0,
            info_json  TEXT NOT NULL DEFAULT '{}'
        );

        CREATE TABLE IF NOT EXISTS streams (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            name         TEXT NOT NULL,
            stream_key   TEXT NOT NULL DEFAULT '',
            rtmp_url     TEXT NOT NULL DEFAULT 'rtmp://a.rtmp.youtube.com/live2',
            page_url     TEXT NOT NULL DEFAULT 'https://ffteditt.store/ball/',
            server_id    INTEGER REFERENCES servers(id) ON DELETE SET NULL,
            quality      TEXT NOT NULL DEFAULT '480p-v',
            fps          INTEGER NOT NULL DEFAULT 20,
            auto_restart INTEGER NOT NULL DEFAULT 1,
            desired      TEXT NOT NULL DEFAULT 'off',
            status       TEXT NOT NULL DEFAULT 'stopped',
            fps_now      REAL,
            speed_now    TEXT,
            started_at   INTEGER,
            error        TEXT,
            rev          INTEGER NOT NULL DEFAULT 1,
            created_at   INTEGER NOT NULL,
            updated_at   INTEGER NOT NULL
        );

        CREATE TABLE IF NOT EXISTS activity (
            id      INTEGER PRIMARY KEY AUTOINCREMENT,
            ts      INTEGER NOT NULL,
            message TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS commands (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            server_id   INTEGER NOT NULL REFERENCES servers(id) ON DELETE CASCADE,
            cmd         TEXT NOT NULL,
            status      TEXT NOT NULL DEFAULT 'queued',
            output      TEXT,
            exit_code   INTEGER,
            duration_ms INTEGER,
            created_at  INTEGER NOT NULL,
            started_at  INTEGER,
            finished_at INTEGER
        );
        CREATE INDEX IF NOT EXISTS idx_commands_server ON commands(server_id, id);

        CREATE TABLE IF NOT EXISTS settings (
            key   TEXT PRIMARY KEY,
            value TEXT NOT NULL
        );
    ");

    // Columns added after the first release.
    $add = function (string $table, string $col, string $def) use ($pdo): void {
        $cols = array_column($pdo->query("PRAGMA table_info({$table})")->fetchAll(), 'name');
        if (!in_array($col, $cols, true)) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$col} {$def}");
        }
    };
    $add('servers', 'terminal_enabled', 'INTEGER NOT NULL DEFAULT 1');
    $add('streams', 'channel_name', "TEXT NOT NULL DEFAULT ''");
    $add('streams', 'channel_url', "TEXT NOT NULL DEFAULT ''");
    $add('activity', 'level', "TEXT NOT NULL DEFAULT 'info'");
    // YouTube connection (title, description, thumbnail) — see app/youtube.php
    $add('streams', 'yt_refresh', "TEXT NOT NULL DEFAULT ''");
    $add('streams', 'yt_channel', "TEXT NOT NULL DEFAULT ''");
    $add('streams', 'yt_title', "TEXT NOT NULL DEFAULT ''");
    $add('streams', 'yt_desc', "TEXT NOT NULL DEFAULT ''");
    $add('streams', 'yt_thumb', "TEXT NOT NULL DEFAULT 'default'");
    $add('streams', 'yt_auto', 'INTEGER NOT NULL DEFAULT 1');
    $add('streams', 'yt_rev', 'INTEGER NOT NULL DEFAULT 1');
    $add('streams', 'yt_done_rev', 'INTEGER NOT NULL DEFAULT 0');
    $add('streams', 'yt_done_start', 'INTEGER NOT NULL DEFAULT 0');
    $add('streams', 'yt_try_at', 'INTEGER NOT NULL DEFAULT 0');
    $add('streams', 'yt_video', "TEXT NOT NULL DEFAULT ''");
    $add('streams', 'yt_msg', "TEXT NOT NULL DEFAULT ''");
    $add('streams', 'yt_msg_ok', 'INTEGER NOT NULL DEFAULT 0');
    $add('streams', 'yt_fails', 'INTEGER NOT NULL DEFAULT 0');
    $add('streams', 'yt_fail_start', 'INTEGER NOT NULL DEFAULT 0');
    $add('streams', 'yt_video_start', 'INTEGER NOT NULL DEFAULT 0');
    $add('streams', 'yt_find_at', 'INTEGER NOT NULL DEFAULT 0');
    // Live chat (read by the VPS worker) — see app/chat.php
    $add('streams', 'chat_status', "TEXT NOT NULL DEFAULT ''");
    $add('streams', 'chat_seen', 'INTEGER NOT NULL DEFAULT 0');
    $add('streams', 'chat_count', 'INTEGER NOT NULL DEFAULT 0');
    $add('streams', 'run_id', 'INTEGER NOT NULL DEFAULT 0');   // new number on every Start = games begin fresh
    if ($pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='robot_tts'")->fetchColumn()) {
        $add('robot_tts', 'voice', "TEXT NOT NULL DEFAULT 'ryan'");
    }

    // The Ball Drop game moved from the home page to /ball/ (the home page is now the games website).
    $done = $pdo->query("SELECT value FROM settings WHERE key = 'mig_ball_path'")->fetchColumn();
    if ($done === false) {
        $up = $pdo->prepare('UPDATE streams SET page_url = ?, rev = rev + 1 WHERE id = ?');
        foreach ($pdo->query('SELECT id, page_url FROM streams')->fetchAll() as $r) {
            $u = parse_url((string) $r['page_url']);
            if (!empty($u['host']) && in_array($u['path'] ?? '/', ['', '/', '/index.html'], true)) {
                $new = ($u['scheme'] ?? 'https') . '://' . $u['host'] . '/ball/' . (isset($u['query']) ? '?' . $u['query'] : '');
                $up->execute([$new, $r['id']]);
            }
        }
        $pdo->exec("INSERT INTO settings (key, value) VALUES ('mig_ball_path', '1')");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS metrics (
            server_id INTEGER NOT NULL REFERENCES servers(id) ON DELETE CASCADE,
            ts        INTEGER NOT NULL,
            cpu       REAL, ram REAL, disk REAL, up_kbps REAL, down_kbps REAL
        );
        CREATE INDEX IF NOT EXISTS idx_metrics ON metrics(server_id, ts);

        CREATE TABLE IF NOT EXISTS bandwidth (
            server_id INTEGER NOT NULL REFERENCES servers(id) ON DELETE CASCADE,
            month     TEXT NOT NULL,
            bytes     INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (server_id, month)
        );

        CREATE TABLE IF NOT EXISTS chat_events (
            id        INTEGER PRIMARY KEY AUTOINCREMENT,
            stream_id INTEGER NOT NULL REFERENCES streams(id) ON DELETE CASCADE,
            ts        INTEGER NOT NULL,
            kind      TEXT NOT NULL DEFAULT 'chat',
            msg_id    TEXT NOT NULL DEFAULT '',
            author    TEXT NOT NULL DEFAULT '',
            text      TEXT NOT NULL DEFAULT '',
            amount    TEXT NOT NULL DEFAULT ''
        );
        CREATE INDEX IF NOT EXISTS idx_chat ON chat_events(stream_id, id);
        CREATE UNIQUE INDEX IF NOT EXISTS idx_chat_msg ON chat_events(stream_id, msg_id);

        CREATE TABLE IF NOT EXISTS robot_tts (
            h       TEXT PRIMARY KEY,
            text    TEXT NOT NULL,
            voice   TEXT NOT NULL DEFAULT 'ryan',
            status  TEXT NOT NULL DEFAULT 'wait',
            created INTEGER NOT NULL,
            sent_at INTEGER NOT NULL DEFAULT 0
        );

        CREATE TABLE IF NOT EXISTS audience (
            stream_id INTEGER NOT NULL,
            ts        INTEGER NOT NULL,
            video     TEXT NOT NULL DEFAULT '',
            viewers   INTEGER NOT NULL DEFAULT 0,
            likes     INTEGER NOT NULL DEFAULT 0
        );
        CREATE INDEX IF NOT EXISTS idx_audience ON audience(stream_id, ts);

        CREATE TABLE IF NOT EXISTS schedules (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            stream_id  INTEGER NOT NULL REFERENCES streams(id) ON DELETE CASCADE,
            start_time TEXT NOT NULL,
            end_time   TEXT NOT NULL,
            days       TEXT NOT NULL DEFAULT '1234567',
            enabled    INTEGER NOT NULL DEFAULT 1,
            last_state TEXT NOT NULL DEFAULT 'out',
            created_at INTEGER NOT NULL
        );

        CREATE TABLE IF NOT EXISTS gifts (
            id        INTEGER PRIMARY KEY AUTOINCREMENT,
            stream_id INTEGER NOT NULL,
            ts        INTEGER NOT NULL,
            kind      TEXT NOT NULL DEFAULT '',
            author    TEXT NOT NULL DEFAULT '',
            country   TEXT NOT NULL DEFAULT '',
            result    TEXT NOT NULL DEFAULT ''
        );
        CREATE INDEX IF NOT EXISTS idx_gifts ON gifts(stream_id, ts);
    ");
}

/** $level: ok (green), info (blue), warn (orange), error (red). */
function log_activity(string $message, string $level = 'info'): void
{
    db()->prepare('INSERT INTO activity (ts, message, level) VALUES (?, ?, ?)')->execute([time(), $message, $level]);
    // keep the log small
    db()->exec('DELETE FROM activity WHERE id NOT IN (SELECT id FROM activity ORDER BY id DESC LIMIT 500)');
}
