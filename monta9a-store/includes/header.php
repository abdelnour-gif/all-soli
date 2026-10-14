<?php
require_once __DIR__ . '/functions.php';
lang(); // read ?lang= before any output so the cookie can be set
$storeName = setting('store_name', 'MONTA9A');
$docTitle = isset($pageTitle) ? $pageTitle . ' | ' . $storeName : $storeName . ' — ' . st('footer_tagline', setting('store_tagline'));
$activeNav = $activeNav ?? '';
$menuCats = array_values(array_filter(topCategories(), fn($c) => (int)$c['show_menu'] === 1));
$topbar = settingJson('topbar');
$brands = array_values(array_filter(settingJson('brands'), fn($b) => is_string($b) && trim($b) !== ''));
$cPrimary = colorSetting('color_primary', '#c0396b');
$cDark = colorSetting('color_dark', '#151a21');
$cSoft = colorSetting('color_soft', '#f6efe8');
$fr = isFr();
$wishN = count(wishlist());
$offerOnly = offerOnly();
?><!DOCTYPE html>
<html lang="<?= lang() ?>" dir="<?= dir_() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($docTitle) ?></title>
<meta name="description" content="<?= e($metaDescription ?? $storeName . ' — ' . st('footer_about')) ?>">
<meta name="csrf" content="<?= csrfToken() ?>">
<meta name="theme-color" content="<?= $cDark ?>">
<link rel="icon" href="assets/img/favicon.png?v=4" type="image/png">
<link rel="apple-touch-icon" href="assets/img/favicon.png">
<link rel="alternate" hreflang="ar" href="<?= e(langUrl('ar')) ?>">
<link rel="alternate" hreflang="fr" href="<?= e(langUrl('fr')) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Almarai:wght@300;400;700;800&family=Cairo:wght@600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css?v=83">
<style>:root{--accent:<?= $cPrimary ?>;--dark:<?= $cDark ?>;--soft:<?= $cSoft ?>}</style>
<?php if ($pixel = setting('fb_pixel')): ?>
<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init','<?= e(preg_replace('/\D/', '', $pixel)) ?>');fbq('track','PageView');</script>
<?php endif; ?>
</head>
<body class="lang-<?= lang() ?>">

<div class="topbar">
  <div class="container topbar-inner">
    <div class="tb-items">
      <?php foreach ($topbar as $i => $t): if (!is_array($t)) continue; $txt = trim((string)($fr && trim((string)($t['text_fr'] ?? '')) !== '' ? $t['text_fr'] : ($t['text'] ?? ''))); if ($txt === '') continue;
          if (preg_match('/^0([5-7]\d{2})(\d{2})(\d{2})(\d{2})$/', preg_replace('/[\s.-]+/', '', $txt), $pm)) $txt = "+213 $pm[1] $pm[2] $pm[3] $pm[4]"; ?>
        <span class="tb-item <?= $i > 0 ? 'hide-sm' : '' ?>"><?php if (!empty($t['icon'])): ?><?= icon($t['icon'], 16) ?><?php endif; ?><bdi<?= preg_match('/^[\d\s+().-]+$/', $txt) ? ' dir="ltr"' : '' ?>><?= e($txt) ?></bdi></span>
      <?php endforeach; ?>
    </div>
    <div class="tb-end">
      <div class="lang-switch">
        <button type="button" class="ls-btn" aria-haspopup="true" aria-expanded="false"><span><?= e(LANGS[lang()]) ?></span> <?= icon('chev-d', 12) ?></button>
        <div class="ls-menu">
          <?php foreach (LANGS as $code => $label): ?><a href="<?= e(langUrl($code)) ?>" lang="<?= $code ?>" class="<?= $code === lang() ? 'on' : '' ?>"><?= e($label) ?></a><?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<header class="site-header" id="siteHeader">
  <div class="container header-main">
    <a href="index.php" class="logo" aria-label="<?= e($storeName) ?>">
      <?php if (($logo = setting('logo')) && setting('logo_with_name') !== '1'): ?>
        <img src="<?= e($logo) ?>" alt="<?= e($storeName) ?>">
      <?php else: ?>
        <?php if ($logo): ?><img class="logo-badge" src="<?= e($logo) ?>" alt="" width="70" height="70"><?php else: ?><span class="logo-icon"><?= icon(setting('logo_icon') ?: 'lotus', 46) ?></span><?php endif; ?>
        <span class="logo-words">
          <span class="logo-text"><?= e($storeName) ?></span>
          <?php if ($tag = setting('store_tagline')): ?><span class="logo-tag"><?= e($tag) ?></span><?php endif; ?>
        </span>
      <?php endif; ?>
    </a>

<?php if ($offerOnly): ?>
    <div class="header-actions">
      <a href="index.php#order" class="btn hdr-cta"><?= icon('cartsm', 18) ?> <span><?= tx('اطلب الآن', 'Commander') ?></span></a>
    </div>
<?php else: ?>
    <div class="search-row">
      <button class="icon-btn menu-toggle" id="menuToggle" aria-label="<?= tx('القائمة', 'Menu') ?>"><?= icon('menu', 22) ?></button>
      <form class="search-box" action="shop.php" method="get" role="search">
        <input type="search" name="q" placeholder="<?= e(st('search_placeholder', tx('ابحث عن المنتجات', 'Rechercher des produits'))) ?>" value="<?= e($_GET['q'] ?? '') ?>" aria-label="<?= tx('بحث', 'Rechercher') ?>">
        <select name="cat" class="search-cat" aria-label="<?= tx('القسم', 'Catégorie') ?>">
          <option value=""><?= tx('اختر الفئة', 'Catégorie') ?></option>
          <?php foreach (topCategories() as $tc): ?><option value="<?= (int)$tc['id'] ?>" <?= (int)($_GET['cat'] ?? 0) === (int)$tc['id'] ? 'selected' : '' ?>><?= e(tr($tc, 'name')) ?></option><?php endforeach; ?>
        </select>
        <button type="submit" aria-label="<?= tx('بحث', 'Rechercher') ?>"><?= icon('search', 18) ?></button>
      </form>
    </div>

    <div class="header-actions">
      <a href="cart.php" class="hc-cart" title="<?= tx('السلة', 'Panier') ?>"><span class="hc-btn"><?= icon('cartsm', 21) ?><span class="badge" id="cartCount"><?= cartCount() ?></span></span></a>
    </div>
<?php endif; ?>
  </div>

  <div class="nav-row">
    <nav class="container main-nav" id="mainNav" aria-label="<?= tx('القائمة الرئيسية', 'Menu principal') ?>">
      <div class="nav-head"><strong><?= e($storeName) ?></strong><button class="icon-btn" id="menuClose" aria-label="<?= tx('إغلاق', 'Fermer') ?>"><?= icon('x', 22) ?></button></div>
<?php if ($offerOnly): ?>
      <a href="index.php" class="nav-link nav-home <?= $activeNav === 'home' ? 'active' : '' ?>"><?= tx('الصفحة الرئيسية', 'Accueil') ?></a>
      <a href="index.php#order" class="nav-link nav-promo"><?= e(st('sale_title', tx('العرض', "L'offre"))) ?></a>
      <a href="index.php#faq" class="nav-link"><?= tx('الأسئلة الشائعة', 'Questions fréquentes') ?></a>
<?php else: ?>
      <a href="index.php" class="nav-link nav-home <?= $activeNav === 'home' ? 'active' : '' ?>"><?= icon('home', 17) ?><?= tx('الرئيسية', 'Accueil') ?></a>
      <div class="has-drop">
        <a href="shop.php" class="nav-link <?= $activeNav === 'shop' ? 'active' : '' ?>"><span class="nl-ic"><?= icon('grid', 17) ?></span><?= tx('جميع المنتجات', 'Tous les produits') ?> <?= icon('chev-d', 13) ?></a>
        <div class="dropdown">
          <?php foreach (topCategories() as $tc): ?><a href="shop.php?cat=<?= (int)$tc['id'] ?>"><?= e(tr($tc, 'name')) ?></a><?php endforeach; ?>
          <a href="shop.php" class="drop-all"><?= tx('كل المنتجات', 'Tout voir') ?></a>
        </div>
      </div>
      <?php foreach ($menuCats as $mc):
          $kids = childCategories((int)$mc['id']);
          // no sub-categories: the list shows the category's products
          $drop = $kids ?: getDB()->query(productSelect() . ' WHERE p.active = 1 AND p.category_id = ' . (int)$mc['id'] . ' ORDER BY p.is_featured DESC, p.id DESC LIMIT 6')->fetchAll();
          $mcIc = '<span class="nl-ic">' . icon(($mc['icon'] ?? '') ?: 'tag', 18) . '</span>'; ?>
        <?php if ($drop): ?>
        <div class="has-drop">
          <a href="shop.php?cat=<?= (int)$mc['id'] ?>" class="nav-link <?= $activeNav === 'cat' . $mc['id'] ? 'active' : '' ?>"><?= $mcIc ?><?= e(tr($mc, 'name')) ?> <?= icon('chev-d', 13) ?></a>
          <div class="dropdown">
            <?php foreach ($drop as $k): ?><a href="<?= $kids ? 'shop.php?cat=' . (int)$k['id'] : 'product.php?id=' . (int)$k['id'] ?>"><?= e(tr($k, 'name')) ?></a><?php endforeach; ?>
            <a href="shop.php?cat=<?= (int)$mc['id'] ?>" class="drop-all"><?= tx('عرض الكل', 'Voir tout') ?></a>
          </div>
        </div>
        <?php else: ?>
        <a href="shop.php?cat=<?= (int)$mc['id'] ?>" class="nav-link <?= $activeNav === 'cat' . $mc['id'] ? 'active' : '' ?>"><?= $mcIc ?><?= e(tr($mc, 'name')) ?></a>
        <?php endif; ?>
      <?php endforeach; ?>
<?php endif; ?>
      <a href="track.php" class="nav-link nav-track <?= $activeNav === 'track' ? 'active' : '' ?>"><?= tx('تتبع طلبي', 'Suivre ma commande') ?></a>
      <a href="contact.php" class="nav-link nav-contact <?= $activeNav === 'contact' ? 'active' : '' ?>"><span class="nl-ic"><?= icon('phone', 16) ?></span><?= tx('اتصل بنا', 'Contact') ?></a>
      <a href="wishlist.php" class="nav-link only-mobile"><?= tx('المفضلة', 'Mes favoris') ?> (<span id="wishCount"><?= $wishN ?></span>)</a>
      <a href="page.php?p=about" class="nav-link only-mobile"><?= tx('من نحن', 'Qui sommes-nous') ?></a>
      <div class="nav-langs only-mobile">
        <?php foreach (LANGS as $code => $label): ?><a href="<?= e(langUrl($code)) ?>" class="<?= $code === lang() ? 'on' : '' ?>"><?= e($label) ?></a><?php endforeach; ?>
      </div>
    </nav>
  </div>
</header>
<div class="nav-overlay" id="navOverlay"></div>
<div class="toast" id="toast" role="status" aria-live="polite"></div>
<main>
