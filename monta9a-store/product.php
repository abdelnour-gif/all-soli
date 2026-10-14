<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/images.php';
$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (offerOnly()) redirect('index.php?pick=' . $id . '#order');
$st = $db->prepare(productSelect() . ' WHERE p.id = ? AND p.active = 1');
$st->execute([$id]);
$p = $st->fetch();
if (!$p) {
    http_response_code(404);
    $pageTitle = tx('المنتج غير موجود', 'Produit introuvable');
    require_once __DIR__ . '/includes/header.php';
    echo '<section class="section"><div class="container empty-state">' . icon('search', 48) . '<h3>' . tx('المنتج غير موجود', 'Produit introuvable') . '</h3><a href="shop.php" class="btn btn-dark">' . tx('العودة إلى المتجر', 'Retour à la boutique') . '</a></div></section>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// ---- Review submission ----
$reviewMsg = null;
$reviewsOn = setting('reviews_on') === '1';
if ($reviewsOn && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'review') {
    $name = trim((string)($_POST['name'] ?? ''));
    $rating = (int)($_POST['rating'] ?? 0);
    $comment = trim((string)($_POST['comment'] ?? ''));
    $recent = array_filter($_SESSION['reviewed'] ?? [], fn($t) => $t > time() - 3600);
    if (!checkCsrf()) $reviewMsg = [tx('انتهت صلاحية الصفحة، أعد المحاولة', 'La page a expiré, réessayez'), 'error'];
    elseif (!empty($_POST['website'])) $reviewMsg = [tx('تم الإرسال', 'Envoyé'), 'success']; // honeypot: silently ignore bots
    elseif (mb_strlen($name) < 2) $reviewMsg = [tx('يرجى كتابة اسمك', 'Veuillez écrire votre nom'), 'error'];
    elseif ($rating < 1 || $rating > 5) $reviewMsg = [tx('يرجى اختيار التقييم (عدد النجوم)', 'Veuillez choisir une note (étoiles)'), 'error'];
    elseif (isset($recent[$id]) || count($recent) >= 5) $reviewMsg = [tx('شكراً، لقد قيّمت هذا المنتج مؤخراً', 'Merci, vous avez déjà noté ce produit récemment'), 'error'];
    else {
        $approved = setting('reviews_moderate') === '1' ? 0 : 1;
        $db->prepare('INSERT INTO reviews (product_id, name, rating, comment, approved, ip) VALUES (?,?,?,?,?,?)')
           ->execute([$id, mb_substr($name, 0, 60), $rating, mb_substr($comment, 0, 1000), $approved, $_SERVER['REMOTE_ADDR'] ?? '']);
        $recent[$id] = time();
        $_SESSION['reviewed'] = $recent;
        flash($approved ? tx('شكراً لتقييمك!', 'Merci pour votre avis !') : tx('شكراً لتقييمك! سيظهر بعد مراجعته.', 'Merci pour votre avis ! Il sera publié après vérification.'));
        redirect('product.php?id=' . $id . '#reviews');
    }
}

$images = productImages($p);
$colors = productColors($p);
$sizes = productSizes($p);
$discount = ((int)$p['old_price'] > (int)$p['price']) ? (int)round(100 - $p['price'] * 100 / $p['old_price']) : 0;
$isOut = (int)$p['stock'] <= 0;
$rc = (int)$p['reviews_count'];
$reviews = [];
if ($reviewsOn) {
    $st = $db->prepare('SELECT name, rating, comment, created_at FROM reviews WHERE product_id = ? AND approved = 1 ORDER BY id DESC LIMIT 30');
    $st->execute([$id]);
    $reviews = $st->fetchAll();
}
$st = $db->prepare(productSelect() . ' WHERE p.active = 1 AND p.id != ? AND p.category_id = ? ORDER BY RANDOM() LIMIT 6');
$st->execute([$id, $p['category_id']]);
$related = $st->fetchAll();
if (count($related) < 6) {
    $st = $db->prepare(productSelect() . ' WHERE p.active = 1 AND p.id != ? AND p.category_id != ? ORDER BY p.is_featured DESC, RANDOM() LIMIT ' . (6 - count($related)));
    $st->execute([$id, $p['category_id']]);
    $related = array_merge($related, $st->fetchAll());
}
$cat = getCategory((int)$p['category_id']);
$flash = flash();

$pName = tr($p, 'name');
$pDesc = tr($p, 'description');
$pageTitle = $pName;
$metaDescription = mb_substr($pDesc, 0, 150);
$activeNav = $cat ? 'cat' . ((int)$cat['parent_id'] ?: (int)$cat['id']) : '';
require_once __DIR__ . '/includes/header.php';
?>
<section class="section sp-top">
  <div class="container product-layout">
    <div class="gallery">
      <div class="gallery-main">
        <div class="p-badges">
          <?php if ($discount): ?><span class="p-badge sale">-<?= $discount ?>%</span><?php endif; ?>
          <?php if (trim(tr($p, 'badge')) !== ''): ?><span class="p-badge hot"><?= e(tr($p, 'badge')) ?></span><?php elseif ($p['is_new']): ?><span class="p-badge new"><?= tx('جديد', 'Nouveau') ?></span><?php endif; ?>
        </div>
        <img src="<?= e($images[0]) ?>" alt="<?= e($pName) ?>" id="mainImg" fetchpriority="high" decoding="async">
      </div>
      <?php if (count($images) > 1): ?>
      <div class="gallery-thumbs">
        <?php foreach ($images as $i => $img): ?><button class="<?= $i === 0 ? 'active' : '' ?>" data-img="<?= e($img) ?>"><img src="<?= e(imageSmall($img)) ?>" alt="" loading="lazy" decoding="async"></button><?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <div class="product-info">
      <nav class="breadcrumb"><a href="index.php"><?= tx('الرئيسية', 'Accueil') ?></a> / <?php if ($cat): ?><a href="shop.php?cat=<?= (int)$cat['id'] ?>"><?= e(tr($cat, 'name')) ?></a> / <?php endif; ?><span><?= e($pName) ?></span></nav>
      <h1><?= e($pName) ?></h1>
      <?php if ($rc): ?><a href="#tabs" class="pi-rating"><?= stars((float)$p['rating'], 16) ?> <b><?= e($p['rating']) ?></b> <span>(<?= $rc ?> <?= tx('تقييم', 'avis') ?>)</span></a><?php endif; ?>
      <div class="pi-price"><?php if ($discount): ?><del><?= money($p['old_price']) ?></del> <?php endif; ?><span><?= money($p['price']) ?></span></div>
      <?php $short = trim(strtok(trim($pDesc), "\n") ?: ''); if ($short !== ''): ?><p class="pi-short"><?= e($short) ?></p><?php endif; ?>

      <form id="addForm" class="pi-form" data-product="<?= (int)$p['id'] ?>">
        <?php if ($colors): ?>
        <div class="pi-colors">
          <span class="lbl"><?= tx('اللون:', 'Couleur :') ?> <b id="colorLabel"><?= count($colors) === 1 ? e(colorLabel($colors[0])) : tx('اختر لوناً', 'Choisissez une couleur') ?></b></span>
          <div class="swatches">
            <?php foreach ($colors as $c): ?>
              <label class="swatch" title="<?= e(colorLabel($c)) ?>"><input type="radio" name="color" value="<?= e($c) ?>" data-name="<?= e(colorLabel($c)) ?>" <?= count($colors) === 1 ? 'checked' : '' ?>><span style="background:<?= e($c) ?>"></span></label>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
        <?php if ($sizes): ?>
        <div class="pi-sizes">
          <span class="lbl"><?= tx('المقاس:', 'Taille :') ?> <b id="sizeLabel"><?= count($sizes) === 1 ? e($sizes[0]) : tx('اختاري المقاس', 'Choisissez la taille') ?></b></span>
          <div class="size-opts">
            <?php foreach ($sizes as $sz): ?>
              <label class="size-opt"><input type="radio" name="size" value="<?= e($sz) ?>" <?= count($sizes) === 1 ? 'checked' : '' ?>><span><?= e($sz) ?></span></label>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
        <div class="pi-actions">
          <div class="qty">
            <button type="button" data-qty="1" aria-label="<?= tx('زيادة', 'Plus') ?>">+</button>
            <input type="number" name="qty" value="1" min="1" max="<?= max(1, min(20, (int)$p['stock'])) ?>" aria-label="<?= tx('الكمية', 'Quantité') ?>">
            <button type="button" data-qty="-1" aria-label="<?= tx('إنقاص', 'Moins') ?>">−</button>
          </div>
          <button type="submit" class="btn pi-add" <?= $isOut ? 'disabled' : '' ?>><?= tx('إضافة إلى السلة', 'Ajouter au panier') ?></button>
          <button type="button" class="btn pi-buy" id="buyNow" data-order-info="<?= e(orderData($p)) ?>" <?= $isOut ? 'disabled' : '' ?>><?= tx('شراء الآن', 'Acheter') ?></button>
        </div>
        <div class="form-error" id="addError" hidden></div>
      </form>
      <?php require_once __DIR__ . '/includes/paypal.php'; if (ppEnabled() && !$isOut): ?>
      <a href="intl.php?buy=<?= (int)$p['id'] ?>" class="pi-intl" id="intlLink">
        <span class="pi-intl-ic">🌍</span>
        <span><b><?= tx('تطلب من خارج الجزائر؟', 'Vous êtes hors d\'Algérie ?') ?></b><small><?= tx('ادفع أونلاين بـ PayPal أو البطاقة', 'Payez en ligne par PayPal ou carte') ?> — <bdi dir="ltr"><?= ppMoney(ppCents((int)$p['price'])) ?></bdi></small></span>
        <span class="pi-intl-go"><?= icon('chev-l', 16) ?></span>
      </a>
      <script>(function(){var a=document.getElementById('intlLink'),f=document.getElementById('addForm');a.addEventListener('click',function(e){var c=f.querySelector('input[name=color]:checked'),s=f.querySelector('input[name=size]:checked'),q=f.querySelector('input[name=qty]');var need=(f.querySelectorAll('input[name=color]').length>1&&!c)||(f.querySelectorAll('input[name=size]').length>1&&!s);if(need){e.preventDefault();var er=document.getElementById('addError');er.textContent=<?= json_encode(tx('اختر الخيارات أولاً', 'Choisissez d\'abord les options'), JSON_UNESCAPED_UNICODE) ?>;er.hidden=false;return;}a.href='intl.php?buy=<?= (int)$p['id'] ?>&qty='+(q?q.value:1)+(c?'&color='+encodeURIComponent(c.value):'')+(s?'&size='+encodeURIComponent(s.value):'');});})();</script>
      <?php endif; ?>
      <button type="button" class="pi-wish wish-btn-lg <?= inWishlist((int)$p['id']) ? 'active' : '' ?>" data-wish="<?= (int)$p['id'] ?>"><?= icon('heart', 17) ?> <?= tx('إضافة إلى المفضلة', 'Ajouter aux favoris') ?></button>

      <ul class="pi-perks">
        <li><?= icon('truck', 19) ?> <?= tx('توصيل إلى 58 ولاية', 'Livraison dans les 58 wilayas') ?></li>
        <li><?= icon('cash', 19) ?> <?= tx('الدفع عند الاستلام', 'Paiement à la livraison') ?></li>
      </ul>
      <div class="pi-meta">
        <?php if ($cat): ?><div><b><?= tx('التصنيف:', 'Catégorie :') ?></b> <a href="shop.php?cat=<?= (int)$cat['id'] ?>"><?= e(tr($cat, 'name')) ?></a></div><?php endif; ?>
        <div class="pi-share"><b><?= tx('مشاركة:', 'Partager :') ?></b>
          <?php $shareUrl = rawurlencode((isset($_SERVER['HTTP_HOST']) ? ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] : '') . ($_SERVER['REQUEST_URI'] ?? '')); ?>
          <a href="https://www.facebook.com/sharer/sharer.php?u=<?= $shareUrl ?>" target="_blank" rel="noopener" aria-label="Facebook"><?= icon('facebook', 15) ?></a>
          <a href="https://wa.me/?text=<?= $shareUrl ?>" target="_blank" rel="noopener" aria-label="WhatsApp"><?= icon('whatsapp', 15) ?></a>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="sp-tabs" id="tabs">
  <div class="container">
    <div class="tab-heads" role="tablist">
      <button type="button" class="on" data-tab="tDesc" role="tab"><?= tx('الوصف', 'Description') ?></button>
      <?php if ($reviewsOn): ?><button type="button" data-tab="tRev" role="tab"><?= tx('التقييمات', 'Avis') ?> (<?= $rc ?>)</button><?php endif; ?>
    </div>
    <div class="tab-panel" id="tDesc"><?php if (trim($pDesc) !== ''): ?><div class="pi-desc"><?= nl2br(e($pDesc)) ?></div><?php endif; ?></div>
    <?php if ($reviewsOn): ?>
    <div class="tab-panel" id="tRev" hidden>
  <div class="reviews-layout">
    <div>
      <div class="section-head"><h2><?= tx('آراء العملاء', 'Avis clients') ?> <?php if ($rc): ?><small><?= stars((float)$p['rating'], 16) ?> <?= e($p['rating']) ?> <?= tx('من 5', 'sur 5') ?> (<?= $rc ?>)</small><?php endif; ?></h2></div>
      <?php if ($flash): ?><div class="alert alert-<?= $flash[1] === 'error' ? 'error' : 'success' ?>"><?= e($flash[0]) ?></div><?php endif; ?>
      <?php if ($reviewMsg): ?><div class="alert alert-<?= $reviewMsg[1] ?>"><?= e($reviewMsg[0]) ?></div><?php endif; ?>
      <?php foreach ($reviews as $r): ?>
        <div class="review">
          <div class="review-head"><strong><?= e($r['name']) ?></strong> <?= stars((float)$r['rating'], 13) ?> <span class="muted small"><?= e(substr($r['created_at'], 0, 10)) ?></span></div>
          <?php if ($r['comment']): ?><p><?= nl2br(e($r['comment'])) ?></p><?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if (!$reviews): ?><p class="muted"><?= tx('لا توجد تقييمات بعد — كن أول من يقيّم هذا المنتج.', 'Aucun avis pour le moment — soyez le premier à donner votre avis.') ?></p><?php endif; ?>
    </div>
    <form method="post" class="card review-form">
      <h3><?= tx('أضف تقييمك', 'Donnez votre avis') ?></h3>
      <?= csrfField() ?><input type="hidden" name="action" value="review">
      <div class="star-input" role="radiogroup" aria-label="<?= tx('التقييم', 'Note') ?>">
        <?php for ($i = 5; $i >= 1; $i--): ?><input type="radio" name="rating" id="r<?= $i ?>" value="<?= $i ?>"><label for="r<?= $i ?>" title="<?= $i ?> <?= tx('نجوم', 'étoiles') ?>"><?= icon('star', 26) ?></label><?php endfor; ?>
      </div>
      <label><?= tx('الاسم', 'Nom') ?><input type="text" name="name" maxlength="60" required></label>
      <label><?= tx('تعليقك (اختياري)', 'Votre commentaire (facultatif)') ?><textarea name="comment" rows="3" maxlength="1000"></textarea></label>
      <input type="text" name="website" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true">
      <button class="btn btn-dark btn-block"><?= tx('إرسال التقييم', 'Envoyer mon avis') ?></button>
    </form>
  </div>
    </div>
    <?php endif; ?>
  </div>
</section>

<?php if (!$isOut): ?>
<div class="sp-sticky" id="spSticky">
  <div class="container sp-sticky-in">
    <img src="<?= e(productThumb($p)) ?>" alt="">
    <span class="sp-name"><?= e($pName) ?></span>
    <span class="sp-price"><?php if ($discount): ?><del><?= money($p['old_price']) ?></del> <?php endif; ?><b><?= money($p['price']) ?></b></span>
    <button type="button" class="btn pi-add" onclick="document.querySelector('#addForm .pi-add').click()"><?= tx('إضافة إلى السلة', 'Ajouter au panier') ?></button>
    <button type="button" class="btn pi-buy" onclick="document.getElementById('buyNow').click()"><?= tx('شراء الآن', 'Acheter') ?></button>
  </div>
</div>
<script>(function(){var bar=document.getElementById('spSticky'),f=document.getElementById('addForm');if(!('IntersectionObserver' in window))return;new IntersectionObserver(function(e){bar.classList.toggle('show',!e[0].isIntersecting&&e[0].boundingClientRect.top<0)}).observe(f);})();</script>
<?php endif; ?>
<script>(function(){document.querySelectorAll('.tab-heads button').forEach(function(b){b.onclick=function(){document.querySelectorAll('.tab-heads button').forEach(function(x){x.classList.toggle('on',x===b)});document.querySelectorAll('.tab-panel').forEach(function(p){p.hidden=p.id!==b.dataset.tab})}});
if(location.hash==='#reviews'||document.querySelector('#tRev .alert')){var r=document.querySelector('[data-tab=tRev]');if(r)r.click()}})();</script>

<?php if ($related): ?>
<section class="section">
  <div class="container">
    <div class="sec-head"><h2><?= tx('منتجات ذات صلة', 'Produits similaires') ?></h2></div>
    <div class="products-grid">
      <?php foreach ($related as $p) include __DIR__ . '/includes/product-card.php'; ?>
    </div>
  </div>
</section>
<?php endif; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
