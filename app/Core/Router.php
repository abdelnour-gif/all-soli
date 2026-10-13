<?php
namespace QueuePro\Core;

use QueuePro\Http\ApiController;
use QueuePro\Support\QueueException;
use QueuePro\Support\Security;

defined('QP_APP') || exit;

final class Router
{
    public static function dispatch(Request $r): void
    {
        $path = $r->path;
        $m = $r->method === 'HEAD' ? 'GET' : $r->method;

        // Internal folders are never served (second line of defence after .htaccess).
        if (preg_match('#^/(storage|app|bin|tests|templates|vendor)(/|$)#i', $path) || preg_match('#/\.#', $path)) {
            App::errorPage(404);
            return;
        }
        if (strpos($path, '/api/') === 0 || $path === '/api') {
            (new ApiController(App::container(), App::auth()))->dispatch($r, substr($path, 4) ?: '/');
            return;
        }

        $page = null;
        $vars = [];
        if ($path === '/' && $m === 'GET') {
            $page = 'home';
        } elseif (preg_match('#^/explore/?$#', $path) && $m === 'GET') {
            $page = 'explore';
        } elseif (preg_match('#^/t/([A-Za-z0-9_-]{16,64})/?$#', $path, $mm) && $m === 'GET') {
            $page = 'ticket';
            $vars['token'] = $mm[1];
        } elseif (preg_match('#^/p/([a-z0-9-]{3,60})/?$#', $path, $mm) && $m === 'GET') {
            self::redirect(provider_url($mm[1]), 301); // old /p/ links keep working
            return;
        } elseif (preg_match('#^/dashboard/?$#', $path)) {
            self::loginPost($r, '/dashboard/');
            $page = 'dashboard';
        } elseif (preg_match('#^/super-admin/?$#', $path)) {
            self::loginPost($r, '/super-admin/');
            $page = 'super-admin';
        } elseif (preg_match('#^/logout/?$#', $path)) {
            self::logout($r);
            return;
        } elseif (preg_match('#^/recover-admin/?$#', $path)) {
            RecoverAdmin::handle($r);
            return;
        } elseif ($path === '/robots.txt') {
            header('Content-Type: text/plain; charset=utf-8');
            echo "User-agent: *\nDisallow: /t/\nDisallow: /dashboard/\nDisallow: /super-admin/\nDisallow: /api/\n";
            return;
        } elseif (preg_match('#^/([a-z0-9][a-z0-9-]{1,58}[a-z0-9])/?$#', $path, $mm) && $m === 'GET') {
            $page = 'provider';
            $vars['slug'] = $mm[1];
        }

        if (!$page) {
            App::errorPage(404);
            return;
        }
        if (in_array($page, ['ticket', 'dashboard', 'super-admin'], true)) {
            header('Cache-Control: no-store, private');
            header('X-Robots-Tag: noindex, nofollow');
        }
        if ($page === 'ticket') {
            header('Referrer-Policy: no-referrer'); // keep the secret link out of Referer headers
        }
        self::render($page, $vars);
    }

    public static function render(string $page, array $vars = []): void
    {
        header('Content-Type: text/html; charset=utf-8');
        extract($vars, EXTR_SKIP);
        require QP_ROOT . '/templates/' . $page . '.php';
    }

    public static function redirect(string $to, int $code = 303): void
    {
        header('Location: ' . $to, true, $code);
    }

    /** Login form POST on /dashboard/ and /super-admin/ (Post/Redirect/Get). */
    private static function loginPost(Request $r, string $back): void
    {
        if ($r->method !== 'POST' || empty($r->post['qp_login'])) {
            return;
        }
        $auth = App::auth();
        if (!$auth->checkCsrf($r->post['_csrf'] ?? null)) {
            $GLOBALS['qp_login_error'] = 'csrf';
            return;
        }
        try {
            $auth->login((string) ($r->post['log'] ?? ''), (string) ($r->post['pwd'] ?? ''), Security::hashIp($r->ip(App::trustProxy())));
            self::redirect(url($back));
            exit;
        } catch (QueueException $e) {
            $GLOBALS['qp_login_error'] = $e->errorCode();
        }
    }

    private static function logout(Request $r): void
    {
        $to = ($r->query['to'] ?? '') === 'admin' ? '/super-admin/' : '/dashboard/';
        if (App::auth()->checkCsrf($r->query['t'] ?? null)) {
            App::auth()->logout();
        }
        self::redirect(url($to));
    }
}
