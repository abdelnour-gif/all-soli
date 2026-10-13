<?php
namespace QueuePro\Notifications;

use QueuePro\Container;

defined('QP_APP') || exit;

/**
 * Sends queued outbox messages through the configured driver.
 * Runs at the end of the request that queued them (after the response is flushed)
 * and every 5 minutes from WP-Cron for retries. Each row is claimed atomically
 * (pending → sending) so two workers never send the same SMS twice.
 */
final class Dispatcher
{
    private Container $c;

    public function __construct(Container $c)
    {
        $this->c = $c;
    }

    public function driver(): Driver
    {
        $s = $this->c->settings;
        switch ($s->string('sms_driver')) {
            case 'http':
                return new HttpSmsDriver($s);
            case 'twilio':
                return new TwilioDriver($s);
            default:
                return new LogDriver();
        }
    }

    public function run(int $limit = 20): int
    {
        $db = $this->c->db;
        $t = $db->table('notifications');
        // Recover rows stuck in "sending" (worker died mid-flight).
        $db->query("UPDATE $t SET status = 'pending' WHERE status = 'sending' AND created_at < %s", [gmdate('Y-m-d H:i:s', time() - 600)]);

        $driver = $this->driver();
        $sent = 0;
        foreach ($db->getResults("SELECT * FROM $t WHERE status = 'pending' ORDER BY id ASC LIMIT %d", [$limit]) as $row) {
            if ($db->query("UPDATE $t SET status = 'sending', attempts = attempts + 1 WHERE id = %d AND status = 'pending'", [(int) $row['id']]) !== 1) {
                continue; // claimed by another worker
            }
            $result = $driver->send($row['to_phone'], $row['body'], $row['channel']);
            $attempts = (int) $row['attempts'] + 1;
            $set = $result->ok
                ? ['status' => 'sent', 'sent_at' => gmdate('Y-m-d H:i:s'), 'external_id' => $result->id, 'last_error' => $result->note]
                : ['status' => $attempts >= 3 ? 'failed' : 'pending', 'last_error' => mb_substr((string) $result->error, 0, 255)];
            if ($result->ok && $driver->delivers()) {
                $set['body'] = self::redact($row['type'], $row['body']);
            }
            $db->update($t, $set, ['id' => (int) $row['id']]);
            $sent += $result->ok ? 1 : 0;
        }
        return $sent;
    }

    /** Once delivered, secrets (OTP codes, ticket links) are removed from the stored copy. */
    public static function redact(string $type, string $body): string
    {
        if ($type === 'otp') {
            return preg_replace('/\d{4,8}/', '••••••', $body);
        }
        return preg_replace('#/t/[A-Za-z0-9_-]{16,64}/?#', '/t/••••/', $body);
    }

    /** Admin "send test message" button: bypasses the queue to show the gateway's answer immediately. */
    public function test(string $phone, string $message): SendResult
    {
        return $this->driver()->send($phone, $message, $this->c->settings->string('sms_channel') ?: 'sms');
    }
}
