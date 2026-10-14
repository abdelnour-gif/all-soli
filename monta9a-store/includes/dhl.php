<?php
/**
 * DHL Express (MyDHL API) — international shipments of orders paid from abroad (delivery_type = 'intl').
 * Local orders keep the Algerian delivery companies (includes/courier.php).
 *
 * Settings (admin/solivr, switched ON in admin/soli): dhl_on, dhl_mode (test|live), dhl_key, dhl_secret, dhl_account,
 * shipper: dhl_company, dhl_contact, dhl_phone, dhl_email, dhl_address, dhl_city, dhl_zip,
 * parcel: dhl_weight (kg), dhl_length / dhl_width / dhl_height (cm), dhl_product (P = Express Worldwide),
 * customs: dhl_hs (HS code), dhl_desc (goods description), dhl_incoterm (DAP|DDP).
 * Labels are kept in database/labels/ (not reachable from the web) and served by admin/dhl-label.php.
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/courier.php';   // hasTracking()

function dhlCfg(): array {
    $g = fn(string $k, string $d = '') => trim((string)setting($k, $d));
    return [
        'on' => $g('dhl_on') === '1', 'mode' => $g('dhl_mode', 'test') === 'live' ? 'live' : 'test',
        'key' => $g('dhl_key'), 'secret' => $g('dhl_secret'), 'account' => $g('dhl_account'),
        'company' => $g('dhl_company', setting('store_name')), 'contact' => $g('dhl_contact'), 'phone' => $g('dhl_phone', setting('phone')),
        'email' => $g('dhl_email'), 'address' => $g('dhl_address'), 'city' => $g('dhl_city'), 'zip' => $g('dhl_zip'),
        'weight' => max(0.1, (float)str_replace(',', '.', $g('dhl_weight', '0.5'))),
        'length' => max(1, (int)$g('dhl_length', '20')), 'width' => max(1, (int)$g('dhl_width', '15')), 'height' => max(1, (int)$g('dhl_height', '10')),
        'product' => preg_replace('/[^A-Z0-9]/', '', strtoupper($g('dhl_product', 'P'))) ?: 'P',
        'hs' => preg_replace('/[^0-9]/', '', $g('dhl_hs', '330499')) ?: '330499',
        'desc' => $g('dhl_desc', 'Cosmetics') ?: 'Cosmetics',
        'incoterm' => $g('dhl_incoterm', 'DAP') === 'DDP' ? 'DDP' : 'DAP',
    ];
}

/** Credentials and shipper address are complete. */
function dhlReady(): bool {
    $c = dhlCfg();
    return $c['on'] && $c['key'] !== '' && $c['secret'] !== '' && $c['account'] !== '' && $c['address'] !== '' && $c['city'] !== '' && $c['phone'] !== '';
}

function dhlBase(): string {
    $override = trim(setting('dhl_api_base'));   // hidden: tests against a local copy of the API
    if ($override !== '') return rtrim($override, '/');
    return dhlCfg()['mode'] === 'live' ? 'https://express.api.dhl.com/mydhlapi' : 'https://express.api.dhl.com/mydhlapi/test';
}

function dhlTrackUrl(string $tracking): string {
    return 'https://www.dhl.com/dz-fr/home/tracking.html?tracking-id=' . rawurlencode($tracking);
}

/** Returns [httpCode, decoded body|null]. */
function dhlRequest(string $method, string $path, ?array $body = null): array {
    $c = dhlCfg();
    $ch = curl_init(dhlBase() . $path);
    $h = ['Accept: application/json', 'Message-Reference: ' . bin2hex(random_bytes(16)), 'Message-Reference-Date: ' . gmdate('D, d M Y H:i:s') . ' GMT'];
    $opts = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_USERPWD => $c['key'] . ':' . $c['secret'],
             CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 45];
    if ($body !== null) { $h[] = 'Content-Type: application/json'; $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
    $opts[CURLOPT_HTTPHEADER] = $h;
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false) throw new RuntimeException('تعذر الاتصال بـ DHL: ' . $err);
    return [$code, json_decode($resp, true)];
}

/** Readable error from a MyDHL problem response. */
function dhlError(int $code, $data): string {
    if ($code === 401 || $code === 403) return 'DHL: مفاتيح API (Key / Secret) غير صحيحة أو غير مفعّلة لهذا الوضع';
    $parts = [];
    if (is_array($data)) {
        if (!empty($data['detail'])) $parts[] = (string)$data['detail'];
        foreach ((array)($data['additionalDetails'] ?? []) as $d) if (is_string($d)) $parts[] = $d;
        if (!$parts && !empty($data['title'])) $parts[] = (string)$data['title'];
    }
    return 'DHL: ' . ($parts ? implode(' — ', array_unique($parts)) : 'HTTP ' . $code);
}

/** Order + its items. */
function dhlOrder(int $id): array {
    $db = getDB();
    $st = $db->prepare('SELECT * FROM orders WHERE id = ?');
    $st->execute([$id]);
    $o = $st->fetch();
    if (!$o) throw new RuntimeException('الطلب غير موجود');
    $st = $db->prepare('SELECT * FROM order_items WHERE order_id = ?');
    $st->execute([$id]);
    $o['_items'] = $st->fetchAll();
    return $o;
}

/** The zip is kept with the address ("street — zip"); split it back for DHL. */
function dhlSplitAddress(array $o): array {
    $addr = trim((string)$o['address']);
    $zip = '';
    if (preg_match('/^(.*)\s—\s([^—]{2,20})$/u', $addr, $m)) { $addr = trim($m[1]); $zip = trim($m[2]); }
    return [$addr, $zip];
}

/**
 * Prices offered by DHL for an order (weight in kg). Returns [['code', 'name', 'price', 'currency', 'days'], ...].
 */
function dhlRates(array $o, float $weight): array {
    $c = dhlCfg();
    [, $zip] = dhlSplitAddress($o);
    $q = [
        'accountNumber' => $c['account'], 'originCountryCode' => 'DZ', 'originCityName' => $c['city'], 'originPostalCode' => $c['zip'],
        'destinationCountryCode' => (string)$o['country_code'], 'destinationCityName' => (string)$o['commune'], 'destinationPostalCode' => $zip,
        'weight' => $weight, 'length' => $c['length'], 'width' => $c['width'], 'height' => $c['height'],
        'plannedShippingDate' => date('Y-m-d', strtotime('+1 day')), 'isCustomsDeclarable' => 'true', 'unitOfMeasurement' => 'metric',
    ];
    [$code, $data] = dhlRequest('GET', '/rates?' . http_build_query(array_filter($q, fn($v) => $v !== '')));
    if ($code !== 200 || !is_array($data)) throw new RuntimeException(dhlError($code, $data));
    $out = [];
    foreach ((array)($data['products'] ?? []) as $p) {
        $price = null; $cur = '';
        foreach ((array)($p['totalPrice'] ?? []) as $tp) {
            if (($tp['currencyType'] ?? '') === 'BILLC' || $price === null) { $price = (float)($tp['price'] ?? 0); $cur = (string)($tp['priceCurrency'] ?? ''); }
        }
        $out[] = ['code' => (string)($p['productCode'] ?? ''), 'name' => (string)($p['productName'] ?? ''), 'price' => $price, 'currency' => $cur,
                  'days' => $p['deliveryCapabilities']['totalTransitDays'] ?? null];
    }
    return $out;
}

/**
 * Create the DHL shipment of an international order. Saves the label, the tracking number,
 * sets the order to "shipped". Returns ['tracking', 'label' (path or '')].
 */
function dhlShip(int $orderId, float $weight, string $product = ''): array {
    $c = dhlCfg();
    $o = dhlOrder($orderId);
    if (($o['delivery_type'] ?? '') !== 'intl') throw new RuntimeException('هذا الطلب ليس طلباً دولياً');
    if (hasTracking($o['tracking'] ?? '')) throw new RuntimeException('تم إرسال هذا الطلب من قبل — رقم التتبع: ' . $o['tracking']);
    if (strlen((string)$o['country_code']) !== 2) throw new RuntimeException('رمز بلد الزبون غير معروف — صحّحه في الطلب');
    [$addr, $zip] = dhlSplitAddress($o);
    $cur = (string)($o['pay_currency'] ?: 'EUR');
    $rate = max(1.0, (float)setting('paypal_rate', '250'));
    $lines = []; $declared = 0.0; $n = 1;
    $perItem = max(0.05, round($weight / max(1, array_sum(array_map(fn($i) => (int)$i['qty'], $o['_items']))), 3));
    foreach ($o['_items'] as $it) {
        $unit = round((int)$it['price'] / $rate, 2);
        $declared += $unit * (int)$it['qty'];
        $lines[] = [
            'number' => $n++, 'description' => dhlLatin($c['desc'] . ' - ' . dhlItemName($it), 70),
            'price' => $unit, 'quantity' => ['value' => (int)$it['qty'], 'unitOfMeasurement' => 'PCS'],
            'commodityCodes' => [['typeCode' => 'outbound', 'value' => $c['hs']]], 'exportReasonType' => 'permanent', 'manufacturerCountry' => 'DZ',
            'weight' => ['netValue' => round($perItem * (int)$it['qty'], 3), 'grossValue' => round($perItem * (int)$it['qty'], 3)],
        ];
    }
    $when = new DateTime('tomorrow 10:00', new DateTimeZone('Africa/Algiers'));
    $body = [
        'plannedShippingDateAndTime' => $when->format('Y-m-d\TH:i:s') . ' GMT+01:00',
        'pickup' => ['isRequested' => false],
        'productCode' => $product !== '' ? preg_replace('/[^A-Z0-9]/', '', strtoupper($product)) : $c['product'],
        'accounts' => [['typeCode' => 'shipper', 'number' => $c['account']]],
        'outputImageProperties' => ['encodingFormat' => 'pdf', 'imageOptions' => [['typeCode' => 'label', 'templateName' => 'ECOM26_84_001'], ['typeCode' => 'invoice', 'templateName' => 'COMMERCIAL_INVOICE_P_10', 'isRequested' => true]]],
        'customerDetails' => [
            'shipperDetails' => [
                'postalAddress' => array_filter(['postalCode' => $c['zip'], 'cityName' => $c['city'], 'countryCode' => 'DZ', 'addressLine1' => mb_substr($c['address'], 0, 45)]),
                'contactInformation' => array_filter(['phone' => $c['phone'], 'companyName' => $c['company'] ?: 'Store', 'fullName' => $c['contact'] ?: ($c['company'] ?: 'Store'), 'email' => $c['email']]),
            ],
            'receiverDetails' => [
                'postalAddress' => array_filter(['postalCode' => $zip, 'cityName' => (string)$o['commune'], 'countryCode' => strtoupper((string)$o['country_code']), 'addressLine1' => mb_substr($addr, 0, 45), 'addressLine2' => mb_strlen($addr) > 45 ? mb_substr($addr, 45, 45) : '']),
                'contactInformation' => array_filter(['phone' => (string)$o['phone'], 'companyName' => (string)$o['customer_name'], 'fullName' => (string)$o['customer_name'], 'email' => (string)$o['email']]),
            ],
        ],
        'content' => [
            'packages' => [['weight' => $weight, 'dimensions' => ['length' => $c['length'], 'width' => $c['width'], 'height' => $c['height']], 'customerReferences' => [['value' => 'ORDER-' . $orderId]]]],
            'isCustomsDeclarable' => true,
            'declaredValue' => round($declared, 2), 'declaredValueCurrency' => $cur,
            'description' => mb_substr($c['desc'], 0, 70), 'incoterm' => $c['incoterm'], 'unitOfMeasurement' => 'metric',
            'exportDeclaration' => ['lineItems' => $lines, 'invoice' => ['number' => 'INV-' . $orderId, 'date' => date('Y-m-d')]],
        ],
        'customerReferences' => [['value' => 'ORDER-' . $orderId, 'typeCode' => 'CU']],
    ];
    [$code, $data] = dhlRequest('POST', '/shipments', $body);
    if (!in_array($code, [200, 201], true) || empty($data['shipmentTrackingNumber'])) throw new RuntimeException(dhlError($code, $data));
    $tracking = (string)$data['shipmentTrackingNumber'];

    // Label (+ commercial invoice) kept outside the web root.
    $labelPath = '';
    $pdf = '';
    foreach ((array)($data['documents'] ?? []) as $d) if (($d['typeCode'] ?? '') === 'label' && !empty($d['content'])) { $pdf = (string)$d['content']; break; }
    if ($pdf !== '') {
        $dir = ROOT_PATH . '/database/labels';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $labelPath = 'database/labels/dhl-' . $orderId . '.pdf';
        @file_put_contents(ROOT_PATH . '/' . $labelPath, base64_decode($pdf));
        foreach ((array)($data['documents'] ?? []) as $d) {
            if (($d['typeCode'] ?? '') === 'invoice' && !empty($d['content'])) @file_put_contents($dir . '/dhl-' . $orderId . '-invoice.pdf', base64_decode((string)$d['content']));
        }
    }
    getDB()->prepare("UPDATE orders SET tracking = ?, shipped_at = datetime('now'), status = 'shipped', courier = 'dhl', courier_label = ? WHERE id = ?")
           ->execute([$tracking, $labelPath, $orderId]);
    return ['tracking' => $tracking, 'label' => $labelPath];
}

/** Customs documents need Latin text: the French product name, transliterated. */
function dhlItemName(array $it): string {
    $st = getDB()->prepare('SELECT name_fr FROM products WHERE id = ?');
    $st->execute([(int)$it['product_id']]);
    $fr = trim((string)$st->fetchColumn());
    return $fr !== '' ? $fr : 'item ' . (int)$it['product_id'];
}
function dhlLatin(string $s, int $max): string {
    if (function_exists('iconv')) { $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s); if ($t !== false) $s = $t; }
    $s = trim(preg_replace('/\s+/', ' ', preg_replace("/[^A-Za-z0-9 .,'\\-+\\/()%]/", '', $s)));
    return substr($s, 0, $max) ?: 'Goods';
}

/** Check credentials + account with a rate request to Paris. Returns [ok, message]. */
function dhlTest(): array {
    try {
        $fake = ['country_code' => 'FR', 'commune' => 'Paris', 'address' => 'Test — 75001'];
        $r = dhlRates($fake, dhlCfg()['weight']);
        if (!$r) return [true, 'الاتصال بـ DHL ناجح ✔ (لا توجد أسعار لهذا المسار حالياً)'];
        $best = $r[0];
        return [true, 'الاتصال بـ DHL ناجح ✔ — مثال: باريس، ' . dhlCfg()['weight'] . ' كغ: ' . $best['name'] . ' = ' . $best['price'] . ' ' . $best['currency']];
    } catch (Throwable $e) {
        return [false, $e->getMessage()];
    }
}

/** Countries offered in the international order form: ISO code => name. */
function dhlCountries(): array {
    return [
        'FR' => 'France', 'BE' => 'Belgique', 'CH' => 'Suisse', 'LU' => 'Luxembourg', 'DE' => 'Deutschland', 'ES' => 'España', 'IT' => 'Italia',
        'NL' => 'Nederland', 'PT' => 'Portugal', 'AT' => 'Österreich', 'IE' => 'Ireland', 'GB' => 'United Kingdom', 'SE' => 'Sverige', 'NO' => 'Norge',
        'DK' => 'Danmark', 'FI' => 'Suomi', 'PL' => 'Polska', 'CZ' => 'Česko', 'GR' => 'Ελλάδα', 'TR' => 'Türkiye',
        'CA' => 'Canada', 'US' => 'United States', 'AU' => 'Australia',
        'TN' => 'Tunisie', 'MA' => 'Maroc', 'LY' => 'Libya', 'EG' => 'Égypte', 'MR' => 'Mauritanie', 'SN' => 'Sénégal', 'CI' => 'Côte d\'Ivoire',
        'SA' => 'Saudi Arabia', 'AE' => 'United Arab Emirates', 'QA' => 'Qatar', 'KW' => 'Kuwait', 'OM' => 'Oman', 'BH' => 'Bahrain', 'JO' => 'Jordan', 'LB' => 'Lebanon',
    ];
}
/** Countries where DHL does not need a postal code. */
const DHL_NO_ZIP = ['AE', 'QA', 'KW', 'OM', 'BH', 'SA', 'LY', 'MR', 'CI', 'IE', 'JO', 'LB', 'EG'];
