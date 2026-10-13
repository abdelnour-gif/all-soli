<?php
/**
 * Plugin Name:       QueuePro — Digital Queues
 * Description:       Multi-provider digital queue platform (sequential numbers, no appointments). Own database tables, mobile-first, Arabic RTL / English LTR. Public provider pages at yoursite.com/provider-name.
 * Version:           2.1.0
 * Requires at least: 5.8
 * Requires PHP:      8.0
 * Author:            QueuePro
 * License:           Proprietary — owned by the site owner
 * Text Domain:       queuepro
 */
defined('ABSPATH') || exit;

if (version_compare(PHP_VERSION, '8.0.0', '<')) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>QueuePro needs PHP 8.0 or newer (this server runs PHP ' . esc_html(PHP_VERSION) . ').</p></div>';
    });
    return;
}

define('QP_APP', true);
define('QP_ROOT', __DIR__);
define('QP_VERSION', '2.1.0');
define('QP_WP', true);
// Private data (sessions, cache, logs, backups) and images live outside the plugin folder,
// so updating or re-uploading the plugin never deletes them.
define('QP_STORAGE', WP_CONTENT_DIR . '/queuepro-data');
define('QP_UPLOADS', WP_CONTENT_DIR . '/uploads/queuepro');
define('QP_ASSETS_URL', plugins_url('assets', __FILE__));
define('QP_UPLOADS_URL', content_url('uploads/queuepro'));
define('QP_STORAGE_URL', content_url('queuepro-data'));

require __DIR__ . '/app/autoload.php';
require __DIR__ . '/app/helpers.php';

\QueuePro\WordPress\Plugin::register(__FILE__);
