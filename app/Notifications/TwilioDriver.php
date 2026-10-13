<?php
namespace QueuePro\Notifications;

use QueuePro\Services\Settings;

defined('QP_APP') || exit;

/** Twilio Programmable Messaging — SMS, or WhatsApp when sms_channel = whatsapp. */
final class TwilioDriver implements Driver
{
    private Settings $s;

    public function __construct(Settings $s)
    {
        $this->s = $s;
    }

    public function delivers(): bool
    {
        return true;
    }

    public function send(string $to, string $message, string $channel): SendResult
    {
        $sid = $this->s->string('twilio_sid');
        $secret = $this->s->string('twilio_secret');
        $from = $this->s->string('twilio_from');
        if (!$sid || !$secret || !$from) {
            return SendResult::fail('Twilio is not configured');
        }
        $wa = $channel === 'whatsapp';
        $res = \QueuePro\Core\HttpClient::request('https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($sid) . '/Messages.json', [
            'method'  => 'POST',
            'timeout' => 6,
            'headers' => ['Authorization' => 'Basic ' . base64_encode($sid . ':' . $secret)],
            'body'    => [
                'To'   => ($wa ? 'whatsapp:' : '') . $to,
                'From' => ($wa && strpos($from, 'whatsapp:') !== 0 ? 'whatsapp:' : '') . $from,
                'Body' => $message,
            ],
        ]);
        if ($res['error']) {
            return SendResult::fail($res['error']);
        }
        $code = $res['status'];
        $json = json_decode($res['body'], true) ?: [];
        if ($code < 200 || $code >= 300) {
            return SendResult::fail('Twilio ' . $code . ': ' . mb_substr((string) ($json['message'] ?? ''), 0, 180));
        }
        return SendResult::ok($json['sid'] ?? null);
    }
}
