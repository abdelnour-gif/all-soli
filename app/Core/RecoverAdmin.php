<?php
namespace QueuePro\Core;

use QueuePro\Support\QueueException;
use QueuePro\Support\Security;

defined('QP_APP') || exit;

/**
 * Super Admin password recovery without e-mail.
 * Enabled only while the file storage/recover.txt exists. The owner creates it from the
 * hosting file manager and writes a code inside (proof of server access). After a
 * successful reset the file is deleted automatically.
 */
final class RecoverAdmin
{
    public static function handle(Request $r): void
    {
        $file = QP_STORAGE . '/recover.txt';
        if (!is_file($file)) {
            App::errorPage(404);
            return;
        }
        $code = trim((string) file_get_contents($file));
        $error = null;
        $done = false;
        if ($r->method === 'POST') {
            try {
                $c = App::container();
                $c->limiter->hit('recover-admin:' . (Security::hashIp($r->ip(App::trustProxy())) ?? ''), 5, 3600);
                if (!App::auth()->checkCsrf($r->post['_csrf'] ?? null)) {
                    throw new QueueException('csrf', 403);
                }
                if (strlen($code) < 8 || !hash_equals($code, trim((string) ($r->post['code'] ?? '')))) {
                    throw new QueueException('login_failed', 403);
                }
                $u = $c->users->findByLogin((string) ($r->post['username'] ?? ''));
                if (!$u || $u['role'] !== 'admin') {
                    throw new QueueException('login_failed', 403);
                }
                if ((string) ($r->post['password'] ?? '') !== (string) ($r->post['password2'] ?? '')) {
                    throw new QueueException('invalid_input', 422);
                }
                $c->users->setPassword((int) $u['id'], (string) ($r->post['password'] ?? ''));
                $c->users->setActive((int) $u['id'], true);
                $c->audit->log(new \QueuePro\Support\Actor('system'), 'user.password_recovered', 'user', (int) $u['id']);
                @unlink($file);
                $done = true;
            } catch (QueueException $e) {
                $error = $e->errorCode();
            }
        }
        header('Cache-Control: no-store');
        $codeTooShort = strlen($code) < 8;
        require QP_ROOT . '/templates/recover-admin.php';
    }
}
