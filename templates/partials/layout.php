<?php
/**
 * Shared page shell for public templates:
 *   qp_lang(), qp_t(), qp_icon(), qp_header($title, $opts), qp_footer($opts)
 */
defined('QP_APP') || exit;

function qp_lang(): string
{
    static $lang = null;
    if ($lang !== null) {
        return $lang;
    }
    $q = isset($_GET['lang']) ? sanitize_key($_GET['lang']) : '';
    if (in_array($q, ['ar', 'en'], true)) {
        if (!headers_sent()) {
            setcookie('qp_lang', $q, ['expires' => time() + 31536000, 'path' => (parse_url(url('/'), PHP_URL_PATH) ?: '/'), 'samesite' => 'Lax', 'httponly' => true]);
        }
        return $lang = $q;
    }
    $c = isset($_COOKIE['qp_lang']) ? sanitize_key($_COOKIE['qp_lang']) : '';
    return $lang = in_array($c, ['ar', 'en'], true) ? $c : 'ar';
}

/** Server-side bilingual strings. */
function qp_t(string $key, array $vars = []): string
{
    static $s = [
        'home' => ['الرئيسية', 'Home'],
        'providers' => ['المزوّدون', 'Providers'],
        'my_tickets' => ['تذاكري', 'My tickets'],
        'faq' => ['كيف يعمل', 'How it works'],
        'provider_login' => ['دخول المزوّدين', 'Provider login'],
        'now_serving' => ['الرقم الحالي', 'Now serving'],
        'waiting' => ['المنتظرون', 'Waiting'],
        'eta' => ['الوقت التقريبي', 'Est. wait'],
        'min' => ['دقيقة', 'min'],
        'services' => ['الخدمات', 'Services'],
        'prices' => ['الأسعار', 'Prices'],
        'reviews_tab' => ['التقييمات', 'Reviews'],
        'location' => ['الموقع', 'Location'],
        'book_now' => ['احجز دورك الآن', 'Take a number'],
        'phone' => ['رقم الهاتف', 'Phone number'],
        'name' => ['الاسم (اختياري)', 'Name (optional)'],
        'no_account' => ['بدون إنشاء حساب — رقم هاتفك يكفي', 'No account needed — your phone number is enough'],
        'reviews' => ['تقييم', 'reviews'],
        'my_ticket' => ['تذكرتي', 'My ticket'],
        'have_ticket' => ['لديك تذكرة نشطة هنا', 'You have an active ticket here'],
        'view_ticket' => ['عرض التذكرة', 'View ticket'],
        'open_now' => ['مفتوح الآن', 'Open now'],
        'closed_now' => ['مغلق', 'Closed'],
        'view_details' => ['عرض التفاصيل', 'View details'],
        'all_categories' => ['كل التصنيفات', 'All categories'],
        'all_cities' => ['كل المدن', 'All cities'],
        'search_ph' => ['ابحث عن مزوّد أو خدمة…', 'Search a provider or service…'],
        'search' => ['بحث', 'Search'],
        'footer_tag' => ['معاً نحو تجربة أفضل', 'Together towards a better experience'],
        'rights' => ['جميع الحقوق محفوظة', 'All rights reserved'],
        'contact' => ['تواصل معنا', 'Contact us'],
    ];
    $pair = $s[$key] ?? [$key, $key];
    $str = qp_lang() === 'en' ? $pair[1] : $pair[0];
    foreach ($vars as $k => $v) {
        $str = str_replace('{' . $k . '}', (string) $v, $str);
    }
    return $str;
}

/** Small inline icon set (stroke icons, inherit currentColor). */
function qp_icon(string $name, int $size = 22): string
{
    static $p = [
        'scissors'    => '<circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><path d="M20 4 8.12 15.88M14.47 14.48 20 20M8.12 8.12 12 12"/>',
        'stethoscope' => '<path d="M6 3v6a5 5 0 0 0 10 0V3"/><path d="M11 14v2a5 5 0 0 0 10 0v-1"/><circle cx="21" cy="13" r="1.5"/>',
        'utensils'    => '<path d="M4 3v8a2 2 0 0 0 2 2h1v8M8 3v6M6 3v6M17 21V3c-2 0-4 2-4 6v4h4"/>',
        'pill'        => '<rect x="3" y="9" width="18" height="6" rx="3" transform="rotate(-45 12 12)"/><path d="m9.5 9.5 5 5"/>',
        'grid'        => '<rect x="4" y="4" width="7" height="7" rx="2"/><rect x="13" y="4" width="7" height="7" rx="2"/><rect x="4" y="13" width="7" height="7" rx="2"/><rect x="13" y="13" width="7" height="7" rx="2"/>',
        'building'    => '<path d="M4 21V5l8-3 8 3v16M9 21v-4h6v4M8 8h.01M12 8h.01M16 8h.01M8 12h.01M12 12h.01M16 12h.01"/>',
        'store'       => '<path d="M3 9 5 4h14l2 5M3 9h18v2a3 3 0 0 1-6 0 3 3 0 0 1-6 0 3 3 0 0 1-6 0V9ZM5 13v8h14v-8"/>',
        'pin'         => '<path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11Z"/><circle cx="12" cy="10" r="2.5"/>',
        'clock'       => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'users'       => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.8-3.5 3.3-5.5 6.5-5.5s5.7 2 6.5 5.5M16 4.5a3.5 3.5 0 0 1 0 7M18 14.5c2 .6 3.2 2.6 3.5 5.5"/>',
        'user'        => '<circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/>',
        'bolt'        => '<path d="M13 2 4 14h7l-1 8 9-12h-7l1-8Z"/>',
        'ticket'      => '<path d="M3 8a2 2 0 0 0 2-2h14a2 2 0 0 0 2 2v2a2 2 0 0 0 0 4v2a2 2 0 0 0-2 2H5a2 2 0 0 0-2-2v-2a2 2 0 0 0 0-4V8Z"/><path d="M13 6v12" stroke-dasharray="2 2"/>',
        'search'      => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        'star'        => '<path d="m12 3 2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1-4.4-4.3 6.1-.9L12 3Z"/>',
        'phone'       => '<path d="M5 4h3l2 5-2.5 1.5a11 11 0 0 0 6 6L15 14l5 2v3a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/>',
        'shield'      => '<path d="M12 3 4 6v6c0 4.5 3.4 8.3 8 9 4.6-.7 8-4.5 8-9V6l-8-3Z"/><path d="m9 12 2 2 4-4"/>',
        'chart'       => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'cog'         => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/>',
        'menu'        => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'arrow'       => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'back'        => '<path d="M19 12H5M11 18l-6-6 6-6"/>',
        'check'       => '<path d="m5 12 5 5L20 7"/>',
        'bell'        => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 8 3 8H3s3-1 3-8M10 21h4"/>',
        'map'         => '<path d="m9 4-6 2v14l6-2 6 2 6-2V4l-6 2-6-2ZM9 4v14M15 6v14"/>',
        'external'    => '<path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
    ];
    $d = $p[$name] ?? $p['store'];
    return '<svg class="qp-ico" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
}

function qp_brand(): string
{
    static $b = null;
    if ($b === null) {
        $b = qp()->settings->string('brand_name') ?: 'QueuePro';
    }
    return $b;
}

/**
 * @param array{wide?:bool,nav?:string,body_class?:string,head?:string} $o
 */
function qp_header(string $title, array $o = []): void
{
    $lang = qp_lang();
    $dir = $lang === 'ar' ? 'rtl' : 'ltr';
    $self = qp_remove_query_arg('lang');
    $nav = $o['nav'] ?? '';
    $links = [
        'home' => url('/'),
        'providers' => url('/explore/'),
        'my_tickets' => url('/#my-tickets'),
    ];
    ?><!doctype html>
<html lang="<?php echo esc_attr($lang); ?>" dir="<?php echo esc_attr($dir); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0b2236">
<title><?php echo esc_html($title); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&family=Inter:wght@500;600;700;800&display=swap">
<link rel="stylesheet" href="<?php echo esc_url(asset('css/queuepro.css')); ?>">
<script src="<?php echo esc_url(asset('js/ui.js')); ?>" defer></script>
<?php echo $o['head'] ?? ''; // trusted, built by templates ?>
</head>
<body class="qp <?php echo esc_attr($o['body_class'] ?? ''); ?>">
<header class="qp-topbar<?php echo !empty($o['wide']) ? ' qp-topbar--wide' : ''; ?>">
  <div class="qp-topbar-in">
    <a class="qp-brand" href="<?php echo esc_url(url('/')); ?>"><span class="qp-brand-mark" aria-hidden="true"></span><?php echo esc_html(qp_brand()); ?></a>
    <nav class="qp-nav" id="qp-nav" aria-label="Main">
      <?php foreach ($links as $k => $url): ?>
        <a href="<?php echo esc_url($url); ?>" class="<?php echo $nav === $k ? 'is-active' : ''; ?>"><?php echo esc_html(qp_t($k)); ?></a>
      <?php endforeach; ?>
      <a href="<?php echo esc_url(url('/dashboard/')); ?>" class="qp-nav-cta"><?php echo qp_icon('user', 16) . ' ' . esc_html(qp_t('provider_login')); ?></a>
    </nav>
    <div class="qp-topbar-end">
      <div class="qp-lang" aria-label="Language">
        <a href="<?php echo esc_url(qp_add_query_arg('lang', 'ar', $self)); ?>" class="<?php echo $lang === 'ar' ? 'is-active' : ''; ?>">AR</a>
        <a href="<?php echo esc_url(qp_add_query_arg('lang', 'en', $self)); ?>" class="<?php echo $lang === 'en' ? 'is-active' : ''; ?>">EN</a>
      </div>
      <button class="qp-menu-btn" type="button" aria-controls="qp-nav" aria-expanded="false" data-toggle-nav="qp-nav"><?php echo qp_icon('menu'); ?></button>
    </div>
  </div>
</header>
<main class="<?php echo !empty($o['wide']) ? 'qp-wide' : 'qp-shell'; ?>">
<?php
}

function qp_footer(array $o = []): void
{
    $support = qp()->settings->string('support_phone');
    echo "</main>\n";
    if (!empty($o['footer'])) {
        ?>
<footer class="qp-footer">
  <div class="qp-footer-in">
    <div>
      <a class="qp-brand" href="<?php echo esc_url(url('/')); ?>"><span class="qp-brand-mark" aria-hidden="true"></span><?php echo esc_html(qp_brand()); ?></a>
      <p><?php echo esc_html(qp_t('footer_tag')); ?></p>
    </div>
    <nav>
      <a href="<?php echo esc_url(url('/explore/')); ?>"><?php echo esc_html(qp_t('providers')); ?></a>
      <a href="<?php echo esc_url(url('/#how')); ?>"><?php echo esc_html(qp_t('faq')); ?></a>
      <a href="<?php echo esc_url(url('/dashboard/')); ?>"><?php echo esc_html(qp_t('provider_login')); ?></a>
      <?php if ($support): ?><a href="tel:<?php echo esc_attr($support); ?>" dir="ltr"><?php echo esc_html($support); ?></a><?php endif; ?>
    </nav>
  </div>
  <p class="qp-footer-copy">© <?php echo esc_html(gmdate('Y') . ' ' . qp_brand() . ' — ' . qp_t('rights')); ?></p>
</footer>
<?php
    }
    echo "</body>\n</html>";
}
