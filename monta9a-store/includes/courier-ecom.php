<?php
/**
 * E-COM Delivery integration (https://ecom-dz.net).
 *
 * API: https://ecom-dz.net/Api_v1/...
 * Every request carries two headers from the E-COM dashboard: "Token" and "Key".
 *   GET  Api_v1/Test                       -> {"Quota": {...}}        (checks the credentials)
 *   GET  Api_v1/Commune/{wilaya}           -> {"Commune": [{"Commune","Wilaya","Code_postal"}]}
 *   GET  Api_v1/Stopdesk                   -> {"Commune": [{"Libelle","Code","Adresse","Commune","Ville"}]}
 *   POST Api_v1/Colis        {"Colis":[...]} -> {"Colis": [{"Tracking","label","Commentaire",...}]}
 *   PUT  Api_v1/aExpédier    {"Colis":[{"Tracking"}]}   (mark the parcel ready to ship)
 *   GET  Api_v1/Historique/Tracking/{t}    -> {"Historique": [{"Situation","Avancement","Date_Création"}]}
 *
 * Settings live in database/couriers-config.php (filled in /admin/solivr).
 * This file is loaded by includes/courier.php — do not include it directly.
 */

const ECOM_DEFAULT_URL = 'https://ecom-dz.net';

/** All E-COM settings (stored with the other companies in database/couriers-config.php). */
function ecomConfig(bool $reload = false): array {
    $defaults = ['token' => '', 'key' => '', 'url' => '', 'note' => '', 'fragile' => '0', 'set_shipped' => '1', 'auto_validate' => '0'];
    return array_merge($defaults, courierCfg('ecom'));
}

function ecom(string $key): string {
    return (string)(ecomConfig()[$key] ?? '');
}

function ecomConfigured(): bool {
    return ecom('token') !== '' && ecom('key') !== '';
}

function ecomBaseUrl(): string {
    $url = rtrim(ecom('url') ?: ECOM_DEFAULT_URL, '/');
    return preg_replace('#/Api_v1$#i', '', $url);
}

/**
 * Call the E-COM API. $body is sent as JSON for POST/PUT, as the query string for GET.
 * Returns [httpCode, decodedBody|null, rawBody]. Throws RuntimeException on network failure.
 */
function ecomRequest(string $method, string $path, array $body = []): array {
    if (!ecomConfigured()) throw new RuntimeException('لم يتم إدخال Token و Key الخاصين بـ E-COM Delivery');
    $method = strtoupper($method);
    // Encode each path segment ("aExpédier" has an accented letter).
    $path = implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
    $url = ecomBaseUrl() . '/' . $path;
    $headers = ['Accept: application/json', 'Token: ' . ecom('token'), 'Key: ' . ecom('key')];
    $payload = null;
    if ($method === 'GET') {
        if ($body) $url .= '?' . http_build_query($body);
    } else {
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
        if ($resp === false) throw new RuntimeException('تعذر الاتصال بخادم E-COM Delivery: ' . $err);
    } else {
        $ctx = stream_context_create(['http' => [
            'method' => $method, 'header' => implode("\r\n", $headers),
            'content' => $payload ?? '', 'timeout' => 30, 'ignore_errors' => true, 'follow_location' => 0,
        ]]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false) throw new RuntimeException('تعذر الاتصال بخادم E-COM Delivery');
        $code = 0;
        foreach ($http_response_header ?? [] as $h) if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $code = (int)$m[1];
    }
    $resp = (string)$resp;
    $resp = preg_replace('/^\xEF\xBB\xBF/', '', $resp); // some servers prepend a BOM
    return [$code, json_decode($resp, true), $resp];
}

/** Turn an E-COM error response into a readable message. */
function ecomError(int $code, $data, string $raw): string {
    if ($code === 401 || $code === 403) return 'Token أو Key غير صحيح (رفض E-COM Delivery الدخول)';
    if ($code === 429) return 'طلبات كثيرة إلى E-COM في وقت قصير، انتظر دقيقة ثم أعد المحاولة';
    $parts = [];
    $collect = function ($v) use (&$parts, &$collect) {
        if (is_string($v) && trim($v) !== '') $parts[] = trim($v);
        elseif (is_array($v)) foreach ($v as $x) $collect($x);
    };
    if (is_array($data)) {
        foreach (['message', 'Message', 'error', 'Error', 'errors', 'Erreur', 'Commentaire'] as $k) {
            if (isset($data[$k])) $collect($data[$k]);
        }
        foreach ((array)($data['Colis'] ?? []) as $c) {
            if (is_array($c) && isset($c['Commentaire'])) $collect($c['Commentaire']);
        }
    }
    if (!$parts) $parts[] = 'رد غير متوقع (HTTP ' . $code . '): ' . mb_substr(trim(strip_tags($raw)), 0, 200);
    return implode(' — ', array_unique($parts));
}

/** [ok, message] — checks that E-COM accepts the saved Token and Key. */
function ecomTestToken(): array {
    [$code, $data, $raw] = ecomRequest('GET', 'Api_v1/Test');
    if ($code === 200 && is_array($data) && isset($data['Quota'])) {
        $q = $data['Quota'];
        $extra = is_array($q) && isset($q['Limite_24h']) ? ' — الحصة اليومية: ' . (int)($q['Consommer_24h'] ?? 0) . ' / ' . (int)$q['Limite_24h'] . ' طلب' : '';
        return [true, 'الاتصال ناجح ✔ — Token و Key مقبولان لدى E-COM Delivery' . $extra];
    }
    return [false, ecomError($code, $data, $raw)];
}

/**
 * Wilaya number (1-58) of an E-COM office row. E-COM may give the wilaya as a number or as a name
 * ("16", "Alger", "16 - Alger", "الجزائر"), in different fields; when it gives none, the office's
 * commune is looked up in assets/data/communes.json.
 */
function ecomWilayaOf(array $r): int {
    static $byName = null, $byCommune = null;
    if ($byName === null) {
        $byName = [];
        foreach (getDB()->query('SELECT id, name_ar, name_fr FROM wilayas') as $w) {
            foreach ([$w['name_ar'], $w['name_fr']] as $n) if ((string)$n !== '') $byName[communeKey((string)$n)] = (int)$w['id'];
            $byName[(string)$w['name_ar']] = (int)$w['id']; // Arabic names do not survive communeKey()
        }
    }
    foreach (['IDWilaya', 'ID_Wilaya', 'Wilaya_id', 'wilaya_id', 'Code_wilaya', 'CodeWilaya', 'Wilaya', 'wilaya', 'Ville', 'ville'] as $f) {
        if (!isset($r[$f]) || is_array($r[$f])) continue;
        $v = trim((string)$r[$f]);
        if ($v === '') continue;
        if (preg_match('/^0*(\d{1,2})\b/', $v, $m) && (int)$m[1] >= 1 && (int)$m[1] <= 58) return (int)$m[1];
        $name = trim(preg_replace('/^\d+\s*[-–:]?\s*/u', '', $v));
        if (isset($byName[$name])) return $byName[$name];
        if (($k = communeKey($name)) !== '' && isset($byName[$k])) return $byName[$k];
    }
    $commune = trim((string)($r['Commune'] ?? $r['commune'] ?? ''));
    if ($commune !== '') {
        if ($byCommune === null) {
            $byCommune = [];
            foreach (communesData() as $wid => $list) foreach ($list as $c) {
                foreach ((array)$c as $n) if (is_string($n) && $n !== '') { $byCommune[communeKey($n)] ??= (int)$wid; $byCommune[$n] ??= (int)$wid; }
            }
        }
        if (isset($byCommune[$commune])) return $byCommune[$commune];
        if (($k = communeKey($commune)) !== '' && isset($byCommune[$k])) return $byCommune[$k];
    }
    return 0;
}

/** Rows of an E-COM list response, whatever key it is wrapped in. */
function ecomRows($data, array $keys): array {
    if (!is_array($data)) return [];
    foreach ($keys as $k) if (isset($data[$k]) && is_array($data[$k])) return $data[$k];
    $first = reset($data);
    return is_array($first) && (isset($first['Code']) || isset($first['code']) || isset($first['Libelle'])) ? $data : [];
}

/**
 * E-COM offices (stop desks) of all wilayas, cached 12h:
 * [['wilaya'=>int,'commune'=>,'label'=>,'code'=>,'address'=>], ...]. An empty answer is never cached.
 */
function ecomAllDesks(): array {
    static $mem = null;
    if ($mem !== null) return $mem;
    $cached = json_decode(setting('ecom_desks'), true);
    $old = is_array($cached) ? (array)($cached['list'] ?? []) : [];
    if ($old && ($cached['t'] ?? 0) > time() - 43200) return $mem = $old;
    try {
        [$code, $data, $raw] = ecomRequest('GET', 'Api_v1/Stopdesk');
    } catch (Throwable $e) {
        saveSetting('ecom_desks_error', date('Y-m-d H:i') . ' — ' . $e->getMessage());
        return $mem = $old;
    }
    $rows = ecomRows($data, ['Commune', 'Stopdesk', 'stopdesk', 'Stopdesks', 'Bureaux', 'bureaux', 'data', 'Data']);
    if ($code !== 200 || !$rows) {
        saveSetting('ecom_desks_error', date('Y-m-d H:i') . ' — HTTP ' . $code . ': ' . mb_substr(trim(strip_tags((string)$raw)), 0, 300));
        return $mem = $old;
    }
    $list = [];
    foreach ($rows as $k => $r) {
        if (!is_array($r)) continue;
        $code_ = trim((string)($r['Code'] ?? $r['code'] ?? $r['CodeStopdesk'] ?? (is_string($k) ? $k : '')));
        if ($code_ === '') continue;
        $list[] = [
            'wilaya'  => ecomWilayaOf($r),
            'commune' => trim((string)($r['Commune'] ?? $r['commune'] ?? '')),
            'label'   => trim((string)($r['Libelle'] ?? $r['libelle'] ?? $r['Nom'] ?? '')),
            'code'    => $code_,
            'address' => trim((string)($r['Adresse'] ?? $r['adresse'] ?? '')),
        ];
    }
    if (!$list) {
        saveSetting('ecom_desks_error', date('Y-m-d H:i') . ' — no office code in the answer: ' . mb_substr((string)$raw, 0, 300));
        return $mem = $old;
    }
    saveSetting('ecom_desks', json_encode(['t' => time(), 'list' => $list], JSON_UNESCAPED_UNICODE));
    saveSetting('ecom_desks_error', '');
    return $mem = $list;
}

/**
 * Offices of one wilaya: [['name' => shown to the customer, 'code' => E-COM office code, 'commune' => ...], ...].
 * The name is the commune itself, or "Commune (Office)" when a commune has more than one office.
 */
function ecomDesks(int $wilayaId): array {
    $rows = array_values(array_filter(ecomAllDesks(), fn($d) => $d['wilaya'] === $wilayaId));
    $count = array_count_values(array_map(fn($d) => communeKey($d['commune']), $rows));
    $out = [];
    foreach ($rows as $d) {
        $name = $d['commune'] !== '' ? $d['commune'] : $d['label'];
        if ($d['commune'] === '' || ($count[communeKey($d['commune'])] ?? 0) > 1) {
            $name = trim(($d['commune'] !== '' ? $d['commune'] . ' ' : '') . '(' . ($d['label'] !== '' ? $d['label'] : $d['code']) . ')');
        }
        $out[] = ['name' => $name, 'code' => $d['code'], 'commune' => $d['commune'], 'stop_desk' => true];
    }
    return $out;
}

/** Communes E-COM delivers to in a wilaya: [['name' => ..., 'stop_desk' => bool], ...] (cached 12h). */
function ecomCommunes(int $wilayaId): array {
    $key = 'ecom_communes_' . $wilayaId;
    $cached = json_decode(setting($key), true);
    if (is_array($cached) && ($cached['t'] ?? 0) > time() - 43200 && !empty($cached['list'])) $names = $cached['list'];
    else {
        [$code, $data] = ecomRequest('GET', 'Api_v1/Commune/' . $wilayaId);
        $rows = is_array($data) ? ($data['Commune'] ?? $data['data'] ?? null) : null;
        if ($code !== 200 || !is_array($rows)) {
            $names = is_array($cached) ? ($cached['list'] ?? []) : [];
        } else {
            $names = [];
            foreach ($rows as $r) {
                $n = trim((string)(is_array($r) ? ($r['Commune'] ?? $r['nom'] ?? '') : $r));
                if ($n !== '') $names[] = $n;
            }
            $names = array_values(array_unique($names));
            if ($names) saveSetting($key, json_encode(['t' => time(), 'list' => $names], JSON_UNESCAPED_UNICODE));
        }
    }
    $deskKeys = array_flip(array_map(fn($d) => communeKey($d['commune']), ecomDesks($wilayaId)));
    return array_map(fn($n) => ['name' => $n, 'stop_desk' => isset($deskKeys[communeKey($n)])], $names);
}

/** E-COM phone format: digits only, 9 or 10 digits (0XXXXXXXXX). */
function ecomPhone(string $p): string {
    $p = preg_replace('/\D+/', '', $p);
    if (str_starts_with($p, '213')) $p = '0' . substr($p, 3);
    if (strlen($p) === 9) $p = '0' . $p;
    return $p;
}

/**
 * Create the parcel at E-COM for a store order.
 * $override: exact E-COM commune (home delivery) or office name (office pickup) chosen by the admin.
 * $mode: '' = as the customer ordered, 'home' = force home delivery, 'office' = force office pickup.
 * Returns ['tracking' => ..., 'label' => url or '']. Throws CourierCommuneException when the admin must decide.
 */
function ecomSendOrder(array $order, string $override = '', string $mode = ''): array {
    $db = getDB();
    $wilayaId = (int)$order['wilaya_id'];
    $stopDesk = $mode !== '' ? $mode === 'office' : $order['delivery_type'] === 'office';
    $local = findCommune($wilayaId, (string)$order['commune']);
    $candidates = array_values(array_filter([$order['commune_fr'] ?? '', $local[1] ?? '', (string)$order['commune']]));

    $deskCode = '';
    if ($stopDesk) {
        // Office pickup: find the E-COM office (its code is required).
        $desks = ecomDesks($wilayaId);
        $want = $override !== '' ? $override : trim((string)($order['desk_commune'] ?? ''));
        $found = null;
        foreach ($desks as $d) if ($want !== '' && ($d['name'] === $want || $d['code'] === $want)) { $found = $d; break; }
        if (!$found && $want !== '') foreach ($desks as $d) if (communeKey($d['commune']) === communeKey($want)) { $found = $d; break; }
        if (!$found && $override === '') {
            foreach ($desks as $d) foreach ($candidates as $c) if (communeKey($d['commune']) === communeKey($c)) { $found = $d; break 2; }
        }
        if (!$found) {
            throw new CourierCommuneException('اختار الزبون «استلام من المكتب» ولم نجد مكتباً لـ E-COM Delivery في بلدية «' . ($want !== '' ? $want : $order['commune']) . '». اختر أقرب مكتب في الولاية، أو أرسل الطلب كتوصيل إلى المنزل.', $desks, 'desk');
        }
        $deskCode = $found['code'];
        $commune = $found['commune'] !== '' ? $found['commune'] : (string)($order['commune_fr'] ?: $order['commune']);
    } else {
        $list = ecomCommunes($wilayaId);
        $names = array_map(fn($c) => ['name' => $c['name'], 'stop_desk' => $c['stop_desk']], $list);
        if ($override !== '') {
            $commune = courierMatchCommune($names, $override) ?? ($names ? null : $override);
            if ($commune === null) throw new CourierCommuneException('البلدية المختارة غير موجودة في قائمة E-COM Delivery', $names);
        } else {
            $commune = $names ? courierMatchCommune($names, ...$candidates) : ($candidates[0] ?? '');
            if ($commune === null || $commune === '') {
                throw new CourierCommuneException('لم نجد البلدية «' . $order['commune'] . '» في قائمة E-COM Delivery لهذه الولاية. اختر البلدية المقابلة ثم أعد الإرسال.', $names);
            }
        }
    }

    $items = $db->prepare('SELECT product_name, color, qty FROM order_items WHERE order_id = ?');
    $items->execute([$order['id']]);
    $rows = $items->fetchAll();
    $article = implode(' + ', array_map(function ($i) {
        $color = trim(preg_replace('/\s*\(#[0-9a-fA-F]{6}\)/', '', (string)$i['color']));
        return $i['product_name'] . ($color !== '' ? ' (' . $color . ')' : '') . ' x' . (int)$i['qty'];
    }, $rows)) ?: 'Produit';

    $note = trim(($order['gift_wrap'] ? 'تغليف هدية. ' : '') . (ecom('fragile') === '1' ? 'قابل للكسر (Fragile). ' : '') . ecom('note'));
    $address = trim((string)$order['address']) !== '' ? (string)$order['address'] : (string)$order['commune'];
    $parcel = [
        'Echange'     => 0,
        'Stopdesk'    => $stopDesk ? 1 : 0,
        'NomComplet'  => mb_substr(trim((string)$order['customer_name']), 0, 255),
        'Mobile_1'    => ecomPhone((string)$order['phone']),
        'Adresse'     => mb_substr($address, 0, 255),
        'Wilaya'      => (string)$wilayaId,
        'Commune'     => mb_substr($commune, 0, 255),
        'Article'     => mb_substr($article, 0, 255),
        'Total'       => (string)(int)$order['total'],
        'ID_Externe'  => (string)$order['id'],
        'Source'      => 'SYSTEM',
    ];
    if ($stopDesk) $parcel['CodeStopdesk'] = $deskCode;
    if ($note !== '') $parcel['NoteFournisseur'] = mb_substr($note, 0, 255);

    [$code, $data, $raw] = ecomRequest('POST', 'Api_v1/Colis', ['Colis' => [$parcel]]);
    $res = is_array($data) && isset($data['Colis']) && is_array($data['Colis']) ? (reset($data['Colis']) ?: []) : [];
    $tracking = is_array($res) ? trim((string)($res['Tracking'] ?? '')) : '';
    if ($code >= 300 || $tracking === '') {
        throw new RuntimeException('رفضت E-COM Delivery الطلب: ' . ecomError($code, $data, $raw));
    }
    $label = is_array($res) ? trim((string)($res['label'] ?? $res['Label'] ?? '')) : '';
    if ($label !== '' && !preg_match('#^https?://#i', $label)) $label = '';

    // Optionally mark the parcel "à expédier" right away so E-COM comes to pick it up.
    if (ecom('auto_validate') === '1') {
        try { ecomRequest('PUT', 'Api_v1/aExpédier', ['Colis' => [['Tracking' => $tracking]]]); } catch (Throwable $e) {}
    }
    return ['tracking' => $tracking, 'label' => $label];
}

/** Latest status of a parcel at E-COM: ['status' => ..., 'step' => ..., 'date' => ..., 'history' => [...]] or null. */
function ecomParcelStatus(string $tracking): ?array {
    [$code, $data] = ecomRequest('GET', 'Api_v1/Historique/Tracking/' . $tracking);
    $rows = is_array($data) ? ($data['Historique'] ?? null) : null;
    if ($code !== 200 || !is_array($rows) || !$rows) return null;
    $rows = array_values(array_filter($rows, 'is_array'));
    if (!$rows) return null;
    $last = end($rows);
    return [
        'status'  => (string)($last['Situation'] ?? ''),
        'step'    => (string)($last['Avancement'] ?? ''),
        'date'    => (string)($last['Date_Création'] ?? $last['Date'] ?? ''),
        'history' => $rows,
    ];
}
