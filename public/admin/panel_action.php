<?php
// Dashboard switches: Auto Start, Auto Stop, Notification.
require __DIR__ . '/../_boot.php';
require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('dashboard.php');
}
check_csrf();
$labels = ['auto_start' => 'Auto Start', 'auto_stop' => 'Auto Stop', 'notify' => 'Notifications'];
$key = (string) ($_POST['key'] ?? '');
if (isset($labels[$key])) {
    $on = ($_POST['to'] ?? '') === 'on';
    panel_save([$key => $on]);
    log_activity($labels[$key] . ' turned ' . ($on ? 'on' : 'off'));
}
redirect('dashboard.php');
