<?php
require __DIR__ . '/../_boot.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    logout();
}
redirect('index.php');
