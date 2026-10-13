<?php
namespace QueuePro\Notifications;

use QueuePro\Services\Settings;

defined('QP_APP') || exit;

/**
 * Generic HTTPS gateway — works with most SMS providers (local Algerian gateways,
 * Infobip, Vonage, MessageBird, Termii, Ultramsg/WhatsApp APIs…) without code changes.
 *
 * Settings:
 *   sms_http_url      https://api.gateway.com/send?key={secret}
 *   sms_http_method   POST | GET
 *   sms_http_body     {"to":"{to}","message":"{message}","sender":"{sender}"}   (POST)
 *   sms_http_headers  one per line, e.g. "Authorization: Bearer {secret}"
 *   sms_http_secret   API key (never displayed in full)
 *
 * Placeholders: {to} (+213…), {to_plain} (213…), {to_local} (05…), {message}, {sender}, {secret}
 */
final class HttpSmsDriver implements Driver
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
        $url = $this->s->string('sms_http_url');
        $allowHttp = !empty(\QueuePro\Core\App::config()['sms_allow_http']); // local testing only
        if ($url === '' || (stripos($url, 'https://') !== 0 && !($allowHttp && stripos($url, 'http://') === 0))) {
            return SendResult::fail('SMS gateway URL is not configured (HTTPS required)');
        }
        $vars = [
            'to'       => $to,
            'to_plain' => ltrim($to, '+'),
            'to_local' => preg_replace('/^\+213/', '0', $to),
            'sender'   => $this->s->string('sms_sender'),
            'secret'   => $this->s->string('sms_http_secret'),
            'message'  => $message,
        ];
        $method = $this->s->string('sms_http_method') === 'GET' ? 'GET' : 'POST';
        $fillUrl = fn(string $tpl) => preg_replace_callback('/\{(\w+)\}/', fn($m) => rawurlencode((string) ($vars[$m[1]] ?? '')), $tpl);

        $headers = [];
        foreach (preg_split('/\r?\n/', $this->s->string('sms_http_headers')) as $line) {
            if (strpos($line, ':') !== false) {
                [$k, $v] = array_map('trim', explode(':', $line, 2));
                $headers[$k] = preg_replace_callback('/\{(\w+)\}/', fn($m) => (string) ($vars[$m[1]] ?? ''), $v);
            }
        }

        $args = ['method' => $method, 'timeout' => 6, 'headers' => $headers];
        if ($method === 'POST') {
            $tpl = $this->s->string('sms_http_body');
            $isJson = stripos($headers['Content-Type'] ?? '', 'json') !== false || (strlen(trim($tpl)) && trim($tpl)[0] === '{');
            $args['body'] = preg_replace_callback('/\{(\w+)\}/', function ($m) use ($vars, $isJson) {
                $v = (string) ($vars[$m[1]] ?? '');
                return $isJson ? substr(json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 1, -1) : rawurlencode($v);
            }, $tpl);
        }

        $res = \QueuePro\Core\HttpClient::request($fillUrl($url), $args);
        if ($res['error']) {
            return SendResult::fail($res['error']);
        }
        $code = $res['status'];
        $body = $res['body'];
        if ($code < 200 || $code >= 300) {
            return SendResult::fail("HTTP $code: " . mb_substr(trim(strip_tags($body)), 0, 180));
        }
        $json = json_decode($body, true);
        $id = is_array($json) ? (string) ($json['id'] ?? $json['message_id'] ?? $json['messageId'] ?? $json['sid'] ?? '') : '';
        return SendResult::ok($id !== '' ? mb_substr($id, 0, 100) : null);
    }
}
