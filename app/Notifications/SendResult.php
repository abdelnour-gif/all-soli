<?php
namespace QueuePro\Notifications;

defined('QP_APP') || exit;

final class SendResult
{
    public bool $ok;
    public ?string $id;
    public ?string $error;
    public ?string $note;

    private function __construct(bool $ok, ?string $id, ?string $error, ?string $note)
    {
        $this->ok = $ok;
        $this->id = $id;
        $this->error = $error;
        $this->note = $note;
    }

    public static function ok(?string $id = null, ?string $note = null): self
    {
        return new self(true, $id, null, $note);
    }

    public static function fail(string $error): self
    {
        return new self(false, null, $error, null);
    }
}
