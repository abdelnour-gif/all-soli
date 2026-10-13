<?php
namespace QueuePro\Core;

defined('QP_APP') || exit;

/** Minimal outbound HTTPS client (cURL, or PHP streams when cURL is missing). */
final class HttpClient
{
    /**
     * @param array{method?:string,headers?:array<string,string>,body?:string|array,timeout?:int} $o
     * @return array{status:int,body:string,error:?string}
     */
    public static function request(string $url, array $o = []): array
    {
        $method = strtoupper($o['method'] ?? 'GET');
        $headers = $o['headers'] ?? [];
        $body = $o['body'] ?? null;
        if (is_array($body)) {
            $body = http_build_query($body);
            $headers['Content-Type'] = $headers['Content-Type'] ?? 'application/x-www-form-urlencoded';
        }
        $timeout = (int) ($o['timeout'] ?? 8);
        $lines = [];
        foreach ($headers as $k => $v) {
            $lines[] = $k . ': ' . str_replace(["\r", "\n"], '', (string) $v);
        }
        $lines[] = 'User-Agent: QueuePro/2.0';

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 2,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
                CURLOPT_HTTPHEADER => $lines,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            if ($body !== null && $method !== 'GET') {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            $resp = curl_exec($ch);
            $err = $resp === false ? curl_error($ch) : null;
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            return ['status' => $status, 'body' => $resp === false ? '' : (string) $resp, 'error' => $err];
        }

        $ctx = stream_context_create(['http' => [
            'method' => $method, 'header' => implode("\r\n", $lines), 'content' => $body ?? '',
            'timeout' => $timeout, 'ignore_errors' => true, 'max_redirects' => 3,
        ]]);
        $resp = @file_get_contents($url, false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];
            }
        }
        $err = $resp === false ? (error_get_last()['message'] ?? 'request failed') : null;
        return ['status' => $status, 'body' => $resp === false ? '' : $resp, 'error' => $err];
    }
}
