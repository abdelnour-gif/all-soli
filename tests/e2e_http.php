<?php
/**
 * End-to-end check of a RUNNING installation over HTTP (standalone or WordPress edition).
 * It creates two test providers (e2e-…) and a few tickets, so run it on a staging copy.
 *
 *   php tests/e2e_http.php <base-url> <admin-user> <admin-password> [--standalone]
 *   e.g. php tests/e2e_http.php http://localhost:8080 admin 'S3cret"pass\'x' --standalone
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
[$_, $base, $adminUser, $adminPass] = array_pad($argv, 4, null);
if (!$base || !$adminUser || !$adminPass) {
    fwrite(STDERR, "usage: php tests/e2e_http.php <base-url> <admin-user> <admin-password> [--standalone]\n");
    exit(2);
}
$base = rtrim($base, '/');
$standalone = in_array('--standalone', $argv, true);
$pass = 0;
$fail = 0;

function ok(bool $cond, string $label, $extra = null): void
{
    global $pass, $fail;
    $cond ? $pass++ : $fail++;
    echo ($cond ? "  \e[32m✓\e[0m " : "  \e[31m✗\e[0m ") . $label . (!$cond && $extra !== null ? '  → ' . (is_string($extra) ? $extra : json_encode($extra, JSON_UNESCAPED_UNICODE)) : '') . "\n";
}

final class Client
{
    public array $cookies = [];
    public string $base;

    public function __construct(string $base)
    {
        $this->base = $base;
    }

    /** @return array{status:int, headers:array, body:string, json:?array} */
    public function req(string $method, string $path, $body = null, array $headers = []): array
    {
        $ch = curl_init(preg_match('#^https?://#', $path) ? $path : $this->base . $path);
        $h = $headers;
        if ($this->cookies) {
            $h[] = 'Cookie: ' . implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($this->cookies), $this->cookies));
        }
        if (is_array($body) && ($headers['form'] ?? false) === false && !in_array('Content-Type: application/x-www-form-urlencoded', $headers, true)) {
            $body = json_encode($body);
            $h[] = 'Content-Type: application/json';
        }
        $respHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => array_values(array_filter($h, 'is_string')), CURLOPT_TIMEOUT => 30,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$respHeaders) {
                if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $line, $m)) {
                    if ($m[2] === '' || $m[2] === 'deleted') {
                        unset($this->cookies[$m[1]]);
                    } else {
                        $this->cookies[$m[1]] = $m[2];
                    }
                }
                if (strpos($line, ':') !== false) {
                    [$k, $v] = explode(':', $line, 2);
                    $respHeaders[strtolower(trim($k))] = trim($v);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $out = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $json = json_decode($out, true);
        return ['status' => $status, 'headers' => $respHeaders, 'body' => $out, 'json' => is_array($json) ? $json : null];
    }

    public function form(string $path, array $fields): array
    {
        return $this->req('POST', $path, http_build_query($fields), ['Content-Type: application/x-www-form-urlencoded', 'Origin: ' . preg_replace('#^(https?://[^/]+).*$#', '$1', $this->base)]);
    }

    public function api(string $method, string $path, ?array $body = null, ?string $csrf = null, array $extra = []): array
    {
        $hasOrigin = (bool) preg_grep('/^Origin:/i', $extra);
        $h = array_merge($hasOrigin ? [] : ['Origin: ' . preg_replace('#^(https?://[^/]+).*$#', '$1', $this->base)], ['Accept: application/json'], $extra);
        if ($csrf) {
            $h[] = 'X-CSRF-Token: ' . $csrf;
        }
        return $this->req($method, '/api' . $path, $body ?? ($method === 'GET' ? null : []), $h);
    }

    /** Logs in through the HTML form on /super-admin/ or /dashboard/ and returns the API CSRF token. */
    public function login(string $page, string $user, string $password): ?string
    {
        $r = $this->req('GET', $page);
        if (!preg_match('/name="_csrf" value="([^"]+)"/', $r['body'], $m)) {
            return null;
        }
        $r = $this->form($page, ['qp_login' => 1, '_csrf' => html_entity_decode($m[1]), 'log' => $user, 'pwd' => $password]);
        if ($r['status'] !== 303) {
            return null;
        }
        $r = $this->req('GET', $page);
        return preg_match('/"nonce":"([a-f0-9]{64})"/', $r['body'], $m) ? $m[1] : null;
    }
}

$tag = substr(bin2hex(random_bytes(3)), 0, 5);
echo "\n\e[1m■ " . ($standalone ? 'Standalone' : 'WordPress') . " edition at $base\e[0m\n";

// ---------------------------------------------------------------- pages
$guest = new Client($base);
foreach (['/', '/explore/', '/super-admin/', '/dashboard/'] as $p) {
    $r = $guest->req('GET', $p);
    ok($r['status'] === 200 && strpos($r['body'], 'qp') !== false, "GET $p → 200");
}
$r = $guest->req('GET', '/no-such-provider-' . $tag . '/');
ok($r['status'] === 404, 'unknown provider page → 404', $r['status']);
$r = $guest->req('GET', '/');
ok(isset($r['headers']['content-security-policy']) && isset($r['headers']['x-content-type-options']), 'security headers sent');

// ---------------------------------------------------------------- admin
$admin = new Client($base);
$aCsrf = $admin->login('/super-admin/', $adminUser, $adminPass);
ok($aCsrf !== null, 'Super Admin login (password with quotes / backslash)');
$wrong = new Client($base);
ok($wrong->login('/super-admin/', $adminUser, $adminPass . 'x') === null, 'wrong password refused');

$r = $admin->api('GET', '/admin/stats', null, $aCsrf);
ok($r['status'] === 200 && isset($r['json']['overview']['providers']), 'admin stats', $r['status']);
$r = $admin->api('POST', '/admin/providers', ['slug' => "e2e-$tag", 'name_ar' => "صالون اختبار $tag", 'create_login' => 1, 'username' => "e2e$tag", 'open_queue' => 1], $aCsrf);
ok($r['status'] === 200 && !empty($r['json']['credentials']['password']), 'create provider with login', $r['json']);
$p1 = $r['json']['provider'] ?? [];
$p1Pass = $r['json']['credentials']['password'] ?? '';
$r = $admin->api('POST', '/admin/providers', ['slug' => "e2e-$tag-b", 'name_ar' => "مزوّد ب $tag", 'create_login' => 1, 'username' => "e2e{$tag}b", 'open_queue' => 1], $aCsrf);
$p2 = $r['json']['provider'] ?? [];
$r = $admin->api('POST', '/admin/providers', ['slug' => "e2e-$tag-c", 'name_ar' => 'x'], null);
ok($r['status'] === 403, 'admin write without CSRF token → 403', $r['status']);
$r = $admin->api('POST', '/admin/providers', ['slug' => "e2e-$tag-c", 'name_ar' => 'x'], $aCsrf, ['Origin: https://evil.example']);
ok($r['status'] === 403, 'cross-site request (foreign Origin) → 403', $r['status']);

// ---------------------------------------------------------------- customer
$r = $guest->req('GET', "/e2e-$tag/");
ok($r['status'] === 200 && strpos($r['body'], "صالون اختبار $tag") !== false, "provider page /e2e-$tag/ (domain.com/provider-name)");
$r = $guest->req('GET', "/p/e2e-$tag/");
ok($r['status'] === 301, 'old /p/ link redirects', $r['status']);

$c1 = new Client($base);
$j1 = $c1->api('POST', "/providers/e2e-$tag/join", ['phone' => '0550' . random_int(100000, 999999)], null, ['Idempotency-Key: e2e-' . $tag . '-1']);
ok(in_array($j1['status'], [200, 201], true) && ($j1['json']['ticket']['number'] ?? 0) === 1, 'customer joins without an account → number 1', $j1['json']);
$phone2 = '0660' . random_int(100000, 999999);
$j2 = (new Client($base))->api('POST', "/providers/e2e-$tag/join", ['phone' => $phone2], null);
ok(($j2['json']['ticket']['number'] ?? 0) === 2, 'second customer → number 2');
$dup = (new Client($base))->api('POST', "/providers/e2e-$tag/join", ['phone' => $phone2], null);
ok($dup['status'] >= 400 && $dup['status'] < 500, 'same phone twice → refused', $dup['json']);
$form = (new Client($base))->form("/api/providers/e2e-$tag/join", ['phone' => '0770123456']);
ok($form['status'] === 415, 'cross-site HTML form booking blocked (JSON only)', $form['status']);
ok(empty($c1->cookies), 'customers get no session / account cookie');

$tok2 = $j2['json']['token'] ?? '';
$t = $guest->api('GET', "/tickets/$tok2");
ok(($t['json']['ahead'] ?? null) === 1, 'ticket: 1 person ahead', $t['json']);
$r = $guest->req('GET', "/t/$tok2/");
ok($r['status'] === 200 && ($r['headers']['referrer-policy'] ?? '') === 'no-referrer', 'ticket page /t/… (no Referer leak)');
$r = $guest->api('GET', "/providers/e2e-$tag/live");
ok($r['status'] === 200 && strpos($r['headers']['cache-control'] ?? '', 'max-age=5') !== false, 'live endpoint is cacheable (5 s)');

// ---------------------------------------------------------------- provider
$prov = new Client($base);
$pCsrf = $prov->login('/dashboard/', "e2e$tag", $p1Pass);
ok($pCsrf !== null, 'provider dashboard login');
$r = $prov->api('GET', '/me/live', null, $pCsrf);
ok(($r['json']['balance']['available'] ?? null) === 8 && ($r['json']['balance']['reserved'] ?? null) === 2, 'balance: 10 free − 2 reserved = 8 available', $r['json']['balance'] ?? $r['json']);
$r = $prov->api('POST', '/me/call-next', [], $pCsrf);
$tid = $r['json']['ticket']['id'] ?? 0;
ok($r['status'] === 200 && ($r['json']['ticket']['status'] ?? '') === 'called', 'Call next', $r['json']);
$r = $prov->api('POST', "/me/tickets/$tid/start", [], $pCsrf);
ok(($r['json']['ticket']['status'] ?? '') === 'in_service', 'In Service');
$r = $prov->api('POST', "/me/tickets/$tid/complete", [], $pCsrf);
ok(($r['json']['ticket']['status'] ?? '') === 'completed' && ($r['json']['balance']['consumed'] ?? 0) === 1, 'Complete → 1 credit consumed', $r['json']);
$r = $prov->api('POST', '/me/walkin', ['name' => 'Walk-in'], $pCsrf);
ok(($r['json']['ticket']['number'] ?? 0) === 3, 'walk-in without phone → number 3', $r['json']);
$t = $guest->api('GET', "/tickets/$tok2");
ok(($t['json']['ahead'] ?? null) === 0, 'customer 2 is now first in line', $t['json']);
$r = $guest->api('POST', "/tickets/$tok2/cancel", []);
ok(($r['json']['status'] ?? '') === 'cancelled', 'customer cancels with the link (no account)', $r['json']);
$r = $prov->api('GET', '/me/live', null, $pCsrf);
ok(($r['json']['balance']['consumed'] ?? null) === 1 && ($r['json']['balance']['reserved'] ?? null) === 1, 'cancel released the credit (no charge)', $r['json']['balance'] ?? null);

// isolation
$p2Ticket = (new Client($base))->api('POST', "/providers/e2e-$tag-b/join", ['phone' => '0770' . random_int(100000, 999999)], null);
$p2Tid = $p2Ticket['json']['ticket']['id'] ?? 0;
$r = $prov->api('POST', "/me/tickets/$p2Tid/complete", [], $pCsrf);
ok($r['status'] === 404 || $r['status'] === 403, "provider cannot touch another provider's ticket (IDOR)", $r['status']);
$r = $prov->api('GET', '/admin/stats', null, $pCsrf);
ok($r['status'] === 403, 'provider cannot open admin API', $r['status']);
$r = $guest->api('GET', '/me/live');
ok($r['status'] === 401, 'anonymous cannot open provider API', $r['status']);

// ledger & audit
$r = $admin->api('GET', '/admin/providers/' . ($p1['id'] ?? 0) . '/ledger', null, $aCsrf);
$types = array_column($r['json']['items'] ?? $r['json'] ?? [], 'type');
ok(in_array('free_grant', $types, true) && in_array('consumed', $types, true) && in_array('released', $types, true), 'ledger shows free_grant / reserved / consumed / released', $types);
$r = $admin->api('GET', '/admin/audit', null, $aCsrf);
ok($r['status'] === 200 && strpos($r['body'], 'provider.create') !== false, 'audit log records provider creation');

// ---------------------------------------------------------------- private files
$probes = $standalone
    ? ['/storage/database.sqlite', '/storage/config.php', '/storage/logs/app.log', '/app/Core/App.php', '/tests/acceptance.php', '/bin/console.php', '/templates/home.php', '/README.md', '/.htaccess', '/.git/config']
    : ['/wp-content/queuepro-data/', '/wp-content/queuepro-data/logs/app.log', '/wp-content/plugins/queuepro/index.php', '/wp-content/plugins/queuepro/tests/acceptance.php', '/wp-content/plugins/queuepro/app/Core/App.php', '/wp-content/plugins/queuepro/bin/console.php'];
foreach ($probes as $p) {
    $r = $guest->req('GET', $p);
    $leak = $r['status'] === 200 && (strlen(trim($r['body'])) > 0) && !preg_match('/<html|qp-shell/i', $r['body']);
    ok(!$leak && $r['status'] !== 500, "$p not exposed ({$r['status']})", substr($r['body'], 0, 80));
}

echo "\n" . ($fail ? "\e[41;97m FAIL \e[0m" : "\e[42;30m PASS \e[0m") . " $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
