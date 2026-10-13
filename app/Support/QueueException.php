<?php
namespace QueuePro\Support;

defined('QP_APP') || exit;

/** Business-rule violation. $errorCode is stable and maps to a bilingual message. */
class QueueException extends \RuntimeException
{
    private string $errorCode;
    private int $httpStatus;
    private array $context;

    public function __construct(string $errorCode, int $httpStatus = 400, array $context = [])
    {
        parent::__construct(Messages::get($errorCode, 'en'));
        $this->errorCode = $errorCode;
        $this->httpStatus = $httpStatus;
        $this->context = $context;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function context(): array
    {
        return $this->context;
    }
}
