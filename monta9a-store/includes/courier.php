<?php
/**
 * Delivery companies — every Algerian company with a public API.
 *
 *   Platform   Companies                                                     File
 *   ecotrack   Packers, DHD, Conexlog, Rex, MSM Go, Anderson, Imir, ... (~23)  this file
 *   yalidine   Yalidine, Guepex, Yalitec                                     includes/courier-yalidine.php
 *   zr         ZR Express (new platform)                                     includes/courier-zr.php
 *   ecom       E-COM Delivery                                                includes/courier-ecom.php
 *   noest      NOEST Express                                                 includes/courier-more.php
 *   maystro    Maystro Delivery                                              includes/courier-more.php
 *
 * Settings of all companies live in ONE file, database/couriers-config.php:
 *   on      = switched on in the hidden page /admin/soli (credentials entered in /admin/solivr)
 *   active  = offered to customers at checkout (toggled in /admin/solivr)
 * The rest of the store calls only the courier*() functions at the bottom of this file.
 */
require_once __DIR__ . '/functions.php';

/* ---------------------------------------------------------------------------
 * Shared helpers
 * ------------------------------------------------------------------------- */

/** Communes list shipped with the store: ['16' => [['حسين داي', 'Hussein Dey'], ...], ...] */
function communesData(): array {
    static $data = null;
    if ($data === null) {
        $json = @file_get_contents(ROOT_PATH . '/assets/data/communes.json');
        $data = $json ? (json_decode($json, true) ?: []) : [];
    }
    return $data;
}

/** Find a commune of a wilaya by its Arabic or French name. Returns [ar, fr] or null. */
function findCommune(int $wilayaId, string $name): ?array {
    $name = trim($name);
    foreach (communesData()[(string)$wilayaId] ?? [] as $c) {
        if ($c[0] === $name || strcasecmp($c[1], $name) === 0) return $c;
    }
    return null;
}

/** True when the order holds a real tracking number (not the temporary "sending" lock). */
function hasTracking($t): bool {
    return is_string($t) && $t !== '' && !str_starts_with($t, 'sending');
}

/** Lowercase ASCII key used to compare commune spellings ("Aïn Taya" == "Ain-Taya"). */
function communeKey(string $s): string {
    if (function_exists('iconv')) {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($t !== false && $t !== '') $s = $t;
    }
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '', $s);
    return str_replace(['ou', 'dj', 'kh'], ['u', 'j', 'k'], $s);
}

/** Match the order's commune with a company's own spelling. Returns the company's name or null. */
function courierMatchCommune(array $companyCommunes, string ...$names): ?string {
    foreach ($names as $n) {
        if ($n === '') continue;
        $k = communeKey($n);
        foreach ($companyCommunes as $c) {
            if ($c['name'] === $n || ($k !== '' && communeKey($c['name']) === $k)) return $c['name'];
        }
    }
    return null;
}

/**
 * Thrown when the admin has to decide something before the parcel can be created.
 * kind 'commune': the commune was not recognised — pick it from $choices.
 * kind 'desk': office pickup is not available there — pick an office from $choices or send as home delivery.
 */
class CourierCommuneException extends RuntimeException {
    public array $choices;
    public string $kind;
    public function __construct(string $msg, array $choices, string $kind = 'commune') {
        parent::__construct($msg); $this->choices = $choices; $this->kind = $kind;
    }
}

/** Plain HTTP call. Returns [httpCode, rawBody]. Throws RuntimeException on network failure. */
function courierHttp(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): array {
    $method = strtoupper($method);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false) throw new RuntimeException('تعذر الاتصال بخادم شركة التوصيل: ' . $err);
    } else {
        $ctx = stream_context_create(['http' => [
            'method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body ?? '',
            'timeout' => $timeout, 'ignore_errors' => true, 'follow_location' => 0,
        ]]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false) throw new RuntimeException('تعذر الاتصال بخادم شركة التوصيل');
        $code = 0;
        foreach ($http_response_header ?? [] as $h) if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $code = (int)$m[1];
    }
    return [$code, preg_replace('/^\xEF\xBB\xBF/', '', (string)$resp)];
}

/** Readable message from any JSON error body. */
function courierErrorText(int $code, $data, string $raw, string $company = ''): string {
    if ($code === 401 || $code === 403) return 'بيانات الربط (Token / API) غير صحيحة — رفضت ' . ($company ?: 'الشركة') . ' الدخول';
    if ($code === 429) return 'طلبات كثيرة في وقت قصير، انتظر دقيقة ثم أعد المحاولة';
    $parts = [];
    $collect = function ($v, $k = '') use (&$parts, &$collect) {
        if (is_string($v) && trim($v) !== '') $parts[] = (is_string($k) && $k !== '' && !in_array($k, ['message', 'error', 'detail', 'title', 'msg'], true) ? $k . ': ' : '') . trim($v);
        elseif (is_array($v)) foreach ($v as $kk => $x) $collect($x, is_string($kk) ? $kk : $k);
    };
    if (is_array($data)) foreach (['message', 'error', 'errors', 'detail', 'title', 'msg'] as $k) if (isset($data[$k])) $collect($data[$k], $k);
    if (!$parts) $parts[] = 'رد غير متوقع (HTTP ' . $code . '): ' . mb_substr(trim(strip_tags($raw)), 0, 200);
    return implode(' — ', array_unique($parts));
}

/** Small JSON cache in the settings table. $fn is only called when the cache is old; empty results are never cached. */
function courierCache(string $key, int $ttl, callable $fn) {
    $k = 'cc_' . substr(md5($key), 0, 20);
    $c = json_decode(setting($k), true);
    if (is_array($c) && ($c['t'] ?? 0) > time() - $ttl && !empty($c['v'])) return $c['v'];
    try {
        $v = $fn();
    } catch (Throwable $e) {
        if (is_array($c) && !empty($c['v'])) return $c['v'];  // keep serving the last good answer
        throw $e;
    }
    if (!empty($v)) saveSetting($k, json_encode(['t' => time(), 'v' => $v], JSON_UNESCAPED_UNICODE));
    elseif (is_array($c) && !empty($c['v'])) return $c['v'];
    return $v;
}

/** Forget all cached lists (communes, offices, prices). */
function courierClearCache(): void {
    getDB()->exec("DELETE FROM settings WHERE key LIKE 'cc_%' OR key IN ('zr_territories', 'zr_hubs', 'zr_hubs_error', 'ecom_desks') OR key LIKE 'ecom_communes_%' OR key LIKE 'courier_communes_%'");
}

/** French name of a wilaya (from the store's table). */
function wilayaNameFr(int $id): string {
    static $names = null;
    if ($names === null) $names = getDB()->query('SELECT id, name_fr FROM wilayas')->fetchAll(PDO::FETCH_KEY_PAIR);
    return (string)($names[$id] ?? '');
}

/** "Product A (color) x2 + Product B x1" for the parcel. */
function courierArticles(array $order): array {
    $st = getDB()->prepare('SELECT product_name, color, qty, price FROM order_items WHERE order_id = ?');
    $st->execute([$order['id']]);
    $rows = $st->fetchAll();
    $text = implode(' + ', array_map(function ($i) {
        $color = trim(preg_replace('/\s*\(#[0-9a-fA-F]{6}\)/', '', (string)$i['color']));
        return $i['product_name'] . ($color !== '' ? ' (' . $color . ')' : '') . ' x' . (int)$i['qty'];
    }, $rows)) ?: 'Produit';
    return ['text' => mb_substr($text, 0, 250), 'qty' => max(1, (int)array_sum(array_column($rows, 'qty'))), 'rows' => $rows];
}

/** Note sent with every parcel of a company. */
function courierNote(string $key, array $order): string {
    $c = courierCfg($key);
    return mb_substr(trim(($order['gift_wrap'] ? 'تغليف هدية. ' : '') . (($c['fragile'] ?? '0') === '1' ? 'قابل للكسر (Fragile). ' : '') . ($c['note'] ?? '')), 0, 250);
}

/* ---------------------------------------------------------------------------
 * The companies
 * ------------------------------------------------------------------------- */

/** key => [name, platform, default API url, extra note for the owner] */
function courierCatalog(): array {
    $et = fn(string $sub) => 'https://' . $sub . '.ecotrack.dz';
    return [
        'yalidine'    => ['Yalidine', 'yalidine', 'https://api.yalidine.app/v1', ''],
        'guepex'      => ['Guepex', 'yalidine', 'https://api.guepex.app/v1', ''],
        'yalitec'     => ['Yalitec', 'yalidine', 'https://api.yalitec.me/v1', ''],
        'zr'          => ['ZR Express', 'zr', 'https://api.zrexpress.app', ''],
        'ecom'        => ['E-COM Delivery', 'ecom', 'https://ecom-dz.net', ''],
        'noest'       => ['NOEST Express', 'noest', 'https://app.noest-dz.com', ''],
        'maystro'     => ['Maystro Delivery', 'maystro', 'https://backend.maystro-delivery.com/api', 'تجريبي — بدون استلام من المكتب'],
        'packers'     => ['Packers (باكرز)', 'ecotrack', $et('packers'), ''],
        'dhd'         => ['DHD', 'ecotrack', $et('dhd'), ''],
        'conexlog'    => ['Conexlog', 'ecotrack', 'https://app.conexlog-dz.com', ''],
        'rex'         => ['Rex Livraison', 'ecotrack', $et('rex'), ''],
        'msmgo'       => ['MSM Go', 'ecotrack', $et('msmgo'), ''],
        'anderson'    => ['Anderson Delivery', 'ecotrack', $et('anderson'), ''],
        'areex'       => ['Areex', 'ecotrack', $et('areex'), ''],
        'bacexpress'  => ['BA Consult', 'ecotrack', $et('bacexpress'), ''],
        'coyote'      => ['Coyote Express', 'ecotrack', $et('coyoteexpressdz'), ''],
        'distazero'   => ['Distazero', 'ecotrack', $et('distazero'), ''],
        '48hr'        => ['48Hr Livraison', 'ecotrack', $et('48hr'), ''],
        'fret'        => ['FRET.Direct', 'ecotrack', $et('fret'), ''],
        'golivri'     => ['GOLIVRI', 'ecotrack', $et('golivri'), ''],
        'mono'        => ['Mono Hub', 'ecotrack', $et('mono'), ''],
        'negmar'      => ['Negmar Express', 'ecotrack', $et('negmar'), ''],
        'prest'       => ['Prest', 'ecotrack', $et('prest'), ''],
        'rblivraison' => ['RB Livraison', 'ecotrack', $et('rblivraison'), ''],
        'rocket'      => ['Rocket Delivery', 'ecotrack', $et('rocket'), ''],
        'salva'       => ['Salva Delivery', 'ecotrack', $et('salvadelivery'), ''],
        'speed'       => ['Speed Delivery', 'ecotrack', $et('speeddelivery'), ''],
        'tsl'         => ['TSL Express', 'ecotrack', $et('tsl'), ''],
        'worldexpress'=> ['World Express', 'ecotrack', $et('worldexpress'), ''],
        'ontime'      => ['On Time', 'ecotrack', $et('ontime'), ''],
        'imir'        => ['Imir Logistics', 'ecotrack', $et('imir35'), ''],
    ];
}

/** Credential fields of a platform: name => [label, secret?, help] */
function courierFields(string $platform): array {
    return match ($platform) {
        'yalidine' => ['api_id' => ['API ID', false, ''], 'api_token' => ['API Token', true, ''],
                       'from_wilaya' => ['ولاية الإرسال (رقم)', false, 'رقم الولاية التي ترسل منها الطرود، مثلاً 16']],
        'zr'       => ['api_key' => ['Secret Key (API Key)', true, ''], 'tenant' => ['Tenant ID', false, 'بالشكل xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx']],
        'ecom'     => ['token' => ['Token', true, ''], 'key' => ['Key', true, '']],
        'noest'    => ['token' => ['API Token', true, ''], 'guid' => ['User GUID', false, '']],
        'maystro'  => ['token' => ['API Token', true, '']],
        default    => ['token' => ['API Token', true, '']],
    };
}

/** Options each company can have (besides credentials). */
const COURIER_OPTIONS = ['note', 'fragile', 'set_shipped', 'auto_validate', 'url', 'prices', 'label_format'];

function courierPlatform(string $key): string {
    return courierCatalog()[$key][1] ?? '';
}

/** Display name of a company ('' = the default one). */
function courierName(string $key = ''): string {
    $key = $key !== '' ? $key : courierProvider();
    return courierCatalog()[$key][0] ?? 'شركة التوصيل';
}

const COURIERS_FILE = ROOT_PATH . '/database/couriers-config.php';

/** All settings: ['default' => key, 'c' => [key => [...]]]. */
function couriersAll(bool $reload = false): array {
    static $all = null;
    if ($all !== null && !$reload) return $all;
    if (is_file(COURIERS_FILE)) {
        $data = include COURIERS_FILE;
        $all = is_array($data) ? $data + ['default' => '', 'c' => []] : ['default' => '', 'c' => []];
        if (!is_array($all['c'])) $all['c'] = [];
        return $all;
    }
    $all = ['default' => '', 'c' => []];
    return $all;
}

function couriersSave(array $all): bool {
    $clean = ['default' => (string)($all['default'] ?? ''), 'c' => []];
    foreach ((array)($all['c'] ?? []) as $k => $c) {
        if (!isset(courierCatalog()[$k]) || !is_array($c)) continue;
        $clean['c'][$k] = array_map('strval', $c);
    }
    $php = "<?php\n// Delivery companies — edited from /admin/soli and /admin/solivr. Do not share this file.\n"
         . "if (!defined('ROOT_PATH')) { http_response_code(404); exit; }\n"
         . 'return ' . var_export($clean, true) . ";\n";
    $tmp = COURIERS_FILE . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, $php, LOCK_EX) === false) return false;
    @chmod($tmp, 0600);
    if (!@rename($tmp, COURIERS_FILE)) { @unlink($tmp); return false; }
    if (function_exists('opcache_invalidate')) @opcache_invalidate(COURIERS_FILE, true);
    couriersAll(true);
    return true;
}

/** Settings of one company (empty array when never set). */
function courierCfg(string $key): array {
    return couriersAll()['c'][$key] ?? [];
}

function courierSaveCfg(string $key, array $cfg): bool {
    $all = couriersAll();
    $all['c'][$key] = $cfg;
    return couriersSave($all);
}

/** Switched on in /admin/soli and all credentials filled. */
function courierReady(string $key): bool {
    $c = courierCfg($key);
    if (($c['on'] ?? '0') !== '1' || !isset(courierCatalog()[$key])) return false;
    foreach (courierFields(courierPlatform($key)) as $f => $def) {
        if ($f === 'from_wilaya') continue;
        if (trim((string)($c[$f] ?? '')) === '') return false;
    }
    return true;
}

/** Companies ready to use (keys). */
/** Companies switched on in /admin/soli (their details are filled in /admin/solivr). */
function courierOnList(): array {
    return array_values(array_filter(array_keys(courierCatalog()), fn($k) => (courierCfg($k)['on'] ?? '0') === '1'));
}

function courierReadyList(): array {
    return array_values(array_filter(array_keys(courierCatalog()), 'courierReady'));
}

/** Companies offered to customers at checkout (ready + active). */
function courierActiveList(): array {
    return array_values(array_filter(courierReadyList(), fn($k) => (courierCfg($k)['active'] ?? '1') === '1'));
}

/** Company used when an order has none chosen. */
function courierProvider(): string {
    $all = couriersAll();
    $d = (string)($all['default'] ?? '');
    if ($d !== '' && courierReady($d)) return $d;
    $act = courierActiveList();
    if ($act) return $act[0];
    $ready = courierReadyList();
    return $ready[0] ?? '';
}

/** Ready companies: key => name (for selects). */
function courierLinked(): array {
    $out = [];
    foreach (courierReadyList() as $k) $out[$k] = courierName($k);
    return $out;
}

/** Make a company the default one for sending. */
function courierSetActive(string $key): bool {
    $all = couriersAll();
    $all['default'] = $key;
    return couriersSave($all);
}

/** The company an order goes / went to. Orders sent before this update without a company were Packers. */
function orderCourier(array $order): string {
    $c = (string)($order['courier'] ?? '');
    if ($c !== '' && isset(courierCatalog()[$c])) return $c;
    return hasTracking($order['tracking'] ?? '') ? 'packers' : courierProvider();
}

function courierConfigured(): bool {
    return courierProvider() !== '';
}

/** "set the order to shipped after sending" option. */
function courierSetShipped(string $key = ''): bool {
    $key = $key ?: courierProvider();
    return (courierCfg($key)['set_shipped'] ?? '1') === '1';
}

/* ---------------------------------------------------------------------------
 * EcoTrack platform (Packers, DHD, Conexlog, ...)
 * ------------------------------------------------------------------------- */

function etBase(string $key): string {
    $u = $GLOBALS['etBaseTry'][$key] ?? (trim((string)(courierCfg($key)['url'] ?? '')) ?: (courierCatalog()[$key][2] ?? ''));
    // Keep only https://host — the owner may paste the dashboard link (…/login, …/api/v1, …).
    if (preg_match('#^(https?://[^/?\#]+)#i', $u, $m)) return strtolower($m[1]);
    return rtrim($u, '/');
}

/** Other addresses tried when the token is refused (company platform name not certain). */
function etCandidates(string $key): array {
    $et = fn(string $sub) => 'https://' . $sub . '.ecotrack.dz';
    return match ($key) {
        'imir' => array_map($et, ['imir35', 'imir', 'imirlogistics', 'imirlogistic', 'imir-logistics', 'imirexpress', 'imirdz', 'imir-dz', 'imirdelivery', 'imirlivraison']),
        default => [],
    };
}

/** Returns [httpCode, decoded|null, raw]. */
function etRequest(string $key, string $method, string $path, array $params = [], bool $raw = false): array {
    // Pasted tokens sometimes carry "Bearer " or spaces / line breaks.
    $token = preg_replace('/\s+/', '', preg_replace('/^\s*Bearer\s+/i', '', (string)(courierCfg($key)['token'] ?? '')));
    if ($token === '') throw new RuntimeException('لم يتم إدخال رمز API الخاص بـ ' . courierName($key));
    $params['api_token'] = $token;
    $query = http_build_query($params);
    $url = etBase($key) . '/api/v1/' . ltrim($path, '/') . '?' . $query;
    $headers = ['Authorization: Bearer ' . $token, 'Accept: ' . ($raw ? '*/*' : 'application/json')];
    $body = null;
    if (!in_array(strtoupper($method), ['GET', 'DELETE'], true)) { $body = $query; $headers[] = 'Content-Type: application/x-www-form-urlencoded'; }
    [$code, $resp] = courierHttp($method, $url, $headers, $body, 25);
    return [$code, $raw ? null : json_decode($resp, true), $resp];
}

function etTest(string $key): array {
    $ok = fn($c, $d) => $c === 200 && is_array($d) && !empty($d['success']);
    try {
        [$code, $data, $raw] = etRequest($key, 'GET', 'validate/token');
        if ($ok($code, $data)) return [true, 'الاتصال ناجح ✔ — الرمز مقبول لدى ' . courierName($key) . ' (' . etBase($key) . ')'];
        $msg = courierErrorText($code, $data, $raw, courierName($key));
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
    }
    // Refused or unreachable: try the other known addresses of this company with the same token.
    $cfg = courierCfg($key);
    $tried = [etBase($key)];
    foreach (etCandidates($key) as $u) {
        if (in_array($u, $tried, true)) continue;
        $tried[] = $u;
        $GLOBALS['etBaseTry'][$key] = $u;
        try {
            [$c2, $d2] = etRequest($key, 'GET', 'validate/token');
        } catch (Throwable $e) {
            $c2 = 0; $d2 = null;
        }
        unset($GLOBALS['etBaseTry'][$key]);
        if ($ok($c2, $d2)) {
            courierSaveCfg($key, array_merge($cfg, ['url' => $u]));
            return [true, 'الاتصال ناجح ✔ — وجدنا منصة ' . courierName($key) . ' على ' . $u . ' وحفظناها في «رابط API»'];
        }
    }
    if (count($tried) > 1) {
        $msg .= ' — جرّبنا أيضاً: ' . implode('، ', array_map(fn($u) => preg_replace('#^https://#', '', $u), array_slice($tried, 1))) . '. '
              . 'اطلب من ' . courierName($key) . ' رابط منصّة التجار (الصفحة التي تدخل منها لحسابك، مثل https://xxxx.ecotrack.dz) وضعه في «رابط API»، وتأكد أن الـ Token هو «API Token» من صفحة الإعدادات / API في حسابك.';
    }
    return [false, $msg];
}

/** All communes of the company: [wilaya => [['name','stop_desk','code'], ...]] (cached 12h). */
function etAllCommunes(string $key): array {
    return courierCache('et_communes_' . $key, 43200, function () use ($key) {
        [$code, $data] = etRequest($key, 'GET', 'get/communes');
        if ($code !== 200 || !is_array($data)) return [];
        $rows = isset($data['data']) && is_array($data['data']) ? $data['data'] : $data;
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $name = trim((string)($r['nom'] ?? $r['name'] ?? ''));
            $w = (int)($r['wilaya_id'] ?? 0);
            if ($name === '' || $w < 1) continue;
            $out[$w][] = ['name' => $name, 'stop_desk' => !empty($r['has_stop_desk']), 'code' => (string)($r['code_postal'] ?? '')];
        }
        return $out;
    });
}

function etCommunes(string $key, int $w): array {
    return etAllCommunes($key)[$w] ?? [];
}

function etDesks(string $key, int $w): array {
    $out = [];
    foreach (etCommunes($key, $w) as $c) if ($c['stop_desk']) $out[] = ['name' => $c['name'], 'code' => $c['code'], 'commune' => $c['name'], 'address' => '', 'stop_desk' => true];
    return $out;
}

/** [wilaya => ['home' => int, 'office' => int]] from the company's tariffs (cached 24h). */
function etFees(string $key): array {
    return courierCache('et_fees_' . $key, 86400, function () use ($key) {
        [$code, $data] = etRequest($key, 'GET', 'get/fees');
        if ($code !== 200 || !is_array($data)) return [];
        $rows = $data['livraison'] ?? $data['data']['livraison'] ?? $data['data'] ?? $data;
        $out = [];
        foreach ((array)$rows as $r) {
            if (!is_array($r) || !isset($r['wilaya_id'])) continue;
            $out[(int)$r['wilaya_id']] = ['home' => (int)round((float)($r['tarif'] ?? 0)), 'office' => (int)round((float)($r['tarif_stopdesk'] ?? 0))];
        }
        return $out;
    });
}

function etSend(string $key, array $order, string $override, string $mode): array {
    $wilayaId = (int)$order['wilaya_id'];
    $stopDesk = $mode !== '' ? $mode === 'office' : $order['delivery_type'] === 'office';
    $list = etCommunes($key, $wilayaId);
    $local = findCommune($wilayaId, (string)$order['commune']);
    $desk = $stopDesk ? trim((string)($order['desk_commune'] ?? '')) : '';
    $candidates = array_values(array_filter([$desk, $order['commune_fr'] ?? '', $local[1] ?? '', (string)$order['commune']]));
    $name = courierName($key);
    if ($override !== '') {
        $commune = courierMatchCommune($list, $override) ?? ($list ? null : $override);
        if ($commune === null) throw new CourierCommuneException('البلدية المختارة غير موجودة في قائمة ' . $name, $list);
    } else {
        $commune = $list ? courierMatchCommune($list, ...$candidates) : ($candidates[0] ?? '');
        if ($commune === null || $commune === '') {
            throw new CourierCommuneException('لم نجد البلدية «' . $order['commune'] . '» في قائمة ' . $name . ' لهذه الولاية. اختر البلدية المقابلة ثم أعد الإرسال.', $list);
        }
    }
    $stationCode = '';
    if ($stopDesk && $list) {
        foreach ($list as $c) {
            if ($c['name'] !== $commune) continue;
            if (!$c['stop_desk']) throw new CourierCommuneException($name . ' ليس لها مكتب في بلدية «' . $commune . '». اختر أقرب مكتب في الولاية، أو أرسل الطلب كتوصيل إلى المنزل.', etDesks($key, $wilayaId), 'desk');
            $stationCode = $c['code'];
        }
    }
    $art = courierArticles($order);
    $fields = [
        'reference'   => (string)$order['id'],
        'nom_client'  => mb_substr((string)$order['customer_name'], 0, 255),
        'telephone'   => preg_replace('/\D+/', '', (string)$order['phone']),
        'adresse'     => mb_substr(trim((string)$order['address']) !== '' ? (string)$order['address'] : (string)$order['commune'], 0, 255),
        'code_wilaya' => (string)$wilayaId,
        'commune'     => $commune,
        'montant'     => (string)(int)$order['total'],
        'produit'     => $art['text'],
        'quantite'    => (string)$art['qty'],
        'type'        => '1',
        'stop_desk'   => $stopDesk ? '1' : '0',
    ];
    if ($stationCode !== '') $fields['code_postal'] = $stationCode;
    if (($note = courierNote($key, $order)) !== '') $fields['remarque'] = $note;
    if ((courierCfg($key)['fragile'] ?? '0') === '1') $fields['fragile'] = '1';
    [$code, $data, $raw] = etRequest($key, 'POST', 'create/order', $fields);
    if ((!is_array($data) || empty($data['success'])) && $stopDesk && preg_match('/stop\s*desk/i', $raw)) {
        throw new CourierCommuneException($name . ' ليس لها مكتب في بلدية «' . $commune . '». اختر أقرب مكتب، أو أرسل الطلب كتوصيل إلى المنزل.', etDesks($key, $wilayaId), 'desk');
    }
    if (!is_array($data) || empty($data['success']) || empty($data['tracking'])) {
        throw new RuntimeException('رفضت ' . $name . ' الطلب: ' . courierErrorText($code, $data, $raw, $name));
    }
    $tracking = (string)$data['tracking'];
    if ((courierCfg($key)['auto_validate'] ?? '0') === '1') {
        try { etRequest($key, 'POST', 'valid/order', ['tracking' => $tracking, 'ask_collection' => 1]); } catch (Throwable $e) {}
    }
    return ['tracking' => $tracking, 'label' => ''];
}

/* ---------------------------------------------------------------------------
 * Other platforms
 * ------------------------------------------------------------------------- */
require_once __DIR__ . '/courier-ecom.php';
require_once __DIR__ . '/courier-zr.php';
require_once __DIR__ . '/courier-yalidine.php';
require_once __DIR__ . '/courier-more.php';

/* ===========================================================================
 * What the store calls. $key = company ('' = the default company).
 * ========================================================================= */

/** [ok, message] */
function courierTestToken(string $key = ''): array {
    $key = $key ?: courierProvider();
    return match (courierPlatform($key)) {
        'zr' => zrTestToken(), 'ecom' => ecomTestToken(), 'yalidine' => ylTest($key),
        'noest' => noestTest($key), 'maystro' => maystroTest($key), 'ecotrack' => etTest($key),
        default => [false, 'شركة غير معروفة'],
    };
}

/** Communes of a company in a wilaya: [['name' => ..., 'stop_desk' => bool], ...]. */
function courierCommunes(int $wilayaId, string $key = ''): array {
    $key = $key ?: courierProvider();
    return match (courierPlatform($key)) {
        'zr' => zrCommunes($wilayaId), 'ecom' => ecomCommunes($wilayaId), 'yalidine' => ylCommunes($key, $wilayaId),
        'maystro' => maystroCommunes($key, $wilayaId), 'ecotrack' => etCommunes($key, $wilayaId),
        default => [],
    };
}

/** Offices of a company in a wilaya: [['name' => shown to the customer, 'code', 'commune', 'address'], ...]. */
function courierDesks(int $wilayaId, string $key = ''): array {
    $key = $key ?: courierProvider();
    return match (courierPlatform($key)) {
        'zr' => zrDesks($wilayaId), 'ecom' => ecomDesks($wilayaId), 'yalidine' => ylDesks($key, $wilayaId),
        'noest' => noestDesks($key, $wilayaId), 'ecotrack' => etDesks($key, $wilayaId),
        default => [],
    };
}

/** True when the office list of the company could be read (else the admin picks the office later). */
function courierDesksAvailable(int $wilayaId, string $key = ''): bool {
    $key = $key ?: courierProvider();
    return match (courierPlatform($key)) {
        'zr' => (bool)zrHubs(), 'ecom' => (bool)ecomAllDesks(), 'yalidine' => (bool)ylAllCenters($key),
        'noest' => (bool)noestAllDesks($key), 'ecotrack' => (bool)etAllCommunes($key),
        default => false,
    };
}

/**
 * Delivery prices of a company for a wilaya (and commune when the company prices per commune):
 * ['home' => int, 'office' => int|null, 'source' => 'api'|'store']. office = null → no office pickup.
 */
function courierPrices(string $key, array $wilaya, string $commune = ''): array {
    $store = ['home' => (int)$wilaya['home_price'], 'office' => (int)$wilaya['office_price'], 'source' => 'store'];
    if ((courierCfg($key)['prices'] ?? 'api') !== 'api') return $store;
    $w = (int)$wilaya['id'];
    try {
        $p = match (courierPlatform($key)) {
            'ecotrack' => etFees($key)[$w] ?? null,
            'yalidine' => ylFees($key, $w, $commune),
            default => null,
        };
    } catch (Throwable $e) {
        $p = null;
    }
    if (!$p || (int)($p['home'] ?? 0) <= 0) return $store;
    return ['home' => (int)$p['home'], 'office' => (int)($p['office'] ?? 0) > 0 ? (int)$p['office'] : $store['office'], 'source' => 'api'];
}

/**
 * Everything the checkout shows for one company in a wilaya:
 * ['key','name','home','office','desks' => [names], 'info' => [name => address]]. desks = null when unknown.
 */
function courierQuote(string $key, array $wilaya, string $commune = ''): array {
    $pr = courierPrices($key, $wilaya, $commune);
    $desks = null; $info = [];
    try {
        if (courierDesksAvailable((int)$wilaya['id'], $key)) {
            $desks = [];
            foreach (courierDesks((int)$wilaya['id'], $key) as $d) {
                if (in_array($d['name'], $desks, true)) continue;
                $desks[] = $d['name'];
                $a = trim(implode(' — ', array_filter([trim((string)($d['address'] ?? '')), trim((string)($d['commune'] ?? ''))])));
                if ($a !== '' && $a !== $d['name']) $info[$d['name']] = $a;
            }
        }
    } catch (Throwable $e) {
        $desks = null;
    }
    if (courierPlatform($key) === 'maystro') $desks = [];  // home delivery only
    return ['key' => $key, 'name' => courierName($key), 'home' => $pr['home'], 'office' => $desks === [] ? null : $pr['office'], 'desks' => $desks, 'info' => $info];
}

/** Live status of a sent parcel (['status','step','date']) or null. */
function courierParcelStatus(array $order): ?array {
    $t = (string)($order['tracking'] ?? '');
    if (!hasTracking($t)) return null;
    $key = orderCourier($order);
    if (!courierReady($key)) return null;
    try {
        return match (courierPlatform($key)) {
            'zr' => zrParcelStatus($t), 'ecom' => ecomParcelStatus($t), 'yalidine' => ylStatus($key, $t),
            default => null,
        };
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Send an order. $key = company ('' = the one chosen by the customer, else the default).
 * Returns ['provider' => key, 'tracking' => ..., 'label' => url or ''].
 */
function courierSendOrder(array $order, string $override = '', string $mode = '', string $key = ''): array {
    $key = $key ?: orderCourier($order);
    if (!courierReady($key)) throw new RuntimeException(courierName($key) . ' غير مربوطة — أكمل معلوماتها في صفحة «شركات التوصيل»');
    $r = match (courierPlatform($key)) {
        'zr' => zrSendOrder($order, $override, $mode),
        'ecom' => ecomSendOrder($order, $override, $mode),
        'yalidine' => ylSend($key, $order, $override, $mode),
        'noest' => noestSend($key, $order, $override, $mode),
        'maystro' => maystroSend($key, $order, $override, $mode),
        'ecotrack' => etSend($key, $order, $override, $mode),
    };
    return ['provider' => $key] + $r;
}

/** The label of a sent order: ['pdf' => bytes] or ['url' => link] or [] when unavailable. */
function courierLabel(array $order): array {
    $key = orderCourier($order);
    $t = (string)$order['tracking'];
    $saved = (string)($order['courier_label'] ?? '');
    try {
        switch (courierPlatform($key)) {
            case 'zr':
                $u = zrLabelUrl($t);
                return $u !== '' ? ['url' => $u] : [];
            case 'ecotrack':
                [$code, , $raw] = etRequest($key, 'GET', 'get/order/label', ['tracking' => $t], true);
                return $code === 200 && str_starts_with($raw, '%PDF') ? ['pdf' => $raw] : [];
            case 'noest':
                return noestLabel($key, $t);
            case 'maystro':
                return maystroLabel($key, $t, $saved);
            case 'ecom':
            case 'yalidine':
                if ($saved !== '' && preg_match('#^https?://#i', $saved)) return ['url' => $saved];
                return $key !== 'ecom' && courierPlatform($key) === 'yalidine' ? ylLabel($key, $t) : [];
        }
    } catch (Throwable $e) {
    }
    return [];
}
