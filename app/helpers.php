<?php
/**
 * Global view & request helpers (escaping, URLs, CSRF, small utilities).
 * Inside WordPress the core functions with the same names (esc_html, sanitize_key…) are already
 * loaded and behave the same way, so ours are only declared when missing.
 */
defined('QP_APP') || exit;

use QueuePro\Core\App;

function qp(): \QueuePro\Container
{
    return App::container();
}

// ------------------------------------------------------------------ escaping
if (!function_exists('esc_html')) {
    function esc_html($s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/** Allows http(s), relative URLs, tel: and mailto:. Anything else (javascript:, data:) becomes ''. */
if (!function_exists('esc_url_raw')) {
    function esc_url_raw($url): string
    {
        $url = trim(str_replace(["\0", "\r", "\n", "\t"], '', (string) $url));
        if ($url === '') {
            return '';
        }
        if (preg_match('#^(https?://|/|\?|\#|tel:|mailto:)#i', $url) || !preg_match('#^[a-z][a-z0-9+.-]*:#i', $url)) {
            return $url;
        }
        return '';
    }
}

if (!function_exists('esc_url')) {
    function esc_url($url): string
    {
        return esc_attr(esc_url_raw($url));
    }
}

function json_enc($v): string
{
    // Safe inside <script>: escapes <, >, &, quotes.
    return (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}

// ------------------------------------------------------------------ input
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($s): string
    {
        $s = strip_tags((string) $s);
        $s = preg_replace('/[\r\n\t ]+/u', ' ', $s) ?? '';
        return trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $s) ?? '');
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key($s): string
    {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s)) ?? '';
    }
}

// ------------------------------------------------------------------ URLs
/** Absolute URL for an app path, e.g. url('/p/salon/'). */
function url(string $path = '/'): string
{
    return App::baseUrl() . '/' . ltrim($path, '/');
}

function api_url(string $path = ''): string
{
    return url('/api/' . ltrim($path, '/'));
}

function asset(string $path): string
{
    $base = QP_ASSETS_URL !== '' ? rtrim(QP_ASSETS_URL, '/') : url('/assets');
    return $base . '/' . ltrim($path, '/') . '?v=' . QP_VERSION;
}

/** Public URL of an uploaded image (relative to QP_UPLOADS). */
function uploads_url(string $path = ''): string
{
    $base = QP_UPLOADS_URL !== '' ? rtrim(QP_UPLOADS_URL, '/') : url('/uploads');
    return $base . '/' . ltrim($path, '/');
}

/** Public URL a file inside QP_STORAGE would have (used only to prove it is NOT reachable). */
function storage_url(string $path = ''): string
{
    $base = QP_STORAGE_URL !== '' ? rtrim(QP_STORAGE_URL, '/') : url('/storage');
    return $base . '/' . ltrim($path, '/');
}

/** Public link of a provider page (domain.com/{slug}/). */
function provider_url(string $slug): string
{
    return url('/' . rawurlencode($slug) . '/');
}

function current_url(): string
{
    return App::request()->uri;
}

/**
 * qp_add_query_arg('k', 'v', $url) or qp_add_query_arg(['k' => 'v'], $url).
 * Null values remove the key. Values are URL-encoded (unlike WordPress' add_query_arg).
 */
function qp_add_query_arg($key, $value = null, ?string $url = null): string
{
    if (is_array($key)) {
        $url = $value;
        $args = $key;
    } else {
        $args = [$key => $value];
    }
    $url = $url ?? current_url();
    $parts = explode('#', $url, 2);
    [$base, $qs] = array_pad(explode('?', $parts[0], 2), 2, '');
    parse_str($qs, $q);
    foreach ($args as $k => $v) {
        if ($v === null || $v === false) {
            unset($q[$k]);
        } else {
            $q[$k] = $v;
        }
    }
    $out = $base . ($q ? '?' . http_build_query($q) : '');
    return isset($parts[1]) ? $out . '#' . $parts[1] : $out;
}

function qp_remove_query_arg($keys, ?string $url = null): string
{
    $args = [];
    foreach ((array) $keys as $k) {
        $args[$k] = null;
    }
    return qp_add_query_arg($args, $url ?? current_url());
}

if (!function_exists('selected')) {
    function selected($a, $b): void
    {
        if ((string) $a === (string) $b) {
            echo ' selected';
        }
    }
}

// ------------------------------------------------------------------ security
function csrf_token(): string
{
    return App::auth()->csrfToken();
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . esc_attr(csrf_token()) . '">';
}

/** CSP nonce for inline <script> tags. */
function csp_nonce(): string
{
    return App::cspNonce();
}

function nonce_attr(): string
{
    return ' nonce="' . esc_attr(App::cspNonce()) . '"';
}

function http_status(int $code): void
{
    if (!headers_sent()) {
        http_response_code($code);
    }
}
