<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/i18n.php';

function e($v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function &settingsCache(): array {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (getDB()->query('SELECT key, value FROM settings') as $r) $cache[$r['key']] = $r['value'];
    }
    return $cache;
}

function setting(string $key, string $default = ''): string {
    $cache = &settingsCache();
    return isset($cache[$key]) && $cache[$key] !== '' ? $cache[$key] : $default;
}

function saveSetting(string $key, string $value): void {
    getDB()->prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)')->execute([$key, $value]);
    // Keep this request's cache in sync so a value saved now is read back correctly.
    $cache = &settingsCache();
    $cache[$key] = $value;
}

function money($n): string {
    if (isFr()) return number_format((int)$n, 0, ',', "\u{202F}") . ' DA';
    return number_format((int)$n, 0, ',', ',') . ' د.ج';
}

/* ---------- CSRF ---------- */
function csrfToken(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrfField(): string {
    return '<input type="hidden" name="csrf" value="' . csrfToken() . '">';
}
function checkCsrf(): bool {
    $t = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? '');
    return is_string($t) && hash_equals(csrfToken(), $t);
}

/* ---------- Single-offer mode (off unless the landing products are set) ---------- */
/** True when the store sells only the offer of offer.php (home page = the offer). */
function offerOnly(): bool {
    static $on = null;
    // both books must still be on sale, otherwise the normal store comes back (no redirect loop)
    return $on ??= setting('offer_only') === '1' && getProduct((int)setting('landing_p1')) && getProduct((int)setting('landing_p2'));
}

/* ---------- Products ---------- */
function productImages(array $p): array {
    $imgs = json_decode($p['images'] ?? '[]', true);
    return is_array($imgs) && $imgs ? $imgs : ['assets/img/placeholder.svg'];
}
function productImage(array $p): string {
    return productImages($p)[0];
}
function productColors(array $p): array {
    $c = json_decode($p['colors'] ?? '[]', true);
    return is_array($c) ? array_values(array_filter($c, 'isValidColor')) : [];
}
/** Sizes of a clothing item (S, M, L, 38, 40, Taille unique...) — free short labels. */
function productSizes(array $p): array {
    $s = json_decode($p['sizes'] ?? '[]', true);
    if (!is_array($s)) return [];
    $out = [];
    foreach ($s as $v) { if (is_string($v) && ($v = trim($v)) !== '' && mb_strlen($v) <= 20 && !in_array($v, $out, true)) $out[] = $v; }
    return $out;
}
/** Parse "S, M, L" / one per line into a clean list of sizes. */
function parseSizes(string $raw): array {
    $out = [];
    foreach (preg_split('/[,،;\n\/|]+/u', $raw) as $v) { $v = trim($v); if ($v !== '' && mb_strlen($v) <= 20 && !in_array($v, $out, true)) $out[] = $v; }
    return array_slice($out, 0, 20);
}
/** Text saved with an order line: "أسود (#111111) — المقاس: M". */
function variantText(string $color, string $size): string {
    $parts = [];
    if ($color !== '') $parts[] = colorName($color) . ' (' . $color . ')';
    if ($size !== '') $parts[] = 'المقاس: ' . $size;
    return implode(' — ', $parts);
}
function isValidColor($c): bool {
    return is_string($c) && preg_match('/^#[0-9a-fA-F]{6}$/', $c);
}
function colorName(string $hex): string {
    $names = [
        '#000000' => 'أسود', '#111111' => 'أسود', '#121212' => 'أسود', '#151515' => 'أسود', '#1a1a1a' => 'أسود', '#1c1c1c' => 'أسود', '#222222' => 'أسود',
        '#ffffff' => 'أبيض', '#c9a45c' => 'ذهبي', '#b8923f' => 'ذهبي', '#a9adb3' => 'فضي', '#9aa0a6' => 'فضي', '#c0c0c0' => 'فضي',
        '#6b3f22' => 'هافانا', '#5a3620' => 'هافانا', '#8b4513' => 'بني', '#1f3a5f' => 'أزرق', '#0000ff' => 'أزرق',
        // Wood & upholstery tones
        '#5b3a21' => 'جوز داكن', '#b98a55' => 'بلوط فاتح', '#6b4326' => 'بني', '#8a7059' => 'بني فاتح',
        '#6f6f6f' => 'رمادي', '#cbb79c' => 'بيج', '#3e2a1a' => 'ونجي', '#d9c7ae' => 'كريمي',
        '#8f1d2c' => 'أحمر داكن', '#d4af37' => 'ذهبي', '#e8704a' => 'برتقالي', '#d63f6b' => 'وردي', '#f4c2c2' => 'وردي فاتح', '#800020' => 'عنابي', '#f5f5dc' => 'بيج', '#6b3f22' => 'بني', '#ff0000' => 'أحمر', '#808080' => 'رمادي', '#008000' => 'أخضر', '#ffc0cb' => 'وردي', '#d8c3a5' => 'بيج', '#a9c1e8' => 'أزرق فاتح', '#1b2a4a' => 'كحلي', '#6b7a3a' => 'كاكي', '#b48ec7' => 'موف', '#e0413a' => 'أحمر',
    ];
    return $names[strtolower($hex)] ?? $hex;
}
/** Colour name in the visitor's language (orders are always saved with the Arabic name). */
function colorLabel(string $hex): string {
    if (!isFr()) return colorName($hex);
    $fr = ['أسود' => 'Noir', 'أبيض' => 'Blanc', 'ذهبي' => 'Doré', 'فضي' => 'Argenté', 'بني' => 'Marron', 'بني فاتح' => 'Marron clair', 'أزرق' => 'Bleu', 'أزرق فاتح' => 'Bleu ciel',
        'رمادي' => 'Gris', 'بيج' => 'Beige', 'كريمي' => 'Crème', 'أحمر' => 'Rouge', 'أحمر داكن' => 'Rouge foncé', 'عنابي' => 'Bordeaux', 'وردي' => 'Rose', 'وردي فاتح' => 'Rose pâle',
        'أخضر' => 'Vert', 'برتقالي' => 'Orange', 'كاكي' => 'Kaki', 'كحلي' => 'Bleu marine', 'موف' => 'Mauve'];
    $ar = colorName($hex);
    return $fr[$ar] ?? $ar;
}
/** Settings stored as JSON (lists of banners, features, ...). */
function settingJson(string $key): array {
    $v = json_decode(setting($key, '[]'), true);
    return is_array($v) ? $v : [];
}

/* ---------- Categories ---------- */
function allCategories(bool $activeOnly = true): array {
    static $cache = [];
    $k = (int)$activeOnly;
    if (!isset($cache[$k])) {
        $cache[$k] = getDB()->query('SELECT * FROM categories' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY sort, id')->fetchAll();
    }
    return $cache[$k];
}
function topCategories(): array {
    return array_values(array_filter(allCategories(), fn($c) => (int)$c['parent_id'] === 0));
}
function childCategories(int $parentId): array {
    return array_values(array_filter(allCategories(), fn($c) => (int)$c['parent_id'] === $parentId));
}
function getCategory(int $id): ?array {
    foreach (allCategories(false) as $c) if ((int)$c['id'] === $id) return $c;
    return null;
}
/** The category id plus all of its sub-category ids. */
function categoryWithChildren(int $id): array {
    $ids = [$id];
    foreach (childCategories($id) as $c) $ids = array_merge($ids, categoryWithChildren((int)$c['id']));
    return $ids;
}

function getProduct(int $id): ?array {
    $st = getDB()->prepare('SELECT p.*, c.name AS category, c.name_fr AS category_fr FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.id = ? AND p.active = 1');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}
/** Base SELECT for product lists, with category name and approved review stats. */
function productSelect(): string {
    return 'SELECT p.*, c.name AS category, c.name_fr AS category_fr, c.description AS category_label,
        (SELECT ROUND(AVG(rating),1) FROM reviews r WHERE r.product_id = p.id AND r.approved = 1) AS rating,
        (SELECT COUNT(*) FROM reviews r WHERE r.product_id = p.id AND r.approved = 1) AS reviews_count
        FROM products p LEFT JOIN categories c ON c.id = p.category_id';
}
function stars(float $rating, int $size = 14): string {
    $out = '<span class="stars" aria-label="' . $rating . ' ' . tx('من 5', 'sur 5') . '">';
    for ($i = 1; $i <= 5; $i++) {
        $fill = $rating >= $i ? 'full' : ($rating >= $i - 0.5 ? 'half' : 'empty');
        $out .= '<svg class="star ' . $fill . '" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24"><defs><linearGradient id="hs"><stop offset="50%" stop-color="currentColor"/><stop offset="50%" stop-color="#ddd"/></linearGradient></defs><path d="m12 2 3.1 6.3 6.9 1-5 4.9 1.2 6.8L12 17.8 5.8 21l1.2-6.8-5-4.9 6.9-1z"/></svg>';
    }
    return $out . '</span>';
}

/* ---------- Cart (session) ---------- */
function cart(): array {
    return $_SESSION['cart'] ?? [];
}
function cartCount(): int {
    return array_sum(array_column(cart(), 'qty'));
}
function cartAdd(int $pid, string $color, int $qty, string $size = ''): array {
    $p = getProduct($pid);
    if (!$p) return [false, tx('المنتج غير موجود', 'Produit introuvable')];
    $colors = productColors($p);
    if (count($colors) === 1) $color = $colors[0];
    if ($colors && !in_array($color, $colors, true)) return [false, tx('يرجى اختيار اللون', 'Veuillez choisir la couleur')];
    if (!$colors) $color = '';
    $sizes = productSizes($p);
    if (count($sizes) === 1) $size = $sizes[0];
    if ($sizes && !in_array($size, $sizes, true)) return [false, tx('يرجى اختيار المقاس', 'Veuillez choisir la taille')];
    if (!$sizes) $size = '';
    $qty = max(1, min(20, $qty));
    $key = $pid . '|' . $color . '|' . $size;
    $current = $_SESSION['cart'][$key]['qty'] ?? 0;
    if ($current + $qty > (int)$p['stock']) return [false, tx('الكمية المطلوبة غير متوفرة في المخزون', 'Quantité demandée indisponible en stock')];
    $_SESSION['cart'][$key] = ['id' => $pid, 'color' => $color, 'size' => $size, 'qty' => $current + $qty];
    if (cartMergeBundle()) return [true, tx('تم تطبيق عرض الكتابين في السلة: ', 'Offre 2 livres appliquée : ') . money(getProduct((int)setting('landing_bundle'))['price'] ?? 0)];
    return [true, tx('تمت الإضافة إلى السلة', 'Ajouté au panier')];
}
/**
 * Two-book offer: when both books (landing_p1 + landing_p2) are in the cart, each pair becomes one
 * "offer" item (landing_bundle) at the bundle price. Returns true when something was merged.
 */
function cartMergeBundle(): bool {
    $b = (int)setting('landing_bundle'); $a1 = (int)setting('landing_p1'); $a2 = (int)setting('landing_p2');
    if (!$b || !$a1 || !$a2 || empty($_SESSION['cart'])) return false;
    $bp = getProduct($b);
    if (!$bp || (int)$bp['stock'] <= 0) return false;
    $sumOf = fn(int $id) => array_sum(array_map(fn($it) => (int)$it['id'] === $id ? (int)$it['qty'] : 0, $_SESSION['cart']));
    $pairs = min($sumOf($a1), $sumOf($a2));
    if ($pairs < 1) return false;
    foreach ([$a1, $a2] as $id) {
        $left = $pairs;
        foreach ($_SESSION['cart'] as $k => $it) {
            if ($left < 1) break;
            if ((int)$it['id'] !== $id) continue;
            $take = min($left, (int)$it['qty']);
            $_SESSION['cart'][$k]['qty'] -= $take;
            $left -= $take;
            if ($_SESSION['cart'][$k]['qty'] < 1) unset($_SESSION['cart'][$k]);
        }
    }
    $key = $b . '||';
    $_SESSION['cart'][$key] = ['id' => $b, 'color' => '', 'size' => '', 'qty' => min(20, ($_SESSION['cart'][$key]['qty'] ?? 0) + $pairs)];
    return true;
}
function cartTotal(): int {
    return array_sum(array_column(cartLines(), 'total'));
}
/** Returns cart lines joined with fresh product data (price, name, stock). */
function cartLines(): array {
    $lines = [];
    foreach (cart() as $key => $item) {
        $p = getProduct((int)$item['id']);
        if (!$p) { unset($_SESSION['cart'][$key]); continue; }
        $lines[] = [
            'key' => $key, 'product' => $p, 'color' => $item['color'], 'size' => (string)($item['size'] ?? ''),
            'qty' => (int)$item['qty'], 'total' => (int)$item['qty'] * (int)$p['price'],
        ];
    }
    return $lines;
}

/* ---------- Wishlist (session) ---------- */
function wishlist(): array {
    return $_SESSION['wishlist'] ?? [];
}
function inWishlist(int $id): bool {
    return in_array($id, wishlist(), true);
}

/* ---------- Orders ---------- */
function orderStatuses(): array {
    if (isFr()) return [
        'new' => 'Reçue', 'confirmed' => 'Confirmée', 'shipped' => 'Expédiée',
        'delivered' => 'Livrée', 'cancelled' => 'Annulée', 'returned' => 'Retournée',
    ];
    return [
        'new' => 'جديد', 'confirmed' => 'مؤكد', 'shipped' => 'تم الشحن',
        'delivered' => 'تم التسليم', 'cancelled' => 'ملغى', 'returned' => 'مرتجع',
    ];
}
function normalizePhone(string $p): string {
    $p = preg_replace('/\D+/', '', $p);
    if (str_starts_with($p, '213')) $p = '0' . substr($p, 3);
    return $p;
}
function validPhone(string $p): bool {
    return (bool)preg_match('/^0[5-7]\d{8}$/', $p);
}

/* ---------- Uploads ---------- */
/** Validate an uploaded image and store a compressed copy (see includes/images.php). */
function uploadImage(array $file, string $folder): ?string {
    $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_NO_FILE) return null;
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) throw new RuntimeException('الصورة أكبر من الحد الذي تسمح به الاستضافة — جرّب صورة أصغر');
    if ($err !== UPLOAD_ERR_OK) throw new RuntimeException('فشل رفع الصورة (رمز ' . (int)$err . ')');
    if ($file['size'] > 25 * 1024 * 1024) throw new RuntimeException('حجم الصورة أكبر من 25 ميغابايت');
    if (!@getimagesize($file['tmp_name'])) throw new RuntimeException('الملف ليس صورة صالحة (JPG, PNG, WEBP)');
    require_once __DIR__ . '/images.php';
    return compressImageUpload($file['tmp_name'], $folder);
}
function deleteUpload(?string $path): void {
    if ($path && str_starts_with($path, 'uploads/') && !str_contains($path, '..')) {
        $full = ROOT_PATH . '/' . $path;
        if (is_file($full)) @unlink($full);
        $small = preg_replace('/\.(\w+)$/', '-sm.$1', $full);
        if (is_file($small)) @unlink($small);
    }
}
/** Small (card-size) version of a product's main image. */
/** JSON for the "اطلب الآن" popup (read by main.js from data-order). */
function orderData(array $p): string {
    return json_encode([
        'id' => (int)$p['id'], 'name' => tr($p, 'name'), 'price' => (int)$p['price'],
        'img' => productThumb($p), 'max' => max(1, min(20, (int)$p['stock'])),
        'colors' => array_map(fn($c) => [$c, colorLabel($c)], productColors($p)),
        'sizes' => productSizes($p),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
function productThumb(array $p): string {
    require_once __DIR__ . '/images.php';
    return imageSmall(productImage($p));
}

function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}
function flash(?string $msg = null, string $type = 'success') {
    if ($msg !== null) { $_SESSION['flash'] = [$msg, $type]; return null; }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

/** Small inline SVG icon set. */
function icon(string $name, int $size = 20): string {
    $p = [
        'phone'   => '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.7a2 2 0 0 1-.5 2.1L8 9.8a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.7.7a2 2 0 0 1 1.7 2z"/>',
        'shield'  => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>',
        'leaf'    => '<path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10z"/><path d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12"/>',
        'bolt'    => '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
        'badge'   => '<circle cx="12" cy="9" r="6"/><path d="M8.2 13.9 7 22l5-2.5L17 22l-1.2-8.1"/>',
        'drop'    => '<path d="M12 2.7s6 6.3 6 10.3a6 6 0 0 1-12 0c0-4 6-10.3 6-10.3z"/>',
        'cash'    => '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 12h.01M18 12h.01"/>',
        'truck'   => '<path d="M1 3h15v13H1z"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>',
        'cart'    => '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>',
        'cartsm'  => '<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L23 6H6"/>',
        'user'    => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'search'  => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
        'heart'   => '<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1 1.1L12 21l7.8-7.5 1-1.1a5.5 5.5 0 0 0 0-7.8z"/>',
        'chev-l'  => '<path d="m15 18-6-6 6-6"/>',
        'chev-r'  => '<path d="m9 18 6-6-6-6"/>',
        'chev-d'  => '<path d="m6 9 6 6 6-6"/>',
        'arrow-l' => '<path d="M19 12H5M12 19l-7-7 7-7"/>',
        'gift'    => '<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"/><path d="M7.5 8a2.5 2.5 0 0 1 0-5C11 3 12 8 12 8s1-5 4.5-5a2.5 2.5 0 0 1 0 5"/>',
        'star'    => '<path d="m12 2 3.1 6.3 6.9 1-5 4.9 1.2 6.8L12 17.8 5.8 21l1.2-6.8-5-4.9 6.9-1z"/>',
        'send'    => '<path d="m22 2-7 20-4-9-9-4z"/><path d="M22 2 11 13"/>',
        'card'    => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
        'crown'   => '<path d="m2 18 2-11 5 5 3-7 3 7 5-5 2 11z"/><path d="M4 21h16"/>',
        'flame'   => '<path d="M12 22c4 0 7-3 7-7 0-5-5-7-5-12-3 2-5 5-5 8-1-1-2-2-2-4-2 2-2 5-2 8 0 4 3 7 7 7z"/>',
        'arrow-r' => '<path d="M5 12h14M12 5l7 7-7 7"/>',
        'sparkle' => '<path d="M12 3v4M12 17v4M3 12h4M17 12h4M5.6 5.6l2.8 2.8M15.6 15.6l2.8 2.8M5.6 18.4l2.8-2.8M15.6 8.4l2.8-2.8"/>',
        'headset' => '<path d="M3 18v-6a9 9 0 0 1 18 0v6"/><path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"/>',
        'return'  => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/>',
        'lock'    => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
        'tag'     => '<path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L2 12V2h10l8.6 8.6a2 2 0 0 1 0 2.8z"/><circle cx="7" cy="7" r="1.5"/>',
        'menu'    => '<path d="M3 6h18M3 12h18M3 18h18"/>',
        'x'       => '<path d="M18 6 6 18M6 6l12 12"/>',
        'trash'   => '<path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/>',
        'mail'    => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 6-10 7L2 6"/>',
        'pin'     => '<path d="M12 22s7-7.5 7-13a7 7 0 0 0-14 0c0 5.5 7 13 7 13z"/><circle cx="12" cy="9" r="2.5"/>',
        'check'   => '<path d="M20 6 9 17l-5-5"/>',
        'box'     => '<path d="M21 8 12 3 3 8v8l9 5 9-5z"/><path d="m3 8 9 5 9-5M12 13v8"/>',
        'whatsapp'=> '<path d="M3 21l1.7-5A9 9 0 1 1 8 19.3z"/><path d="M9 9.5c0 3 2.5 5.5 5.5 5.5l1.2-1.3-2-1-1 .8a4 4 0 0 1-2.2-2.2l.8-1-1-2z"/>',
        // Perfume
        'bottle'  => '<path d="M9.5 2.5h5v3h-5z"/><path d="M10.5 5.5v2M13.5 5.5v2"/><rect x="5" y="7.5" width="14" height="14" rx="3"/><rect x="8.5" y="11.5" width="7" height="6" rx="1"/>',
        'drops'   => '<path d="M8 3s-4 4.5-4 7.5a4 4 0 0 0 8 0C12 7.5 8 3 8 3z"/><path d="M17 11s-3 3.3-3 5.5a3 3 0 0 0 6 0c0-2.2-3-5.5-3-5.5z"/>',
        'flower'  => '<circle cx="12" cy="12" r="2.5"/><path d="M12 9.5C10 6 10.5 3 12 3s2 3 0 6.5zM12 14.5c-2 3.5-1.5 6.5 0 6.5s2-3 0-6.5zM9.5 12C6 10 3 10.5 3 12s3 2 6.5 0zM14.5 12c3.5-2 6.5-1.5 6.5 0s-3 2-6.5 0z"/>',
        // Furniture
        'desk'    => '<path d="M2 9h20"/><path d="M4 9v11M20 9v11"/><path d="M13 9v7h7V9"/><path d="M13 12.5h7"/><path d="M2 9V6.5h20V9"/>',
        'wardrobe'=> '<rect x="4" y="2.5" width="16" height="17" rx="1.6"/><path d="M12 2.5v17"/><path d="M10 10.5v2.5M14 10.5v2.5"/><path d="M7 19.5V22M17 19.5V22"/>',
        'sofa'    => '<path d="M4 11V8a3 3 0 0 1 3-3h10a3 3 0 0 1 3 3v3"/><path d="M2 12.5a2 2 0 0 1 4 0V16h12v-3.5a2 2 0 0 1 4 0V19H2z"/><path d="M6 16v3M18 16v3"/>',
        'chair'   => '<path d="M7 3.5h10a2 2 0 0 1 2 2V12H5V5.5a2 2 0 0 1 2-2z"/><path d="M4 12h16"/><path d="M12 12v5"/><path d="M8 21l4-4 4 4"/><path d="M12 17v.5"/>',
        'table'   => '<path d="M2 8.5h20"/><path d="M5 8.5 4 21M19 8.5 20 21"/><path d="M2 8.5V6h20v2.5"/><path d="M12 8.5V14"/>',
        'bed'     => '<path d="M2 18V7"/><path d="M2 11h11a4 4 0 0 1 4 4v3"/><path d="M2 18h20v-4a3 3 0 0 0-3-3h-2"/><rect x="5" y="7.5" width="5" height="3" rx="1.2"/><path d="M4 18v2M20 18v2"/>',
        'home'    => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.7V20h14V9.7"/><path d="M10 20v-5h4v5"/>',
        'ruler'   => '<rect x="2" y="8" width="20" height="8" rx="1.6"/><path d="M6.5 8v3M10 8v4.5M13.5 8v3M17 8v4.5"/>',
        'layers'  => '<path d="m12 2.5 9 5-9 5-9-5z"/><path d="m3 12.5 9 5 9-5"/><path d="m3 17 9 5 9-5"/>',
        'hammer'  => '<path d="M14.5 6 18 2.5 21.5 6 18 9.5z"/><path d="m16.2 7.8-9 9"/><path d="m4 15 5 5-2.5 2.5L1.5 17.5z"/>',
        'expand'  => '<path d="M3 9V3h6M21 9V3h-6M3 15v6h6M21 15v6h-6"/>',
        'heart-pulse' => '<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1 1.1L12 21l7.8-7.5 1-1.1a5.5 5.5 0 0 0 0-7.8z"/><path d="M3.5 12h4l1.5-3 3 6 1.5-3h7"/>',
        'lungs'   => '<path d="M12 3v8"/><path d="M12 11c-1 1-2.2 1.5-3.5 1.5"/><path d="M12 11c1 1 2.2 1.5 3.5 1.5"/><path d="M8.6 6.2C6 6.8 3.5 10.5 3.5 15.5c0 2.6 1 4 2.7 4 2.6 0 3.8-1.6 3.8-4.6V7.8c0-1-.6-1.8-1.4-1.6z"/><path d="M15.4 6.2c2.6.6 5.1 4.3 5.1 9.3 0 2.6-1 4-2.7 4-2.6 0-3.8-1.6-3.8-4.6V7.8c0-1 .6-1.8 1.4-1.6z"/>',
        'massage' => '<circle cx="14.5" cy="4.5" r="2"/><path d="M4 21v-3.5a3 3 0 0 1 3-3h4.5l3-4"/><path d="m11.5 14.5 1.5 6.5"/><path d="M17 11.5c1.5.5 2.5 1.8 2.5 3.5"/><path d="M2.5 21h19"/>',
        'medkit'  => '<rect x="2.5" y="7" width="19" height="13.5" rx="2"/><path d="M8.5 7V5a1.5 1.5 0 0 1 1.5-1.5h4A1.5 1.5 0 0 1 15.5 5v2"/><path d="M12 10.5v6.5M8.75 13.75h6.5"/>',
        'wheelchair' => '<circle cx="9" cy="4" r="1.8"/><path d="M9 7.5v6h6l3 5.5"/><path d="M9 10.5h5"/><path d="M6.5 11.5a5.5 5.5 0 1 0 8 6.5"/>',
        'thermometer' => '<path d="M14 14.8V5a2 2 0 0 0-4 0v9.8a4 4 0 1 0 4 0z"/><path d="M12 9v8"/>',
        'grid'    => '<rect x="3.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="13.5" width="7" height="7" rx="1.5"/>',
        'bag'     => '<path d="M5.5 7.5h13l1 13.5h-15z"/><path d="M9 10V6.5a3 3 0 0 1 6 0V10"/>',
        // Skincare
        'face'    => '<path d="M12 2.8c-4 0-6.6 3-6.6 7.3 0 2.6.9 4.8 2.3 6.3 1.2 1.3 2.7 2 4.3 2s3.1-.7 4.3-2c1.4-1.5 2.3-3.7 2.3-6.3 0-4.3-2.6-7.3-6.6-7.3z"/><path d="M5.6 9.2c2.9.3 5.6-.9 7.4-3.2 1.1 1.6 3 2.7 5.4 3"/><path d="M9.3 11.6h.01M14.7 11.6h.01"/><path d="M10.4 15c.9.6 2.3.6 3.2 0"/><path d="M8.5 18.4 7.6 21.5M15.5 18.4l.9 3.1"/>',
        'belly'   => '<path d="M7.2 2c.2 1.5-.1 2.6-1 3.6"/><path d="M16.8 2c-.2 1.5.1 2.6 1 3.6"/><path d="M6.2 5.6c1.6-.9 3.1.2 4.4-.5 1-.5 1.8-.5 2.8 0 1.3.7 2.8-.4 4.4.5"/><path d="M6.2 5.6C4.4 6.8 3.9 9 4.9 10.5 3.6 11.8 3.4 13.6 4.3 15.3"/><path d="M17.8 5.6c1.8 1.2 2.3 3.4 1.3 4.9 1.3 1.3 1.5 3.1.6 4.8"/><path d="M4.3 15.3C6.8 17.6 9.4 18.3 12 18.3s5.2-.7 7.7-3"/><path d="M4.3 15.3c-.3 2.3-.1 4.5.5 6.7"/><path d="M19.7 15.3c.3 2.3.1 4.5-.5 6.7"/><path d="M8.6 17.8c1.6 1 2.8 2.3 3.4 4.2.6-1.9 1.8-3.2 3.4-4.2"/><path d="M12 13.2v.01"/>',
        'body'    => '<path d="M8 2.5c-.3 2 .2 3.6 1.4 4.6-1.9 1.4-3 3.8-3 6.9 0 2.4.6 4.7 1.4 7.5"/><path d="M16 2.5c.3 2-.2 3.6-1.4 4.6 1.9 1.4 3 3.8 3 6.9 0 2.4-.6 4.7-1.4 7.5"/><path d="M9.6 11.6c.8.6 1.6.9 2.4.9s1.6-.3 2.4-.9"/><path d="M12 16.5v.01"/>',
        'hair'    => '<path d="M15.5 21.5c-.6-2.6-.3-5 .9-7.2 1.4-2.6 1.6-5.4.2-7.7C15.2 4.1 12.4 2.6 9.6 3 6 3.5 4 6.6 4.4 10c.2 1.9 1.1 3.3 2.2 4.4"/><path d="M9.6 3c-1.1 2.5-.7 5.4 1 7.6 1.4 1.8 1.8 4.2 1 6.6l-1.4 4.3"/><path d="M6.6 14.4c.8 1.6 1 3.4.6 5.3"/><path d="M19.6 9.6c.5 2.4 0 4.6-1.3 6.6"/>',
        'lipstick'=> '<path d="M7.5 21.5h5v-9h-5z"/><path d="M8.3 12.5V9.4l2.9-1.8v4.9"/><path d="M8.3 9.4V6.2l2.9-3.7v5.1"/><path d="M14.5 21.5h3v-6.8l-1.5-2.2-1.5 2.2z"/><path d="M14.5 17.2h3"/>',
        'sun'     => '<circle cx="12" cy="12" r="4.2"/><path d="M12 2v2.5M12 19.5V22M2 12h2.5M19.5 12H22M4.9 4.9l1.8 1.8M17.3 17.3l1.8 1.8M4.9 19.1l1.8-1.8M17.3 6.7l1.8-1.8"/>',
        'serum'   => '<path d="M10 2.5h4v4h-4z"/><path d="M9 6.5h6"/><path d="M8.5 9.5a2 2 0 0 1 2-2h3a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2h-3a2 2 0 0 1-2-2z"/><path d="M8.5 13h7"/>',
        'jar'     => '<rect x="4" y="5" width="16" height="4.5" rx="1.5"/><path d="M5 9.5v8.5a3 3 0 0 0 3 3h8a3 3 0 0 0 3-3V9.5"/><path d="M8.5 14h7"/>',
        'globe'   => '<circle cx="12" cy="12" r="9.5"/><path d="M2.5 12h19M12 2.5c2.5 2.6 3.8 5.8 3.8 9.5s-1.3 6.9-3.8 9.5c-2.5-2.6-3.8-5.8-3.8-9.5S9.5 5.1 12 2.5z"/>',
        'book-open' => '<path d="M2 5.5C4.5 4.2 8 4 12 6.2c4-2.2 7.5-2 10-.7V19c-2.5-1.3-6-1.1-10 1.1-4-2.2-7.5-2.4-10-1.1z"/><path d="M12 6.2v13.9"/><path d="M4.5 9c1.8-.5 3.7-.3 5.3.4M4.5 12.3c1.8-.5 3.7-.3 5.3.4M14.2 9.4c1.6-.7 3.5-.9 5.3-.4M14.2 12.7c1.6-.7 3.5-.9 5.3-.4"/>',
        'atom'    => '<circle cx="12" cy="12" r="1.6"/><ellipse cx="12" cy="12" rx="10" ry="4.2"/><ellipse cx="12" cy="12" rx="10" ry="4.2" transform="rotate(60 12 12)"/><ellipse cx="12" cy="12" rx="10" ry="4.2" transform="rotate(120 12 12)"/>',
        'func'    => '<path d="M3 3v18h18"/><path d="M6 17c2.5 0 3.5-9 6-9s3 5 5 5 2-6 3-8"/>',
        'cap'     => '<path d="M2 9.5 12 5l10 4.5-10 4.5z"/><path d="M6 11.3V16c3.6 2.4 8.4 2.4 12 0v-4.7"/><path d="M22 9.5V15"/>',
        'bulb'    => '<path d="M9 18h6M10 21h4"/><path d="M12 3a6 6 0 0 0-3.6 10.8c.7.6 1.1 1.4 1.1 2.2h5c0-.8.4-1.6 1.1-2.2A6 6 0 0 0 12 3z"/>',
        'lotus'   => '<path d="M12 4c2.2 2.1 3.3 4.6 3.3 7.4 0 3-1.3 5.4-3.3 7.1-2-1.7-3.3-4.1-3.3-7.1 0-2.8 1.1-5.3 3.3-7.4z"/><path d="M8.9 8.5C6.5 8 4.4 8.3 2.5 9.4c.3 4.7 4.3 8.6 9.5 9.1"/><path d="M15.1 8.5c2.4-.5 4.5-.2 6.4.9-.3 4.7-4.3 8.6-9.5 9.1"/><path d="M5.6 15.7c-1.3.6-2.4 1.5-3.1 2.6 2.6 1.5 6 1.9 9.5.2 3.5 1.7 6.9 1.3 9.5-.2-.7-1.1-1.8-2-3.1-2.6"/>',
        'heart-check' => '<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1 1.1L12 21l7.8-7.5 1-1.1a5.5 5.5 0 0 0 0-7.8z"/><path d="m9 11.5 2.2 2.2 4-4"/>',
    ];
    $social = [
        'instagram' => '<rect x="3" y="3" width="18" height="18" rx="5" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="12" cy="12" r="4" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="17.5" cy="6.5" r="1.2"/>',
        'facebook'  => '<path d="M14 8h3V4h-3a4 4 0 0 0-4 4v2H7v4h3v8h4v-8h3l1-4h-4V8.5c0-.3.2-.5.5-.5z"/>',
        'tiktok'    => '<path d="M16 3c.4 2.4 2 4 4.5 4.2v3.3a8 8 0 0 1-4.4-1.4v6.4A5.6 5.6 0 1 1 10.5 10v3.4a2.3 2.3 0 1 0 2.2 2.2V3z"/>',
        'youtube'   => '<path d="M22 8.2a3 3 0 0 0-2-2.1C18.2 5.6 12 5.6 12 5.6s-6.2 0-8 .5a3 3 0 0 0-2 2.1A31 31 0 0 0 1.6 12 31 31 0 0 0 2 15.8a3 3 0 0 0 2 2.1c1.8.5 8 .5 8 .5s6.2 0 8-.5a3 3 0 0 0 2-2.1 31 31 0 0 0 .4-3.8 31 31 0 0 0-.4-3.8zM10 15V9l5.2 3z"/>',
        'pinterest' => '<path d="M12 2a10 10 0 0 0-3.6 19.3c-.1-.8-.2-2 0-2.9l1.2-5s-.3-.6-.3-1.5c0-1.4.8-2.5 1.8-2.5.9 0 1.3.6 1.3 1.4 0 .9-.5 2.1-.8 3.3-.2 1 .5 1.8 1.5 1.8 1.8 0 3.1-1.9 3.1-4.6 0-2.4-1.7-4.1-4.2-4.1-2.9 0-4.5 2.1-4.5 4.4 0 .9.3 1.8.8 2.3v.4l-.3 1.2c0 .2-.2.3-.4.2-1.3-.6-2.1-2.5-2.1-4 0-3.3 2.4-6.3 6.9-6.3 3.6 0 6.4 2.6 6.4 6 0 3.6-2.3 6.5-5.4 6.5-1 0-2-.5-2.4-1.2l-.6 2.5c-.2.9-.9 2.1-1.3 2.8A10 10 0 1 0 12 2z"/>',
    ];
    if (isset($social[$name])) {
        return '<svg class="ic" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">' . $social[$name] . '</svg>';
    }
    return '<svg class="ic" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$name] ?? '') . '</svg>';
}

/** Icons the owner can choose from in the admin (topbar & "why us" items). */
function iconChoices(): array {
    return [
        // Perfume
        'bottle' => 'زجاجة عطر', 'drops' => 'قطرات', 'flower' => 'زهرة',
        // Furniture
        'desk' => 'مكتب', 'wardrobe' => 'خزانة', 'sofa' => 'أريكة / ركنة', 'chair' => 'كرسي',
        'table' => 'طاولة', 'bed' => 'سرير', 'home' => 'منزل', 'layers' => 'رفوف',
        'ruler' => 'قياسات', 'hammer' => 'تصنيع', 'expand' => 'مساحات',
        // General
        'cash' => 'دفع', 'truck' => 'توصيل', 'shield' => 'ضمان', 'star' => 'نجمة', 'headset' => 'خدمة عملاء',
        'badge' => 'جودة', 'sparkle' => 'تميّز', 'heart' => 'قلب', 'return' => 'إرجاع', 'lock' => 'أمان',
        'tag' => 'سعر', 'box' => 'علبة', 'phone' => 'هاتف', 'card' => 'بطاقة', 'gift' => 'هدية',
        'crown' => 'تاج', 'bolt' => 'طاقة', 'leaf' => 'ورقة',
        // Skincare
        'face' => 'وجه (عناية بالوجه)', 'body' => 'جسم', 'belly' => 'جسم ممتلئ (زيادة الوزن)', 'hair' => 'شعر', 'lipstick' => 'مكياج', 'sun' => 'شمس', 'drop' => 'قطرة (ترطيب)',
        'heart-pulse' => 'قلب ونبض', 'lungs' => 'رئتان (تنفس)', 'massage' => 'مساج', 'medkit' => 'حقيبة طبية', 'wheelchair' => 'كرسي متحرك', 'thermometer' => 'ميزان حرارة', 'grid' => 'شبكة',
        'serum' => 'سيروم', 'jar' => 'كريم', 'globe' => 'عالمي', 'lotus' => 'زهرة اللوتس', 'heart-check' => 'قلب مع علامة',
    ];
}

/** Safe hex color from settings. */
function colorSetting(string $key, string $default): string {
    $v = setting($key, $default);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? $v : $default;
}


/**
 * Write one CSV row safely.
 *
 * Two problems this solves:
 *  1. PHP 8.4 deprecates calling fputcsv() without $escape — the notice was
 *     being injected into the downloaded file, corrupting the export.
 *  2. Excel and LibreOffice execute any cell starting with = + - @ , so a
 *     customer who types "=cmd|..." as their name would run code on the
 *     shop owner's machine. Prefixing with an apostrophe stops that.
 *
 * @param resource $handle
 * @param array    $row
 */
function csvRow($handle, array $row): void {
    $safe = array_map(function ($value) {
        $value = (string) $value;
        if ($value !== '' && strpbrk($value[0], "=+-@\t\r") !== false) {
            $value = "'" . $value;
        }
        return $value;
    }, $row);

    fputcsv($handle, $safe, ',', '"', '');
}
