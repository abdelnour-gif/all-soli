<?php
namespace QueuePro\Services;

use QueuePro\Database\Connection;
use QueuePro\Database\DbException;
use QueuePro\Support\QueueException;

defined('QP_APP') || exit;

/**
 * Login accounts (Super Admins and Providers). Customers never have accounts.
 * Passwords: password_hash() (bcrypt/argon2 — PHP default), rehashed on login when PHP upgrades the default.
 * session_version is bumped on every password change → all other sessions are signed out.
 */
final class UserService
{
    public const MIN_PASSWORD = 10;

    private Connection $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    public function find(int $id): ?array
    {
        return $this->db->getRow('SELECT * FROM qp_users WHERE id = %d', [$id]);
    }

    public function findByLogin(string $login): ?array
    {
        $login = trim($login);
        if ($login === '') {
            return null;
        }
        return $this->db->getRow('SELECT * FROM qp_users WHERE LOWER(username) = LOWER(%s) OR LOWER(email) = LOWER(%s) LIMIT 1', [$login, $login]);
    }

    public static function generatePassword(int $len = 12): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $out;
    }

    public static function validUsername(string $u): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{2,59}$/', $u);
    }

    /** @return array{id:int,password:string} */
    public function create(string $username, ?string $email, string $role, ?string $displayName = null, ?string $password = null): array
    {
        $username = trim($username);
        $email = $email !== null && trim($email) !== '' ? strtolower(trim($email)) : null;
        if (!self::validUsername($username)) {
            throw new QueueException('invalid_input', 422, ['field' => 'username']);
        }
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new QueueException('invalid_input', 422, ['field' => 'email']);
        }
        if (!in_array($role, ['admin', 'provider'], true)) {
            throw new QueueException('invalid_input', 422, ['field' => 'role']);
        }
        $password = $password ?? self::generatePassword();
        $this->assertStrong($password);
        if ($this->findByLogin($username) || ($email && $this->findByLogin($email))) {
            throw new QueueException('user_exists', 409);
        }
        $now = gmdate('Y-m-d H:i:s');
        try {
            $id = $this->db->insert('qp_users', [
                'username' => $username, 'email' => $email, 'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role' => $role, 'display_name' => $displayName ? mb_substr(strip_tags($displayName), 0, 120) : null,
                'is_active' => 1, 'session_version' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        } catch (DbException $e) {
            if ($e->isDuplicate()) {
                throw new QueueException('user_exists', 409);
            }
            throw $e;
        }
        return ['id' => $id, 'password' => $password];
    }

    public function assertStrong(string $password): void
    {
        if (mb_strlen($password) < self::MIN_PASSWORD || mb_strlen($password) > 200) {
            throw new QueueException('weak_password', 422);
        }
    }

    public function setPassword(int $userId, string $password): void
    {
        $this->assertStrong($password);
        $this->db->query(
            'UPDATE qp_users SET password_hash = %s, session_version = session_version + 1, updated_at = %s WHERE id = %d',
            [password_hash($password, PASSWORD_DEFAULT), gmdate('Y-m-d H:i:s'), $userId]
        );
    }

    /** Verifies credentials. Constant-time-ish even for unknown users. */
    public function verify(string $login, string $password): ?array
    {
        $u = $this->findByLogin($login);
        if (!$u) {
            static $dummy = null;
            $dummy = $dummy ?? password_hash('qp-dummy-password', PASSWORD_DEFAULT);
            password_verify($password, $dummy); // same cost as a real check → no user enumeration by timing
            return null;
        }
        if (!(int) $u['is_active'] || !password_verify($password, $u['password_hash'])) {
            return null;
        }
        $set = ['last_login_at' => gmdate('Y-m-d H:i:s')];
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
            $set['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        }
        $this->db->update('qp_users', $set, ['id' => (int) $u['id']]);
        return $this->find((int) $u['id']);
    }

    public function setActive(int $userId, bool $active): void
    {
        $this->db->query('UPDATE qp_users SET is_active = %d, session_version = session_version + 1, updated_at = %s WHERE id = %d', [$active ? 1 : 0, gmdate('Y-m-d H:i:s'), $userId]);
    }

    public function admins(): array
    {
        return $this->db->getResults("SELECT id, username, email, display_name, is_active, last_login_at, created_at FROM qp_users WHERE role = 'admin' ORDER BY id");
    }
}
