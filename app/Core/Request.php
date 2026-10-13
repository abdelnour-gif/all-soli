<?php
namespace QueuePro\Core;

defined('QP_APP') || exit;

final class Request
{
    public string $method;
    /** Path relative to the app base, always starting with "/" (e.g. "/p/salon/"). */
    public string $path;
    /** Original request URI (path + query), for building links. */
    public string $uri;
    public array $query;
    public array $post;
    public array $files;
    /** Decoded JSON body (API) */
    public array $json = [];
    /** Route parameters */
    public array $params = [];
    private array $headers;

    public static function capture(string $basePath): self
    {
        $r = new self();
        $r->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $r->uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = rawurldecode((string) parse_url($r->uri, PHP_URL_PATH));
        if ($basePath !== '' && strpos($path, $basePath) === 0) {
            $path = substr($path, strlen($basePath));
        }
        $path = '/' . ltrim(preg_replace('#/+#', '/', $path), '/');
        if ($path === '/index.php' || strpos($path, '/index.php/') === 0) {
            $path = '/' . ltrim(substr($path, 10), '/');
        }
        $r->path = $path;
        $r->query = $_GET;
        $r->post = $_POST;
        $r->files = $_FILES;
        $r->headers = [];
        foreach ($_SERVER as $k => $v) {
            if (strpos($k, 'HTTP_') === 0) {
                $r->headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $r->headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        if (stripos($r->header('content-type'), 'application/json') !== false) {
            $raw = file_get_contents('php://input', false, null, 0, 1024 * 1024);
            $data = json_decode((string) $raw, true);
            $r->json = is_array($data) ? $data : [];
        }
        return $r;
    }

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    /** Route param → JSON body → POST → query string. */
    public function param(string $key, $default = null)
    {
        foreach ([$this->params, $this->json, $this->post, $this->query] as $src) {
            if (array_key_exists($key, $src)) {
                return $src[$key];
            }
        }
        return $default;
    }

    /** All body params (JSON or form), without route params. */
    public function body(): array
    {
        return $this->json ?: $this->post;
    }

    public function isJson(): bool
    {
        return stripos($this->header('content-type'), 'application/json') !== false;
    }

    public function isHttps(bool $trustProxy): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }
        return $trustProxy && strtolower($this->header('x-forwarded-proto')) === 'https';
    }

    public function ip(bool $trustProxy): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        if ($trustProxy) {
            $fwd = $this->header('cf-connecting-ip') ?: $this->header('x-forwarded-for');
            $first = trim(explode(',', $fwd)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                $ip = $first;
            }
        }
        return $ip;
    }
}
