<?php
/**
 * Storefront languages: Arabic (default, right-to-left) and French (left-to-right).
 *
 * The visitor switches with ?lang=ar / ?lang=fr (the language switch in the top bar);
 * the choice is kept in a cookie. The admin panel always stays in Arabic.
 *
 *   tx('النص العربي', 'Texte français')  — a fixed text of the page
 *   tr($row, 'name')                      — a database field: uses name_fr in French when it is filled
 *   st('cats_title')                      — a setting: uses cats_title_fr in French when it is filled
 */

const LANGS = ['ar' => 'العربية', 'fr' => 'Français'];

function lang(): string {
    static $lang = null;
    if ($lang !== null) return $lang;
    // The dashboard is Arabic only.
    if (str_contains(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '')), '/admin/')) return $lang = 'ar';
    $want = (string)($_GET['lang'] ?? '');
    if (isset(LANGS[$want])) {
        if (!headers_sent()) {
            setcookie('lang', $want, ['expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax']);
        }
        return $lang = $want;
    }
    $c = (string)($_COOKIE['lang'] ?? '');
    if (isset(LANGS[$c])) return $lang = $c;
    $d = function_exists('setting') ? setting('default_lang', 'ar') : 'ar';
    return $lang = isset(LANGS[$d]) ? $d : 'ar';
}

function isFr(): bool {
    return lang() === 'fr';
}

function dir_(): string {
    return isFr() ? 'ltr' : 'rtl';
}

/** Fixed text in the visitor's language. */
function tx(string $ar, string $fr): string {
    return isFr() ? $fr : $ar;
}

/** A field of a database row (product, category, slide, page...) in the visitor's language. */
function tr(?array $row, string $field): string {
    if (!$row) return '';
    if (isFr()) {
        $fr = trim((string)($row[$field . '_fr'] ?? ''));
        if ($fr !== '') return $fr;
    }
    return (string)($row[$field] ?? '');
}

/** A setting in the visitor's language (falls back to the Arabic value). */
function st(string $key, string $default = ''): string {
    if (isFr()) {
        $fr = setting($key . '_fr');
        if ($fr !== '') return $fr;
    }
    return setting($key, $default);
}

/** Same page in another language. */
function langUrl(string $l): string {
    $q = $_GET;
    $q['lang'] = $l;
    $path = basename(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: 'index.php');
    if ($path === '' || !preg_match('/^[a-z0-9\-]+\.php$/i', $path)) $path = 'index.php';
    return $path . '?' . http_build_query($q);
}

/** Texts used by assets/js/main.js, printed once in the page. */
function jsTexts(): array {
    return [
        'cur'          => tx(' د.ج', ' DA'),
        'free'         => tx('مجاني', 'Gratuit'),
        'pickWilaya'   => tx('اختر الولاية', 'Choisissez la wilaya'),
        'pickWilayaF'  => tx('اختر الولاية أولاً', "Choisissez d'abord la wilaya"),
        'loading'      => tx('جاري التحميل...', 'Chargement...'),
        'pickCommune'  => tx('اختر البلدية', 'Choisissez la commune'),
        'communesErr'  => tx('تعذر تحميل البلديات، أعد تحميل الصفحة', 'Impossible de charger les communes, rechargez la page'),
        'pickCourier'  => tx('اختر شركة التوصيل', 'Choisissez la société de livraison'),
        'pickDesk'     => tx('اختر المكتب', 'Choisissez le bureau'),
        'noDesk'       => tx('لا يوجد مكتب استلام في هذه الولاية، التوصيل إلى المنزل فقط', 'Aucun point relais dans cette wilaya, livraison à domicile uniquement'),
        'deskHere'     => tx('ستستلم طلبك من هذا المكتب', 'Vous récupérerez votre commande à ce bureau'),
        'desksN'       => tx('%n مكاتب في هذه الولاية — اختر المكتب الأقرب إليك.', '%n bureaux dans cette wilaya — choisissez le plus proche.'),
        'desks1'       => tx('مكتب واحد في هذه الولاية.', 'Un seul bureau dans cette wilaya.'),
        'errName'      => tx('يرجى إدخال الاسم الكامل', 'Veuillez saisir votre nom complet'),
        'errPhone'     => tx('رقم الهاتف غير صحيح (10 أرقام يبدأ بـ 05 أو 06 أو 07)', 'Numéro invalide (10 chiffres commençant par 05, 06 ou 07)'),
        'errWilaya'    => tx('يرجى اختيار الولاية', 'Veuillez choisir la wilaya'),
        'errCommune'   => tx('يرجى اختيار البلدية', 'Veuillez choisir la commune'),
        'errAddress'   => tx('يرجى إدخال العنوان', "Veuillez saisir l'adresse"),
        'errDesk'      => tx('يرجى اختيار مكتب الاستلام', 'Veuillez choisir le point relais'),
        'errColor'     => tx('يرجى اختيار اللون', 'Veuillez choisir la couleur'),
        'errColorFirst'=> tx('يرجى اختيار اللون أولاً', "Veuillez d'abord choisir la couleur"),
        'color'        => tx('اللون: ', 'Couleur : '),
        'pickColor'    => tx('اختر لوناً', 'Choisissez une couleur'),
        'errSize'      => tx('يرجى اختيار المقاس', 'Veuillez choisir la taille'),
        'size'         => tx('المقاس: ', 'Taille : '),
        'pickSize'     => tx('اختاري المقاس', 'Choisissez la taille'),
        'viewCart'     => tx('عرض السلة', 'Voir le panier'),
        'netErr'       => tx('تعذر الاتصال، تحقق من الإنترنت', 'Connexion impossible, vérifiez Internet'),
    ];
}
