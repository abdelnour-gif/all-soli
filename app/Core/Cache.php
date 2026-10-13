<?php
namespace QueuePro\Core;

defined('QP_APP') || exit;

/** Tiny TTL cache: APCu when available, otherwise files in storage/cache. */
final class Cache
{
    private static ?string $dir = null;
    private static ?bool $apcu = null;

    public static function init(string $dir): void
    {
        self::$dir = rtrim($dir, '/');
        self::$apcu = function_exists('apcu_fetch') && filter_var(ini_get('apc.enabled'), FILTER_VALIDATE_BOOLEAN) && PHP_SAPI !== 'cli';
    }

    private static function file(string $key): string
    {
        return self::$dir . '/' . hash('sha256', $key) . '.cache';
    }

    public static function get(string $key)
    {
        if (self::$apcu) {
            $v = apcu_fetch('qp:' . $key, $ok);
            return $ok ? $v : null;
        }
        $f = self::file($key);
        $raw = @file_get_contents($f);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || ($data['e'] ?? 0) < time()) {
            return null;
        }
        return $data['v'];
    }

    public static function set(string $key, $value, int $ttl): void
    {
        if (self::$apcu) {
            apcu_store('qp:' . $key, $value, $ttl);
            return;
        }
        $f = self::file($key);
        $tmp = $f . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, json_encode(['e' => time() + $ttl, 'v' => $value], JSON_UNESCAPED_UNICODE)) !== false) {
            @rename($tmp, $f);
        }
    }

    public static function delete(string $key): void
    {
        if (self::$apcu) {
            apcu_delete('qp:' . $key);
            return;
        }
        @unlink(self::file($key));
    }

    /** Remove expired cache files (called by maintenance). */
    public static function prune(): void
    {
        if (self::$apcu || !self::$dir) {
            return;
        }
        foreach (glob(self::$dir . '/*.cache') ?: [] as $f) {
            if (@filemtime($f) < time() - 3600) {
                @unlink($f);
            }
        }
    }
}
