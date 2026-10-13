<?php
namespace QueuePro\Database;

defined('QP_APP') || exit;

class DbException extends \RuntimeException
{
    private int $errno;

    public function __construct(string $message, int $errno)
    {
        parent::__construct($message, $errno);
        $this->errno = $errno;
    }

    public function errno(): int
    {
        return $this->errno;
    }

    public function isDuplicate(): bool
    {
        return $this->errno === Connection::ERR_DUPLICATE;
    }
}
