<?php
namespace QueuePro\Notifications;

defined('QP_APP') || exit;

interface Driver
{
    /** $to is E.164 (+213…); $channel is "sms" or "whatsapp". */
    public function send(string $to, string $message, string $channel): SendResult;

    /** False for the log driver (nothing actually leaves the server). */
    public function delivers(): bool;
}
