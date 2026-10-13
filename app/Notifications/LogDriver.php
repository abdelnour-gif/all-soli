<?php
namespace QueuePro\Notifications;

defined('QP_APP') || exit;

/** Default: nothing is sent; messages stay visible in Super Admin → Messages (for testing before a gateway is set up). */
final class LogDriver implements Driver
{
    public function send(string $to, string $message, string $channel): SendResult
    {
        return SendResult::ok('log', 'log mode — not delivered');
    }

    public function delivers(): bool
    {
        return false;
    }
}
