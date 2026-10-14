<?php
/**
 * PayPal Checkout (REST API v2) for customers outside Algeria.
 *
 * - Prices stay in DZD in the store. For PayPal they are converted with the rate set in the dashboard
 *   (admin/paypal.php): 1 EUR (or USD) = paypal_rate DZD. A flat international shipping fee is added.
 * - Amounts are always computed on the server: the browser only receives the PayPal order id.
 * - The store order is created only after PayPal confirms the capture (status COMPLETED, same amount
 *   and currency) — an order that was not paid never appears in the dashboard.
 *
 * Settings (table settings): paypal_on, paypal_mode (sandbox|live), paypal_client_id, paypal_secret,
 * paypal_currency (EUR|USD), paypal_rate, paypal_ship.
 */
require_once __DIR__ . '/functions.php';

const PAYPAL_CURRENCIES = ['EUR' => '€', 'USD' => '$'];

function ppCfg(): array {
    $cur = strtoupper(setting('paypal_currency', 'EUR'));
    return [
        'on'       => setting('paypal_on') === '1',
        'mode'     => setting('paypal_mode', 'sandbox') === 'live' ? 'live' : 'sandbox',
        'client'   => trim(setting('paypal_client_id')),
        'secret'   => trim(setting('paypal_secret')),
        'currency' => isset(PAYPAL_CURRENCIES[$cur]) ? $cur : 'EUR',
        'rate'     => max(1.0, (float)str_replace(',', '.', setting('paypal_rate', '250'))),
        'ship'     => max(0, (int)round((float)str_replace(',', '.', setting('paypal_ship', '0')) * 100)),   // in cents
    ];
}

/** PayPal payments can be offered to visitors. */
function ppEnabled(): bool {
    $c = ppCfg();
    return $c['on'] && $c['client'] !== '' && $c['secret'] !== '';
}

function ppBase(): string {
    // paypal_api_base: hidden setting, only used to test against a local copy of the API.
    $override = trim(setting('paypal_api_base'));
    if ($override !== '') return rtrim($override, '/');
    return ppCfg()['mode'] === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
}

/** DZD → cents of the PayPal currency (rounded per unit, like a price tag). */
function ppCents(int $dzd): int {
    return (int)round($dzd / ppCfg()['rate'] * 100);
}
function ppFmt(int $cents): string {
    return number_format($cents / 100, 2, '.', '');
}
/** "12.40 €" for display. */
function ppMoney(int $cents, ?string $currency = null): string {
    $cur = $currency ?: ppCfg()['currency'];
    return number_format($cents / 100, 2, '.', ' ') . ' ' . (PAYPAL_CURRENCIES[$cur] ?? $cur);
}

/**
 * The amounts sent to PayPal for a list of lines [['product' => row, 'qty' => n, 'color' => .., 'size' => ..], ...].
 * Returns items, item_total, shipping and total in cents of the PayPal currency.
 */
function ppQuote(array $lines): array {
    $c = ppCfg();
    $items = [];
    $itemTotal = 0;
    foreach ($lines as $l) {
        $unit = ppCents((int)$l['product']['price']);
        $qty = (int)$l['qty'];
        $variant = trim(preg_replace('/\s*\(#[0-9a-fA-F]{6}\)/', '', variantText((string)($l['color'] ?? ''), (string)($l['size'] ?? ''))));
        $items[] = ['name' => mb_substr(tr($l['product'], 'name') . ($variant !== '' ? ' — ' . $variant : ''), 0, 120), 'qty' => $qty, 'unit' => $unit];
        $itemTotal += $unit * $qty;
    }
    return ['currency' => $c['currency'], 'items' => $items, 'item_total' => $itemTotal, 'shipping' => $c['ship'], 'total' => $itemTotal + $c['ship']];
}

/** Low-level call. Returns [httpCode, decoded body|null]. */
function ppRequest(string $method, string $path, ?array $body = null, array $headers = [], bool $auth = true): array {
    $c = ppCfg();
    $h = array_merge(['Accept: application/json'], $headers);
    $payload = null;
    if ($auth) {
        $h[] = 'Authorization: Bearer ' . ppToken();
        if ($body !== null) { $h[] = 'Content-Type: application/json'; $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
    }
    $ch = curl_init(ppBase() . $path);
    $opts = [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $h,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
    ];
    if (!$auth) {   // token request: client id + secret, form body
        $opts[CURLOPT_USERPWD] = $c['client'] . ':' . $c['secret'];
        $opts[CURLOPT_POSTFIELDS] = 'grant_type=client_credentials';
    } elseif ($payload !== null) {
        $opts[CURLOPT_POSTFIELDS] = $payload;
    } elseif ($method === 'POST') {
        $opts[CURLOPT_POSTFIELDS] = '';
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false) throw new RuntimeException('PayPal unreachable: ' . $err);
    return [$code, json_decode($resp, true)];
}

function ppToken(): string {
    static $token = null;
    if ($token !== null) return $token;
    [$code, $data] = ppRequest('POST', '/v1/oauth2/token', null, ['Content-Type: application/x-www-form-urlencoded'], false);
    if ($code !== 200 || empty($data['access_token'])) {
        throw new RuntimeException($code === 401 ? 'PayPal: Client ID / Secret غير صحيحة' : 'PayPal token error (HTTP ' . $code . ')');
    }
    return $token = (string)$data['access_token'];
}

/** Create the PayPal order. Returns the PayPal order id. */
function ppCreateOrder(array $q, string $reference): string {
    $cur = $q['currency'];
    $items = array_map(fn($it) => [
        'name' => $it['name'], 'quantity' => (string)$it['qty'], 'category' => 'PHYSICAL_GOODS',
        'unit_amount' => ['currency_code' => $cur, 'value' => ppFmt($it['unit'])],
    ], $q['items']);
    $body = [
        'intent' => 'CAPTURE',
        'purchase_units' => [[
            'reference_id' => $reference,
            'custom_id'    => $reference,
            'description'  => mb_substr(setting('store_name', 'Store'), 0, 120),
            'amount' => [
                'currency_code' => $cur,
                'value' => ppFmt($q['total']),
                'breakdown' => [
                    'item_total' => ['currency_code' => $cur, 'value' => ppFmt($q['item_total'])],
                    'shipping'   => ['currency_code' => $cur, 'value' => ppFmt($q['shipping'])],
                ],
            ],
            'items' => $items,
        ]],
        'application_context' => [
            'brand_name' => mb_substr(setting('store_name', 'Store'), 0, 120),
            'shipping_preference' => 'NO_SHIPPING',   // the address is collected by the store form
            'user_action' => 'PAY_NOW',
        ],
    ];
    [$code, $data] = ppRequest('POST', '/v2/checkout/orders', $body);
    if (!in_array($code, [200, 201], true) || empty($data['id'])) {
        throw new RuntimeException('PayPal: ' . ($data['details'][0]['description'] ?? $data['message'] ?? ('HTTP ' . $code)));
    }
    return (string)$data['id'];
}

/**
 * Capture an approved order. Returns ['status', 'capture_id', 'value' (cents), 'currency', 'payer'].
 * The request id makes a repeated call safe (PayPal returns the first result).
 */
function ppCapture(string $orderId): array {
    if (!preg_match('/^[A-Z0-9-]{5,40}$/i', $orderId)) throw new RuntimeException('PayPal: bad order id');
    [$code, $data] = ppRequest('POST', '/v2/checkout/orders/' . $orderId . '/capture', null, ['PayPal-Request-Id: cap-' . $orderId, 'Content-Type: application/json']);
    if (!in_array($code, [200, 201], true) || !is_array($data)) {
        $issue = $data['details'][0]['issue'] ?? '';
        throw new RuntimeException($issue === 'INSTRUMENT_DECLINED' ? 'INSTRUMENT_DECLINED' : 'PayPal: ' . ($data['details'][0]['description'] ?? $data['message'] ?? ('HTTP ' . $code)));
    }
    $cap = $data['purchase_units'][0]['payments']['captures'][0] ?? [];
    return [
        'status'     => (string)($cap['status'] ?? $data['status'] ?? ''),
        'capture_id' => (string)($cap['id'] ?? ''),
        'value'      => (int)round((float)($cap['amount']['value'] ?? 0) * 100),
        'currency'   => (string)($cap['amount']['currency_code'] ?? ''),
        'payer'      => (string)($data['payer']['email_address'] ?? ''),
    ];
}

/** Check the credentials from the dashboard. Returns [ok, message]. */
function ppTest(): array {
    try {
        ppToken();
        return [true, 'الاتصال بـ PayPal ناجح ✔ (' . (ppCfg()['mode'] === 'live' ? 'Live — حقيقي' : 'Sandbox — تجريبي') . ')'];
    } catch (Throwable $e) {
        return [false, $e->getMessage()];
    }
}
