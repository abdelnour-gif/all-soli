<?php
namespace QueuePro\Core;

use QueuePro\Database\Connection;
use QueuePro\Database\Schema;
use QueuePro\Database\Seeder;
use QueuePro\Services\UserService;
use QueuePro\Support\QueueException;

defined('QP_APP') || exit;

/**
 * First-run web installer. Active only while storage/config.php does not exist;
 * afterwards every request goes to the normal app and this code is unreachable.
 */
final class Installer
{
    public static function requirements(?string $driver = 'sqlite'): array
    {
        $sqliteVersion = null;
        if (extension_loaded('pdo_sqlite')) {
            try {
                $sqliteVersion = (string) (new \PDO('sqlite::memory:'))->query('select sqlite_version()')->fetchColumn();
            } catch (\Throwable $e) {
            }
        }
        $dirs = ['storage', 'storage/cache', 'storage/logs', 'storage/sessions', 'storage/backups', 'uploads'];
        foreach ($dirs as $d) {
            if (!is_dir(QP_ROOT . '/' . $d)) {
                @mkdir(QP_ROOT . '/' . $d, 0755, true);
            }
        }
        $req = [
            ['PHP 8.0+', version_compare(PHP_VERSION, '8.0.0', '>='), PHP_VERSION, true],
            ['PDO', extension_loaded('pdo'), '', true],
            ['SQLite 3.27+ (pdo_sqlite)', $sqliteVersion && version_compare($sqliteVersion, '3.27.0', '>='), $sqliteVersion ?: '—', $driver === 'sqlite'],
            ['MySQL (pdo_mysql)', extension_loaded('pdo_mysql'), '', $driver === 'mysql'],
            ['mbstring', extension_loaded('mbstring'), '', true],
            ['json', function_exists('json_encode'), '', true],
            ['GD (images)', function_exists('imagecreatefromstring'), '', false],
            ['cURL (SMS)', function_exists('curl_init'), '', false],
        ];
        foreach ($dirs as $d) {
            $req[] = ["$d/ writable", is_dir(QP_ROOT . '/' . $d) && is_writable(QP_ROOT . '/' . $d), '', true];
        }
        return $req;
    }

    public static function handle(Request $r): void
    {
        if (preg_match('#^/(storage|app|bin|tests|templates)(/|$)#i', $r->path)) {
            http_status(404);
            return;
        }
        if (!preg_match('#^/(install/?)?$#', $r->path)) {
            if (strpos($r->path, '/api/') === 0) {
                http_status(503);
                header('Content-Type: application/json');
                echo '{"code":"not_installed"}';
                return;
            }
            header('Location: ' . url('/install'), true, 302);
            return;
        }
        $errors = [];
        $old = $r->post;
        if ($r->method === 'POST') {
            if (empty($_COOKIE['qp_install']) || !hash_equals((string) $_COOKIE['qp_install'], (string) ($r->post['_t'] ?? ''))) {
                $errors[] = 'انتهت صلاحية الصفحة، أعد المحاولة. / Page expired, try again.';
            } else {
                $errors = self::install($r->post);
                if (!$errors) {
                    setcookie('qp_install', '', time() - 3600, '/');
                    header('Location: ' . url('/super-admin/?installed=1'), true, 303);
                    return;
                }
            }
        }
        $token = bin2hex(random_bytes(16));
        setcookie('qp_install', $token, ['expires' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict']);
        $driver = ($old['db_driver'] ?? 'sqlite') === 'mysql' ? 'mysql' : 'sqlite';
        $reqs = self::requirements($driver);
        require QP_ROOT . '/templates/install.php';
    }

    /** @return string[] errors (empty on success) */
    public static function install(array $in): array
    {
        $errors = [];
        $driver = ($in['db_driver'] ?? 'sqlite') === 'mysql' ? 'mysql' : 'sqlite';
        foreach (self::requirements($driver) as [$name, $ok, , $required]) {
            if ($required && !$ok) {
                $errors[] = "Requirement missing: $name";
            }
        }
        $brand = trim(strip_tags((string) ($in['brand_name'] ?? ''))) ?: 'QueuePro';
        $username = trim((string) ($in['username'] ?? ''));
        $email = trim((string) ($in['email'] ?? ''));
        $pass = (string) ($in['password'] ?? '');
        if (!UserService::validUsername($username)) {
            $errors[] = 'اسم المستخدم: 3 أحرف على الأقل (حروف إنجليزية وأرقام) / Username: 3+ letters or digits';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'البريد الإلكتروني غير صالح / Invalid e-mail';
        }
        if (mb_strlen($pass) < UserService::MIN_PASSWORD) {
            $errors[] = 'كلمة المرور 10 أحرف على الأقل / Password must be at least 10 characters';
        }
        if ($pass !== (string) ($in['password2'] ?? '')) {
            $errors[] = 'كلمتا المرور غير متطابقتين / Passwords do not match';
        }
        if ($errors) {
            return $errors;
        }

        $db = $driver === 'sqlite'
            ? ['driver' => 'sqlite', 'path' => QP_ROOT . '/storage/database.sqlite']
            : ['driver' => 'mysql', 'host' => trim((string) ($in['db_host'] ?? 'localhost')) ?: 'localhost', 'port' => (int) ($in['db_port'] ?? 3306) ?: 3306,
               'name' => trim((string) ($in['db_name'] ?? '')), 'user' => trim((string) ($in['db_user'] ?? '')), 'pass' => (string) ($in['db_pass'] ?? '')];
        $config = [
            'db' => $db,
            'url' => App::baseUrl(),
            'secret' => bin2hex(random_bytes(32)),
            'debug' => false,
            'trust_proxy' => !empty($in['trust_proxy']),
            'installed_at' => gmdate('c'),
        ];

        try {
            $conn = new Connection($db);
            Schema::install($conn);
            Seeder::run($conn);
            $conn->query(
                'INSERT INTO qp_settings (setting_key, setting_value, updated_at) VALUES (%s,%s,%s)'
                . $conn->onConflict(['setting_key'], 'setting_value = {new:setting_value}, updated_at = {new:updated_at}'),
                ['schema_version', Schema::VERSION, gmdate('Y-m-d H:i:s')]
            );
            foreach (['brand_name' => mb_substr($brand, 0, 60), 'support_phone' => mb_substr(trim(strip_tags((string) ($in['support_phone'] ?? ''))), 0, 30)] as $k => $v) {
                $conn->query(
                    'INSERT INTO qp_settings (setting_key, setting_value, updated_at) VALUES (%s,%s,%s)'
                    . $conn->onConflict(['setting_key'], 'setting_value = {new:setting_value}, updated_at = {new:updated_at}'),
                    [$k, $v, gmdate('Y-m-d H:i:s')]
                );
            }
            (new UserService($conn))->create($username, $email ?: null, 'admin', $username, $pass);
        } catch (QueueException $e) {
            return [$e->errorCode() === 'user_exists'
                ? 'يوجد حساب بهذا الاسم في قاعدة البيانات / An account with this name already exists in the database'
                : $e->getMessage()];
        } catch (\Throwable $e) {
            return ['Database error: ' . $e->getMessage()];
        }

        $php = "<?php\n// QueuePro configuration — generated by the installer on " . gmdate('Y-m-d H:i') . " UTC.\n"
            . "// Keep this file private. Changing 'secret' only invalidates pending SMS codes and rate-limit counters.\n"
            . 'return ' . var_export($config, true) . ";\n";
        $fh = @fopen(App::configPath(), 'x');
        if (!$fh) {
            return ['Cannot write storage/config.php (already installed, or folder not writable).'];
        }
        fwrite($fh, $php);
        fclose($fh);
        @chmod(App::configPath(), 0640);
        return [];
    }
}
