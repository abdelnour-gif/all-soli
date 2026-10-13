<?php
namespace QueuePro\Core;

use QueuePro\Services\RateLimiter;
use QueuePro\Services\UserService;
use QueuePro\Support\QueueException;

defined('QP_APP') || exit;

/**
 * Session-based login for Super Admins and Providers.
 *  - Sessions start lazily (customers polling their ticket never get a session).
 *  - Cookie: HttpOnly, SameSite=Lax, Secure on HTTPS; strict mode; id regenerated on login.
 *  - Brute force: 10 failed attempts / 15 min per IP, 6 per username.
 *  - Password change bumps session_version → every other device is signed out.
 *  - CSRF: one random token per session, required on every state-changing request.
 */
final class Auth
{
    private const COOKIE = 'qp_session';
    private const LIFETIME = 2592000; // 30 days

    private UserService $users;
    private RateLimiter $limiter;
    private bool $secure;
    private string $cookiePath;
    private string $saveDir;
    private ?array $user = null;
    private bool $loaded = false;

    public function __construct(UserService $users, RateLimiter $limiter, bool $secure, string $cookiePath, string $saveDir)
    {
        $this->users = $users;
        $this->limiter = $limiter;
        $this->secure = $secure;
        $this->cookiePath = $cookiePath ?: '/';
        $this->saveDir = $saveDir;
    }

    private function start(bool $create): bool
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return true;
        }
        if (!$create && empty($_COOKIE[self::COOKIE])) {
            return false;
        }
        if (headers_sent()) {
            return false;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) self::LIFETIME);
        if (ini_get('session.save_handler') === 'files' && is_dir($this->saveDir) && is_writable($this->saveDir)) {
            session_save_path($this->saveDir);
        }
        session_name(self::COOKIE);
        session_set_cookie_params([
            'lifetime' => self::LIFETIME, 'path' => $this->cookiePath, 'secure' => $this->secure,
            'httponly' => true, 'samesite' => 'Lax',
        ]);
        return session_start();
    }

    public function user(): ?array
    {
        if ($this->loaded) {
            return $this->user;
        }
        $this->loaded = true;
        if (!$this->start(false) || empty($_SESSION['uid'])) {
            return null;
        }
        $u = $this->users->find((int) $_SESSION['uid']);
        if (!$u || !(int) $u['is_active'] || (int) $u['session_version'] !== (int) ($_SESSION['ver'] ?? -1)) {
            $_SESSION = [];
            return null;
        }
        return $this->user = $u;
    }

    public function isAdmin(): bool
    {
        return ($this->user()['role'] ?? null) === 'admin';
    }

    public function isProvider(): bool
    {
        return ($this->user()['role'] ?? null) === 'provider';
    }

    public function login(string $login, string $password, ?string $ipHash): array
    {
        $ipKey = 'login-ip:' . ($ipHash ?? 'none');
        $userKey = 'login-user:' . mb_strtolower(trim($login));
        // Only failed attempts count: 10 per IP and 6 per username every 15 minutes.
        $this->limiter->guard($ipKey, 10, 900);
        $this->limiter->guard($userKey, 6, 900);
        $u = $this->users->verify($login, $password);
        if (!$u) {
            $this->limiter->hit($ipKey, 1000, 900);
            $this->limiter->hit($userKey, 1000, 900);
            throw new QueueException('login_failed', 401);
        }
        $this->start(true);
        session_regenerate_id(true);
        $_SESSION = ['uid' => (int) $u['id'], 'ver' => (int) $u['session_version'], 'csrf' => bin2hex(random_bytes(32)), 'at' => time()];
        $this->user = $u;
        $this->loaded = true;
        return $u;
    }

    /** Re-sync the session after the user changed their own password. */
    public function refresh(): void
    {
        if ($this->start(false) && !empty($_SESSION['uid'])) {
            $u = $this->users->find((int) $_SESSION['uid']);
            if ($u) {
                session_regenerate_id(true);
                $_SESSION['ver'] = (int) $u['session_version'];
                $this->user = $u;
            }
        }
    }

    public function logout(): void
    {
        if ($this->start(false)) {
            $_SESSION = [];
            session_destroy();
        }
        if (!headers_sent()) {
            setcookie(self::COOKIE, '', ['expires' => time() - 3600, 'path' => $this->cookiePath, 'secure' => $this->secure, 'httponly' => true, 'samesite' => 'Lax']);
        }
        $this->user = null;
    }

    public function csrfToken(): string
    {
        $this->start(true);
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['csrf'];
    }

    public function checkCsrf(?string $token): bool
    {
        if (!$this->start(false) || empty($_SESSION['csrf']) || !$token) {
            return false;
        }
        return hash_equals((string) $_SESSION['csrf'], (string) $token);
    }

    /** Release the session lock early so parallel requests (polling) are not serialised. */
    public function release(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }
}
