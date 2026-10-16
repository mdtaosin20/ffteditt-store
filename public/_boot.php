<?php
// Finds the private app folder and loads it.
// On the server the app lives next to the web folder: /home/<user>/ffteditt_app
// In the GitHub repo it is ../app
$candidates = [
    dirname(__DIR__) . '/ffteditt_app/bootstrap.php',
    __DIR__ . '/../app/bootstrap.php',
];
foreach ($candidates as $c) {
    if (is_file($c)) {
        require_once $c;
        return;
    }
}
http_response_code(500);
exit('App files not found. Run "Deploy HEAD Commit" in cPanel Git Version Control.');
