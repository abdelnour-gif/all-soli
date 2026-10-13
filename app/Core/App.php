<?php
namespace QueuePro\Core;

use QueuePro\Container;
use QueuePro\Database\Connection;
use QueuePro\Database\Schema;
use QueuePro\Http\ApiController;
use QueuePro\Support\Messages;
use QueuePro\Support\Security;

defined('QP_APP') || exit;

/** Application kernel: config, routing, security headers, error handling, after-response jobs. */
final class App
{
    private static array $config = [];
    private static ?Container $c = null;
    private static ?Auth $auth = null;
    private static ?Request $req = null;
    private static string $baseUrl = '';
    private static string $basePath = '';
    private static string $nonce = '';

    public static function configPath(): string
    {
        return QP_ROOT . '/storage/config.php';
    }

    public static function installed(): bool
    {
        return is_file(self::configPath());
    }

    // ------------------------------------------------------------------ accessors
    public static function config(): array
    {
        return self::$config;
    }

    public static function container(): Container
    {
        return self::$c;
    }

    public static function auth(): Auth
    {
        return self::$auth;
    }

    public static function request(): Request
    {
        return self::$req;
    }

    public static function baseUrl(): string
    {
        return self::$baseUrl;
    }

    public static function cspNonce(): string
    {
        return self::$nonce;
    }

    public static function trustProxy(): bool
    {
        return !empty(self::$config['trust_proxy']);
    }

    // ------------------------------------------------------------------ boot

    /** Boots config + database (also used by the CLI). */
    public static function boot(array $config): void
    {
        self::$config = $config;
        date_default_timezone_set('UTC');
        Security::setSecret((string) $config['secret']);
        Cache::init(QP_ROOT . '/storage/cache');
        self::$c = new Container(new Connection($config['db']));
        if (!empty($config['url'])) {
            self::$baseUrl = rtrim((string) $config['url'], '/');
        }
        self::$c->notify->baseUrl = self::$baseUrl;
        // Upgrade the schema automatically after the files of a new version were uploaded.
        if (self::schemaVersion() !== Schema::VERSION) {
            $lock = fopen(QP_ROOT . '/storage/upgrade.lock', 'c');
            if ($lock) {
                flock($lock, LOCK_EX);
            }
            try {
                if (self::schemaVersion() !== Schema::VERSION) {
                    $db = self::$c->db;
                    Schema::install($db);
                    \QueuePro\Database\Seeder::run($db);
                    $db->query(
                        'INSERT INTO qp_settings (setting_key, setting_value, updated_at) VALUES (%s,%s,%s)'
                        . $db->onConflict(['setting_key'], 'setting_value = {new:setting_value}, updated_at = {new:updated_at}'),
                        ['schema_version', Schema::VERSION, gmdate('Y-m-d H:i:s')]
                    );
                }
            } finally {
                if ($lock) {
                    flock($lock, LOCK_UN);
                    fclose($lock);
                }
            }
        }
    }

    private static function schemaVersion(): string
    {
        try {
            return (string) self::$c->db->getVar("SELECT setting_value FROM qp_settings WHERE setting_key = 'schema_version'");
        } catch (\QueuePro\Database\DbException $e) {
            return '';
        }
    }

    public static function run(): void
    {
        self::setupErrors(false);
        self::$basePath = self::detectBasePath();
        self::$req = Request::capture(self::$basePath);
        self::$nonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');

        $cfg = self::installed() ? (require self::configPath()) : null;
        $trust = !empty($cfg['trust_proxy']);
        if (!$cfg || empty($cfg['url'])) {
            $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
            self::$baseUrl = (self::$req->isHttps($trust) ? 'https://' : 'http://') . $host . self::$basePath;
        }
        self::securityHeaders();

        if (!$cfg) {
            Installer::handle(self::$req);
            return;
        }
        self::setupErrors(!empty($cfg['debug']));
        self::boot($cfg);
        self::$auth = new Auth(self::$c->users, self::$c->limiter, self::$req->isHttps($trust), (self::$basePath ?: '') . '/', QP_ROOT . '/storage/sessions');
        register_shutdown_function([self::class, 'afterResponse']);

        try {
            Router::dispatch(self::$req);
        } catch (\Throwable $e) {
            self::log($e);
            self::errorPage(500);
        }
    }

    /** URL path of the folder that holds index.php ('' at the domain root, '/queue' in a sub-folder). */
    private static function detectBasePath(): string
    {
        $norm = fn(string $p) => rtrim(str_replace('\\', '/', $p), '/');
        $root = $norm((string) realpath(QP_ROOT));
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        $file = (string) ($_SERVER['SCRIPT_FILENAME'] ?? '');
        if (basename($script) === 'index.php' && $file !== '' && $norm((string) realpath($file)) === $root . '/index.php') {
            $base = $norm(dirname($script));
        } else {
            $doc = $norm((string) realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? '')));
            $base = ($doc !== '' && strpos($root . '/', $doc . '/') === 0) ? substr($root, strlen($doc)) : '';
        }
        return ($base === '.' || $base === '/') ? '' : $base;
    }

    // ------------------------------------------------------------------ security

    private static function securityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        header_remove('X-Powered-By');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-" . self::$nonce . "'; "
            . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com data:; "
            . "img-src 'self' data: https:; connect-src 'self'; frame-ancestors 'self'; form-action 'self'; base-uri 'self'; object-src 'none'");
    }

    // ------------------------------------------------------------------ errors

    private static function setupErrors(bool $debug): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', $debug ? '1' : '0');
        ini_set('log_errors', '1');
        if (is_dir(QP_ROOT . '/storage/logs') && is_writable(QP_ROOT . '/storage/logs')) {
            ini_set('error_log', QP_ROOT . '/storage/logs/php-errors.log');
        }
    }

    public static function log(\Throwable $e): void
    {
        $line = sprintf("[%s] %s: %s @ %s:%d\n", gmdate('Y-m-d H:i:s'), get_class($e), $e->getMessage(), $e->getFile(), $e->getLine());
        $file = QP_ROOT . '/storage/logs/app.log';
        if (@filesize($file) > 5 * 1024 * 1024) {
            @rename($file, $file . '.1');
        }
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        if (!empty(self::$config['debug'])) {
            error_log($line . $e->getTraceAsString());
        }
    }

    public static function errorPage(int $code, ?string $message = null): void
    {
        http_status($code);
        $lang = (($_COOKIE['qp_lang'] ?? '') === 'en' || (self::$req->query['lang'] ?? '') === 'en') ? 'en' : 'ar';
        if (strpos(self::$req->path, '/api/') === 0) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['code' => $code === 404 ? 'not_found' : 'server_error', 'message' => Messages::get($code === 404 ? 'not_found' : 'server_error', $lang)], JSON_UNESCAPED_UNICODE);
            return;
        }
        $title = $code === 404 ? Messages::get('not_found', $lang) : Messages::get('server_error', $lang);
        $home = $lang === 'en' ? 'Back to home' : 'العودة للرئيسية';
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="' . $lang . '" dir="' . ($lang === 'en' ? 'ltr' : 'rtl') . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . esc_html($code . ' — ' . $title) . '</title><link rel="stylesheet" href="' . esc_url(asset('css/queuepro.css')) . '"></head>'
            . '<body class="qp"><main class="qp-shell" style="padding:64px 16px;text-align:center"><div style="font-family:var(--qp-num);font-size:64px;font-weight:800;color:var(--qp-green-600)">' . (int) $code . '</div>'
            . '<p style="font-size:18px">' . esc_html($message ?? $title) . '</p><p><a class="qp-btn" style="width:auto;padding:0 24px" href="' . esc_url(url('/')) . '">' . esc_html($home) . '</a></p></main></body></html>';
    }

    // ------------------------------------------------------------------ after the response

    /**
     * Runs after the page has been sent: delivers queued SMS/WhatsApp messages and,
     * every 5 minutes, the maintenance jobs (end-of-day closing, cleanup).
     * This replaces cron on hosts without it; a real cron (bin/console.php cron) is optional.
     */
    public static function afterResponse(): void
    {
        if (!self::$c) {
            return;
        }
        $due = (int) Cache::get('maint_at') < time() - 300;
        if (!$due && !self::$c->notify->queuedThisRequest) {
            return;
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
        ignore_user_abort(true);
        try {
            if (self::$c->notify->queuedThisRequest) {
                (new \QueuePro\Notifications\Dispatcher(self::$c))->run(10);
            }
            if ($due) {
                Maintenance::run(self::$c);
            }
        } catch (\Throwable $e) {
            self::log($e);
        }
    }
}
