<?php
/**
 * QueuePro — front controller. Every request (except real files in assets/ and uploads/) lands here.
 */
define('QP_APP', true);
define('QP_ROOT', __DIR__);
define('QP_VERSION', '2.0.0');

if (version_compare(PHP_VERSION, '8.0.0', '<')) {
    http_response_code(500);
    exit('QueuePro needs PHP 8.0 or newer (this server runs PHP ' . PHP_VERSION . ').');
}

// PHP built-in server (php -S): serve static files directly, but never internal folders.
if (PHP_SAPI === 'cli-server') {
    $p = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
    if (preg_match('#^/(assets|uploads)/[^\x00]+$#', $p) && strpos($p, '..') === false && is_file(__DIR__ . $p)
        && !preg_match('/\.(php|phtml|phar|htaccess)$/i', $p)) {
        return false;
    }
}

require __DIR__ . '/app/autoload.php';
require __DIR__ . '/app/helpers.php';

\QueuePro\Core\App::run();
