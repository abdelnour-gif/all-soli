<?php
/**
 * NOEST Express (app.noest-dz.com/api/public, Bearer token + user GUID)
 * and Maystro Delivery (backend.maystro-delivery.com/api, "Token" header).
 * Loaded by includes/courier.php.
 */

/* ---------- NOEST ---------- */

function noestRequest(string $key, string $method, string $path, $body = null, bool $raw = false): array {
    $c = courierCfg($key);
    $base = rtrim(trim((string)($c['url'] ?? '')) ?: (courierCatalog()[$key][2] ?? ''), '/');
    $headers = ['Authorization: Bearer ' . trim((string)($c['token'] ?? '')), 'Accept: ' . ($raw ? '*/*' : 'application/json')];
    $payload = null;
    if ($body !== null) { $payload = json_encode($body, JSON_UNESCAPED_UNICODE); $headers[] = 'Content-Type: application/json'; }
    [$code, $resp] = courierHttp($method, $base . '/api/public/' . ltrim($path, '/'), $headers, $payload, 30);
    return [$code, $raw ? null : json_decode($resp, true), $resp];
}

function noestTest(string $key): array {
    [$code, $data, $raw] = noestRequest($key, 'GET', 'desks');
    if ($code === 200 && is_array($data)) return [true, 'الاتصال ناجح ✔ — الرمز مقبول لدى ' . courierName($key)];
    return [false, courierErrorText($code, $data, $raw, courierName($key))];
}

function noestAllDesks(string $key): array {
    return courierCache('noest_desks_' . $key, 43200, function () use ($key) {
        [$code, $data] = noestRequest($key, 'GET', 'desks');
        if ($code !== 200 || !is_array($data)) return [];
        $out = [];
        foreach ($data as $d) {
            if (!is_array($d) || !isset($d['code'])) continue;
            $w = (int)$d['code'];
            if ($w < 1 || $w > 58) continue;
            $out[] = ['wilaya' => $w, 'code' => (string)$d['code'], 'name' => trim((string)($d['name'] ?? $d['code'])),
                      'commune' => trim((string)($d['commune'] ?? '')), 'address' => trim((string)($d['address'] ?? ''))];
        }
        return $out;
    });
}

function noestDesks(string $key, int $w): array {
    $out = [];
    foreach (noestAllDesks($key) as $d) if ($d['wilaya'] === $w) $out[] = $d + ['stop_desk' => true];
    return $out;
}

function noestSend(string $key, array $order, string $override, string $mode): array {
    $name = courierName($key);
    $w = (int)$order['wilaya_id'];
    $stopDesk = $mode !== '' ? $mode === 'office' : $order['delivery_type'] === 'office';
    $local = findCommune($w, (string)$order['commune']);
    $commune = $override !== '' && !$stopDesk ? $override : ((string)($order['commune_fr'] ?? '') ?: ($local[1] ?? (string)$order['commune']));
    $station = '';
    if ($stopDesk) {
        $desks = noestDesks($key, $w);
        $want = $override !== '' ? $override : trim((string)($order['desk_commune'] ?? ''));
        $found = null;
        foreach ($desks as $d) if ($want !== '' && ($d['name'] === $want || $d['code'] === $want)) { $found = $d; break; }
        if (!$found && count($desks) === 1 && $override === '') $found = $desks[0];
        if (!$found) throw new CourierCommuneException('اختر مكتب ' . $name . ' للاستلام، أو أرسل الطلب كتوصيل إلى المنزل.', $desks, 'desk');
        $station = $found['code'];
        if ($found['commune'] !== '') $commune = $found['commune'];
    }
    $art = courierArticles($order);
    $body = [
        'user_guid' => trim((string)(courierCfg($key)['guid'] ?? '')),
        'reference' => (string)$order['id'],
        'client'    => mb_substr((string)$order['customer_name'], 0, 255),
        'phone'     => preg_replace('/\D+/', '', (string)$order['phone']),
        'adresse'   => mb_substr(trim((string)$order['address']) !== '' ? (string)$order['address'] : (string)$order['commune'], 0, 255),
        'wilaya_id' => $w,
        'commune'   => $commune,
        'montant'   => (int)$order['total'],
        'produit'   => $art['text'],
        'type_id'   => 1,
        'stop_desk' => $stopDesk ? 1 : 0,
        'poids'     => 1,
    ];
    if ($station !== '') $body['station_code'] = $station;
    if (($note = courierNote($key, $order)) !== '') $body['remarque'] = $note;
    [$code, $data, $raw] = noestRequest($key, 'POST', 'create/order', $body);
    $tracking = is_array($data) ? trim((string)($data['tracking'] ?? '')) : '';
    if ($code >= 300 || $tracking === '') throw new RuntimeException('رفضت ' . $name . ' الطلب: ' . courierErrorText($code, $data, $raw, $name));
    if ((courierCfg($key)['auto_validate'] ?? '0') === '1') {
        try { noestRequest($key, 'POST', 'valid/order', ['user_guid' => $body['user_guid'], 'tracking' => $tracking]); } catch (Throwable $e) {}
    }
    return ['tracking' => $tracking, 'label' => ''];
}

function noestLabel(string $key, string $tracking): array {
    [$code, , $raw] = noestRequest($key, 'GET', 'get/order/label?tracking=' . rawurlencode($tracking), null, true);
    return $code === 200 && str_starts_with($raw, '%PDF') ? ['pdf' => $raw] : [];
}

/* ---------- Maystro ---------- */

function maystroRequest(string $key, string $method, string $path, $body = null, bool $raw = false): array {
    $c = courierCfg($key);
    $base = rtrim(trim((string)($c['url'] ?? '')) ?: (courierCatalog()[$key][2] ?? ''), '/');
    $headers = ['Authorization: Token ' . trim((string)($c['token'] ?? '')), 'Accept: ' . ($raw ? '*/*' : 'application/json')];
    $payload = null;
    if ($body !== null) { $payload = json_encode($body, JSON_UNESCAPED_UNICODE); $headers[] = 'Content-Type: application/json'; }
    [$code, $resp] = courierHttp($method, $base . '/' . ltrim($path, '/'), $headers, $payload, 30);
    return [$code, $raw ? null : json_decode($resp, true), $resp];
}

function maystroTest(string $key): array {
    [$code, $data, $raw] = maystroRequest($key, 'GET', 'base/wilayas/?country=1');
    if (in_array($code, [200, 201], true)) return [true, 'الاتصال ناجح ✔ — الرمز مقبول لدى ' . courierName($key)];
    return [false, courierErrorText($code, $data, $raw, courierName($key))];
}

function maystroCommunes(string $key, int $w): array {
    return courierCache('maystro_communes_' . $key . '_' . $w, 604800, function () use ($key, $w) {
        [$code, $data] = maystroRequest($key, 'GET', 'base/communes/?wilaya=' . $w);
        if ($code !== 200 || !is_array($data)) return [];
        $rows = isset($data['results']) && is_array($data['results']) ? $data['results'] : $data;
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r) || !isset($r['id'])) continue;
            $n = trim((string)($r['name'] ?? $r['nom'] ?? ''));
            if ($n !== '') $out[] = ['name' => $n, 'stop_desk' => false, 'id' => (int)$r['id']];
        }
        return $out;
    });
}

function maystroSend(string $key, array $order, string $override, string $mode): array {
    $name = courierName($key);
    $w = (int)$order['wilaya_id'];
    if (($mode !== '' ? $mode === 'office' : $order['delivery_type'] === 'office')) {
        throw new CourierCommuneException($name . ' في هذا الربط لا تدعم الاستلام من المكتب — أرسل الطلب كتوصيل إلى المنزل.', [], 'desk');
    }
    $list = maystroCommunes($key, $w);
    $local = findCommune($w, (string)$order['commune']);
    $match = $override !== '' ? courierMatchCommune($list, $override)
        : courierMatchCommune($list, ...array_values(array_filter([$order['commune_fr'] ?? '', $local[1] ?? '', (string)$order['commune']])));
    $communeId = 0;
    foreach ($list as $c) if ($c['name'] === $match) $communeId = $c['id'];
    if (!$communeId) throw new CourierCommuneException('لم نجد البلدية «' . $order['commune'] . '» في قائمة ' . $name . '. اختر البلدية المقابلة ثم أعد الإرسال.', $list);
    $art = courierArticles($order);
    $body = [
        'wilaya'            => $w,
        'commune'           => $communeId,
        'destination_text'  => mb_substr(trim((string)$order['address']) !== '' ? (string)$order['address'] : (string)$order['commune'], 0, 255),
        'customer_phone'    => preg_replace('/\D+/', '', (string)$order['phone']),
        'customer_name'     => mb_substr((string)$order['customer_name'], 0, 255),
        'product_price'     => (int)$order['total'],
        'delivery_type'     => 0,
        'express'           => false,
        'note_to_driver'    => courierNote($key, $order),
        'products'          => array_map(fn($r) => ['logistical_description' => mb_substr((string)$r['product_name'], 0, 120), 'quantity' => (int)$r['qty']], $art['rows']) ?: [['logistical_description' => $art['text'], 'quantity' => 1]],
        'source'            => 4,
        'external_order_id' => (string)$order['id'],
    ];
    [$code, $data, $raw] = maystroRequest($key, 'POST', 'stores/orders/', $body);
    $id = is_array($data) ? (string)($data['id'] ?? '') : '';
    $tracking = is_array($data) ? (string)($data['display_id'] ?? $data['tracking'] ?? $data['id'] ?? '') : '';
    if (!in_array($code, [200, 201], true) || $tracking === '') throw new RuntimeException('رفضت ' . $name . ' الطلب: ' . courierErrorText($code, $data, $raw, $name));
    return ['tracking' => $tracking, 'label' => $id !== '' ? 'id:' . $id : ''];
}

function maystroLabel(string $key, string $tracking, string $saved = ''): array {
    $id = str_starts_with($saved, 'id:') ? substr($saved, 3) : $tracking;
    [$code, , $raw] = maystroRequest($key, 'POST', 'delivery/starter/starter_bordureau/', ['all_created' => true, 'orders_ids' => [$id]], true);
    return in_array($code, [200, 201], true) && str_starts_with($raw, '%PDF') ? ['pdf' => $raw] : [];
}
