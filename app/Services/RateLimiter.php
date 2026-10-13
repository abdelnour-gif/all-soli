<?php
namespace QueuePro\Services;

use QueuePro\Database\Connection;
use QueuePro\Support\Clock;
use QueuePro\Support\QueueException;
use QueuePro\Support\Security;

defined('QP_APP') || exit;

/** Fixed-window counter stored in the DB (works on shared hosting, no Redis needed). */
final class RateLimiter
{
    private Connection $db;
    private Clock $clock;

    public function __construct(Connection $db, Clock $clock)
    {
        $this->db = $db;
        $this->clock = $clock;
    }

    /** Throws rate_limited when the bucket is over $max hits inside $windowSeconds. */
    public function hit(string $bucket, int $max, int $windowSeconds): void
    {
        if ($max <= 0) {
            return;
        }
        if ($this->increment($bucket, $windowSeconds) > $max) {
            throw new QueueException('rate_limited', 429);
        }
    }

    /** Counts one event without enforcing a limit (e.g. a booking that succeeded). */
    public function add(string $bucket, int $windowSeconds): void
    {
        $this->increment($bucket, $windowSeconds);
    }

    /** @return int hits in the current window, including this one */
    private function increment(string $bucket, int $windowSeconds): int
    {
        $key = Security::hashKey($bucket);
        $now = $this->clock->utcString();
        $table = $this->db->table('rate_limits');
        // Atomic upsert: reset the window if it has expired, otherwise increment.
        // Increment and read back inside one transaction, so each request sees its own
        // count (not a later one) when many arrive at the same instant.
        $cut = $this->clock->nowUtc()->modify('-' . $windowSeconds . ' seconds')->format('Y-m-d H:i:s');
        return $this->db->transaction(function () use ($table, $key, $now, $cut) {
            $this->db->query(
                "INSERT INTO $table (bucket, hits, window_start) VALUES (%s, 1, %s)"
                . $this->db->onConflict(['bucket'], "hits = CASE WHEN window_start < %s THEN 1 ELSE hits + 1 END,
                   window_start = CASE WHEN window_start < %s THEN {new:window_start} ELSE window_start END"),
                [$key, $now, $cut, $cut]
            );
            return (int) $this->db->getVar("SELECT hits FROM $table WHERE bucket = %s", [$key]);
        });
    }

    /** Current hits in the active window (without counting a new one). */
    public function peek(string $bucket, int $windowSeconds): int
    {
        $row = $this->db->getRow("SELECT hits, window_start FROM {$this->db->table('rate_limits')} WHERE bucket = %s", [Security::hashKey($bucket)]);
        if (!$row) {
            return 0;
        }
        $cut = $this->clock->nowUtc()->modify('-' . $windowSeconds . ' seconds')->format('Y-m-d H:i:s');
        return $row['window_start'] < $cut ? 0 : (int) $row['hits'];
    }

    /** Throws rate_limited when $bucket already reached $max (does not count this attempt). */
    public function guard(string $bucket, int $max, int $windowSeconds): void
    {
        if ($this->peek($bucket, $windowSeconds) >= $max) {
            throw new QueueException('rate_limited', 429);
        }
    }

    public function purgeOld(): void
    {
        $this->db->query(
            "DELETE FROM {$this->db->table('rate_limits')} WHERE window_start < %s",
            [$this->clock->nowUtc()->modify('-2 days')->format('Y-m-d H:i:s')]
        );
    }
}
