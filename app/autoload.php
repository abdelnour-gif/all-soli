<?php
defined('QP_APP') || exit;

/*
 * Filesystem layout. Standalone: everything lives next to index.php.
 * The WordPress plugin defines these before loading this file so that private data
 * (wp-content/queuepro-data) and images (wp-content/uploads/queuepro) survive plugin updates.
 */
defined('QP_ROOT') || define('QP_ROOT', dirname(__DIR__));
defined('QP_STORAGE') || define('QP_STORAGE', QP_ROOT . '/storage');
defined('QP_UPLOADS') || define('QP_UPLOADS', QP_ROOT . '/uploads');
/** Public URLs ('' = derived from the app URL: /assets, /uploads, /storage). */
defined('QP_ASSETS_URL') || define('QP_ASSETS_URL', '');
defined('QP_UPLOADS_URL') || define('QP_UPLOADS_URL', '');
defined('QP_STORAGE_URL') || define('QP_STORAGE_URL', '');
/** true when running inside WordPress (queuepro.php). */
defined('QP_WP') || define('QP_WP', false);

spl_autoload_register(static function (string $class): void {
    if (strpos($class, 'QueuePro\\') !== 0) {
        return;
    }
    $path = __DIR__ . '/' . str_replace('\\', '/', substr($class, 9)) . '.php';
    if (is_file($path)) {
        require $path;
    }
});
