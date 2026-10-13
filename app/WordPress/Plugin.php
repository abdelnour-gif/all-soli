<?php
namespace QueuePro\WordPress;

use QueuePro\Container;
use QueuePro\Core\App;
use QueuePro\Core\Installer;
use QueuePro\Core\Maintenance;
use QueuePro\Database\Connection;
use QueuePro\Services\UserService;
use QueuePro\Support\Actor;
use QueuePro\Support\QueueException;

defined('QP_APP') || exit;

/**
 * WordPress integration. The engine, templates and API are exactly the same as the standalone
 * edition; this class only:
 *  - builds the configuration from wp-config.php (same MySQL database, own qp_* tables),
 *  - answers QueuePro URLs before WordPress looks for a post or page (no rewrite rules to flush),
 *  - adds a "QueuePro" screen in wp-admin (first setup, links, Super Admin password reset),
 *  - runs the maintenance job from WP-Cron as well as after normal requests.
 * QueuePro accounts (Super Admin, providers) are separate from WordPress users on purpose:
 * providers never get access to wp-admin.
 */
final class Plugin
{
    private const OPT_INSTALLED = 'queuepro_installed';
    private const OPT_SECRET = 'queuepro_secret';
    private const OPT_FRONT = 'queuepro_front_page';
    private const OPT_PROXY = 'queuepro_trust_proxy';
    private const CRON = 'queuepro_maintenance';
    private const PAGE = 'queuepro';

    /** First path segments that always belong to QueuePro. */
    private const PREFIXES = ['api', 't', 'p', 'explore', 'dashboard', 'super-admin', 'logout', 'recover-admin'];

    private static string $file = '';

    public static function register(string $file): void
    {
        self::$file = $file;
        register_activation_hook($file, [self::class, 'activate']);
        register_deactivation_hook($file, [self::class, 'deactivate']);
        add_filter('do_parse_request', [self::class, 'route'], 1, 3);
        add_filter('cron_schedules', [self::class, 'cronSchedules']);
        add_action(self::CRON, [self::class, 'cron']);
        add_filter('robots_txt', [self::class, 'robots'], 10, 2);
        if (is_admin()) {
            add_action('admin_menu', [self::class, 'menu']);
            add_action('admin_post_queuepro_setup', [self::class, 'handleSetup']);
            add_action('admin_post_queuepro_options', [self::class, 'handleOptions']);
            add_action('admin_post_queuepro_reset', [self::class, 'handleReset']);
            add_filter('plugin_action_links_' . plugin_basename($file), [self::class, 'actionLinks']);
            add_action('admin_notices', [self::class, 'notices']);
        }
    }

    // ================================================================ state & config

    public static function installed(): bool
    {
        return (bool) get_option(self::OPT_INSTALLED);
    }

    public static function config(): array
    {
        return [
            'db' => self::dbConfig(),
            'url' => untrailingslashit(home_url()),
            'secret' => self::secret(),
            'debug' => false,
            'trust_proxy' => (bool) get_option(self::OPT_PROXY),
        ];
    }

    private static function secret(): string
    {
        $s = (string) get_option(self::OPT_SECRET);
        if (strlen($s) < 32) {
            $s = bin2hex(random_bytes(32));
            update_option(self::OPT_SECRET, $s, false);
        }
        return $s;
    }

    /** Same database as WordPress (from wp-config.php). WordPress on SQLite → a private SQLite file. */
    public static function dbConfig(): array
    {
        if (defined('DB_ENGINE') && strtolower((string) DB_ENGINE) === 'sqlite') {
            return ['driver' => 'sqlite', 'path' => QP_STORAGE . '/database.sqlite'];
        }
        $host = (string) DB_HOST;
        $cfg = ['driver' => 'mysql', 'host' => 'localhost', 'port' => 3306, 'name' => (string) DB_NAME,
                'user' => (string) DB_USER, 'pass' => (string) DB_PASSWORD];
        if (preg_match('#^(.*?):(/.+)$#', $host, $m)) {           // localhost:/path/mysqld.sock
            $cfg['host'] = $m[1] ?: 'localhost';
            $cfg['socket'] = $m[2];
        } elseif (preg_match('#^\[(.+)\](?::(\d+))?$#', $host, $m)) { // [::1]:3306
            $cfg['host'] = $m[1];
            $cfg['port'] = (int) ($m[2] ?? 3306) ?: 3306;
        } elseif (preg_match('#^([^:]+):(\d+)$#', $host, $m)) {   // host:3307
            $cfg['host'] = $m[1];
            $cfg['port'] = (int) $m[2];
        } elseif ($host !== '') {
            $cfg['host'] = $host;
        }
        return $cfg;
    }

    // ================================================================ lifecycle

    public static function activate(): void
    {
        self::secret();
        add_option(self::OPT_FRONT, '1');
        self::prepareFolders();
        if (!wp_next_scheduled(self::CRON)) {
            wp_schedule_event(time() + 300, 'queuepro_5min', self::CRON);
        }
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::CRON);
    }

    /** Creates the data folders and the files that stop the web server from serving them. */
    public static function prepareFolders(): void
    {
        Installer::ensureDirs(); // also writes the .htaccess / index.php protection files
    }

    // ================================================================ front end

    /**
     * Runs at the very start of WordPress' request parsing. QueuePro URLs are answered here and the
     * request ends; anything else (posts, pages, wp-json…) continues to WordPress untouched.
     */
    public static function route($continue, $wp = null, $extra = null)
    {
        if (!$continue || !self::installed() || is_admin() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return $continue;
        }
        $base = rtrim((string) parse_url(home_url('/'), PHP_URL_PATH), '/');
        $path = rawurldecode((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));
        if ($base !== '') {
            if ($path !== $base && strpos($path, $base . '/') !== 0) {
                return $continue;
            }
            $path = substr($path, strlen($base));
        }
        $path = '/' . ltrim((string) preg_replace('#/+#', '/', $path), '/');
        if (!self::claims($path)) {
            return $continue;
        }
        // WordPress adds slashes to all request data ("magic quotes"); QueuePro expects the raw values.
        $_GET = wp_unslash($_GET);
        $_POST = wp_unslash($_POST);
        $_COOKIE = wp_unslash($_COOKIE);
        $_SERVER = wp_unslash($_SERVER);
        $_REQUEST = array_merge($_GET, $_POST);
        if (!is_dir(QP_STORAGE . '/sessions')) {
            self::prepareFolders();
        }
        App::run(self::config(), $base);
        exit;
    }

    private static function claims(string $path): bool
    {
        if ($path === '/') {
            return get_option(self::OPT_FRONT, '1') === '1' && empty($_GET['p']) && empty($_GET['page_id']) && empty($_GET['s']);
        }
        $first = strtolower(explode('/', trim($path, '/'))[0]);
        if (in_array($first, self::PREFIXES, true)) {
            return true;
        }
        // Provider page: yoursite.com/{slug}/ — only when such a provider exists, so WordPress pages keep working.
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (($method === 'GET' || $method === 'HEAD') && preg_match('#^/([a-z0-9][a-z0-9-]{1,58}[a-z0-9])/?$#', $path, $m)) {
            try {
                $db = new Connection(self::dbConfig());
                return (bool) $db->getVar('SELECT 1 FROM qp_providers WHERE slug = %s', [$m[1]]);
            } catch (\Throwable $e) {
                return false;
            }
        }
        return false;
    }

    public static function robots(string $output, $public): string
    {
        $base = rtrim((string) parse_url(home_url('/'), PHP_URL_PATH), '/');
        foreach (['t', 'dashboard', 'super-admin', 'api'] as $p) {
            $output .= "Disallow: $base/$p/\n";
        }
        return $output;
    }

    // ================================================================ cron

    public static function cronSchedules(array $s): array
    {
        $s['queuepro_5min'] = ['interval' => 300, 'display' => 'QueuePro — every 5 minutes'];
        return $s;
    }

    /** End-of-day closing, sending queued SMS, cleanup (also triggered after normal page views). */
    public static function cron(): void
    {
        if (!self::installed()) {
            return;
        }
        try {
            App::boot(self::config());
            Maintenance::run(App::container(), true);
        } catch (\Throwable $e) {
            App::log($e);
        }
    }

    // ================================================================ wp-admin

    public static function menu(): void
    {
        add_menu_page('QueuePro', 'QueuePro', 'manage_options', self::PAGE, [self::class, 'page'], 'dashicons-groups', 26);
    }

    public static function actionLinks(array $links): array
    {
        array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=' . self::PAGE)) . '">' . esc_html__('Settings') . '</a>');
        return $links;
    }

    public static function notices(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!self::installed() && (!$screen || $screen->id !== 'toplevel_page_' . self::PAGE)) {
            echo '<div class="notice notice-warning"><p><b>QueuePro:</b> أكمل الإعداد من <a href="' . esc_url(admin_url('admin.php?page=' . self::PAGE)) . '">صفحة QueuePro</a> (إنشاء حساب المدير).</p></div>';
        }
        if (self::installed() && !get_option('permalink_structure')) {
            echo '<div class="notice notice-error"><p><b>QueuePro:</b> روابط المزوّدين تحتاج تفعيل الروابط الدائمة: الإعدادات ← الروابط الدائمة ← اختر "اسم المقالة" ثم احفظ. / Enable pretty permalinks (Settings → Permalinks → Post name).</p></div>';
        }
    }

    private static function requirements(): array
    {
        $db = self::dbConfig();
        $ok = true;
        $msg = '';
        try {
            new Connection($db);
        } catch (\Throwable $e) {
            $ok = false;
            $msg = $e->getMessage();
        }
        Installer::ensureDirs();
        return [
            ['PHP 8.0+', version_compare(PHP_VERSION, '8.0.0', '>='), PHP_VERSION],
            [$db['driver'] === 'mysql' ? 'pdo_mysql' : 'pdo_sqlite', extension_loaded($db['driver'] === 'mysql' ? 'pdo_mysql' : 'pdo_sqlite'), ''],
            ['mbstring', extension_loaded('mbstring'), ''],
            ['Database connection', $ok, $msg],
            ['wp-content/queuepro-data writable', is_writable(QP_STORAGE), ''],
            ['wp-content/uploads/queuepro writable', is_writable(QP_UPLOADS), ''],
            ['Pretty permalinks', (bool) get_option('permalink_structure'), 'Settings → Permalinks'],
        ];
    }

    public static function page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $msg = isset($_GET['qp_msg']) ? sanitize_key(wp_unslash($_GET['qp_msg'])) : '';
        $err = get_transient('queuepro_errors_' . get_current_user_id());
        delete_transient('queuepro_errors_' . get_current_user_id());
        $notices = [
            'installed' => 'تم إعداد المنصة. ادخل لوحة الإدارة بالحساب الذي أنشأته. / Platform ready.',
            'saved' => 'تم الحفظ. / Saved.',
            'reset' => 'تم تغيير كلمة المرور وتسجيل الخروج من كل الأجهزة. / Password changed.',
        ];
        $post = esc_url(admin_url('admin-post.php'));
        echo '<div class="wrap" dir="rtl" style="max-width:860px"><h1>QueuePro — منصة الطوابير الرقمية</h1>';
        if (isset($notices[$msg])) {
            echo '<div class="notice notice-success"><p>' . esc_html($notices[$msg]) . '</p></div>';
        }
        if (is_array($err) && $err) {
            echo '<div class="notice notice-error">';
            foreach ($err as $e) {
                echo '<p>' . esc_html($e) . '</p>';
            }
            echo '</div>';
        }

        if (!self::installed()) {
            echo '<h2>1) متطلبات الخادم</h2><table class="widefat striped" style="max-width:640px"><tbody>';
            $allOk = true;
            foreach (self::requirements() as [$name, $ok, $info]) {
                $allOk = $allOk && ($ok || $name === 'Pretty permalinks');
                echo '<tr><td dir="ltr">' . esc_html($name) . ($info ? ' <small>(' . esc_html($info) . ')</small>' : '') . '</td><td>'
                    . ($ok ? '<b style="color:#008a20">✓</b>' : '<b style="color:#d63638">✗</b>') . '</td></tr>';
            }
            echo '</tbody></table>';
            echo '<h2>2) حساب المدير العام (Super Admin)</h2><p>حساب خاص بالمنصة، منفصل عن حسابات ووردبريس. ستدخل به على <code dir="ltr">' . esc_html(home_url('/super-admin/')) . '</code></p>';
            echo '<form method="post" action="' . $post . '"><input type="hidden" name="action" value="queuepro_setup">';
            wp_nonce_field('queuepro_setup');
            $cu = wp_get_current_user();
            echo '<table class="form-table"><tbody>'
                . self::row('اسم المنصة', '<input name="brand_name" class="regular-text" value="' . esc_attr(get_bloginfo('name') ?: 'QueuePro') . '" maxlength="60">')
                . self::row('هاتف الدعم', '<input name="support_phone" class="regular-text" dir="ltr" maxlength="30">')
                . self::row('اسم المستخدم *', '<input name="username" class="regular-text" dir="ltr" required pattern="[A-Za-z0-9][A-Za-z0-9._\-]{2,59}" value="admin">')
                . self::row('البريد', '<input name="email" type="email" class="regular-text" dir="ltr" value="' . esc_attr($cu->user_email ?? '') . '">')
                . self::row('كلمة المرور * (10+)', '<input name="password" type="password" class="regular-text" dir="ltr" required minlength="10" autocomplete="new-password">')
                . self::row('تأكيد كلمة المرور *', '<input name="password2" type="password" class="regular-text" dir="ltr" required minlength="10" autocomplete="new-password">')
                . '</tbody></table>';
            submit_button('تثبيت المنصة / Install', 'primary', 'submit', true, $allOk ? [] : ['disabled' => 'disabled']);
            echo '</form></div>';
            return;
        }

        $links = [
            'لوحة الإدارة (Super Admin)' => home_url('/super-admin/'),
            'لوحة المزوّد (للهاتف)' => home_url('/dashboard/'),
            'استكشاف المزوّدين' => home_url('/explore/'),
            'الصفحة الرئيسية للمنصة' => home_url('/'),
        ];
        echo '<h2>الروابط</h2><table class="widefat striped" style="max-width:640px"><tbody>';
        foreach ($links as $label => $u) {
            echo '<tr><td>' . esc_html($label) . '</td><td dir="ltr"><a href="' . esc_url($u) . '" target="_blank" rel="noopener">' . esc_html($u) . '</a></td></tr>';
        }
        echo '</tbody></table><p>صفحة كل مزوّد: <code dir="ltr">' . esc_html(home_url('/provider-name/')) . '</code> — تُدار المنصة بالكامل من لوحة الإدارة أعلاه.</p>';

        echo '<h2>الخيارات</h2><form method="post" action="' . $post . '"><input type="hidden" name="action" value="queuepro_options">';
        wp_nonce_field('queuepro_options');
        echo '<table class="form-table"><tbody>'
            . self::row('الصفحة الرئيسية', '<label><input type="checkbox" name="front_page" value="1"' . checked(get_option(self::OPT_FRONT, '1'), '1', false) . '> اعرض واجهة المنصة على الصفحة الرئيسية للموقع (ألغِ التحديد لإبقاء صفحة ووردبريس الرئيسية).</label>')
            . self::row('Proxy / Cloudflare', '<label><input type="checkbox" name="trust_proxy" value="1"' . checked((bool) get_option(self::OPT_PROXY), true, false) . '> الموقع خلف Cloudflare أو Proxy (لحساب IP الزائر الحقيقي).</label>')
            . '</tbody></table>';
        submit_button('حفظ');
        echo '</form>';

        echo '<h2>استعادة دخول المدير العام</h2><p>إذا نُسيت كلمة مرور حساب Super Admin: أدخل اسم المستخدم وكلمة مرور جديدة.</p>';
        echo '<form method="post" action="' . $post . '"><input type="hidden" name="action" value="queuepro_reset">';
        wp_nonce_field('queuepro_reset');
        echo '<table class="form-table"><tbody>'
            . self::row('اسم المستخدم', '<input name="username" class="regular-text" dir="ltr" required>')
            . self::row('كلمة المرور الجديدة (10+)', '<input name="password" type="password" class="regular-text" dir="ltr" required minlength="10" autocomplete="new-password">')
            . '</tbody></table>';
        submit_button('تغيير كلمة المرور', 'secondary');
        echo '</form>';

        echo '<h2>البيانات والنسخ الاحتياطي</h2><ul style="list-style:disc;padding-inline-start:20px">'
            . '<li>جداول المنصة (<code>qp_*</code>) داخل قاعدة بيانات ووردبريس نفسها، فأي نسخة احتياطية لقاعدة البيانات تشملها.</li>'
            . '<li>الصور في <code dir="ltr">wp-content/uploads/queuepro</code>، والملفات الخاصة في <code dir="ltr">wp-content/queuepro-data</code>.</li>'
            . '<li>من لوحة الإدارة ← الإعدادات ← "تنزيل نسخة احتياطية" (ملف JSON).</li>'
            . '<li>حذف الإضافة لا يحذف أي بيانات.</li></ul></div>';
    }

    private static function row(string $label, string $field): string
    {
        return '<tr><th scope="row">' . esc_html($label) . '</th><td>' . $field . '</td></tr>';
    }

    private static function back(string $msg, array $errors = []): void
    {
        if ($errors) {
            set_transient('queuepro_errors_' . get_current_user_id(), $errors, 120);
        }
        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE . ($msg ? '&qp_msg=' . $msg : '')));
        exit;
    }

    public static function handleSetup(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden', 403);
        }
        check_admin_referer('queuepro_setup');
        if (self::installed()) {
            self::back('');
        }
        $in = wp_unslash($_POST);
        $errors = Installer::validateAdmin($in);
        if (!$errors) {
            self::prepareFolders();
            $errors = Installer::provision(self::dbConfig(), $in);
        }
        if ($errors) {
            self::back('', $errors);
        }
        update_option(self::OPT_INSTALLED, gmdate('c'));
        self::secret();
        if (!wp_next_scheduled(self::CRON)) {
            wp_schedule_event(time() + 300, 'queuepro_5min', self::CRON);
        }
        self::back('installed');
    }

    public static function handleOptions(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden', 403);
        }
        check_admin_referer('queuepro_options');
        update_option(self::OPT_FRONT, empty($_POST['front_page']) ? '0' : '1');
        update_option(self::OPT_PROXY, empty($_POST['trust_proxy']) ? '0' : '1');
        self::back('saved');
    }

    /** Super Admin password reset by a WordPress administrator (replaces storage/recover.txt). */
    public static function handleReset(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden', 403);
        }
        check_admin_referer('queuepro_reset');
        $in = wp_unslash($_POST);
        $pass = (string) ($in['password'] ?? '');
        if (mb_strlen($pass) < UserService::MIN_PASSWORD) {
            self::back('', ['كلمة المرور 10 أحرف على الأقل / Password must be at least 10 characters']);
        }
        try {
            $c = new Container(new Connection(self::dbConfig()));
            $u = $c->users->findByLogin(trim((string) ($in['username'] ?? '')));
            if (!$u || $u['role'] !== 'admin') {
                self::back('', ['لا يوجد مدير بهذا الاسم / No Super Admin with this username']);
            }
            $c->users->setPassword((int) $u['id'], $pass);
            $c->users->setActive((int) $u['id'], true);
            $c->audit->log(new Actor('system'), 'user.password_recovered', 'user', (int) $u['id'], ['by' => 'wp-admin:' . wp_get_current_user()->user_login]);
        } catch (QueueException $e) {
            self::back('', [$e->errorCode()]);
        }
        self::back('reset');
    }
}
