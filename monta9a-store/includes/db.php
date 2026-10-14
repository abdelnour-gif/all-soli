<?php
/**
 * Store database layer (SQLite).
 * The database file and all tables are created automatically on first run.
 */

// Never print PHP notices to a visitor: they reveal the absolute server path.
// Errors still go to the host's error log where the developer can read them.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

if (session_status() === PHP_SESSION_NONE) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        // Without this the admin session cookie can travel over plain HTTP.
        'secure'   => $https,
    ]);
    session_start();
}

define('ROOT_PATH', dirname(__DIR__));
define('DB_PATH', ROOT_PATH . '/database/store.db');

function getDB(): PDO {
    static $db = null;
    if ($db === null) {
        try {
            $db = new PDO('sqlite:' . DB_PATH, null, null, [PDO::ATTR_TIMEOUT => 15]);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            // Two visitors writing at the same moment wait for each other instead of failing with "database is locked".
            $db->exec('PRAGMA busy_timeout = 15000');
            $db->exec('PRAGMA foreign_keys = ON');
            if (storeNeedsSetup($db)) {
                // Only one request at a time may create / update the store (otherwise two simultaneous first
                // visits would both seed it and every product would appear twice).
                $lock = @fopen(ROOT_PATH . '/database/.setup.lock', 'c');
                if ($lock) flock($lock, LOCK_EX);
                try {
                    if (storeNeedsSetup($db)) setupStore($db);
                } finally {
                    if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
                }
            }
        } catch (Throwable $e) {
            if ($db instanceof PDO && $db->inTransaction()) $db->rollBack();
            error_log('Store database error: ' . $e->getMessage());
            $db = null;
            if (!headers_sent()) { http_response_code(503); header('Content-Type: text/html; charset=UTF-8'); header('Retry-After: 30'); }
            exit('<!DOCTYPE html><html lang="ar" dir="rtl"><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>صيانة</title>'
               . '<body style="font-family:system-ui,sans-serif;text-align:center;padding:60px 16px;color:#333">'
               . '<h1 style="color:#12805c">المتجر قيد الصيانة</h1><p>نعود خلال لحظات، شكراً لصبركم.</p>'
               . '<p style="color:#888;font-size:13px">إن استمرت هذه الرسالة: تأكد أن مجلد <b>database</b> قابل للكتابة (صلاحيات 755) وأن إضافة pdo_sqlite مفعّلة.</p></body></html>');
        }
    }
    return $db;
}

/** One-time updates, in the order they must run: settings flag => function. */
function storeUpdates(): array {
    return [
        // Switch to the cosmetics store (earlier storefronts are hidden, orders kept).
        'theme_beauty'     => fn(PDO $db) => applyBeautyTheme($db, false),
        // FATI KRITI branding, "about us" text, new banners and the «زيادة الوزن» category.
        'fk_brand_1'       => fn(PDO $db) => applyFatiKriti($db),
        // «زيادة الوزن» gets its own icon (full body).
        'fk_icon_belly'    => function (PDO $db) {
            $db->exec("UPDATE categories SET icon = 'belly' WHERE name = 'زيادة الوزن' AND icon IN ('', 'flame')");
            $db->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('fk_icon_belly', '1')");
        },
        // Storefront v2 (top bar with icons, 3 hero slides, trust card, featured products, 3-part band).
        'fk_layout_2'      => fn(PDO $db) => applyFatiLayout($db),
        // The store becomes MONTA9A — medical devices and supplies (previous catalogue hidden, orders kept).
        'theme_medical_1'  => fn(PDO $db) => applyMedicalTheme($db),
        // Switch the floating WhatsApp button on.
        'wa_float_fix'     => fn(PDO $db) => $db->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('wa_float_on', '1'), ('wa_float_fix', '1')"),
        // MONTA9A homepage exactly like the design (logo, hero, category pictures, bottom band).
        'monta9a_design_1' => fn(PDO $db) => applyMonta9aDesign($db),
        // One admin account per username: older stores could hold the same account twice, which made
        // «حفظ بيانات الحساب» answer «اسم المستخدم مستعمل» even when only the e-mail changed.
        'admins_unique_1'  => fn(PDO $db) => dedupeAdmins($db),
    ];
}

/** True while the tables are missing/outdated or a one-time update has not run (a new store has none of the flags). */
function storeNeedsSetup(PDO $db): bool {
    try {
        $keys = array_merge(['schema_version'], array_keys(storeUpdates()));
        $in = implode(',', array_fill(0, count($keys), '?'));
        $st = $db->prepare("SELECT key, value FROM settings WHERE key IN ($in)");
        $st->execute($keys);
        $have = $st->fetchAll(PDO::FETCH_KEY_PAIR);
        if ((string)($have['schema_version'] ?? '') !== SCHEMA_VERSION) return true;
        foreach (array_keys(storeUpdates()) as $k) if ((string)($have[$k] ?? '') !== '1') return true;
        return false;
    } catch (Throwable $e) {
        return true; // tables do not exist yet
    }
}

/** Create/upgrade the tables, seed an empty store, then run the pending one-time updates. */
function setupStore(PDO $db): void {
    if (schemaNeedsUpgrade($db)) {
        createSchema($db);
        migrateSchema($db);
        markSchemaCurrent($db);
    }
    // A store that was never seeded (new or empty file): default settings, admin account, delivery prices.
    if (!$db->query('SELECT 1 FROM wilayas LIMIT 1')->fetchColumn()) {
        seedDatabase($db);
    }
    $flag = $db->prepare('SELECT value FROM settings WHERE key = ?');
    foreach (storeUpdates() as $key => $run) {
        $flag->execute([$key]);
        $done = (string)$flag->fetchColumn() === '1';
        $flag->closeCursor();
        if ($done) continue;
        try {
            $run($db);
        } catch (Throwable $e) {
            // A failed update is undone and retried on the next visit; the shop keeps working meanwhile.
            if ($db->inTransaction()) $db->rollBack();
            error_log("Store update '$key' failed: " . $e->getMessage());
            return;
        }
    }
}

/**
 * Removes duplicate admin rows (same username, any letter case) and prevents new ones.
 * The kept row is the oldest one — the one the login page, «نسيت كلمة المرور» and reset-password.txt
 * already use — so nobody is logged out and the password does not change. An e-mail saved only on a
 * removed duplicate is copied to the kept account.
 */
function dedupeAdmins(PDO $db): void {
    $db->beginTransaction();
    $cols = array_column($db->query('PRAGMA table_info(admins)')->fetchAll(), 'name');
    if (in_array('email', $cols, true)) {
        $db->exec("UPDATE admins SET email = (SELECT d.email FROM admins d WHERE lower(d.username) = lower(admins.username) AND d.id > admins.id AND d.email <> '' ORDER BY d.id LIMIT 1)
                   WHERE (email IS NULL OR email = '') AND id = (SELECT MIN(k.id) FROM admins k WHERE lower(k.username) = lower(admins.username))
                     AND EXISTS (SELECT 1 FROM admins d WHERE lower(d.username) = lower(admins.username) AND d.id > admins.id AND d.email <> '')");
    }
    $db->exec('DELETE FROM admins WHERE id NOT IN (SELECT MIN(id) FROM admins GROUP BY lower(username))');
    $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS admins_username_nocase ON admins (username COLLATE NOCASE)');
    $db->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('admins_unique_1', '1')");
    $db->commit();
}

/**
 * MONTA9A — homepage like the design: logo, main hero picture, a picture on every category tile,
 * the band at the bottom with its photo, and the sections in the design's order
 * (hero · trust card · categories · featured products · band). Everything stays editable in the admin.
 */
function applyMonta9aDesign(PDO $db): void {
    $db->beginTransaction();
    $set = $db->prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)');
    $get = fn(string $k) => (string)$db->query('SELECT value FROM settings WHERE key = ' . $db->quote($k))->fetchColumn();
    $d = 'assets/img/seed/med/';

    $db->prepare('UPDATE slides SET image = ? WHERE image = ?')->execute([$d . 'hero-main.webp', $d . 'hero1.webp']);
    if ($get('logo') === '') { $set->execute(['logo', $d . 'logo.webp']); $set->execute(['logo_with_name', '0']); }
    if (in_array($get('cta_image'), ['', $d . 'cta.webp'], true)) $set->execute(['cta_image', $d . 'cta-band.webp']);

    // category tiles: the pictures of the design (only where the owner has not uploaded one)
    $tiles = ['أجهزة قياس الضغط والنبض' => 5, 'أجهزة التنفس الاصطناعي' => 4, 'أجهزة الماساج والعلاج الطبيعي' => 3,
              'أجهزة ومستلزمات الراحة والحركة' => 2, 'العناية الصحية والوقاية' => 1, 'ملحقات طبية متنوعة' => 0];
    $up = $db->prepare("UPDATE categories SET image = ? WHERE name = ? AND (image IS NULL OR image = '')");
    foreach ($tiles as $name => $n) $up->execute([$d . 'tile-' . $n . '.webp', $name]);

    // «معاً نحو صحة أفضل» with the leaf icon, like the design
    $why = json_decode($get('why'), true);
    if (is_array($why)) {
        foreach ($why as &$w) if (is_array($w) && ($w['title'] ?? '') === 'معاً نحو صحة أفضل' && ($w['icon'] ?? '') === 'heart-pulse') $w['icon'] = 'leaf';
        unset($w);
        $set->execute(['why', json_encode($why, JSON_UNESCAPED_UNICODE)]);
    }

    $order = ['hero', 'features', 'categories', 'featured', 'cta'];
    $secs = array_map(fn($k) => ['key' => $k, 'on' => 1], $order);
    foreach (array_keys(homeSectionLabels()) as $k) if (!in_array($k, $order, true)) $secs[] = ['key' => $k, 'on' => 0];
    $set->execute(['home_sections', json_encode($secs)]);

    $set->execute(['monta9a_design_1', '1']);
    $db->commit();
}

/** FATI KRITI — «من نحن» text. */
const FK_ABOUT_AR = "مرحباً بكم في متجر FATI KRITI، وجهتكم المفضلة لاكتشاف عالم الجمال والأناقة. 💗\n\nنحن علامة جزائرية متخصصة في بيع منتجات التجميل والعناية بالبشرة والشعر، المكياج والعناية بالجسم، نحرص دائماً على توفير منتجات مختارة بعناية وبأسعار تنافسية تناسب الجميع.\n\n✨ هدفنا هو رضاكم وثقتكم، ونسعى دائماً إلى تقديم تجربة تسوق سهلة ومريحة، مع خدمة توصيل إلى 58 ولاية والدفع عند الاستلام.\n\nشكراً لثقتكم بعلامتنا، وكونوا دائماً جزءاً من عائلة FATI KRITI، لأن جمالكم وثقتكم هما سر نجاحنا. 💕\n\nFATI KRITI – جمالك، ثقتك، وأناقتك. 🌷";
const FK_ABOUT_FR = "Bienvenue chez FATI KRITI, votre destination préférée pour découvrir l'univers de la beauté et de l'élégance. 💗\n\nNous sommes une marque algérienne spécialisée dans les produits de beauté : soins de la peau et des cheveux, maquillage et soins du corps. Nous veillons toujours à proposer des produits soigneusement sélectionnés, à des prix compétitifs accessibles à tous.\n\n✨ Votre satisfaction et votre confiance sont notre objectif : nous vous offrons une expérience d'achat simple et agréable, avec la livraison dans les 58 wilayas et le paiement à la livraison.\n\nMerci pour votre confiance. Faites toujours partie de la famille FATI KRITI, car votre beauté et votre confiance sont le secret de notre succès. 💕\n\nFATI KRITI – Votre beauté, votre confiance, votre élégance. 🌷";

/** «زيادة الوزن» sample products: [ar, fr, cat, price, old price, image, new?, desc ar, desc fr]. */
const FK_GAIN_PRODUCTS = [
    ['شراب فاتح للشهية', 'Sirop ouvre-appétit', 'gain', 1900, 0, 'p_gain_syrup', 0, "شراب طبيعي يساعد على فتح الشهية.\nالحجم: 200 مل. يُستعمل حسب الإرشادات المكتوبة على العلبة.", "Sirop naturel ouvre-appétit.\nContenance : 200 ml. À utiliser selon les indications."],
    ['بودرة زيادة الوزن 1 كغ', 'Poudre prise de poids 1 kg', 'gain', 3900, 4500, 'p_gain_powder', 0, "بودرة غنية بالسعرات والبروتين تُخلط مع الحليب أو العصير.\nالوزن: 1 كغ.", "Poudre riche en calories et en protéines, à mélanger au lait ou au jus.\nPoids : 1 kg."],
    ['كبسولات زيادة الوزن', 'Gélules prise de poids', 'gain', 2800, 0, 'p_gain_caps', 1, "مكمل غذائي — 60 كبسولة.\nيُستعمل حسب الإرشادات المكتوبة على العلبة.", "Complément alimentaire — 60 gélules.\nÀ utiliser selon les indications."],
];

/**
 * One-time update of an existing store to the FATI KRITI brand: name, top bar, «من نحن», banners
 * without the old name, and the new «زيادة الوزن» category (with 3 sample products).
 * Products, orders and the rest of the settings are kept.
 */
function applyFatiKriti(PDO $db): void {
    $db->beginTransaction();
    $set = $db->prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)');
    $set->execute(['store_name', 'FATI KRITI']);
    // Old name anywhere in the texts (top bar, footer...)
    foreach ($db->query("SELECT key, value FROM settings WHERE value LIKE '%Rosa Beauty%' OR value LIKE '%روزا بيوتي%'")->fetchAll() as $r) {
        $set->execute([$r['key'], str_replace(['Rosa Beauty', 'روزا بيوتي'], 'FATI KRITI', $r['value'])]);
    }
    $db->prepare("UPDATE pages SET content = ?, content_fr = ? WHERE slug = 'about'")->execute([FK_ABOUT_AR, FK_ABOUT_FR]);
    // Banners: the old pictures carried the old name -> new pictures (only where the original picture is still used)
    $d = 'assets/img/seed/beauty/';
    foreach (['banner_skin', 'banner_hair', 'banner_perfume', 'banner_makeup', 'banner_body', 'banner_slim'] as $b) {
        $db->prepare('UPDATE categories SET image = ? WHERE image = ?')->execute([$d . 'fk_' . $b . '.webp', $d . $b . '.webp']);
    }
    $db->prepare('UPDATE slides SET image = ? WHERE image = ?')->execute([$d . 'fk_hero.webp', $d . 'hero.webp']);
    // «زيادة الوزن»
    $cid = (int)$db->query("SELECT id FROM categories WHERE name = 'زيادة الوزن' LIMIT 1")->fetchColumn();
    if (!$cid) {
        $sort = (int)$db->query('SELECT COALESCE(MAX(sort), 0) + 1 FROM categories WHERE parent_id = 0')->fetchColumn();
        $db->prepare("INSERT INTO categories (parent_id, name, name_fr, image, icon, description, sort, show_home, show_menu, active) VALUES (0, 'زيادة الوزن', 'Prise de poids', ?, 'belly', '', ?, 1, 1, 1)")
           ->execute([$d . 'fk_banner_gain.webp', $sort]);
        $cid = (int)$db->lastInsertId();
        $st = $db->prepare("INSERT INTO products (name, name_fr, category_id, price, old_price, stock, images, colors, sizes, is_new, is_featured, badge, badge_fr, description, description_fr, brand, active, created_at) VALUES (?,?,?,?,?,25,?,'[]','[]',?,0,'','',?,?,'',1,datetime('now', ?))");
        foreach (FK_GAIN_PRODUCTS as $n => $p) {
            $st->execute([$p[0], $p[1], $cid, $p[3], $p[4], json_encode([$d . $p[5] . '.webp']), $p[6], $p[7], $p[8], '+' . $n . ' seconds']);
        }
    }
    $set->execute(['fk_brand_1', '1']);
    $db->commit();
}

/** FATI KRITI storefront v2 — sections and texts of the new homepage (runs once; everything stays editable in «المظهر»). */
function applyFatiLayout(PDO $db): void {
    $db->beginTransaction();
    $get = fn(string $k) => (string)$db->query('SELECT value FROM settings WHERE key = ' . $db->quote($k))->fetchColumn();
    $set = $db->prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)');
    $j = fn($v) => json_encode($v, JSON_UNESCAPED_UNICODE);
    $d = 'assets/img/seed/beauty/';

    $phone = preg_replace('/\D/', '', $get('phone'));
    $phoneTxt = strlen($phone) === 10 ? substr($phone, 0, 4) . ' ' . substr($phone, 4, 2) . ' ' . substr($phone, 6, 2) . ' ' . substr($phone, 8, 2) : $get('phone');
    $top = [];
    if ($phoneTxt !== '') $top[] = ['icon' => 'whatsapp', 'text' => $phoneTxt, 'text_fr' => ''];
    $top[] = ['icon' => 'headset', 'text' => 'خدمة ما بعد البيع', 'text_fr' => 'Service après-vente'];
    $top[] = ['icon' => 'shield', 'text' => 'منتجات أصلية 100%', 'text_fr' => 'Produits 100% originaux'];
    $top[] = ['icon' => 'truck', 'text' => 'توصيل سريع لكافة ولايات الوطن', 'text_fr' => 'Livraison rapide dans les 58 wilayas'];
    $set->execute(['topbar', $j($top)]);

    $set->execute(['features', $j([
        ['icon' => 'card',    'title' => 'الدفع عند الاستلام',  'text' => 'راحة وثقة',            'title_fr' => 'Paiement à la livraison', 'text_fr' => 'Simple et sûr'],
        ['icon' => 'shield',  'title' => 'منتجات أصلية 100%',   'text' => 'جودة مضمونة',          'title_fr' => 'Produits 100% originaux', 'text_fr' => 'Qualité garantie'],
        ['icon' => 'truck',   'title' => 'توصيل سريع وآمن',     'text' => 'لكافة ولايات الوطن',    'title_fr' => 'Livraison rapide', 'text_fr' => 'Dans les 58 wilayas'],
        ['icon' => 'headset', 'title' => 'خدمة ما بعد البيع',   'text' => 'دعم دائم بعد الشراء',   'title_fr' => 'Service après-vente', 'text_fr' => 'Toujours à votre écoute'],
    ])]);
    $set->execute(['features_title', '']);
    $set->execute(['why', $j([
        ['icon' => 'shield', 'title' => 'منتجات مختارة بعناية', 'text' => 'من أفضل الماركات', 'title_fr' => 'Produits sélectionnés', 'text_fr' => 'Des meilleures marques'],
        ['icon' => 'lotus',  'title' => 'معاً نحو جمال أفضل',  'text' => 'لأن جمالك يهمّنا',  'title_fr' => 'Ensemble pour votre beauté', 'text_fr' => 'Parce que votre beauté compte'],
    ])]);
    foreach (['cta_title' => 'تواصلي معنا على واتساب', 'cta_title_fr' => 'Écrivez-nous sur WhatsApp', 'cta_text' => 'للاستفسار أو طلب منتج', 'cta_text_fr' => 'Pour une question ou une commande',
              'featured_title' => 'منتجات مميزة', 'featured_title_fr' => 'Produits vedettes', 'featured_sub' => '', 'home_products_count' => '6', 'show_see_all' => '1'] as $k => $v) $set->execute([$k, $v]);
    if ($get('cta_image') === '') $set->execute(['cta_image', $d . 'fk_cta.webp']);

    $on = ['hero', 'features', 'categories', 'featured', 'cta', 'catrows'];
    $secs = array_map(fn($k) => ['key' => $k, 'on' => 1], $on);
    foreach (['newest', 'sale', 'promo', 'reviews', 'banners', 'brands', 'why'] as $k) $secs[] = ['key' => $k, 'on' => 0];
    $set->execute(['home_sections', $j($secs)]);

    // hero: the 3 new slides replace the default picture (pictures the owner uploaded are kept)
    $cat = fn(string $name) => (int)$db->query('SELECT id FROM categories WHERE name = ' . $db->quote($name) . ' LIMIT 1')->fetchColumn();
    if (!(int)$db->query("SELECT COUNT(*) FROM slides WHERE active = 1 AND image NOT LIKE 'assets/img/seed/%'")->fetchColumn()) {
        $db->exec("UPDATE slides SET active = 0 WHERE image LIKE 'assets/img/seed/%'");
        $ins = $db->prepare("INSERT INTO slides (subtitle, title, title2, text, btn_text, btn_link, image, sort, active) VALUES ('', '', '', '', '', ?, ?, ?, 1)");
        $perf = $cat('العطور الأصلية'); $gain = $cat('زيادة الوزن');
        $ins->execute(['shop.php', $d . 'fk_hero1.webp', 0]);
        $ins->execute([$perf ? 'shop.php?cat=' . $perf : 'shop.php', $d . 'fk_hero2.webp', 1]);
        $ins->execute([$gain ? 'shop.php?cat=' . $gain : 'shop.php', $d . 'fk_hero3.webp', 2]);
    }

    // «منتجات مميزة»: a few sample products, only while none is marked
    if (!(int)$db->query('SELECT COUNT(*) FROM products WHERE active = 1 AND is_featured = 1')->fetchColumn()) {
        foreach (['p_gain_powder', 'p_serum_c', 'p_perfume_rose', 'p_cream_hya', 'p_shampoo', 'p_lipstick'] as $img) {
            $db->prepare('UPDATE products SET is_featured = 1 WHERE active = 1 AND images LIKE ?')->execute(['%' . $img . '.webp%']);
        }
        foreach (['p_serum_c', 'p_perfume_rose'] as $img) {
            $db->prepare("UPDATE products SET badge = 'الأكثر مبيعاً', badge_fr = 'Best-seller' WHERE active = 1 AND badge = '' AND images LIKE ?")->execute(['%' . $img . '.webp%']);
        }
    }
    $set->execute(['fk_layout_2', '1']);
    $db->commit();
}

/**
 * MONTA9A — medical devices store (green). Runs once: the previous categories, products and slides are
 * HIDDEN (not deleted); orders, delivery prices, couriers, admin account, phone and PayPal/DHL keys are kept.
 */
function applyMedicalTheme(PDO $db): void {
    $db->beginTransaction();
    $set = $db->prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)');
    $get = fn(string $k) => (string)$db->query('SELECT value FROM settings WHERE key = ' . $db->quote($k))->fetchColumn();
    $j = fn($v) => json_encode($v, JSON_UNESCAPED_UNICODE);
    $d = 'assets/img/seed/med/';

    $db->exec('UPDATE categories SET active = 0, show_home = 0, show_menu = 0');
    $db->exec('UPDATE products SET active = 0, is_featured = 0');
    $db->exec('UPDATE slides SET active = 0');

    // key => [ar, fr, icon]
    $cats = [
        'bp'       => ['أجهزة قياس الضغط والنبض',       'Tensiomètres et glycémie',   'heart-pulse'],
        'breath'   => ['أجهزة التنفس الاصطناعي',         'Appareils respiratoires',     'lungs'],
        'massage'  => ['أجهزة الماساج والعلاج الطبيعي',  'Massage et physiothérapie',   'massage'],
        'mobility' => ['أجهزة ومستلزمات الراحة والحركة', 'Confort et mobilité',         'wheelchair'],
        'care'     => ['العناية الصحية والوقاية',        'Santé et prévention',         'medkit'],
        'acc'      => ['ملحقات طبية متنوعة',             'Accessoires médicaux',        'thermometer'],
    ];
    $cid = [];
    $st = $db->prepare("INSERT INTO categories (parent_id, name, name_fr, image, icon, description, sort, show_home, show_menu, active) VALUES (0, ?, ?, '', ?, '', ?, 1, 1, 1)");
    $i = 0;
    foreach ($cats as $k => $c) { $st->execute([$c[0], $c[1], $c[2], $i++]); $cid[$k] = (int)$db->lastInsertId(); }
    // the category bar shows 4 of them, like the design (all 6 stay on the homepage and in «جميع المنتجات»)
    $db->exec('UPDATE categories SET show_menu = 0 WHERE id IN (' . $cid['mobility'] . ',' . $cid['acc'] . ')');

    // [ar, fr, cat, price, old price, picture, new?, featured?, badge ar, badge fr, desc ar, desc fr] — the featured ones last (newest first)
    $products = [
        ['جهاز قياس الضغط للمعصم', 'Tensiomètre de poignet', 'bp', 5200, 0, 'wristbp', 0, 0, '', '', "جهاز صغير لقياس الضغط والنبض من المعصم، سهل الحمل في السفر.\nذاكرة لحفظ القياسات وشاشة واضحة.", "Tensiomètre compact au poignet, pratique en voyage.\nMémoire des mesures et grand écran."],
        ['جهاز قياس السكر في الدم', 'Glucomètre', 'bp', 3900, 0, 'gluco', 1, 0, '', '', "جهاز لقياس نسبة السكر في الدم بنتيجة خلال ثوانٍ.\nيُستعمل مع شرائط القياس الخاصة به.", "Lecteur de glycémie, résultat en quelques secondes.\nÀ utiliser avec ses bandelettes."],
        ['جهاز مساج الرقبة والكتفين', 'Masseur cou et épaules', 'massage', 7500, 8900, 'neck', 0, 0, '', '', "جهاز مساج بالتدفئة لتخفيف التعب في الرقبة والكتفين.\nيعمل بالشحن أو على السيارة.", "Masseur chauffant pour le cou et les épaules.\nSur secteur ou en voiture."],
        ['كرسي متحرك قابل للطي', 'Fauteuil roulant pliable', 'mobility', 24000, 0, 'wheel', 0, 0, '', '', "كرسي متحرك خفيف وقابل للطي، بعجلات كبيرة ومساند للقدمين.\nسهل الحمل في السيارة.", "Fauteuil roulant léger et pliable, grandes roues et repose-pieds."],
        ['عكاز طبي قابل للتعديل', 'Canne réglable', 'mobility', 2500, 0, 'cane', 0, 0, '', '', "عكاز من الألومنيوم بمقبض مريح وطول قابل للتعديل.", "Canne en aluminium, poignée confortable, hauteur réglable."],
        ['مشاية طبية للمسنين', 'Déambulateur', 'mobility', 8500, 0, 'walker', 0, 0, '', '', "مشاية ثابتة وخفيفة تساعد على المشي بأمان، قابلة للطي.", "Déambulateur léger et pliable pour marcher en sécurité."],
        ['حقيبة إسعافات أولية', 'Trousse de premiers secours', 'care', 3200, 0, 'aid', 0, 0, '', '', "حقيبة إسعافات كاملة للمنزل والسيارة: ضمادات، شاش، مقص، معقم...", "Trousse complète pour la maison et la voiture."],
        ['كمامات طبية (50 قطعة)', 'Masques médicaux (50 pièces)', 'care', 900, 1200, 'masks', 0, 0, '', '', "كمامات طبية بثلاث طبقات، علبة 50 قطعة.", "Masques médicaux 3 plis, boîte de 50."],
        ['معقم اليدين 500 مل', 'Gel hydroalcoolique 500 ml', 'care', 650, 0, 'sanit', 0, 0, '', '', "جل معقم لليدين بنسبة كحول 70%، بمضخة.", "Gel hydroalcoolique 70 %, flacon pompe."],
        ['ميزان حرارة بالأشعة تحت الحمراء', 'Thermomètre infrarouge', 'acc', 3500, 4200, 'irthermo', 1, 0, '', '', "قياس الحرارة من الجبهة بدون لمس خلال ثانية.\nمناسب للأطفال والكبار.", "Mesure frontale sans contact en une seconde."],
        ['ميزان حرارة رقمي', 'Thermomètre digital', 'acc', 900, 0, 'thermo', 0, 0, '', '', "ميزان حرارة رقمي دقيق مع إشارة صوتية عند انتهاء القياس.", "Thermomètre digital précis avec signal sonore."],
        ['سماعة طبية', 'Stéthoscope', 'acc', 4500, 0, 'steth', 0, 0, '', '', "سماعة طبية بجودة صوت عالية للاستعمال المنزلي والمهني.", "Stéthoscope à bonne qualité d'écoute."],
        ['كرسي مساج كهربائي', 'Siège de massage électrique', 'massage', 28500, 0, 'seat', 1, 1, '', '', "مقعد مساج للظهر والرقبة بالتدفئة، يوضع على أي كرسي.\nعدة أوضاع وسرعات.", "Siège de massage chauffant dos et nuque, se pose sur toute chaise."],
        ['جهاز التنفس الاصطناعي (نيبولايزر)', 'Nébuliseur', 'breath', 12900, 14500, 'nebu', 0, 1, 'الأكثر مبيعاً', 'Best-seller', "جهاز بخار لتوصيل الدواء إلى الرئتين، مع قناع للكبار وقناع للأطفال.\nهادئ وسهل التنظيف.", "Nébuliseur avec masques adulte et enfant, silencieux et facile à nettoyer."],
        ['جهاز قياس الضغط والنبض', 'Tensiomètre digital au bras', 'bp', 6800, 7900, 'bp', 0, 1, '', '', "جهاز رقمي لقياس الضغط والنبض من الذراع، بشاشة كبيرة وذاكرة للقياسات.", "Tensiomètre digital au bras, grand écran et mémoire."],
        ['جهاز قياس نسبة الأكسجين', 'Oxymètre de pouls', 'breath', 5500, 0, 'oxim', 1, 1, '', '', "يقيس نسبة الأكسجين في الدم ونبض القلب من الإصبع خلال ثوانٍ.", "Mesure la saturation en oxygène et le pouls au doigt."],
        ['مسدس المساج العضلي', 'Pistolet de massage', 'massage', 9900, 11500, 'gun', 0, 1, '', '', "مسدس مساج بعدة رؤوس وسرعات لإرخاء العضلات بعد التعب أو الرياضة.", "Pistolet de massage, plusieurs têtes et vitesses."],
        ['مولد الأكسجين المنزلي', "Concentrateur d'oxygène", 'breath', 45000, 0, 'oxy', 0, 1, 'الأفضل مبيعاً', 'Top vente', "مولد أكسجين للاستعمال المنزلي بتدفق قابل للتعديل، يعمل بهدوء.\nيُستعمل حسب وصفة الطبيب.", "Concentrateur d'oxygène à domicile, débit réglable, silencieux.\nSur prescription médicale."],
    ];
    $st = $db->prepare("INSERT INTO products (name, name_fr, category_id, price, old_price, stock, images, colors, sizes, is_new, is_featured, badge, badge_fr, description, description_fr, brand, active, created_at) VALUES (?,?,?,?,?,20,?,'[]','[]',?,?,?,?,?,?,'',1,datetime('now', ?))");
    foreach ($products as $n => $p) {
        $st->execute([$p[0], $p[1], $cid[$p[2]], $p[3], $p[4], json_encode([$d . $p[5] . '.webp']), $p[6], $p[7], $p[8], $p[9], $p[10], $p[11], '+' . $n . ' seconds']);
    }

    $ins = $db->prepare("INSERT INTO slides (subtitle, title, title2, text, btn_text, btn_link, image, sort, active) VALUES ('', '', '', '', '', ?, ?, ?, 1)");
    $ins->execute(['shop.php', $d . 'hero1.webp', 0]);
    $ins->execute(['shop.php?cat=' . $cid['massage'], $d . 'hero2.webp', 1]);
    $ins->execute(['shop.php?cat=' . $cid['breath'], $d . 'hero3.webp', 2]);

    $phone = preg_replace('/\D/', '', $get('phone'));
    $phoneTxt = strlen($phone) === 10 ? substr($phone, 0, 4) . ' ' . substr($phone, 4, 2) . ' ' . substr($phone, 6, 2) . ' ' . substr($phone, 8, 2) : $get('phone');
    $top = [];
    if ($phoneTxt !== '') $top[] = ['icon' => 'whatsapp', 'text' => $phoneTxt, 'text_fr' => ''];
    $top[] = ['icon' => 'headset', 'text' => 'خدمة ما بعد البيع', 'text_fr' => 'Service après-vente'];
    $top[] = ['icon' => 'shield', 'text' => 'منتجات أصلية 100%', 'text_fr' => 'Produits 100% originaux'];
    $top[] = ['icon' => 'truck', 'text' => 'توصيل سريع لكافة ولايات الوطن', 'text_fr' => 'Livraison rapide dans les 58 wilayas'];

    $settings = [
        'store_name' => 'MONTA9A', 'store_tagline' => 'منتقى · ننتقي لك أفضل المنتجات', 'logo_icon' => 'heart-pulse', 'logo_with_name' => '0',
        'color_primary' => '#12805c', 'color_dark' => '#0b5d43', 'color_soft' => '#eaf6ef',
        'topbar' => $j($top),
        'search_placeholder' => 'ابحث عن منتجك المفضل...', 'search_placeholder_fr' => 'Rechercher un produit...',
        'features' => $j([
            ['icon' => 'card',    'title' => 'الدفع عند الاستلام', 'text' => 'راحة وثقة',           'title_fr' => 'Paiement à la livraison', 'text_fr' => 'Simple et sûr'],
            ['icon' => 'shield',  'title' => 'منتجات أصلية 100%',  'text' => 'جودة مضمونة',         'title_fr' => 'Produits 100% originaux', 'text_fr' => 'Qualité garantie'],
            ['icon' => 'truck',   'title' => 'توصيل سريع وآمن',    'text' => 'لكافة ولايات الوطن',   'title_fr' => 'Livraison rapide', 'text_fr' => 'Dans les 58 wilayas'],
            ['icon' => 'headset', 'title' => 'خدمة ما بعد البيع',  'text' => 'دعم دائم بعد الشراء',  'title_fr' => 'Service après-vente', 'text_fr' => 'Toujours à votre écoute'],
        ]),
        'features_title' => '',
        'why' => $j([
            ['icon' => 'shield',      'title' => 'منتجات مختارة بعناية', 'text' => 'من أفضل الماركات العالمية', 'title_fr' => 'Produits sélectionnés', 'text_fr' => 'Des meilleures marques'],
            ['icon' => 'heart-pulse', 'title' => 'معاً نحو صحة أفضل',   'text' => 'لأن صحتك تهمّنا',          'title_fr' => 'Ensemble pour votre santé', 'text_fr' => 'Parce que votre santé compte'],
        ]),
        'cta_title' => 'تواصل معنا على واتساب', 'cta_title_fr' => 'Écrivez-nous sur WhatsApp',
        'cta_text' => 'للاستفسار أو طلب منتج', 'cta_text_fr' => 'Pour une question ou une commande',
        'cta_image' => $d . 'cta.webp',
        'featured_title' => 'منتجات مميزة', 'featured_title_fr' => 'Produits vedettes', 'featured_sub' => '',
        'cats_title' => 'الأقسام', 'cats_title_fr' => 'Catégories',
        'home_products_count' => '6', 'show_see_all' => '1',
        'home_sections' => $j([
            ['key' => 'hero', 'on' => 1], ['key' => 'features', 'on' => 1], ['key' => 'categories', 'on' => 1], ['key' => 'featured', 'on' => 1],
            ['key' => 'cta', 'on' => 1], ['key' => 'catrows', 'on' => 1], ['key' => 'newest', 'on' => 0], ['key' => 'sale', 'on' => 0],
            ['key' => 'promo', 'on' => 0], ['key' => 'reviews', 'on' => 0], ['key' => 'banners', 'on' => 0], ['key' => 'brands', 'on' => 0], ['key' => 'why', 'on' => 0],
        ]),
        'footer_about' => 'MONTA9A متجر جزائري للأجهزة والمستلزمات الطبية: قياس الضغط، التنفس، الماساج، الراحة والحركة والعناية الصحية، مع التوصيل إلى 58 ولاية والدفع عند الاستلام.',
        'footer_about_fr' => 'MONTA9A, boutique algérienne de matériel médical : tensiomètres, respiration, massage, mobilité et santé. Livraison dans les 58 wilayas, paiement à la livraison.',
        'footer_tagline' => 'صحة أفضل لحياة أفضل', 'footer_tagline_fr' => 'Une meilleure santé pour une meilleure vie',
        'theme_medical_1' => '1',
    ];
    foreach ($settings as $k => $v) $set->execute([$k, $v]);
    if ($get('dhl_desc') === 'Cosmetics') $set->execute(['dhl_desc', 'Medical devices']);

    $pages = [
        'about' => ['من نحن', 'Qui sommes-nous',
            "مرحباً بكم في MONTA9A — منتقى، وجهتكم لاقتناء الأجهزة والمستلزمات الطبية بثقة.\n\nنحن متجر جزائري نختار لكم بعناية أجهزة قياس الضغط والسكر، أجهزة التنفس والأكسجين، أجهزة الماساج والعلاج الطبيعي، مستلزمات الراحة والحركة، ومنتجات العناية الصحية والوقاية، بجودة مضمونة وأسعار مناسبة.\n\n✅ التوصيل إلى 58 ولاية، والدفع عند الاستلام، وخدمة ما بعد البيع لمرافقتكم بعد الشراء.\n\nMONTA9A — ننتقي لك أفضل المنتجات، لأن صحتك تهمّنا.",
            "Bienvenue chez MONTA9A, votre boutique de matériel médical en Algérie.\n\nNous sélectionnons pour vous tensiomètres, glucomètres, appareils respiratoires, appareils de massage, matériel de mobilité et produits de santé, de qualité et à prix justes.\n\n✅ Livraison dans les 58 wilayas, paiement à la livraison et service après-vente.\n\nMONTA9A — nous choisissons le meilleur pour votre santé."],
        'faq' => ['الأسئلة الشائعة', 'Questions fréquentes',
            "كيف أطلب؟\nاضغط «اطلب الآن» تحت المنتج أو في صفحته، واملأ معلومات التوصيل. سنتصل بك لتأكيد الطلب.\n\nمتى أدفع؟\nعند الاستلام فقط.\n\nكم مدة التوصيل؟\nمن 2 إلى 7 أيام حسب الولاية، إلى المنزل أو إلى مكتب شركة التوصيل.\n\nهل المنتجات تغني عن الطبيب؟\nلا. الأجهزة للمتابعة والاستعمال المنزلي، واستشر طبيبك دائماً في التشخيص والعلاج.",
            "Comment commander ?\nCliquez sur « Commander » et remplissez la livraison. Nous vous appelons pour confirmer.\n\nQuand dois-je payer ?\nÀ la livraison uniquement.\n\nDélai de livraison ?\nDe 2 à 7 jours selon la wilaya.\n\nCes appareils remplacent-ils le médecin ?\nNon : consultez toujours votre médecin pour le diagnostic et le traitement."],
        'returns' => ['سياسة الإرجاع', 'Politique de retour',
            "يمكنك إرجاع المنتج خلال 7 أيام من الاستلام إذا كان في علبته الأصلية ولم يُستعمل.\nإذا وصل المنتج معطلاً أو متضرراً، أبلغنا خلال 48 ساعة مع صور ونتكفّل بالاستبدال.\nلأسباب صحية، لا تُرجع المنتجات المفتوحة ذات الاستعمال الشخصي (الكمامات، الأقنعة، الشرائط...).",
            "Retour possible sous 7 jours si le produit est neuf dans son emballage d'origine.\nProduit défectueux ou abîmé : prévenez-nous sous 48 h avec photos, nous le remplaçons.\nPour des raisons d'hygiène, les articles d'usage personnel ouverts ne sont pas repris."],
        'privacy' => ['سياسة الخصوصية', 'Confidentialité',
            "نستعمل معلوماتك (الاسم، الهاتف، العنوان) فقط لتوصيل طلبك والتواصل معك، ولا نشاركها مع أي طرف آخر باستثناء شركة التوصيل.",
            "Vos informations servent uniquement à livrer votre commande et à vous contacter."],
        'terms' => ['الشروط والأحكام', "Conditions d'utilisation",
            "باستخدامك لهذا الموقع فإنك توافق على تقديم معلومات صحيحة عند الطلب. الأسعار قابلة للتغيير، والطلب يُعتمد بعد تأكيده هاتفياً. المعلومات المعروضة عن المنتجات لا تعوّض استشارة الطبيب.",
            "En utilisant ce site, vous acceptez de fournir des informations exactes. La commande est validée après confirmation téléphonique. Les informations produits ne remplacent pas l'avis d'un médecin."],
    ];
    $up = $db->prepare('UPDATE pages SET title = ?, title_fr = ?, content = ?, content_fr = ? WHERE slug = ?');
    $insP = $db->prepare('INSERT OR IGNORE INTO pages (slug, title, title_fr, content, content_fr, sort) VALUES (?,?,?,?,?,?)');
    $n = 0;
    foreach ($pages as $slug => $p) { $insP->execute([$slug, $p[0], $p[1], $p[2], $p[3], $n++]); $up->execute([$p[0], $p[1], $p[2], $p[3], $slug]); }
    $db->commit();
}

/** Schema version — bump this whenever createSchema() changes. */
const SCHEMA_VERSION = '13';

/** True when the tables have not yet been created for this code version. */
function schemaNeedsUpgrade(PDO $db): bool {
    try {
        $v = $db->query("SELECT value FROM settings WHERE key = 'schema_version'")->fetchColumn();
    } catch (Throwable $e) {
        return true; // settings table does not exist yet
    }
    return (string)$v !== SCHEMA_VERSION;
}

/** Record that the schema matches this code version. */
function markSchemaCurrent(PDO $db): void {
    $db->prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)')
       ->execute(['schema_version', SCHEMA_VERSION]);
}

function createSchema(PDO $db): void {
    $db->exec("
    CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT);
    CREATE TABLE IF NOT EXISTS admins (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        password TEXT NOT NULL
    );
    CREATE TABLE IF NOT EXISTS login_attempts (
        ip TEXT PRIMARY KEY,
        fails INTEGER DEFAULT 0,
        locked_until INTEGER DEFAULT 0,
        updated_at INTEGER DEFAULT 0
    );
    CREATE TABLE IF NOT EXISTS categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        parent_id INTEGER DEFAULT 0,
        name TEXT NOT NULL,
        image TEXT DEFAULT '',
        icon TEXT DEFAULT '',
        description TEXT DEFAULT '',
        sort INTEGER DEFAULT 0,
        show_home INTEGER DEFAULT 1,
        show_menu INTEGER DEFAULT 1,
        active INTEGER DEFAULT 1
    );
    CREATE TABLE IF NOT EXISTS products (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        category_id INTEGER DEFAULT 0,
        sku TEXT DEFAULT '',
        price INTEGER NOT NULL DEFAULT 0,
        old_price INTEGER DEFAULT 0,
        stock INTEGER NOT NULL DEFAULT 0,
        description TEXT,
        images TEXT DEFAULT '[]',
        colors TEXT DEFAULT '[]',
        is_new INTEGER DEFAULT 0,
        is_featured INTEGER DEFAULT 0,
        active INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE IF NOT EXISTS reviews (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        name TEXT NOT NULL,
        rating INTEGER NOT NULL DEFAULT 5,
        comment TEXT,
        approved INTEGER DEFAULT 0,
        ip TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE IF NOT EXISTS slides (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        subtitle TEXT, title TEXT, title2 TEXT DEFAULT '', text TEXT,
        btn_text TEXT, btn_link TEXT,
        image TEXT,
        sort INTEGER DEFAULT 0,
        active INTEGER DEFAULT 1
    );
    CREATE TABLE IF NOT EXISTS pages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        slug TEXT UNIQUE NOT NULL,
        title TEXT NOT NULL,
        content TEXT,
        in_footer INTEGER DEFAULT 1,
        sort INTEGER DEFAULT 0
    );
    CREATE TABLE IF NOT EXISTS subscribers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT UNIQUE NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE IF NOT EXISTS wilayas (
        id INTEGER PRIMARY KEY,
        name_ar TEXT, name_fr TEXT,
        home_price INTEGER DEFAULT 0,
        office_price INTEGER DEFAULT 0,
        active INTEGER DEFAULT 1
    );
    CREATE TABLE IF NOT EXISTS orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        customer_name TEXT NOT NULL,
        phone TEXT NOT NULL,
        wilaya_id INTEGER,
        wilaya_name TEXT,
        commune TEXT,
        commune_fr TEXT DEFAULT '',
        desk_commune TEXT DEFAULT '',
        address TEXT,
        delivery_type TEXT DEFAULT 'home',
        delivery_price INTEGER DEFAULT 0,
        subtotal INTEGER DEFAULT 0,
        gift_wrap INTEGER DEFAULT 0,
        gift_price INTEGER DEFAULT 0,
        gift_message TEXT DEFAULT '',
        total INTEGER DEFAULT 0,
        notes TEXT,
        status TEXT DEFAULT 'new',
        tracking TEXT DEFAULT '',
        shipped_at DATETIME,
        ip TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE IF NOT EXISTS order_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL,
        product_id INTEGER,
        product_name TEXT,
        color TEXT,
        qty INTEGER DEFAULT 1,
        price INTEGER DEFAULT 0,
        FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
    );
    CREATE TABLE IF NOT EXISTS messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT, phone TEXT, email TEXT, message TEXT,
        is_read INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    ");
}

/**
 * Add columns that were introduced after the first release.
 *
 * CREATE TABLE IF NOT EXISTS leaves an existing table exactly as it was, so a
 * store that was installed with an older version would keep the old columns
 * and every query naming a new one would fail. This adds the missing ones.
 */
function migrateSchema(PDO $db): void {
    $added = [
        'categories' => ['icon' => "TEXT DEFAULT ''", 'name_fr' => "TEXT DEFAULT ''", 'description_fr' => "TEXT DEFAULT ''"],
        'products'   => ['name_fr' => "TEXT DEFAULT ''", 'description_fr' => "TEXT DEFAULT ''", 'brand' => "TEXT DEFAULT ''", 'badge' => "TEXT DEFAULT ''", 'badge_fr' => "TEXT DEFAULT ''", 'sizes' => "TEXT DEFAULT '[]'"],
        'slides'     => ['subtitle_fr' => "TEXT DEFAULT ''", 'title_fr' => "TEXT DEFAULT ''", 'title2_fr' => "TEXT DEFAULT ''", 'text_fr' => "TEXT DEFAULT ''", 'btn_text_fr' => "TEXT DEFAULT ''"],
        'pages'      => ['title_fr' => "TEXT DEFAULT ''", 'content_fr' => "TEXT DEFAULT ''"],
        'orders'     => ['commune_fr' => "TEXT DEFAULT ''", 'tracking' => "TEXT DEFAULT ''", 'shipped_at' => 'DATETIME', 'desk_commune' => "TEXT DEFAULT ''", 'courier' => "TEXT DEFAULT ''", 'courier_label' => "TEXT DEFAULT ''",
                         'email' => "TEXT DEFAULT ''", 'country' => "TEXT DEFAULT ''", 'payment_method' => "TEXT DEFAULT 'cod'", 'payment_status' => "TEXT DEFAULT ''",
                         'payment_ref' => "TEXT DEFAULT ''", 'pay_currency' => "TEXT DEFAULT ''", 'pay_amount' => "TEXT DEFAULT ''", 'country_code' => "TEXT DEFAULT ''"],
    ];
    foreach ($added as $table => $columns) {
        try {
            $have = array_column($db->query('PRAGMA table_info(' . $table . ')')->fetchAll(), 'name');
        } catch (Throwable $e) {
            continue;
        }
        foreach ($columns as $name => $def) {
            if (!in_array($name, $have, true)) {
                try { $db->exec("ALTER TABLE $table ADD COLUMN $name $def"); } catch (Throwable $e) {}
            }
        }
    }
}

/** Default admin password for a fresh install (must be changed after first login). */
const DEFAULT_ADMIN_PASSWORD = 'admin111';

/** Homepage sections: key => label (order & visibility are editable in the admin). */
function homeSectionLabels(): array {
    return [
        'hero'       => 'السلايدر الرئيسي',
        'categories' => 'مربعات الأقسام (بالأيقونات)',
        'catrows'    => 'كل قسم: البانر + منتجاته',
        'featured'   => 'الأكثر مبيعاً (المنتجات المميزة)',
        'promo'      => 'شريط العبارة / العرض الخاص (خلفية داكنة)',
        'banners'    => 'البنرات الإعلانية (3 بطاقات ملونة)',
        'brands'     => 'شريط العلامات التجارية',
        'cta'        => 'شريط واتساب + ميزتان (أسفل الصفحة)',
        'newest'     => 'أحدث المنتجات',
        'sale'       => 'العروض الخاصة',
        'features'   => 'شريط المزايا (تحت السلايدر)',
        'why'        => 'لماذا تختارنا (خلفية داكنة)',
        'reviews'    => 'آراء عملائنا',
    ];
}

function wilayaList(): array {
    return [
        [1,'أدرار','Adrar'],[2,'الشلف','Chlef'],[3,'الأغواط','Laghouat'],[4,'أم البواقي','Oum El Bouaghi'],
        [5,'باتنة','Batna'],[6,'بجاية','Béjaïa'],[7,'بسكرة','Biskra'],[8,'بشار','Béchar'],
        [9,'البليدة','Blida'],[10,'البويرة','Bouira'],[11,'تمنراست','Tamanrasset'],[12,'تبسة','Tébessa'],
        [13,'تلمسان','Tlemcen'],[14,'تيارت','Tiaret'],[15,'تيزي وزو','Tizi Ouzou'],[16,'الجزائر','Alger'],
        [17,'الجلفة','Djelfa'],[18,'جيجل','Jijel'],[19,'سطيف','Sétif'],[20,'سعيدة','Saïda'],
        [21,'سكيكدة','Skikda'],[22,'سيدي بلعباس','Sidi Bel Abbès'],[23,'عنابة','Annaba'],[24,'قالمة','Guelma'],
        [25,'قسنطينة','Constantine'],[26,'المدية','Médéa'],[27,'مستغانم','Mostaganem'],[28,'المسيلة','M\'Sila'],
        [29,'معسكر','Mascara'],[30,'ورقلة','Ouargla'],[31,'وهران','Oran'],[32,'البيض','El Bayadh'],
        [33,'إليزي','Illizi'],[34,'برج بوعريريج','Bordj Bou Arréridj'],[35,'بومرداس','Boumerdès'],[36,'الطارف','El Tarf'],
        [37,'تندوف','Tindouf'],[38,'تيسمسيلت','Tissemsilt'],[39,'الوادي','El Oued'],[40,'خنشلة','Khenchela'],
        [41,'سوق أهراس','Souk Ahras'],[42,'تيبازة','Tipaza'],[43,'ميلة','Mila'],[44,'عين الدفلى','Aïn Defla'],
        [45,'النعامة','Naâma'],[46,'عين تموشنت','Aïn Témouchent'],[47,'غرداية','Ghardaïa'],[48,'غليزان','Relizane'],
        [49,'تيميمون','Timimoun'],[50,'برج باجي مختار','Bordj Badji Mokhtar'],[51,'أولاد جلال','Ouled Djellal'],[52,'بني عباس','Béni Abbès'],
        [53,'عين صالح','In Salah'],[54,'عين قزام','In Guezzam'],[55,'تقرت','Touggourt'],[56,'جانت','Djanet'],
        [57,'المغير','El M\'Ghair'],[58,'المنيعة','El Meniaa'],
    ];
}

function seedDatabase(PDO $db): void {
    $db->beginTransaction();
    $settings = [
        'owner_name' => '', 'phone' => '0550 12 34 56', 'whatsapp' => '', 'address' => 'الجزائر، التوصيل لجميع الولايات',
        'facebook' => '', 'instagram' => '', 'tiktok' => '', 'youtube' => '', 'pinterest' => '', 'fb_pixel' => '',
        'free_shipping_min' => '0', 'return_days' => '7', 'gift_msg_on' => '0', 'gift_wrap_price' => '0',
        'reviews_on' => '1', 'reviews_moderate' => '1',
    ];
    $st = $db->prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)');
    foreach ($settings as $k => $v) $st->execute([$k, $v]);

    $db->prepare('INSERT INTO admins (username, password) VALUES (?, ?)')
       ->execute(['admin', password_hash(DEFAULT_ADMIN_PASSWORD, PASSWORD_DEFAULT)]);

    $st = $db->prepare('INSERT INTO wilayas (id, name_ar, name_fr, home_price, office_price) VALUES (?, ?, ?, ?, ?)');
    foreach (wilayaList() as $w) {
        [$id] = $w;
        $near = in_array($id, [9, 16, 35, 42, 10, 15, 26, 44]);
        $south = in_array($id, [1, 8, 11, 33, 37, 49, 50, 52, 53, 54, 56, 58]);
        $st->execute([$w[0], $w[1], $w[2], $near ? 800 : ($south ? 1800 : 1200), $near ? 500 : ($south ? 1200 : 800)]);
    }
    $db->commit();
    applyBeautyTheme($db, true);
}




/**
 * Cosmetics store — FATI KRITI.
 * Classic shop layout: top bar, search with category, icon tiles, then for every category its wide banner
 * and its products. Runs once; on an existing store the previous catalogue is HIDDEN (not deleted) and
 * orders, delivery prices, admin account, phone and courier settings are kept.
 */
function applyBeautyTheme(PDO $db, bool $fresh): void {
    migrateSchema($db);
    $db->beginTransaction();
    $img = fn(string $f) => 'assets/img/seed/beauty/' . (str_starts_with($f, 'banner_') || $f === 'hero' ? 'fk_' : '') . $f . '.webp';
    $set = $db->prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)');
    $j = fn($v) => json_encode($v, JSON_UNESCAPED_UNICODE);

    if (!$fresh) {
        $db->exec('UPDATE categories SET active = 0, show_home = 0, show_menu = 0');
        $db->exec('UPDATE products SET active = 0');
        $db->exec('UPDATE slides SET active = 0');
    }

    // key => [ar, fr, icon, banner]
    $cats = [
        'skin'    => ['العناية بالبشرة',   'Soins du visage',    'face',     'banner_skin'],
        'hair'    => ['العناية بالشعر',    'Soins des cheveux',  'hair',     'banner_hair'],
        'perfume' => ['العطور الأصلية',    'Parfums originaux',  'bottle',   'banner_perfume'],
        'makeup'  => ['مستحضرات التجميل',  'Maquillage',         'lipstick', 'banner_makeup'],
        'body'    => ['العناية بالجسم',    'Soins du corps',     'body',     'banner_body'],
        'slim'    => ['الرشاقة والتنحيف',  'Minceur',            'leaf',     'banner_slim'],
        'gain'    => ['زيادة الوزن',       'Prise de poids',     'belly',    'banner_gain'],
    ];
    $cid = [];
    $st = $db->prepare("INSERT INTO categories (parent_id, name, name_fr, image, icon, description, sort, show_home, show_menu, active) VALUES (0,?,?,?,?,'',?,1,1,1)");
    $i = 0;
    foreach ($cats as $k => $c) { $st->execute([$c[0], $c[1], $img($c[3]), $c[2], $i++]); $cid[$k] = (int)$db->lastInsertId(); }

    // [ar, fr, cat, price, old price, image, new?, desc ar, desc fr] — inserted oldest first (rows show the newest first)
    $products = [
        ['غسول الوجه اللطيف', 'Nettoyant doux visage', 'skin', 1600, 0, 'p_cleanser', 0, "غسول لطيف ينظف البشرة ويزيل الشوائب دون أن يجففها.\nمناسب للاستعمال اليومي صباحاً ومساءً.", "Nettoyant doux qui purifie la peau sans la dessécher.\nPour un usage quotidien, matin et soir."],
        ['كريم الترطيب بحمض الهيالورونيك', 'Crème hydratante à l\'acide hyaluronique', 'skin', 2400, 2900, 'p_cream_hya', 0, "كريم خفيف يمنح البشرة ترطيباً عميقاً طوال اليوم.\nالحجم: 50 مل.", "Crème légère pour une hydratation intense toute la journée.\nContenance : 50 ml."],
        ['سيروم فيتامين C', 'Sérum vitamine C', 'skin', 2900, 3500, 'p_serum_c', 1, "سيروم لنضارة البشرة وتوحيد لونها.\nبضع قطرات صباحاً قبل الكريم المرطب.\nالحجم: 30 مل.", "Sérum éclat qui unifie le teint.\nQuelques gouttes le matin avant la crème.\nContenance : 30 ml."],
        ['زيت الشعر بالأعشاب', 'Huile capillaire aux plantes', 'hair', 1500, 0, 'p_hairoil', 0, "زيت مغذٍّ لفروة الرأس والشعر الجاف والمتقصف.", "Huile nourrissante pour le cuir chevelu et les cheveux secs."],
        ['ماسك الشعر بالكيراتين', 'Masque capillaire à la kératine', 'hair', 2200, 2600, 'p_hairmask', 0, "ماسك لإصلاح الشعر التالف ومنحه النعومة واللمعان.", "Masque réparateur pour des cheveux doux et brillants."],
        ['شامبو بزيت الأرغان', 'Shampooing à l\'huile d\'argan', 'hair', 1800, 0, 'p_shampoo', 1, "شامبو لطيف بزيت الأرغان لشعر ناعم وصحي.\nالحجم: 400 مل.", "Shampooing doux à l'huile d'argan.\nContenance : 400 ml."],
        ['معطر الجسم بالورد', 'Brume corporelle à la rose', 'perfume', 1900, 0, 'p_mist', 0, "رذاذ منعش برائحة الورد للجسم والشعر.", "Brume fraîche parfum rose pour le corps et les cheveux."],
        ['عطر الفانيلا 30 مل', 'Parfum vanille 30 ml', 'perfume', 3200, 0, 'p_perfume_van', 0, "عطر دافئ بنفحات الفانيلا، ثبات طويل.", "Parfum chaleureux aux notes de vanille, longue tenue."],
        ['عطر الورد النسائي 50 مل', 'Parfum rose femme 50 ml', 'perfume', 4500, 5200, 'p_perfume_rose', 1, "عطر زهري أنثوي بنفحات الورد، ثبات طويل طوال اليوم.", "Parfum floral et féminin aux notes de rose, longue tenue."],
        ['ماسكارا لتكثيف الرموش', 'Mascara volume', 'makeup', 1400, 0, 'p_mascara', 0, "ماسكارا تمنح الرموش كثافة وطولاً دون تكتل.", "Mascara volume et longueur sans paquets."],
        ['كريم أساس سائل', 'Fond de teint fluide', 'makeup', 2600, 3000, 'p_foundation', 0, "تغطية متوسطة ولمسة طبيعية تدوم طويلاً.", "Couvrance moyenne et fini naturel longue tenue."],
        ['أحمر شفاه مات', 'Rouge à lèvres mat', 'makeup', 1200, 0, 'p_lipstick', 1, "أحمر شفاه بلمسة مات وثبات طويل.", "Rouge à lèvres mat longue tenue."],
        ['زيت الجسم المغذي', 'Huile corps nourrissante', 'body', 2500, 0, 'p_bodyoil', 0, "زيت جاف يغذي البشرة ويمنحها لمعاناً جميلاً.", "Huile sèche qui nourrit la peau et lui donne de l'éclat."],
        ['مقشر الجسم بالقهوة', 'Gommage corps au café', 'body', 1700, 2000, 'p_scrub', 0, "مقشر طبيعي يزيل الخلايا الميتة ويترك البشرة ناعمة.", "Gommage naturel qui élimine les peaux mortes."],
        ['لوشن الجسم بزبدة الشيا', 'Lait corps au beurre de karité', 'body', 2100, 0, 'p_lotion', 1, "لوشن مرطب بزبدة الشيا لبشرة ناعمة طوال اليوم.", "Lait hydratant au beurre de karité."],
        ['كريم شد الجسم', 'Crème raffermissante', 'slim', 2300, 2800, 'p_firming', 0, "كريم للتدليك يساعد على نعومة البشرة ومرونتها.", "Crème de massage pour une peau plus douce et tonique."],
        ['كبسولات الكولاجن', 'Collagène en gélules', 'slim', 3500, 0, 'p_collagen', 0, "مكمل غذائي بالكولاجن — 60 كبسولة.\nيُستعمل حسب الإرشادات المكتوبة على العلبة.", "Complément au collagène — 60 gélules.\nÀ utiliser selon les indications."],
        ['شاي الأعشاب للرشاقة', 'Infusion minceur aux plantes', 'slim', 1800, 0, 'p_tea', 1, "خلطة أعشاب طبيعية تُشرب ضمن نظام غذائي متوازن.", "Mélange de plantes à boire dans le cadre d'une alimentation équilibrée."],
        ...FK_GAIN_PRODUCTS,
    ];
    $st = $db->prepare("INSERT INTO products (name, name_fr, category_id, price, old_price, stock, images, colors, sizes, is_new, is_featured, badge, badge_fr, description, description_fr, brand, active, created_at) VALUES (?,?,?,?,?,25,?,'[]','[]',?,0,'','',?,?,'',1,datetime('now', ?))");
    foreach ($products as $n => $p) {
        $st->execute([$p[0], $p[1], $cid[$p[2]], $p[3], $p[4], json_encode([$img($p[5])]), $p[6], $p[7], $p[8], '+' . $n . ' seconds']);
    }

    $db->prepare("INSERT INTO slides (subtitle, title, title2, text, btn_text, btn_link, image, sort, active) VALUES ('', '', '', '', '', 'shop.php', ?, 0, 1)")->execute([$img('hero')]);

    $settings = [
        'store_name'         => 'FATI KRITI', 'store_tagline' => 'BEAUTY · CARE',
        'logo'               => '', 'logo_footer' => '', 'logo_icon' => 'lotus', 'logo_with_name' => '0',
        'default_lang'       => 'ar', 'offer_only' => '0',
        'color_primary'      => '#d1678b', 'color_dark' => '#b24f74', 'color_soft' => '#fbeef3',
        'topbar'             => $j([['icon' => 'sparkle', 'text' => 'مرحباً بكم في متجر FATI KRITI!', 'text_fr' => 'Bienvenue chez FATI KRITI !']]),
        'search_placeholder' => 'بحث عن المنتجات', 'search_placeholder_fr' => 'Rechercher des produits',
        'home_sections'      => json_encode([
            ['key' => 'hero', 'on' => 1], ['key' => 'categories', 'on' => 1], ['key' => 'catrows', 'on' => 1],
            ['key' => 'features', 'on' => 0], ['key' => 'featured', 'on' => 0], ['key' => 'newest', 'on' => 0], ['key' => 'sale', 'on' => 0],
            ['key' => 'promo', 'on' => 0], ['key' => 'reviews', 'on' => 0], ['key' => 'banners', 'on' => 0], ['key' => 'brands', 'on' => 0],
            ['key' => 'cta', 'on' => 0], ['key' => 'why', 'on' => 0],
        ]),
        'cats_title'         => 'الأقسام', 'cats_title_fr' => 'Catégories',
        'sale_title'         => 'التخفيضات', 'sale_title_fr' => 'Promotions',
        'show_see_all'       => '1', 'home_products_count' => '6', 'float_cta_on' => '0', 'newsletter_on' => '0',
        'brands' => '[]', 'banners' => '[]', 'hero_pills' => '[]', 'promo_on' => '0', 'features' => '[]', 'why' => '[]',
        'footer_about'       => 'اطلبي الآن جميع احتياجاتك من منتجات التجميل والعناية بسهولة، مع توصيل سريع إلى باب المنزل والدفع عند الاستلام.',
        'footer_about_fr'    => 'Commandez tous vos produits de beauté et de soin, livrés chez vous avec paiement à la livraison.',
        'address_fr'         => 'Algérie — livraison dans les 58 wilayas',
        'footer_tagline'     => 'جمالك يستحق الأفضل', 'footer_tagline_fr' => 'Votre beauté mérite le meilleur',
        'return_days'        => '7',
        'theme_beauty'       => '1', 'fk_brand_1' => '1',
    ];
    foreach ($settings as $k => $v) $set->execute([$k, $v]);
    if (trim((string)$db->query("SELECT value FROM settings WHERE key = 'address'")->fetchColumn()) === '') {
        $set->execute(['address', 'الجزائر، التوصيل لجميع الولايات']);
    }
    foreach (['landing_p1', 'landing_p2', 'landing_bundle'] as $k) $db->exec("DELETE FROM settings WHERE key = '$k'");

    $pages = [
        ['about', 'من نحن', 'Qui sommes-nous', FK_ABOUT_AR, FK_ABOUT_FR],
        ['faq', 'الأسئلة الشائعة', 'Questions fréquentes',
         "كيف أطلب؟\nاضغطي «شراء الآن» في صفحة المنتج أو «اطلب الآن» تحت المنتج، واملئي معلومات التوصيل. سنتصل بك لتأكيد الطلب.\n\nمتى أدفع؟\nعند الاستلام فقط.\n\nكم مدة التوصيل؟\nمن 2 إلى 7 أيام حسب الولاية، إلى المنزل أو إلى مكتب شركة التوصيل.",
         "Comment commander ?\nCliquez sur « Acheter » ou « Commander » et remplissez la livraison. Nous vous appelons pour confirmer.\n\nQuand dois-je payer ?\nÀ la livraison uniquement.\n\nQuel est le délai de livraison ?\nDe 2 à 7 jours selon la wilaya."],
        ['returns', 'سياسة الإرجاع', 'Politique de retour',
         "يمكنك إرجاع المنتج خلال 7 أيام من الاستلام إذا كان مغلقاً وفي حالته الأصلية.\nإذا وصل المنتج متضرراً، أبلغينا خلال 48 ساعة مع صور ونتكفّل بالاستبدال.",
         "Retour possible sous 7 jours si le produit est scellé et dans son état d'origine.\nProduit abîmé : prévenez-nous sous 48 h avec photos, nous le remplaçons."],
        ['privacy', 'سياسة الخصوصية', 'Confidentialité',
         "نستعمل معلوماتك (الاسم، الهاتف، العنوان) فقط لتوصيل طلبك والتواصل معك، ولا نشاركها مع أي طرف آخر باستثناء شركة التوصيل.",
         "Vos informations servent uniquement à livrer votre commande et à vous contacter."],
        ['terms', 'الشروط والأحكام', "Conditions d'utilisation",
         "باستخدامك لهذا الموقع فإنك توافقين على تقديم معلومات صحيحة عند الطلب. الأسعار قابلة للتغيير، والطلب يُعتمد بعد تأكيده هاتفياً.",
         "En utilisant ce site, vous acceptez de fournir des informations exactes. La commande est validée après confirmation téléphonique."],
    ];
    $up = $db->prepare('UPDATE pages SET title = ?, title_fr = ?, content = ?, content_fr = ? WHERE slug = ?');
    $ins = $db->prepare('INSERT OR IGNORE INTO pages (slug, title, title_fr, content, content_fr, sort) VALUES (?,?,?,?,?,?)');
    foreach ($pages as $i => $p) { $ins->execute([$p[0], $p[1], $p[2], $p[3], $p[4], $i]); $up->execute([$p[1], $p[2], $p[3], $p[4], $p[0]]); }

    $db->commit();
}
