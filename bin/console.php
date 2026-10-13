<?php
/**
 * QueuePro command line tools (for hosting with SSH or a cron panel).
 *
 *   php bin/console.php cron                         run maintenance now (end-of-day closing, send SMS, cleanup)
 *   php bin/console.php check                        environment + data integrity check
 *   php bin/console.php backup [file]                write a backup (SQLite: .sqlite copy, MySQL: .json export)
 *   php bin/console.php create-admin <user> [email]  create another Super Admin (prints the password)
 *   php bin/console.php reset-password <user>        new random password for any account
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('QP_APP', true);
define('QP_ROOT', dirname(__DIR__));
define('QP_VERSION', '2.0.0');
require QP_ROOT . '/app/autoload.php';
require QP_ROOT . '/app/helpers.php';

use QueuePro\Core\App;
use QueuePro\Core\Maintenance;
use QueuePro\Services\UserService;

if (!App::installed()) {
    fwrite(STDERR, "Not installed yet: open the site in a browser to run the installer.\n");
    exit(1);
}
App::boot(require App::configPath());
$c = App::container();
$cmd = $argv[1] ?? 'help';

try {
switch ($cmd) {
    case 'cron':
        $out = Maintenance::run($c, true);
        echo json_encode($out) . "\n";
        break;

    case 'check':
        $db = $c->db;
        echo "QueuePro " . QP_VERSION . " | PHP " . PHP_VERSION . " | " . $db->driver() . ' ' . $db->version() . "\n";
        $bad = $db->getResults(
            "SELECT a.provider_id FROM qp_credit_accounts a
             WHERE a.reserved <> (SELECT COUNT(*) FROM qp_tickets t WHERE t.provider_id = a.provider_id AND t.credit_state = 'reserved')
                OR a.consumed <> (SELECT COUNT(*) FROM qp_tickets t WHERE t.provider_id = a.provider_id AND t.credit_state = 'consumed')"
        );
        echo $bad ? "Credit books: MISMATCH for providers " . implode(',', array_column($bad, 'provider_id')) . "\n" : "Credit books: OK\n";
        if ($db->isSqlite()) {
            echo 'SQLite integrity: ' . $db->getVar('PRAGMA integrity_check') . "\n";
        }
        foreach (['storage', 'storage/cache', 'storage/logs', 'storage/sessions', 'uploads'] as $d) {
            echo str_pad($d, 20) . (is_writable(QP_ROOT . '/' . $d) ? 'writable' : 'NOT WRITABLE') . "\n";
        }
        break;

    case 'backup':
        $file = $argv[2] ?? QP_ROOT . '/storage/backups/manual-' . gmdate('Ymd-His') . ($c->db->isSqlite() ? '.sqlite' : '.json');
        if ($c->db->isSqlite()) {
            $c->db->exec("VACUUM INTO '" . str_replace("'", "''", $file) . "'");
        } else {
            $out = [];
            foreach (\QueuePro\Database\Schema::TABLES as $t) {
                $out[$t] = $c->db->getResults('SELECT * FROM ' . $t);
            }
            file_put_contents($file, json_encode(['version' => QP_VERSION, 'tables' => $out], JSON_UNESCAPED_UNICODE));
        }
        echo "Backup written: $file (" . filesize($file) . " bytes)\n";
        break;

    case 'create-admin':
        $user = $argv[2] ?? '';
        $acc = $c->users->create($user, $argv[3] ?? null, 'admin', $user);
        echo "Admin '$user' created. Password: {$acc['password']}\n";
        break;

    case 'reset-password':
        $u = $c->users->findByLogin($argv[2] ?? '');
        if (!$u) {
            fwrite(STDERR, "No such user.\n");
            exit(1);
        }
        $pass = UserService::generatePassword();
        $c->users->setPassword((int) $u['id'], $pass);
        $c->users->setActive((int) $u['id'], true);
        echo "New password for {$u['username']}: $pass\n";
        break;

    default:
        $doc = file_get_contents(__FILE__);
        preg_match('#/\*\*(.*?)\*/#s', $doc, $m);
        echo preg_replace('/^\s*\* ?/m', '', $m[1]) . "\n";
}
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
