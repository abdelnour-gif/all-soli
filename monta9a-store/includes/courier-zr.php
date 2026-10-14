<?php
/**
 * ZR Express integration — the new ZR platform (https://api.zrexpress.app).
 *
 * Every request carries two headers from the ZR Express dashboard:
 *   X-Api-Key: {Secret Key}     X-Tenant: {Tenant ID (UUID)}
 *
 *   POST /api/v1/territories/search        {keyword,pageSize,pageNumber} -> {items:[{id,code,name,level,parentId}]}
 *   POST /api/v1/hubs/search               {pageSize,pageNumber}         -> {items:[{id,name,isPickupPoint,address{...}}]}
 *   POST /api/v1/customers/individual      {name, phone:{number1}}        -> {id}
 *   POST /api/v1/parcels                   {customer, deliveryAddress, deliveryType, amount, ...} -> {id}
 *   GET  /api/v1/parcels/{id|tracking}     -> {id, trackingNumber, state{name,description}, situation{name}, ...}
 *   GET  /api/v1/parcels/{id}/state-history
 *   POST /api/v1/parcels/labels/individual/pdf {trackingNumbers:[...], format:"a6"|"a4"} -> {parcelLabelFiles:[{fileUrl}]}
 *
 * Territories are UUIDs: a wilaya ("wilaya" level, code = 1..58) contains communes ("commune" level).
 * Office pickup ("pickup-point") needs the id of a ZR hub that is a pickup point.
 *
 * Settings live in database/couriers-config.php (filled in /admin/solivr).
 * This file is loaded by includes/courier.php — do not include it directly.
 */

const ZR_DEFAULT_URL = 'https://api.zrexpress.app';
const ZR_KEYS = ['api_key', 'tenant', 'url', 'note', 'fragile', 'set_shipped', 'label_format', 'active'];

/** All ZR Express settings (stored with the other companies in database/couriers-config.php). */
function zrConfig(bool $reload = false): array {
    $defaults = ['api_key' => '', 'tenant' => '', 'url' => '', 'note' => '', 'fragile' => '0', 'set_shipped' => '1', 'label_format' => 'a6'];
    return array_merge($defaults, courierCfg('zr'));
}

function zr(string $key): string {
    return (string)(zrConfig()[$key] ?? '');
}

function zrConfigured(): bool {
    return zr('api_key') !== '' && zr('tenant') !== '';
}

function zrBaseUrl(): string {
    $url = rtrim(zr('url') ?: ZR_DEFAULT_URL, '/');
    return preg_replace('#/api(/v\d+)?$#i', '', $url);
}

/**
 * Call the ZR Express API. $body is sent as JSON (except for GET).
 * Returns [httpCode, decodedBody|null, rawBody]. Throws RuntimeException on network failure.
 */
function zrRequest(string $method, string $path, ?array $body = null): array {
    if (!zrConfigured()) throw new RuntimeException('لم يتم إدخال Secret Key و Tenant ID الخاصين بـ ZR Express');
    $method = strtoupper($method);
    $url = zrBaseUrl() . '/api/v1/' . ltrim($path, '/');
    $headers = ['Accept: application/json', 'X-Api-Key: ' . zr('api_key'), 'X-Tenant: ' . zr('tenant')];
    $payload = null;
    if ($method !== 'GET' && $body !== null) {
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE);
        $headers[] = 'Content-Type: application/json';
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false) throw new RuntimeException('تعذر الاتصال بخادم ZR Express: ' . $err);
    } else {
        $ctx = stream_context_create(['http' => [
            'method' => $method, 'header' => implode("\r\n", $headers),
            'content' => $payload ?? '', 'timeout' => 30, 'ignore_errors' => true, 'follow_location' => 0,
        ]]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false) throw new RuntimeException('تعذر الاتصال بخادم ZR Express');
        $code = 0;
        foreach ($http_response_header ?? [] as $h) if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $code = (int)$m[1];
    }
    $resp = preg_replace('/^\xEF\xBB\xBF/', '', (string)$resp);
    return [$code, json_decode($resp, true), $resp];
}

/** Turn a ZR error response (RFC 7807 problem details) into a readable message. */
function zrError(int $code, $data, string $raw): string {
    if ($code === 401 || $code === 403) return 'Secret Key أو Tenant ID غير صحيح (رفض ZR Express الدخول)';
    if ($code === 429) return 'طلبات كثيرة إلى ZR Express في وقت قصير، انتظر دقيقة ثم أعد المحاولة';
    $parts = [];
    $collect = function ($v) use (&$parts, &$collect) {
        if (is_string($v) && trim($v) !== '') $parts[] = trim($v);
        elseif (is_array($v)) foreach ($v as $x) $collect($x);
    };
    if (is_array($data)) {
        foreach (['title', 'detail', 'message', 'errors', 'errorMessage'] as $k) if (isset($data[$k])) $collect($data[$k]);
        foreach ((array)($data['failures'] ?? []) as $f) if (is_array($f)) $collect($f['errorMessage'] ?? $f['errorCode'] ?? '');
    }
    if (!$parts) $parts[] = 'رد غير متوقع (HTTP ' . $code . '): ' . mb_substr(trim(strip_tags($raw)), 0, 200);
    return implode(' — ', array_unique($parts));
}

/** Accent-free text: ZR stores names without accents and its search is accent-sensitive. */
function zrPlain(string $s): string {
    if (class_exists('Normalizer')) {
        $n = Normalizer::normalize($s, Normalizer::FORM_D);
        if (is_string($n)) $s = preg_replace('/\p{Mn}+/u', '', $n);
    } elseif (function_exists('iconv')) {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($t !== false && $t !== '') $s = $t;
    }
    return trim(str_replace(["'", '’', '`'], '', $s));
}

/** [ok, message] — checks that ZR accepts the saved Secret Key and Tenant ID. */
function zrTestToken(): array {
    [$code, $data, $raw] = zrRequest('POST', 'territories/search', ['keyword' => 'Alger', 'pageSize' => 5, 'pageNumber' => 1]);
    if ($code === 200 && is_array($data) && array_key_exists('items', $data)) {
        $hubs = count(zrHubs(true));
        return [true, 'الاتصال ناجح ✔ — Secret Key و Tenant ID مقبولان لدى ZR Express' . ($hubs ? ' — عدد مكاتب الاستلام: ' . $hubs : '')];
    }
    return [false, zrError($code, $data, $raw)];
}

/**
 * All ZR territories, cached 7 days:
 * ['w' => [wilayaCode => territoryId], 'c' => [wilayaCode => [['id','name','home','desk'], ...]], 'n' => [territoryId => wilayaCode]]
 * An empty answer is never cached.
 */
function zrTerritories(bool $fresh = false): array {
    static $mem = null;
    if ($mem !== null && !$fresh) return $mem;
    $cached = json_decode(setting('zr_territories'), true);
    $old = is_array($cached) && !empty($cached['w']) ? $cached : null;
    if (!$fresh && $old && ($old['t'] ?? 0) > time() - 604800) return $mem = $old;

    $items = [];
    try {
        for ($page = 1; $page <= 10; $page++) {
            [$code, $data, $raw] = zrRequest('POST', 'territories/search', ['keyword' => '', 'pageSize' => 1000, 'pageNumber' => $page]);
            if ($code !== 200 || !is_array($data)) {
                if ($page === 1) throw new RuntimeException(zrError($code, $data, $raw));
                break;
            }
            $rows = is_array($data['items'] ?? null) ? $data['items'] : [];
            foreach ($rows as $r) if (is_array($r)) $items[] = $r;
            if (!$rows || $page >= (int)($data['totalPages'] ?? 1)) break;
        }
    } catch (Throwable $e) {
        if ($old) return $mem = $old;
        throw $e;
    }

    $w = []; $n = []; $names = [];
    foreach ($items as $r) {
        if (strtolower((string)($r['level'] ?? '')) === 'wilaya' && (int)($r['code'] ?? 0) >= 1 && (int)$r['code'] <= 58) {
            $w[(int)$r['code']] = (string)$r['id'];
            $names[(int)$r['code']] = trim((string)($r['name'] ?? ''));
            $n[(string)$r['id']] = (int)$r['code'];
        }
    }
    $c = [];
    foreach ($items as $r) {
        if (strtolower((string)($r['level'] ?? '')) !== 'commune') continue;
        $wid = $n[(string)($r['parentId'] ?? '')] ?? 0;
        if (!$wid || trim((string)($r['name'] ?? '')) === '') continue;
        $c[$wid][] = [
            'id'   => (string)$r['id'],
            'name' => trim((string)$r['name']),
            'home' => !isset($r['delivery']['hasHomeDelivery']) || !empty($r['delivery']['hasHomeDelivery']),
            'desk' => !empty($r['delivery']['hasPickupPoint']),
        ];
    }
    if (!$w) {
        if ($old) return $mem = $old;
        throw new RuntimeException('لم يُرجع ZR Express قائمة الولايات');
    }
    $mem = ['t' => time(), 'w' => $w, 'c' => $c, 'n' => $n, 'names' => $names];
    saveSetting('zr_territories', json_encode($mem, JSON_UNESCAPED_UNICODE));
    return $mem;
}

/** ZR territory id of a wilaya (1-58), or '' when ZR does not serve it. */
function zrWilayaId(int $wilayaId): string {
    return (string)(zrTerritories()['w'][$wilayaId] ?? '');
}

/**
 * Pickup-point hubs (offices), cached 12h:
 * [['wilaya'=>int,'id'=>hubId,'name'=>,'commune'=>,'district_id'=>,'city_id'=>,'address'=>], ...]
 */
function zrHubs(bool $fresh = false): array {
    static $mem = null;
    if ($mem !== null && !$fresh) return $mem;
    $cached = json_decode(setting('zr_hubs'), true);
    $old = is_array($cached) ? (array)($cached['list'] ?? []) : [];
    if (!$fresh && $old && ($cached['t'] ?? 0) > time() - 43200) return $mem = $old;
    try {
        $rows = [];
        for ($page = 1; $page <= 10; $page++) {
            [$code, $data, $raw] = zrRequest('POST', 'hubs/search', ['pageSize' => 200, 'pageNumber' => $page]);
            if ($code !== 200 || !is_array($data)) {
                if ($page === 1) throw new RuntimeException('HTTP ' . $code . ': ' . zrError($code, $data, $raw));
                break;
            }
            $items = is_array($data['items'] ?? null) ? $data['items'] : [];
            foreach ($items as $h) if (is_array($h)) $rows[] = $h;
            if (!$items || $page >= (int)($data['totalPages'] ?? 1)) break;
        }
        $terr = zrTerritories();
    } catch (Throwable $e) {
        saveSetting('zr_hubs_error', date('Y-m-d H:i') . ' — ' . $e->getMessage());
        return $mem = $old;
    }
    // Wilaya of an office: from its wilaya territory, else from its commune territory, else from the wilaya name.
    $byTerritory = $terr['n'];
    $byCommune = [];
    foreach ($terr['c'] as $wid => $cs) foreach ($cs as $c) $byCommune[$c['id']] = (int)$wid;
    $byName = [];
    foreach ($terr['names'] ?? [] as $wid => $n) $byName[communeKey($n)] = (int)$wid;
    $list = [];
    foreach ($rows as $h) {
        if (empty($h['isPickupPoint']) || (isset($h['isVisible']) && $h['isVisible'] === false)) continue;
        $a = is_array($h['address'] ?? null) ? $h['address'] : [];
        $cityId = (string)($a['cityTerritoryId'] ?? '');
        $wilaya = (int)($byTerritory[$cityId] ?? $byCommune[(string)($a['districtTerritoryId'] ?? '')] ?? 0);
        if (!$wilaya && ($k = communeKey(zrPlain((string)($a['city'] ?? '')))) !== '') $wilaya = $byName[$k] ?? 0;
        $list[] = [
            'wilaya'      => $wilaya,
            'id'          => (string)$h['id'],
            'name'        => trim((string)($h['name'] ?? '')),
            'commune'     => trim((string)($a['district'] ?? '')),
            'district_id' => (string)($a['districtTerritoryId'] ?? ''),
            'city_id'     => $cityId,
            'address'     => trim((string)($a['street'] ?? '')),
        ];
    }
    if (!$list) {
        saveSetting('zr_hubs_error', date('Y-m-d H:i') . ' — no pickup point in the answer');
        return $mem = $old;
    }
    saveSetting('zr_hubs', json_encode(['t' => time(), 'list' => $list], JSON_UNESCAPED_UNICODE));
    saveSetting('zr_hubs_error', '');
    return $mem = $list;
}

/** Offices of one wilaya: [['name' => shown to the customer, 'code' => hub id, 'commune' => ..., 'stop_desk' => true], ...]. */
function zrDesks(int $wilayaId): array {
    $out = [];
    foreach (zrHubs() as $h) {
        if ($h['wilaya'] !== $wilayaId) continue;
        $name = $h['name'] !== '' ? $h['name'] : $h['commune'];
        if ($h['commune'] !== '' && $h['name'] !== '' && stripos(communeKey($h['name']), communeKey($h['commune'])) === false) {
            $name = $h['commune'] . ' (' . $h['name'] . ')';
        }
        $out[] = ['name' => $name !== '' ? $name : $h['id'], 'code' => $h['id'], 'commune' => $h['commune'], 'address' => $h['address'], 'stop_desk' => true, 'hub' => $h];
    }
    usort($out, fn($a, $b) => strcmp($a['name'], $b['name']));
    // Two offices must never share a visible name.
    $seen = [];
    foreach ($out as &$d) {
        if (isset($seen[$d['name']])) $d['name'] .= ' #' . substr($d['code'], 0, 4);
        $seen[$d['name']] = true;
    }
    return $out;
}

/** Communes ZR delivers to in a wilaya: [['name' => ..., 'stop_desk' => bool, 'id' => territory id], ...]. */
function zrCommunes(int $wilayaId): array {
    $list = zrTerritories()['c'][$wilayaId] ?? [];
    $deskIds = array_flip(array_column(array_filter(zrHubs(), fn($h) => $h['wilaya'] === $wilayaId), 'district_id'));
    $out = [];
    foreach ($list as $c) $out[] = ['name' => $c['name'], 'stop_desk' => isset($deskIds[$c['id']]) || $c['desk'], 'id' => $c['id']];
    usort($out, fn($a, $b) => strcmp($a['name'], $b['name']));
    return $out;
}

/** Find a commune territory by name in a wilaya (accent/spelling-insensitive). Searches ZR when not in the cache. */
function zrFindCommune(int $wilayaId, string ...$names): ?array {
    $list = zrCommunes($wilayaId);
    if ($list) {
        $match = courierMatchCommune($list, ...array_map('zrPlain', $names));
        if ($match === null) $match = courierMatchCommune($list, ...$names);
        if ($match !== null) foreach ($list as $c) if ($c['name'] === $match) return $c;
        return null;
    }
    // The territory list had no communes for this wilaya: ask ZR by name.
    $cityId = zrWilayaId($wilayaId);
    foreach ($names as $n) {
        $n = zrPlain($n);
        if ($n === '' || preg_match('/\p{Arabic}/u', $n)) continue;
        [$code, $data] = zrRequest('POST', 'territories/search', ['keyword' => $n, 'pageSize' => 50, 'pageNumber' => 1]);
        if ($code !== 200 || !is_array($data['items'] ?? null)) continue;
        foreach ($data['items'] as $t) {
            if (!is_array($t) || strtolower((string)($t['level'] ?? '')) !== 'commune') continue;
            if (($cityId !== '' && ($t['parentId'] ?? '') === $cityId) || communeKey((string)($t['name'] ?? '')) === communeKey($n)) {
                return ['name' => (string)$t['name'], 'stop_desk' => false, 'id' => (string)$t['id']];
            }
        }
    }
    return null;
}

/** ZR phone format: +213XXXXXXXXX. */
function zrPhone(string $p): string {
    $p = preg_replace('/\D+/', '', $p);
    if (str_starts_with($p, '00')) $p = substr($p, 2);
    if (str_starts_with($p, '213')) return '+' . $p;
    if (str_starts_with($p, '0')) $p = substr($p, 1);
    return '+213' . $p;
}

/**
 * Create the parcel at ZR Express for a store order.
 * $override: exact ZR commune (home delivery) or office name (office pickup) chosen by the admin.
 * $mode: '' = as the customer ordered, 'home' = force home delivery, 'office' = force office pickup.
 * Returns ['tracking' => ..., 'label' => '']. Throws CourierCommuneException when the admin must decide.
 */
function zrSendOrder(array $order, string $override = '', string $mode = ''): array {
    $db = getDB();
    $wilayaId = (int)$order['wilaya_id'];
    $stopDesk = $mode !== '' ? $mode === 'office' : $order['delivery_type'] === 'office';
    $local = findCommune($wilayaId, (string)$order['commune']);
    $candidates = array_values(array_filter([$order['commune_fr'] ?? '', $local[1] ?? '', (string)$order['commune']]));

    $cityId = zrWilayaId($wilayaId);
    if ($cityId === '') {
        throw new RuntimeException('ZR Express لا توصّل إلى ولاية ' . $order['wilaya_name'] . ' (' . $wilayaId . ')');
    }

    $hubId = null;
    if ($stopDesk) {
        $desks = zrDesks($wilayaId);
        $want = $override !== '' ? $override : trim((string)($order['desk_commune'] ?? ''));
        $found = null;
        foreach ($desks as $d) if ($want !== '' && ($d['name'] === $want || $d['code'] === $want)) { $found = $d; break; }
        if (!$found && $want !== '') foreach ($desks as $d) if ($d['commune'] !== '' && communeKey($d['commune']) === communeKey(zrPlain($want))) { $found = $d; break; }
        if (!$found && $override === '') {
            foreach ($desks as $d) foreach ($candidates as $c) if ($d['commune'] !== '' && communeKey($d['commune']) === communeKey(zrPlain($c))) { $found = $d; break 2; }
        }
        if (!$found) {
            throw new CourierCommuneException('اختار الزبون «استلام من المكتب» ولم نجد مكتباً لـ ZR Express في «' . ($want !== '' ? $want : $order['commune']) . '». اختر أقرب مكتب في الولاية، أو أرسل الطلب كتوصيل إلى المنزل.', $desks, 'desk');
        }
        $hub = $found['hub'];
        $hubId = $hub['id'];
        $districtId = $hub['district_id'];
        $cityId = $hub['city_id'] !== '' ? $hub['city_id'] : $cityId;
        if ($districtId === '') {
            $c = zrFindCommune($wilayaId, $hub['commune'], ...$candidates);
            if (!$c) throw new RuntimeException('مكتب ZR Express «' . $found['name'] . '» بدون بلدية في بياناته — اختر مكتباً آخر');
            $districtId = $c['id'];
        }
    } else {
        $names = zrCommunes($wilayaId);
        if ($override !== '') {
            $c = zrFindCommune($wilayaId, $override);
            if (!$c) throw new CourierCommuneException('البلدية المختارة غير موجودة في قائمة ZR Express', $names);
        } else {
            $c = zrFindCommune($wilayaId, ...$candidates);
            if (!$c) throw new CourierCommuneException('لم نجد البلدية «' . $order['commune'] . '» في قائمة ZR Express لهذه الولاية. اختر البلدية المقابلة ثم أعد الإرسال.', $names);
        }
        $districtId = $c['id'];
    }

    // 1) ZR needs a customer record before the parcel.
    $name = mb_substr(trim((string)$order['customer_name']), 0, 120) ?: 'Client';
    $phone = zrPhone((string)$order['phone']);
    [$code, $data, $raw] = zrRequest('POST', 'customers/individual', ['name' => $name, 'phone' => ['number1' => $phone]]);
    $customerId = is_array($data) ? (string)($data['id'] ?? '') : '';
    if ($code >= 300 || $customerId === '') throw new RuntimeException('رفض ZR Express بيانات الزبون: ' . zrError($code, $data, $raw));

    // 2) The parcel.
    $items = $db->prepare('SELECT product_name, color, qty FROM order_items WHERE order_id = ?');
    $items->execute([$order['id']]);
    $rows = $items->fetchAll();
    $article = implode(' + ', array_map(function ($i) {
        $color = trim(preg_replace('/\s*\(#[0-9a-fA-F]{6}\)/', '', (string)$i['color']));
        return $i['product_name'] . ($color !== '' ? ' (' . $color . ')' : '') . ' x' . (int)$i['qty'];
    }, $rows)) ?: 'Produit';
    $note = trim(($order['gift_wrap'] ? 'تغليف هدية. ' : '') . (zr('fragile') === '1' ? 'قابل للكسر (Fragile). ' : '') . zr('note'));
    $address = trim((string)$order['address']) !== '' ? (string)$order['address'] : (string)$order['commune'];
    $amount = (int)$order['total'];

    $parcel = [
        'customer'        => ['customerId' => $customerId, 'name' => $name, 'phone' => ['number1' => $phone]],
        'deliveryAddress' => ['cityTerritoryId' => $cityId, 'districtTerritoryId' => $districtId, 'street' => mb_substr($address, 0, 250)],
        'deliveryType'    => $stopDesk ? 'pickup-point' : 'home',
        'amount'          => $amount,
        'description'     => mb_substr($article . ($note !== '' ? ' — ' . $note : ''), 0, 250),
        'externalId'      => (string)$order['id'],
        'orderedProducts' => [['productName' => mb_substr($article, 0, 250), 'unitPrice' => $amount, 'quantity' => 1, 'stockType' => 'none']],
    ];
    if ($hubId) $parcel['hubId'] = $hubId;

    [$code, $data, $raw] = zrRequest('POST', 'parcels', $parcel);
    $parcelId = is_array($data) ? (string)($data['id'] ?? '') : '';
    if ($code >= 300 || $parcelId === '') {
        if ($stopDesk && preg_match('/hub/i', $raw)) {
            throw new CourierCommuneException('رفض ZR Express مكتب الاستلام: ' . zrError($code, $data, $raw) . ' — اختر مكتباً آخر أو أرسل الطلب كتوصيل إلى المنزل.', zrDesks($wilayaId), 'desk');
        }
        throw new RuntimeException('رفض ZR Express الطلب: ' . zrError($code, $data, $raw));
    }

    // 3) The tracking number comes with the parcel details (it can take a moment to be assigned).
    $tracking = '';
    for ($try = 0; $try < 4 && $tracking === ''; $try++) {
        if ($try) usleep(800000);
        try {
            [$c2, $d2] = zrRequest('GET', 'parcels/' . rawurlencode($parcelId));
            if ($c2 === 200 && is_array($d2)) $tracking = trim((string)($d2['trackingNumber'] ?? ''));
        } catch (Throwable $e) {}
    }
    // The parcel exists at ZR: never fail here (a retry would create it twice). Its id also works for tracking.
    return ['tracking' => $tracking !== '' ? $tracking : $parcelId, 'label' => ''];
}

/** Latest status of a parcel at ZR: ['status' => ..., 'step' => ..., 'date' => ..., 'history' => [...]] or null. */
function zrParcelStatus(string $tracking): ?array {
    [$code, $data] = zrRequest('GET', 'parcels/' . rawurlencode($tracking));
    if ($code !== 200 || !is_array($data)) return null;
    $state = is_array($data['state'] ?? null) ? $data['state'] : [];
    $situation = is_array($data['situation'] ?? null) ? $data['situation'] : [];
    $step = trim((string)($state['description'] ?? '')) ?: trim((string)($state['name'] ?? ''));
    return [
        'status'  => trim((string)($situation['name'] ?? '')),
        'step'    => $step,
        'date'    => (string)($data['lastStateUpdateAt'] ?? $data['updatedAt'] ?? ''),
        'history' => [],
    ];
}

/** Temporary link (about 1 hour) to the PDF label of a parcel, or '' on failure. */
function zrLabelUrl(string $tracking): string {
    $format = zr('label_format') === 'a4' ? 'a4' : 'a6';
    [$code, $data] = zrRequest('POST', 'parcels/labels/individual/pdf', ['trackingNumbers' => [$tracking], 'format' => $format]);
    if ($code !== 200 || !is_array($data)) return '';
    foreach ((array)($data['parcelLabelFiles'] ?? []) as $f) {
        $u = is_array($f) ? (string)($f['fileUrl'] ?? '') : '';
        if (preg_match('#^https://#i', $u)) return $u;
    }
    return '';
}
