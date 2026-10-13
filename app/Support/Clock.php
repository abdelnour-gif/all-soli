<?php
namespace QueuePro\Support;

defined('QP_APP') || exit;

/**
 * All "today" logic goes through here so the queue date is always computed in the
 * provider's own timezone, and tests can move time forward.
 */
class Clock
{
    private ?\DateTimeImmutable $frozen = null;

    public function nowUtc(): \DateTimeImmutable
    {
        return $this->frozen ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function utcString(): string
    {
        return $this->nowUtc()->format('Y-m-d H:i:s');
    }

    public function localDate(string $timezone): string
    {
        try {
            $tz = new \DateTimeZone($timezone);
        } catch (\Exception $e) {
            $tz = new \DateTimeZone('UTC');
        }
        return $this->nowUtc()->setTimezone($tz)->format('Y-m-d');
    }

    /** Test helper. */
    public function freeze(string $utc): void
    {
        $this->frozen = new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
    }
}
