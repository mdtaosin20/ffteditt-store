<?php
declare(strict_types=1);

function e($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

function flash(?string $msg = null, string $type = 'ok'): ?array
{
    start_session();
    if ($msg !== null) {
        $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

/** Video presets the worker understands. */
function qualities(): array
{
    return [
        '480p-v'  => ['label' => '480p vertical (9:16)',     'w' => 480,  'h' => 854,  'kbps' => 1500],
        '720p-v'  => ['label' => '720p vertical (9:16)',     'w' => 720,  'h' => 1280, 'kbps' => 3000],
        '1080p-v' => ['label' => '1080p vertical (9:16)',    'w' => 1080, 'h' => 1920, 'kbps' => 6000],
        '720p-h'  => ['label' => '720p landscape (16:9)',    'w' => 1280, 'h' => 720,  'kbps' => 3000],
        '1080p-h' => ['label' => '1080p landscape (16:9)',   'w' => 1920, 'h' => 1080, 'kbps' => 6000],
    ];
}

function mask_key(string $key): string
{
    if ($key === '') {
        return 'Not set';
    }
    return '••••-••••-••••-••••-' . substr($key, -4);
}

function ago(int $ts): string
{
    if ($ts <= 0) {
        return 'never';
    }
    $d = time() - $ts;
    if ($d < 60)    return $d . ' s ago';
    if ($d < 3600)  return intdiv($d, 60) . ' min ago';
    if ($d < 86400) return intdiv($d, 3600) . ' h ago';
    return date('M j, H:i', $ts);
}

function duration(int $since): string
{
    $d = max(0, time() - $since);
    $h = intdiv($d, 3600);
    $m = intdiv($d % 3600, 60);
    if ($h >= 24) {
        return intdiv($h, 24) . 'd ' . ($h % 24) . 'h';
    }
    return $h > 0 ? "{$h}h {$m}m" : "{$m}m " . ($d % 60) . 's';
}

function server_online(?array $server): bool
{
    return $server && (time() - (int) $server['last_seen']) <= SERVER_OFFLINE_AFTER;
}

/**
 * What the admin should see for a stream, combining what was asked (desired)
 * with what the VPS last reported (status).
 * Returns [code, label].
 */
function stream_view_status(array $s, ?array $server): array
{
    if (!$server) {
        return ['muted', 'No server'];
    }
    if (!server_online($server)) {
        return $s['desired'] === 'on' ? ['warn', 'Server offline'] : ['muted', 'Stopped'];
    }
    switch ($s['status']) {
        case 'live':
            return $s['desired'] === 'on' ? ['live', 'Live'] : ['warn', 'Stopping…'];
        case 'starting':
            return ['warn', 'Starting…'];
        case 'error':
            return ['error', 'Error'];
        default:
            return $s['desired'] === 'on' ? ['warn', 'Starting…'] : ['muted', 'Stopped'];
    }
}

function all_servers(): array
{
    $rows = db()->query('SELECT * FROM servers ORDER BY name')->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[(int) $r['id']] = $r;
    }
    return $out;
}

/* ---------- page layout ---------- */

function page_start(string $title, string $active = '', ?array $admin = null): void
{
    $f = flash();
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> · FFTEdit Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Russo+One&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/admin.css?v=1">
</head>
<body>
<?php if ($admin): ?>
<header class="topbar">
  <div class="wrap topbar-in">
    <a class="brand" href="dashboard.php"><span class="logo">FFTEdit</span><span class="pill">Admin</span></a>
    <nav class="nav">
      <a href="dashboard.php" class="<?= $active === 'streams' ? 'on' : '' ?>">Streams</a>
      <a href="servers.php" class="<?= $active === 'servers' ? 'on' : '' ?>">Servers</a>
      <a href="activity.php" class="<?= $active === 'activity' ? 'on' : '' ?>">Activity</a>
      <a href="settings.php" class="<?= $active === 'settings' ? 'on' : '' ?>">Settings</a>
      <form method="post" action="logout.php" class="inline"><?= csrf_field() ?><button class="nav-btn" type="submit">Log out</button></form>
    </nav>
  </div>
</header>
<?php endif; ?>
<main class="wrap main">
<?php if ($f): ?>
  <div class="flash flash-<?= e($f['type']) ?>" role="status"><?= e($f['msg']) ?></div>
<?php endif; ?>
<?php
}

function page_end(string $script = ''): void
{
    ?>
</main>
<?php if ($script): ?><script src="/assets/<?= e($script) ?>?v=1"></script><?php endif; ?>
</body>
</html>
<?php
}
