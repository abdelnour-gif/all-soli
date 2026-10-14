<?php
/** Expects $p (a row from productSelect()). */
require_once __DIR__ . "/images.php";
$pColors = productColors($p);
$pSizes = productSizes($p);
$isOut = (int)$p['stock'] <= 0;
$discount = ((int)$p['old_price'] > (int)$p['price']) ? (int)round(100 - $p['price'] * 100 / $p['old_price']) : 0;
$rc = (int)($p['reviews_count'] ?? 0);
$pName = tr($p, 'name');
$pBadge = trim(tr($p, 'badge'));
$needsChoice = count($pColors) > 1 || count($pSizes) > 1;
?>
<div class="product-card<?= $isOut ? ' is-out' : '' ?>">
  <div class="p-media">
    <a href="product.php?id=<?= (int)$p['id'] ?>" class="p-img">
      <?php $pFull = productImage($p); $pSet = imageSrcset($pFull); ?>
      <img src="<?= e(productThumb($p)) ?>"<?php if ($pSet): ?> srcset="<?= e($pSet) ?>" sizes="(max-width: 600px) 50vw, (max-width: 1000px) 33vw, 300px"<?php endif; ?> alt="<?= e($pName) ?>" loading="lazy" decoding="async">
    </a>
    <div class="p-badges">
      <?php if ($isOut): ?><span class="p-badge out"><?= tx('نفد', 'Épuisé') ?></span><?php endif; ?>
      <?php // one label per card, like the design: the product's own label, else «جديد», else the discount
      if ($pBadge !== ''): ?><span class="p-badge hot"><?= e($pBadge) ?></span><?php elseif ($p['is_new']): ?><span class="p-badge new"><?= tx('جديد', 'Nouveau') ?></span><?php elseif ($discount): ?><span class="p-badge sale">-<?= $discount ?>%</span><?php endif; ?>
    </div>
    <button class="wish-btn <?= inWishlist((int)$p['id']) ? 'active' : '' ?>" data-wish="<?= (int)$p['id'] ?>" aria-label="<?= tx('أضف إلى المفضلة', 'Ajouter aux favoris') ?>"><?= icon('heart', 18) ?></button>
    <?php if (!$isOut): ?>
    <div class="p-hover">
      <button class="btn-order" data-order="<?= e(orderData($p)) ?>"><?= tx('اطلب الآن', 'Commander') ?></button>
      <?php if ($needsChoice): ?>
        <a href="product.php?id=<?= (int)$p['id'] ?>" class="btn-add" title="<?= tx('اختر الخيارات', 'Choisir les options') ?>"><?= icon('cartsm', 17) ?></a>
      <?php else: ?>
        <button class="btn-add" data-add="<?= (int)$p['id'] ?>" data-color="<?= e($pColors[0] ?? '') ?>" data-size="<?= e($pSizes[0] ?? '') ?>" title="<?= tx('إضافة إلى السلة', 'Ajouter au panier') ?>" aria-label="<?= tx('إضافة إلى السلة', 'Ajouter au panier') ?>"><?= icon('cartsm', 17) ?></button>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
  <div class="p-info">
    <a href="product.php?id=<?= (int)$p['id'] ?>" class="p-name"><?= e($pName) ?></a>
    <span class="p-stock<?= $isOut ? ' out' : '' ?>"><?= $isOut ? icon('x', 14) . tx('غير متوفر', 'Indisponible') : icon('check', 14) . tx('متوفر', 'En stock') ?></span>
    <?php if ($rc): ?><div class="p-rating"><?= stars((float)$p['rating'], 12) ?> <span>(<?= e($p['rating']) ?>)</span></div><?php endif; ?>
    <div class="p-price"><?php if ($discount): ?><del><?= money($p['old_price']) ?></del> <?php endif; ?><span><?= money($p['price']) ?></span></div>
    <?php if (!$isOut): ?>
      <?php if ($needsChoice): ?>
      <a href="product.php?id=<?= (int)$p['id'] ?>" class="p-cart-btn"><?= icon('cartsm', 16) ?> <?= tx('أضف إلى السلة', 'Ajouter au panier') ?></a>
      <?php else: ?>
      <button class="p-cart-btn" data-add="<?= (int)$p['id'] ?>" data-color="<?= e($pColors[0] ?? '') ?>" data-size="<?= e($pSizes[0] ?? '') ?>"><?= icon('cartsm', 16) ?> <?= tx('أضف إلى السلة', 'Ajouter au panier') ?></button>
      <?php endif; ?>
      <button class="btn-order p-order-mob" data-order="<?= e(orderData($p)) ?>"><?= tx('اطلب الآن', 'Commander') ?></button>
    <?php endif; ?>
  </div>
</div>
