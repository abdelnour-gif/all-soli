<?php
namespace QueuePro\Services;

use QueuePro\Database\Connection;
use QueuePro\Support\Clock;
use QueuePro\Support\QueueException;
use QueuePro\Support\Security;

defined('QP_APP') || exit;

/**
 * One-time SMS codes for account-less customers.
 *  - 6 digits, stored as a keyed hash, valid N minutes (default 5)
 *  - 60 s cooldown per phone, 5 codes / hour / phone, 15 / hour / IP
 *  - max 5 wrong attempts per code, single use
 */
final class OtpService
{
    public const PURPOSES = ['join', 'recover'];

    private Connection $db;
    private Clock $clock;
    private Settings $settings;
    private RateLimiter $limiter;
    private NotificationService $notify;

    public function __construct(Connection $db, Clock $clock, Settings $settings, RateLimiter $limiter, NotificationService $notify)
    {
        $this->db = $db;
        $this->clock = $clock;
        $this->settings = $settings;
        $this->limiter = $limiter;
        $this->notify = $notify;
    }

    /** OTP needs a real gateway; with the "log" driver codes only appear in the admin outbox. */
    public function available(): bool
    {
        return $this->settings->string('sms_driver') !== 'log' || defined('QP_TESTING') || (defined('QP_OTP_LOG_MODE') && QP_OTP_LOG_MODE);
    }

    public function request(string $phone, string $purpose, string $lang = 'ar', ?string $ipHash = null): array
    {
        if (!in_array($purpose, self::PURPOSES, true)) {
            throw new QueueException('invalid_input', 422);
        }
        if (!$this->available()) {
            throw new QueueException('otp_unavailable', 409);
        }
        $t = $this->db->table('otp_codes');
        $last = $this->db->getRow("SELECT created_at FROM $t WHERE phone = %s ORDER BY id DESC LIMIT 1", [$phone]);
        if ($last && strtotime($last['created_at'] . ' UTC') > $this->clock->nowUtc()->getTimestamp() - 60) {
            throw new QueueException('otp_cooldown', 429, ['retry_in' => 60 - ($this->clock->nowUtc()->getTimestamp() - strtotime($last['created_at'] . ' UTC'))]);
        }
        $this->limiter->hit('otp-phone:' . $phone, 5, 3600);
        if ($ipHash) {
            $this->limiter->hit('otp-ip:' . $ipHash, 40, 3600); // shared networks (shop Wi-Fi) send many real customers
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $ttl = max(1, $this->settings->int('otp_ttl_minutes'));
        $now = $this->clock->nowUtc();
        $this->db->transaction(function () use ($t, $phone, $purpose, $code, $ttl, $now, $ipHash, $lang) {
            // Any older unused code for the same purpose is invalidated.
            $this->db->query("UPDATE $t SET consumed_at = %s WHERE phone = %s AND purpose = %s AND consumed_at IS NULL", [$now->format('Y-m-d H:i:s'), $phone, $purpose]);
            $this->db->insert($t, [
                'phone' => $phone, 'purpose' => $purpose, 'code_hash' => $this->hash($phone, $purpose, $code),
                'expires_at' => $now->modify("+$ttl minutes")->format('Y-m-d H:i:s'),
                'ip_hash' => $ipHash, 'created_at' => $now->format('Y-m-d H:i:s'),
            ]);
            $this->notify->queue('otp', $phone, $this->notify->render('otp', $lang, ['otp' => $code]));
        });
        return ['expires_in' => $ttl * 60, 'resend_in' => 60];
    }

    /** Throws on failure; consumes the code on success. */
    public function verify(string $phone, string $purpose, ?string $code): void
    {
        $code = preg_replace('/\D/', '', (string) $code);
        if ($code === '') {
            throw new QueueException('otp_required', 422);
        }
        $t = $this->db->table('otp_codes');
        // The transaction returns an outcome instead of throwing, so a failed attempt
        // counter is committed (a rollback would let attackers retry forever).
        $outcome = $this->db->transaction(function () use ($t, $phone, $purpose, $code) {
            $row = $this->db->getRow(
                "SELECT * FROM $t WHERE phone = %s AND purpose = %s AND consumed_at IS NULL ORDER BY id DESC LIMIT 1" . $this->db->forUpdate(),
                [$phone, $purpose]
            );
            if (!$row) {
                return 'otp_expired';
            }
            if (strtotime($row['expires_at'] . ' UTC') < $this->clock->nowUtc()->getTimestamp() || (int) $row['attempts'] >= 5) {
                $this->db->update($t, ['consumed_at' => $this->clock->utcString()], ['id' => (int) $row['id']]);
                return 'otp_expired';
            }
            if (!hash_equals($row['code_hash'], $this->hash($phone, $purpose, $code))) {
                $this->db->update($t, ['attempts' => (int) $row['attempts'] + 1], ['id' => (int) $row['id']]);
                return 'otp_invalid';
            }
            $this->db->update($t, ['consumed_at' => $this->clock->utcString()], ['id' => (int) $row['id']]);
            return 'ok';
        });
        if ($outcome !== 'ok') {
            throw new QueueException($outcome, 422);
        }
    }

    private function hash(string $phone, string $purpose, string $code): string
    {
        return Security::hashKey("otp|$phone|$purpose|$code");
    }

    public function purgeOld(): void
    {
        $this->db->query("DELETE FROM {$this->db->table('otp_codes')} WHERE created_at < %s", [$this->clock->nowUtc()->modify('-2 days')->format('Y-m-d H:i:s')]);
    }
}
