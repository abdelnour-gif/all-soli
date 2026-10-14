<?php
/**
 * Yalidine platform — Yalidine (api.yalidine.app), Guepex (api.guepex.app), Yalitec (api.yalitec.me).
 * Headers X-API-ID / X-API-TOKEN from the company's developer dashboard.
 *   GET  /v1/wilayas/  /v1/communes/?wilaya_id=  /v1/centers/  /v1/fees/?from_wilaya_id=&to_wilaya_id=
 *   POST /v1/parcels/ [ {...} ]   GET /v1/parcels/{tracking}   GET /v1/histories/{tracking}
 * Loaded by includes/courier.php.
 */

function ylBase(string $key): string {
    $u = rtrim(trim((string)(courierCfg($key)['url'] ?? '')) ?: (courierCatalog()[$key][2] ?? ''), '/');
    return preg_match('#/v1$#', $u) ? $u : $u . '/v1';
}

/** Returns [httpCode, decoded|null, raw]. */
function ylRequest(string $key, string $method, string $path, $body = null): array {
    $c = courierCfg($key);
    if (trim((string)($c['api_id'] ?? '')) === '' || trim((string)($c['api_token'] ?? '')) === '') {
        throw new RuntimeException('لم يتم إدخال API ID و API Token الخاصين بـ ' . courierName($key));
    }
    $headers = ['X-API-ID: ' . trim($c['api_id']), 'X-API-TOKEN: ' . trim($c['api_token']), 'Accept: application/json'];
    $payload = null;
    if ($body !== null) { $payload = json_encode($body, JSON_UNESCAPED_UNICODE); $headers[] = 'Content-Type: application/json'; }
    [$code, $raw] = courierHttp($method, ylBase($key) . '/' . ltrim($path, '/'), $headers, $payload, 30);
    return [$code, json_decode($raw, true), $raw];
}

/** All pages of a list endpoint. */
function ylList(string $key, string $path, int $maxPages = 10): array {
    $rows = [];
    for ($page = 1; $page <= $maxPages; $page++) {
        $sep = str_contains($path, '?') ? '&' : '?';
        [$code, $data, $raw] = ylRequest($key, 'GET', $path . $sep . 'page_size=1000&page=' . $page);
        if ($code !== 200 || !is_array($data)) {
            if ($page === 1) throw new RuntimeException(courierErrorText($code, $data, $raw, courierName($key)));
            break;
        }
        foreach ((array)($data['data'] ?? []) as $r) if (is_array($r)) $rows[] = $r;
        if (empty($data['has_more']) || empty($data['data'])) break;
    }
    return $rows;
}

function ylTest(string $key): array {
    [$code, $data, $raw] = ylRequest($key, 'GET', 'wilayas/?page_size=1');
    if ($code === 200 && is_array($data) && isset($data['data'])) return [true, 'الاتصال ناجح ✔ — البيانات مقبولة لدى ' . courierName($key)];
    return [false, courierErrorText($code, $data, $raw, courierName($key))];
}

/** id => name, as the company writes them (needed when creating parcels). */
function ylWilayas(string $key): array {
    return courierCache('yl_wilayas_' . $key, 604800, function () use ($key) {
        $out = [];
        foreach (ylList($key, 'wilayas/', 1) as $w) if (isset($w['id'], $w['name'])) $out[(int)$w['id']] = (string)$w['name'];
        return $out;
    });
}

function ylCommunes(string $key, int $w): array {
    return courierCache('yl_communes_' . $key . '_' . $w, 604800, function () use ($key, $w) {
        $out = [];
        foreach (ylList($key, 'communes/?wilaya_id=' . $w, 2) as $c) {
            if (isset($c['is_deliverable']) && !$c['is_deliverable']) continue;
            if (trim((string)($c['name'] ?? '')) === '') continue;
            $out[] = ['name' => (string)$c['name'], 'stop_desk' => !empty($c['has_stop_desk']), 'id' => (int)($c['id'] ?? 0)];
        }
        return $out;
    });
}

/** All offices (centers) of the company, cached 12h. */
function ylAllCenters(string $key): array {
    return courierCache('yl_centers_' . $key, 43200, function () use ($key) {
        $out = [];
        foreach (ylList($key, 'centers/', 5) as $c) {
            if (!isset($c['center_id'])) continue;
            $out[] = ['wilaya' => (int)($c['wilaya_id'] ?? 0), 'code' => (string)$c['center_id'], 'name' => trim((string)($c['name'] ?? '')),
                      'commune' => trim((string)($c['commune_name'] ?? '')), 'address' => trim((string)($c['address'] ?? ''))];
        }
        return $out;
    });
}

function ylDesks(string $key, int $w): array {
    $out = [];
    foreach (ylAllCenters($key) as $c) {
        if ($c['wilaya'] !== $w) continue;
        $out[] = ['name' => $c['name'] !== '' ? $c['name'] : $c['commune'], 'code' => $c['code'], 'commune' => $c['commune'], 'address' => $c['address'], 'stop_desk' => true];
    }
    usort($out, fn($a, $b) => strcmp($a['name'], $b['name']));
    return $out;
}

/** ['home' => int, 'office' => int] for a commune (or the cheapest of the wilaya), null when unknown. */
function ylFees(string $key, int $w, string $commune = ''): ?array {
    $from = (int)(courierCfg($key)['from_wilaya'] ?? 16) ?: 16;
    $per = courierCache('yl_fees_' . $key . '_' . $from . '_' . $w, 86400, function () use ($key, $from, $w) {
        [$code, $data] = ylRequest($key, 'GET', 'fees/?from_wilaya_id=' . $from . '&to_wilaya_id=' . $w);
        if ($code !== 200 || !is_array($data)) return [];
        $out = [];
        foreach ((array)($data['per_commune'] ?? []) as $c) {
            if (!is_array($c)) continue;
            $out[(string)($c['commune_name'] ?? '')] = ['home' => (int)($c['express_home'] ?? 0), 'office' => (int)($c['express_desk'] ?? 0)];
        }
        return $out;
    });
    if (!$per) return null;
    if ($commune !== '') {
        $local = findCommune($w, $commune);
        foreach (array_filter([$commune, $local[1] ?? '']) as $n) {
            foreach ($per as $name => $p) if (communeKey($name) === communeKey($n) && $p['home'] > 0) return $p;
        }
    }
    $homes = array_filter(array_column($per, 'home'));
    $desks = array_filter(array_column($per, 'office'));
    return $homes ? ['home' => min($homes), 'office' => $desks ? min($desks) : 0] : null;
}

function ylSend(string $key, array $order, string $override, string $mode): array {
    $name = courierName($key);
    $w = (int)$order['wilaya_id'];
    $stopDesk = $mode !== '' ? $mode === 'office' : $order['delivery_type'] === 'office';
    $wilayas = ylWilayas($key);
    $from = (int)(courierCfg($key)['from_wilaya'] ?? 16) ?: 16;
    if (!isset($wilayas[$w])) throw new RuntimeException($name . ' لا توصّل إلى ولاية ' . $order['wilaya_name']);
    $local = findCommune($w, (string)$order['commune']);
    $candidates = array_values(array_filter([$order['commune_fr'] ?? '', $local[1] ?? '', (string)$order['commune']]));

    $deskId = null;
    if ($stopDesk) {
        $desks = ylDesks($key, $w);
        $want = $override !== '' ? $override : trim((string)($order['desk_commune'] ?? ''));
        $found = null;
        foreach ($desks as $d) if ($want !== '' && ($d['name'] === $want || $d['code'] === $want)) { $found = $d; break; }
        if (!$found && $override === '') foreach ($desks as $d) foreach ($candidates as $c) if (communeKey($d['commune']) === communeKey($c)) { $found = $d; break 2; }
        if (!$found) throw new CourierCommuneException('لم نجد مكتب ' . $name . ' «' . ($want ?: $order['commune']) . '». اختر مكتباً في الولاية، أو أرسل الطلب كتوصيل إلى المنزل.', $desks, 'desk');
        $deskId = (int)$found['code'];
        $commune = $found['commune'];
    } else {
        $list = ylCommunes($key, $w);
        $commune = $override !== '' ? courierMatchCommune($list, $override) : courierMatchCommune($list, ...$candidates);
        if ($commune === null) throw new CourierCommuneException('لم نجد البلدية «' . ($override ?: $order['commune']) . '» في قائمة ' . $name . '. اختر البلدية المقابلة ثم أعد الإرسال.', $list);
    }

    $parts = preg_split('/\s+/u', trim((string)$order['customer_name'])) ?: ['Client'];
    $first = array_shift($parts);
    $art = courierArticles($order);
    $ref = 'CMD' . (int)$order['id'];
    $parcel = [
        'order_id'         => $ref,
        'from_wilaya_name' => $wilayas[$from] ?? wilayaNameFr($from),
        'firstname'        => mb_substr($first, 0, 60),
        'familyname'       => mb_substr($parts ? implode(' ', $parts) : $first, 0, 60),
        'contact_phone'    => preg_replace('/\D+/', '', (string)$order['phone']),
        'address'          => mb_substr(trim((string)$order['address']) !== '' ? (string)$order['address'] : (string)$order['commune'], 0, 250),
        'to_commune_name'  => $commune,
        'to_wilaya_name'   => $wilayas[$w],
        'product_list'     => mb_substr($art['text'] . (($n = courierNote($key, $order)) !== '' ? ' — ' . $n : ''), 0, 250),
        'price'            => (int)$order['total'],
        'do_insurance'     => false,
        'declared_value'   => (int)$order['total'],
        'length' => 1, 'width' => 1, 'height' => 1, 'weight' => 1,
        'freeshipping'     => true,
        'is_stopdesk'      => $stopDesk,
        'stopdesk_id'      => $deskId,
        'has_exchange'     => false,
    ];
    [$code, $data, $raw] = ylRequest($key, 'POST', 'parcels/', [$parcel]);
    $res = is_array($data) ? ($data[$ref] ?? (is_array(reset($data)) ? reset($data) : [])) : [];
    if ($code >= 300 || empty($res['success']) || empty($res['tracking'])) {
        throw new RuntimeException('رفضت ' . $name . ' الطلب: ' . (is_array($res) && !empty($res['message']) ? $res['message'] : courierErrorText($code, $data, $raw, $name)));
    }
    $label = (string)($res['label'] ?? '');
    return ['tracking' => (string)$res['tracking'], 'label' => preg_match('#^https?://#i', $label) ? $label : ''];
}

function ylStatus(string $key, string $tracking): ?array {
    [$code, $data] = ylRequest($key, 'GET', 'histories/' . rawurlencode($tracking));
    $rows = is_array($data) ? array_values(array_filter((array)($data['data'] ?? []), 'is_array')) : [];
    if ($code !== 200 || !$rows) return null;
    usort($rows, fn($a, $b) => strcmp((string)($b['date_status'] ?? ''), (string)($a['date_status'] ?? '')));
    return ['status' => (string)($rows[0]['reason'] ?? ''), 'step' => (string)($rows[0]['status'] ?? ''), 'date' => (string)($rows[0]['date_status'] ?? ''), 'history' => []];
}

function ylLabel(string $key, string $tracking): array {
    [$code, $data] = ylRequest($key, 'GET', 'parcels/' . rawurlencode($tracking));
    $row = is_array($data) ? ($data['data'][0] ?? $data) : [];
    $u = (string)($row['label'] ?? '');
    return preg_match('#^https?://#i', $u) ? ['url' => $u] : [];
}
