<?php
/**
 * FFTEdit admin — shared bootstrap.
 * Lives OUTSIDE the public web folder on the server (/home/<user>/ffteditt_app).
 */

declare(strict_types=1);

if (!defined('APP_DIR')) {
    define('APP_DIR', __DIR__);
}
if (!defined('DATA_DIR')) {
    // Server: /home/<user>/ffteditt_data   Repo: <repo>/ffteditt_data (git-ignored)
    define('DATA_DIR', dirname(APP_DIR) . '/ffteditt_data');
}

date_default_timezone_set('Asia/Dhaka');

const SERVER_OFFLINE_AFTER = 45;   // seconds without a check-in = server offline
const LOGIN_MAX_TRIES      = 5;
const LOGIN_LOCK_SECONDS   = 900;  // 15 minutes

require APP_DIR . '/db.php';
require APP_DIR . '/auth.php';
require APP_DIR . '/helpers.php';
require APP_DIR . '/game.php';
require APP_DIR . '/flags.php';
require APP_DIR . '/war.php';
require APP_DIR . '/tug.php';
require APP_DIR . '/music.php';
require APP_DIR . '/voice.php';
require APP_DIR . '/panel.php';
require APP_DIR . '/site.php';
require APP_DIR . '/ui.php';
require APP_DIR . '/youtube.php';
require APP_DIR . '/chat.php';
require APP_DIR . '/robot.php';
require APP_DIR . '/insights.php';
