<?php
defined('QP_APP') || exit;

spl_autoload_register(static function (string $class): void {
    if (strpos($class, 'QueuePro\\') !== 0) {
        return;
    }
    $path = __DIR__ . '/' . str_replace('\\', '/', substr($class, 9)) . '.php';
    if (is_file($path)) {
        require $path;
    }
});
