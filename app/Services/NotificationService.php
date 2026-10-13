<?php
namespace QueuePro\Services;

use QueuePro\Database\Connection;
use QueuePro\Support\Clock;

defined('QP_APP') || exit;

/**
 * Builds customer messages and queues them in the outbox (qp_notifications).
 * Queuing happens inside the business transaction, so a message exists if and only
 * if the event really happened. Sending is done after the response by the dispatcher
 * through the configured driver (log / HTTP SMS gateway / Twilio SMS or WhatsApp).
 */
final class NotificationService
{
    private const TEMPLATES = [
        'otp'    => ['رمز التحقق: {otp} — {brand}', 'Your {brand} code: {otp}'],
        'joined' => ['{provider}: رقمك {code}. تابع دورك هنا: {url}', '{provider}: your number is {code}. Track it: {url}'],
        'near'   => ['{provider}: اقترب دورك! أمامك {ahead}. رقمك {code}', '{provider}: almost your turn — {ahead} ahead. Your number {code}'],
        'called' => ['{provider}: حان دورك الآن! رقمك {code}', '{provider}: it\'s your turn now! Number {code}'],
    ];

    private Connection $db;
    private Clock $clock;
    private Settings $settings;
    /** Base URL used to build ticket links (set by the application kernel). */
    public string $baseUrl = '';
    /** Set when something was queued during this request → dispatcher runs at shutdown. */
    public bool $queuedThisRequest = false;

    public function __construct(Connection $db, Clock $clock, Settings $settings)
    {
        $this->db = $db;
        $this->clock = $clock;
        $this->settings = $settings;
    }

    public function render(string $type, string $lang, array $vars): string
    {
        $pair = self::TEMPLATES[$type] ?? ['{message}', '{message}'];
        $tpl = $lang === 'en' ? $pair[1] : $pair[0];
        $vars += ['brand' => $this->settings->string('brand_name') ?: 'QueuePro'];
        return preg_replace_callback('/\{(\w+)\}/', fn($m) => (string) ($vars[$m[1]] ?? ''), $tpl);
    }

    public function queue(string $type, string $phone, string $body, ?int $providerId = null, ?int $ticketId = null): int
    {
        $this->queuedThisRequest = true;
        return $this->db->insert($this->db->table('notifications'), [
            'provider_id' => $providerId,
            'ticket_id'   => $ticketId,
            'type'        => $type,
            'channel'     => $this->settings->string('sms_channel') ?: 'sms',
            'to_phone'    => $phone,
            'body'        => mb_substr($body, 0, 1000),
            'status'      => 'pending',
            'created_at'  => $this->clock->utcString(),
        ]);
    }

    public function ticketUrl(string $token): string
    {
        return rtrim($this->baseUrl, '/') . '/t/' . $token . '/';
    }

    // ---------------------------------------------------------------- event hooks (called by QueueService inside its transaction)

    public function onJoined(array $ticket, array $provider, string $token): void
    {
        if (!$ticket['phone'] || !$this->settings->bool('notify_on_join')) {
            return;
        }
        $this->queue('joined', $ticket['phone'], $this->render('joined', $ticket['lang'], [
            'provider' => $this->providerName($provider, $ticket['lang']),
            'code'     => $provider['ticket_prefix'] . str_pad((string) $ticket['ticket_number'], 3, '0', STR_PAD_LEFT),
            'url'      => $this->ticketUrl($token),
        ]), (int) $provider['id'], (int) $ticket['id']);
    }

    public function onCalled(array $ticket, array $provider): void
    {
        if (!$ticket['phone'] || !$this->settings->bool('notify_on_call')) {
            return;
        }
        $this->queue('called', $ticket['phone'], $this->render('called', $ticket['lang'], [
            'provider' => $this->providerName($provider, $ticket['lang']),
            'code'     => $provider['ticket_prefix'] . str_pad((string) $ticket['ticket_number'], 3, '0', STR_PAD_LEFT),
        ]), (int) $provider['id'], (int) $ticket['id']);
    }

    /**
     * "Almost your turn": every waiting ticket with a phone that is now within N of the
     * front and hasn't been told yet gets exactly one message (notified_near flag).
     */
    public function checkNear(array $provider, string $date): void
    {
        $n = $this->settings->int('notify_near_ahead');
        if ($n <= 0) {
            return;
        }
        $t = $this->db->table('tickets');
        $front = $this->db->getResults(
            "SELECT * FROM $t WHERE provider_id = %d AND queue_date = %s AND status = 'waiting' ORDER BY sort_key ASC LIMIT %d",
            [(int) $provider['id'], $date, $n + 1]
        );
        foreach ($front as $ahead => $ticket) {
            if ((int) $ticket['notified_near'] || !$ticket['phone']) {
                continue;
            }
            $this->db->update($t, ['notified_near' => 1], ['id' => (int) $ticket['id']]);
            if ($ahead === 0) {
                continue; // next in line: the "called" message will follow right away
            }
            $this->queue('near', $ticket['phone'], $this->render('near', $ticket['lang'], [
                'provider' => $this->providerName($provider, $ticket['lang']),
                'ahead'    => $ahead,
                'code'     => $provider['ticket_prefix'] . str_pad((string) $ticket['ticket_number'], 3, '0', STR_PAD_LEFT),
            ]), (int) $provider['id'], (int) $ticket['id']);
        }
    }

    private function providerName(array $provider, string $lang): string
    {
        return $lang === 'en' && $provider['name_en'] ? $provider['name_en'] : $provider['name_ar'];
    }

    // ---------------------------------------------------------------- outbox reads (admin)

    public function recent(int $limit = 50, ?string $status = null): array
    {
        $limit = max(1, min(200, $limit));
        return $status
            ? $this->db->getResults("SELECT * FROM {$this->db->table('notifications')} WHERE status = %s ORDER BY id DESC LIMIT %d", [$status, $limit])
            : $this->db->getResults("SELECT * FROM {$this->db->table('notifications')} ORDER BY id DESC LIMIT %d", [$limit]);
    }
}
