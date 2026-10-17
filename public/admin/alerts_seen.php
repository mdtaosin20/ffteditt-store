<?php
// Bell → "Mark all read".
require __DIR__ . '/../_boot.php';
require_login();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    panel_save(['alerts_seen' => (int) db()->query('SELECT COALESCE(MAX(id), 0) FROM activity')->fetchColumn()]);
}
$back = (string) parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_PATH);
redirect(preg_match('#^/admin/[a-z_]+\.php$#', $back) ? $back : 'dashboard.php');
