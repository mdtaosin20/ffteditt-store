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
            page_url     TEXT NOT NULL DEFAULT 'https://ffteditt.store/game/',
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
    $cols = array_column($pdo->query('PRAGMA table_info(servers)')->fetchAll(), 'name');
    if (!in_array('terminal_enabled', $cols, true)) {
        $pdo->exec('ALTER TABLE servers ADD COLUMN terminal_enabled INTEGER NOT NULL DEFAULT 1');
    }
}

function log_activity(string $message): void
{
    db()->prepare('INSERT INTO activity (ts, message) VALUES (?, ?)')->execute([time(), $message]);
    // keep the log small
    db()->exec('DELETE FROM activity WHERE id NOT IN (SELECT id FROM activity ORDER BY id DESC LIMIT 500)');
}
