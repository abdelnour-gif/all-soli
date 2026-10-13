<?php
namespace QueuePro\Services;

use QueuePro\Database\Connection;
use QueuePro\Support\Actor;
use QueuePro\Support\QueueException;

defined('QP_APP') || exit;

/** Platform-wide policy switches, editable by Super Admin (every change is audited). */
final class Settings
{
    public const DEFAULTS = [
        'free_grant_credits'       => 10,
        'low_credit_threshold'     => 5,
        // Credit policy: what consumes a credit. Only "complete" by default.
        'consume_on_no_show'       => 0,
        'consume_on_cancel'        => 0,
        // Anti-abuse for account-less booking (see QueueService::join).
        'max_joins_per_ip_window'  => 6,   // bookings from one network before an SMS code is asked (when SMS works)
        'max_joins_per_ip_hard'    => 30,  // absolute cap per network per window
        'max_joins_per_device_window' => 3, // bookings from one phone / browser per window
        'ip_window_minutes'        => 15,
        'max_joins_per_phone_day'  => 6,
        'poll_interval_seconds'    => 7,
        // Phase 5: verification & notifications
        'require_otp'              => 0,   // customers must confirm their phone with an SMS code
        'notify_on_join'           => 0,   // SMS with the ticket link right after booking
        'notify_on_call'           => 1,   // "it's your turn"
        'notify_near_ahead'        => 2,   // "almost your turn" when N people are left (0 = off)
        'otp_ttl_minutes'          => 5,
        'rating_window_days'       => 7,
        'take_homepage'            => 1,   // platform home replaces the theme's front page
    ];

    /** Free-text settings (gateway config, branding). Keys ending in _secret are never returned in full. */
    public const STRING_DEFAULTS = [
        'sms_driver'        => 'log',      // log | http | twilio
        'sms_channel'       => 'sms',      // sms | whatsapp (twilio)
        'sms_sender'        => 'QueuePro',
        'sms_http_url'      => '',
        'sms_http_method'   => 'POST',
        'sms_http_body'     => '{"to":"{to}","message":"{message}","sender":"{sender}"}',
        'sms_http_headers'  => 'Content-Type: application/json',
        'sms_http_secret'   => '',
        'twilio_sid'        => '',
        'twilio_from'       => '',
        'twilio_secret'     => '',
        'brand_name'        => 'QueuePro',
        'support_phone'     => '',
    ];

    private Connection $db;
    private ?array $cache = null;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    public function all(): array
    {
        if ($this->cache === null) {
            $rows = $this->db->getResults("SELECT setting_key, setting_value FROM {$this->db->table('settings')}");
            $this->cache = self::DEFAULTS + self::STRING_DEFAULTS;
            foreach ($rows as $r) {
                $this->cache[$r['setting_key']] = $r['setting_value'];
            }
        }
        return $this->cache;
    }

    public function int(string $key): int
    {
        return (int) ($this->all()[$key] ?? 0);
    }

    public function bool(string $key): bool
    {
        return $this->int($key) === 1;
    }

    public function string(string $key): string
    {
        return (string) ($this->all()[$key] ?? '');
    }

    /** All settings with secrets masked (safe to send to the admin UI). */
    public function forDisplay(): array
    {
        $out = $this->all();
        foreach ($out as $k => $v) {
            if (substr($k, -7) === '_secret') {
                $out[$k] = $v === '' ? '' : '••••' . substr((string) $v, -4);
            }
        }
        return $out;
    }

    public function set(string $key, $value, Actor $actor, AuditLogger $audit): void
    {
        $isString = array_key_exists($key, self::STRING_DEFAULTS);
        if (!$isString && !array_key_exists($key, self::DEFAULTS)) {
            throw new QueueException('invalid_input', 422, ['field' => $key]);
        }
        if ($isString) {
            $value = trim(str_replace("\0", '', (string) $value));
            if (mb_strlen($value) > 1000) {
                throw new QueueException('invalid_input', 422, ['field' => $key]);
            }
            if (substr($key, -7) === '_secret' && strpos($value, '••••') === 0) {
                return; // masked value sent back unchanged → keep the stored secret
            }
            $enums = ['sms_driver' => ['log', 'http', 'twilio'], 'sms_channel' => ['sms', 'whatsapp'], 'sms_http_method' => ['POST', 'GET']];
            if (isset($enums[$key]) && !in_array($value, $enums[$key], true)) {
                throw new QueueException('invalid_input', 422, ['field' => $key]);
            }
            if ($key === 'sms_http_url' && $value !== '' && !preg_match('#^https://#i', $value)) {
                throw new QueueException('invalid_input', 422, ['field' => $key]);
            }
        } else {
            if (!is_numeric($value) || (int) $value < 0) {
                throw new QueueException('invalid_input', 422, ['field' => $key]);
            }
            $value = (string) (int) $value;
        }
        $old = $this->all()[$key] ?? null;
        if ((string) $old === (string) $value) {
            return;
        }
        $this->db->query(
            "INSERT INTO {$this->db->table('settings')} (setting_key, setting_value, updated_at) VALUES (%s,%s,%s)"
            . $this->db->onConflict(['setting_key'], 'setting_value = {new:setting_value}, updated_at = {new:updated_at}'),
            [$key, $value, gmdate('Y-m-d H:i:s')]
        );
        $this->cache = null;
        $secret = substr($key, -7) === '_secret';
        $audit->log($actor, 'settings.update', 'setting', null, ['key' => $key, 'old' => $secret ? '***' : $old, 'new' => $secret ? '***' : $value]);
    }
}
