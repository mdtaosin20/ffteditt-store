<?php
/** Game settings (names, colours, photos, target) shared by the admin panel and /api/game.php. */
declare(strict_types=1);

const UPLOAD_MAX_BYTES = 5 * 1024 * 1024;

function game_defaults(): array
{
    return [
        'target'   => 20,
        'autoplay' => true,      // false = the page shows "be right back" instead of playing
        'pace'     => 'normal',  // slow | normal | fast
        'rev'      => '1',
        'reset'   => '1',
        'players' => [
            ['name' => 'MESSI',   'team' => 'PINK TEAM', 'color' => '#ff2f9e', 'number' => '10', 'photo' => '', 'ball' => ''],
            ['name' => 'RONALDO', 'team' => 'GOLD TEAM', 'color' => '#ffd21a', 'number' => '7',  'photo' => '', 'ball' => ''],
        ],
    ];
}

function setting_get(string $key): ?string
{
    $st = db()->prepare('SELECT value FROM settings WHERE key = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string) $v;
}

function setting_set(string $key, string $value): void
{
    db()->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
        ->execute([$key, $value]);
}

function game_config(): array
{
    $d = game_defaults();
    $c = json_decode(setting_get('game') ?? '', true);
    if (!is_array($c)) {
        return $d;
    }
    $out = array_merge($d, array_intersect_key($c, $d));
    foreach ($d['players'] as $i => $p) {
        $out['players'][$i] = array_merge($p, array_intersect_key((array) ($c['players'][$i] ?? []), $p));
    }
    return $out;
}

function game_save(array $c): void
{
    setting_set('game', json_encode($c));
}

/* ---------- uploaded images (kept in the private data folder, served by /api/image.php) ---------- */

function upload_dir(): string
{
    $dir = DATA_DIR . '/uploads';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir;
}

/** Saves an uploaded image and returns its file name, or throws with a friendly message. */
function save_upload(array $f): string
{
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed. Try a smaller picture (max 5 MB).');
    }
    if ($f['size'] > UPLOAD_MAX_BYTES) {
        throw new RuntimeException('Picture is too big (max 5 MB).');
    }
    $info = @getimagesize($f['tmp_name']);
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($types[$info[2]])) {
        throw new RuntimeException('Please upload a JPG, PNG or WEBP picture.');
    }
    $name = bin2hex(random_bytes(12)) . '.' . $types[$info[2]];
    if (!move_uploaded_file($f['tmp_name'], upload_dir() . '/' . $name)) {
        throw new RuntimeException('Could not save the picture on the server.');
    }
    return $name;
}

function delete_upload(string $name): void
{
    if (preg_match('/^[a-f0-9]{24}\.(jpg|png|webp|mp3|wav|ogg|m4a)$/', $name)) {
        @unlink(upload_dir() . '/' . $name);
    }
}

function upload_url(string $name): string
{
    return $name === '' ? '' : '/api/image.php?f=' . rawurlencode($name);
}
