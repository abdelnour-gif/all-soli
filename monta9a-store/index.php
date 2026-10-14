<?php
$activeNav = 'home';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';
$db = getDB();
$sections = settingJson('home_sections');
if (!$sections) $sections = array_map(fn($k) => ['key' => $k, 'on' => 1], array_keys(homeSectionLabels()));
$arrowEnd = '<span class="flip">' . icon('arrow-r', 17) . '</span>';

$productList = function (string $where, string $order, int $limit) use ($db): array {
    return $db->query(productSelect() . " WHERE p.active = 1 AND $where ORDER BY $order LIMIT $limit")->fetchAll();
};
// A JSON-setting item in the visitor's language: $it['title_fr'] in French when filled.
$jt = fn(array $it, string $f): string => trim((string)(isFr() && trim((string)($it[$f . '_fr'] ?? '')) !== '' ? $it[$f . '_fr'] : ($it[$f] ?? '')));

foreach ($sections as $sec):
    if (empty($sec['on'])) continue;
    switch ($sec['key']):

    case 'hero':
        $slides = $db->query('SELECT * FROM slides WHERE active = 1 ORDER BY sort, id')->fetchAll();
        if (!$slides) break;
        // A field holding only spaces/dots/symbols (e.g. ".") counts as empty.
        $blank = fn($v) => preg_replace('/[\p{P}\p{S}\s]+/u', '', (string)$v) === '';
        foreach ($slides as &$sl) {
            foreach (['subtitle', 'title', 'title2', 'text', 'btn_text'] as $f) { $sl['_' . $f] = tr($sl, $f); if ($blank($sl['_' . $f])) $sl['_' . $f] = ''; }
            // Picture with no wording (a button alone is fine): show the whole image
            $sl['img_only'] = $sl['image'] && $sl['_subtitle'] . $sl['_title'] . $sl['_title2'] . $sl['_text'] === '';
        }
        unset($sl);
        $pills = array_values(array_filter(settingJson('hero_pills'), fn($p) => trim((string)($p['text'] ?? '')) !== '')); ?>
<section class="hero" id="hero">
  <?php foreach ($slides as $i => $s): ?>
  <div class="slide <?= $i === 0 ? 'active' : '' ?><?= $s['img_only'] ? ' img-only' : '' ?>">
    <?php if ($s['img_only'] && !$s['_btn_text']): ?>
    <a class="slide-pic" href="<?= e($s['btn_link'] ?: 'shop.php') ?>"><img src="<?= e($s['image']) ?>" alt="<?= e($storeName) ?>" <?= $i ? 'loading="lazy"' : 'fetchpriority="high"' ?>></a>
    <?php elseif ($s['image']): ?><div class="slide-img" style="background-image:url('<?= e($s['image']) ?>')"></div><?php endif; ?>
    <?php if (!$s['img_only'] || $s['_btn_text']): ?>
    <div class="container slide-content">
      <div class="slide-box">
        <?php if ($s['_subtitle']): ?><span class="slide-kicker"><?= e($s['_subtitle']) ?></span><?php endif; ?>
        <?php if ($s['_title'] . $s['_title2'] !== ''): ?><<?= $i ? 'h2' : 'h1' ?> class="slide-title"><?= e($s['_title']) ?><?php if ($s['_title2']): ?><span class="t2"><?= e($s['_title2']) ?></span><?php endif; ?></<?= $i ? 'h2' : 'h1' ?>><?php endif; ?>
        <?php if ($s['_text']): ?><p class="slide-text"><?= nl2br(e($s['_text'])) ?></p><?php endif; ?>
        <?php if ($s['_btn_text']): ?><a href="<?= e($s['btn_link'] ?: 'shop.php') ?>" class="btn btn-hero"><?= icon('cartsm', 20) ?> <?= e($s['_btn_text']) ?></a><?php endif; ?>
        <?php if ($pills && !$s['img_only']): ?>
        <div class="hero-pills">
          <?php foreach ($pills as $hp): ?>
            <span class="hero-pill"><?= icon($hp['icon'] ?? 'leaf', 30) ?><span><b><?= e($jt($hp, 'text')) ?></b><?php if ($t2 = $jt($hp, 'text2')): ?><small><?= e($t2) ?></small><?php endif; ?></span></span>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php if (count($slides) > 1): ?>
  <div class="hero-dots">
    <?php foreach ($slides as $i => $s): ?><button class="<?= $i === 0 ? 'active' : '' ?>" data-slide="<?= $i ?>" aria-label="<?= tx('شريحة', 'Diapositive') ?> <?= $i + 1 ?>"></button><?php endforeach; ?>
  </div>
  <div class="hero-arrows">
    <button class="hero-nav prev" aria-label="<?= tx('السابق', 'Précédent') ?>"><span class="flip"><?= icon('chev-l', 20) ?></span></button>
    <button class="hero-nav next" aria-label="<?= tx('التالي', 'Suivant') ?>"><span class="flip"><?= icon('chev-r', 20) ?></span></button>
  </div>
  <?php endif; ?>
</section>
<?php break;

    case 'categories':
        $homeCats = array_values(array_filter(topCategories(), fn($c) => (int)$c['show_home'] === 1));
        if (!$homeCats) break; ?>
<section class="section cats-section">
  <div class="container">
    <div class="sec-head">
      <h2><?= e(st('cats_title', tx('الأقسام', 'Catégories'))) ?></h2>
      <?php if (setting('show_see_all') === '1'): ?><a href="shop.php" class="see-all"><?= tx('عرض الكل', 'Voir tout') ?></a><?php endif; ?>
    </div>
    <div class="cat-tiles">
      <?php foreach ($homeCats as $c):
          // picture of the tile: the category's first products (no picture to upload)
          // the category's own picture when it has one, otherwise its first products (no picture to upload)
          $cPics = [];
          if (!empty($c['image'])) {
              $cOwn = true;
          } else {
              $cOwn = false;
              $cIds = implode(',', array_map('intval', categoryWithChildren((int)$c['id'])));
              foreach ($productList("p.category_id IN ($cIds)", 'p.is_featured DESC, p.id DESC', 2) as $cp) $cPics[] = productThumb($cp);
          } ?>
      <a href="shop.php?cat=<?= (int)$c['id'] ?>" class="cat-tile<?= $cOwn ? ' has-own' : ($cPics ? ' has-pic' : '') ?>">
        <span class="ct-box"><?php if ($cOwn): ?><img src="<?= e($c['image']) ?>" alt="" loading="lazy"><?php elseif ($cPics): ?><?php foreach ($cPics as $cpi): ?><img src="<?= e($cpi) ?>" alt="" loading="lazy"><?php endforeach; ?><?php else: ?><?= icon(($c['icon'] ?? '') ?: 'book-open', 58) ?><?php endif; ?></span>
        <span class="ct-foot"><strong><?= e(tr($c, 'name')) ?></strong><span class="ct-go"><span class="flip"><?= icon('chev-r', 15) ?></span></span></span>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php break;

    case 'catrows':
        // For every home category: its wide banner, the title with "عرض الكل", then its products.
        foreach (array_values(array_filter(topCategories(), fn($c) => (int)$c['show_home'] === 1)) as $c):
            $ids = implode(',', array_map('intval', categoryWithChildren((int)$c['id'])));
            $rowItems = $productList("p.category_id IN ($ids)", 'p.is_featured DESC, p.id DESC', 8);
            if (!$rowItems) continue;
            if (str_contains((string)$c['image'], '/seed/med/tile-')) $c['image'] = ''; ?>
<?php $split = $c['image'] && count($rowItems) <= 2; // few products: banner and cards side by side ?>
<section class="section cat-row-sec">
  <div class="container">
    <?php if ($split): ?>
    <div class="sec-head">
      <h2><?= e(tr($c, 'name')) ?></h2>
      <a href="shop.php?cat=<?= (int)$c['id'] ?>" class="see-all"><?= tx('عرض الكل', 'Voir tout') ?></a>
    </div>
    <div class="cat-split n<?= count($rowItems) ?>">
      <?php foreach ($rowItems as $p) include __DIR__ . '/includes/product-card.php'; ?>
      <a href="<?= count($rowItems) === 1 ? 'product.php?id=' . (int)$rowItems[0]['id'] : 'shop.php?cat=' . (int)$c['id'] ?>" class="cat-banner"><img src="<?= e($c['image']) ?>" alt="<?= e(tr($c, 'name')) ?>" loading="lazy"></a>
    </div>
    <?php else: ?>
    <?php if ($c['image']): ?><a href="shop.php?cat=<?= (int)$c['id'] ?>" class="cat-banner"><img src="<?= e($c['image']) ?>" alt="<?= e(tr($c, 'name')) ?>" loading="lazy"></a><?php endif; ?>
    <div class="sec-head">
      <h2><?= e(tr($c, 'name')) ?></h2>
      <a href="shop.php?cat=<?= (int)$c['id'] ?>" class="see-all"><?= tx('عرض الكل', 'Voir tout') ?></a>
    </div>
    <div class="products-grid">
      <?php foreach ($rowItems as $p) include __DIR__ . '/includes/product-card.php'; ?>
    </div>
    <?php endif; ?>
  </div>
</section>
<?php   endforeach;
        break;

    case 'featured':
    case 'newest':
    case 'sale':
        $cfg = [
            'featured' => ['p.is_featured = 1', 'p.id DESC', 'shop.php?sort=featured', 'الأكثر مبيعاً', 'Nos meilleures ventes'],
            'newest'   => ['1', 'p.created_at DESC, p.id DESC', 'shop.php?sort=new', 'وصل حديثاً', 'Nouveautés'],
            'sale'     => ['p.old_price > p.price', 'p.id DESC', 'shop.php?sale=1', 'التخفيضات', 'Promotions'],
        ][$sec['key']];
        $limit = max(1, min(48, (int)setting('home_products_count', '6')));
        $items = $productList($cfg[0], $cfg[1], $limit);
        if (!$items && $sec['key'] === 'featured') $items = $productList('1', 'p.id DESC', $limit);
        if (!$items) break; ?>
<section class="section products-section">
  <div class="container">
    <div class="sec-head">
      <div>
        <h2><span class="sh-ic"><?= icon('star', 22) ?></span> <?= e(st($sec['key'] . '_title', tx($cfg[3], $cfg[4]))) ?></h2>
        <?php if ($sub = st($sec['key'] . '_sub')): ?><p><?= e($sub) ?></p><?php endif; ?>
      </div>
      <?php if (setting('show_see_all') === '1'): ?><a href="<?= $cfg[2] ?>" class="see-all"><?= tx('عرض الكل', 'Voir tout') ?> <span class="flip"><?= icon('chev-r', 14) ?></span></a><?php endif; ?>
    </div>
    <div class="products-grid">
      <?php foreach ($items as $p) include __DIR__ . '/includes/product-card.php'; ?>
    </div>
  </div>
</section>
<?php break;

    case 'banners':
        $banners = array_values(array_filter(settingJson('banners'), fn($b) => trim($b['title'] ?? '') !== '' || !empty($b['image'])));
        if (!$banners) break; ?>
<section class="section banners-section">
  <div class="container banners n<?= min(3, count($banners)) ?>">
    <?php foreach ($banners as $b): $style = in_array($b['style'] ?? '', ['pink', 'green', 'blue', 'dark', 'light'], true) ? $b['style'] : 'pink'; ?>
    <a href="<?= e(($b['link'] ?? '') ?: 'shop.php') ?>" class="banner bn-<?= $style ?>">
      <?php if (!empty($b['image'])): ?><span class="banner-img" style="background-image:url('<?= e($b['image']) ?>')"></span><?php endif; ?>
      <span class="banner-text">
        <strong><?= e($jt($b, 'title')) ?></strong>
        <?php if ($t = $jt($b, 'text')): ?><span><?= e($t) ?></span><?php endif; ?>
        <?php if ($bt = $jt($b, 'btn')): ?><span class="banner-btn"><?= e($bt) ?> <?= '<span class="flip">' . icon('arrow-r', 14) . '</span>' ?></span><?php endif; ?>
      </span>
    </a>
    <?php endforeach; ?>
  </div>
</section>
<?php break;

    case 'cta':
        $ctaItems = array_slice(array_values(array_filter(settingJson('why'), fn($f) => trim($f['title'] ?? '') !== '')), 0, 2);
        $wa = preg_replace('/\D/', '', setting('whatsapp') ?: setting('phone'));
        if (str_starts_with($wa, '0')) $wa = '213' . substr($wa, 1);
        $ctaImg = setting('cta_image'); ?>
<section class="section cta-section">
  <div class="container cta-strip"<?php if ($ctaImg): ?> style="--cta-img:url('../../<?= e($ctaImg) ?>')"<?php endif; ?>>
    <?php if ($ctaImg || $ctaItems): ?>
    <div class="cta-info">
      <?php if ($ctaImg): ?><span class="cta-img" style="background-image:url('<?= e($ctaImg) ?>')"></span><?php endif; ?>
      <?php foreach (array_reverse($ctaItems) as $ci): ?>
      <div class="cta-item"><span class="cta-ic"><?= icon($ci['icon'] ?? 'shield', 34) ?></span><span><strong><?= e($jt($ci, 'title')) ?></strong><small><?= e($jt($ci, 'text')) ?></small></span></div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if ($wa): ?>
    <a class="cta-wa" href="https://wa.me/<?= e($wa) ?>" target="_blank" rel="noopener"><?= icon('whatsapp', 40) ?><span><strong><?= e(st('cta_title', tx('تواصل معنا واتساب', 'Contactez-nous sur WhatsApp'))) ?></strong><small><?= e(st('cta_text', tx('للاستفسار أو طلب منتج', 'Pour une question ou une commande'))) ?></small></span></a>
    <?php endif; ?>
  </div>
</section>
<?php break;

    case 'brands':
        $brandList = array_values(array_filter(settingJson('brands'), fn($b) => is_string($b) && trim($b) !== ''));
        if (!$brandList) break; ?>
<section class="brands-strip">
  <div class="container">
    <h2><?= e(st('brands_title', tx('علاماتنا الشريكة', 'Nos marques partenaires'))) ?></h2>
    <div class="brand-row">
      <?php foreach ($brandList as $n => $b): ?><a href="shop.php?brand=<?= urlencode($b) ?>" class="brand-mark bm-<?= $n % 8 ?>" dir="ltr"><?= e($b) ?></a><?php endforeach; ?>
    </div>
  </div>
</section>
<?php break;

    case 'promo':
        $promoTitle = st('promo_title');
        if (setting('promo_on', '1') !== '1' || ($promoTitle === '' && !setting('promo_image'))) break;
        $promoPoints = array_values(array_filter(settingJson('promo_points'), fn($p) => trim($p['text'] ?? '') !== ''));
        $pi = setting('promo_image'); ?>
<section class="quote-band" <?php if ($pi): ?>style="background-image:url('<?= e($pi) ?>')"<?php endif; ?>>
  <div class="container qb-inner">
    <?php if ($pp = st('promo_pill')): ?><span class="promo-pill"><?= e($pp) ?></span><?php endif; ?>
    <?php if ($promoTitle !== ''): ?><h2 class="qb-title"><span class="qb-icons" aria-hidden="true"><?= icon('cap', 34) ?><?= icon('book-open', 34) ?><?= icon('bulb', 34) ?></span><span><?= e($promoTitle) ?></span></h2><?php endif; ?>
    <?php if ($pt = st('promo_text')): ?><p><?= e($pt) ?></p><?php endif; ?>
    <?php if ($promoPoints): ?>
    <ul class="qb-points">
      <?php foreach ($promoPoints as $p): ?><li><?= icon($p['icon'] ?? 'badge', 18) ?><span><?= e($jt($p, 'text')) ?></span></li><?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <?php if ($pb = st('promo_btn')): ?><a href="<?= e(setting('promo_link') ?: 'shop.php') ?>" class="btn btn-light btn-pill"><?= e($pb) ?></a><?php endif; ?>
  </div>
</section>
<?php break;

    case 'features':
        $features = array_values(array_filter(settingJson('features'), fn($f) => trim($f['title'] ?? '') !== ''));
        if (!$features) break; ?>
<section class="trust-strip">
  <div class="container">
    <?php if ($ft = st('features_title')): ?><h2><?= e($ft) ?></h2><?php endif; ?>
    <div class="trust-grid" style="--cols:<?= min(6, count($features)) ?>">
      <?php foreach ($features as $f): ?>
      <div class="trust-item"><span class="ti-text"><strong><?= e($jt($f, 'title')) ?></strong><span><?= e($jt($f, 'text')) ?></span></span><span class="ti-icon"><?= icon($f['icon'] ?? 'star', 40) ?></span></div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php break;

    case 'why':
        $why = array_values(array_filter(settingJson('why'), fn($f) => trim($f['title'] ?? '') !== ''));
        if (!$why) break; ?>
<section class="why-dark">
  <div class="container">
    <?php if ($wt = st('why_title')): ?><h2><?= e($wt) ?></h2><?php endif; ?>
    <div class="why-dark-grid" style="--cols:<?= min(4, count($why)) ?>">
      <?php foreach ($why as $f): ?>
      <div class="wd-item">
        <span class="wd-icon"><?= icon($f['icon'] ?? 'star', 28) ?></span>
        <strong><?= e($jt($f, 'title')) ?></strong>
        <span><?= e($jt($f, 'text')) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php break;

    case 'reviews':
        if (setting('reviews_on', '1') !== '1') break;
        $testimonials = $db->query(
            'SELECT r.*, p.name AS product, p.name_fr AS product_fr FROM reviews r LEFT JOIN products p ON p.id = r.product_id
             WHERE r.approved = 1 AND r.comment IS NOT NULL AND trim(r.comment) != \'\'
             ORDER BY r.rating DESC, r.id DESC LIMIT 3'
        )->fetchAll();
        if (!$testimonials) break; ?>
<section class="section reviews-section">
  <div class="container">
    <div class="sec-head">
      <div><h2><?= e(st('reviews_title', tx('آراء عملائنا', 'Avis de nos clients'))) ?></h2>
      <?php if ($rs = st('reviews_sub')): ?><p><?= e($rs) ?></p><?php endif; ?></div>
    </div>
    <div class="testi-grid">
      <?php foreach ($testimonials as $t): ?>
      <figure class="testi">
        <div class="testi-stars"><?= stars((float)$t['rating'], 14) ?></div>
        <blockquote><?= e($t['comment']) ?></blockquote>
        <figcaption>
          <span class="testi-avatar"><?= e(mb_substr(trim($t['name']), 0, 1)) ?></span>
          <span class="testi-who">
            <strong><?= e($t['name']) ?></strong>
            <?php if ($t['product']): ?><span><?= e(isFr() && $t['product_fr'] ? $t['product_fr'] : $t['product']) ?></span><?php endif; ?>
          </span>
        </figcaption>
      </figure>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php break;

    endswitch;
endforeach;

require_once __DIR__ . '/includes/footer.php';
