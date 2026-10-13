<?php
namespace QueuePro\Support;

defined('QP_APP') || exit;

/** Who is performing an action. Passed explicitly into every service call. */
final class Actor
{
    public const CUSTOMER = 'customer';
    public const PROVIDER = 'provider';
    public const ADMIN    = 'admin';
    public const SYSTEM   = 'system';

    public string $type;
    public ?int $userId;
    /** Provider the actor owns (only for type=provider). */
    public ?int $providerId;
    public ?string $ipHash;

    public function __construct(string $type, ?int $userId = null, ?int $providerId = null, ?string $ipHash = null)
    {
        $this->type = $type;
        $this->userId = $userId;
        $this->providerId = $providerId;
        $this->ipHash = $ipHash;
    }

    public static function customer(?string $ipHash = null): self
    {
        return new self(self::CUSTOMER, null, null, $ipHash);
    }

    public static function provider(int $userId, int $providerId, ?string $ipHash = null): self
    {
        return new self(self::PROVIDER, $userId, $providerId, $ipHash);
    }

    public static function admin(int $userId, ?string $ipHash = null): self
    {
        return new self(self::ADMIN, $userId, null, $ipHash);
    }

    public static function system(): self
    {
        return new self(self::SYSTEM);
    }

    public function isAdmin(): bool
    {
        return $this->type === self::ADMIN;
    }
}
