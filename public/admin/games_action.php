<?php
// Game Manager buttons: auto play on/off, speed, restart.
require __DIR__ . '/../_boot.php';
require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('games.php');
}
check_csrf();

$key  = (string) ($_POST['game'] ?? '');
$do   = (string) ($_POST['do'] ?? '');
$back = in_array($_POST['back'] ?? '', ['dashboard.php', 'games.php'], true) ? $_POST['back'] : 'games.php';
$games = games_list();
if (!isset($games[$key])) {
    flash('Game not found.', 'error');
    redirect($back);
}
$name = $games[$key]['name'];
$cfg  = match ($key) { 'flags' => flags_config(), 'war' => war_config(), 'tug' => tug_config(), default => game_config() };
$save = fn (array $c) => match ($key) { 'flags' => flags_save($c), 'war' => war_save($c), 'tug' => tug_save($c), default => game_save($c) };

if ($do === 'autoplay') {
    $cfg['autoplay'] = ($_POST['to'] ?? '') === 'on';
    $cfg['rev'] = bin2hex(random_bytes(4));
    $save($cfg);
    log_activity("{$name}: auto play " . ($cfg['autoplay'] ? 'on' : 'off'), $cfg['autoplay'] ? 'ok' : 'warn');
    flash($cfg['autoplay'] ? "{$name} is playing again." : "{$name} is paused. Live streams show a “be right back” screen.");
} elseif ($do === 'pace') {
    $pace = (string) ($_POST['pace'] ?? '');
    if (isset(paces()[$pace])) {
        $cfg['pace'] = $pace;
        $cfg['rev'] = bin2hex(random_bytes(4));
        $save($cfg);
        log_activity("{$name}: speed set to " . paces()[$pace]);
        flash("{$name} speed: " . paces()[$pace] . '. Live streams update within about 20 seconds.');
    }
} elseif ($do === 'reset') {
    $cfg['reset'] = bin2hex(random_bytes(4));
    $save($cfg);
    log_activity("{$name} restarted from the beginning", 'info');
    flash("{$name} starts again from the beginning within about 20 seconds.");
}
redirect($back);
