<?php
$pageTitle = null;
require_once __DIR__ . '/includes/functions.php';
if (offerOnly()) redirect('index.php#order');
$pageTitle = tx('سلة التسوق', 'Mon panier');
require_once __DIR__ . '/includes/header.php';
$lines = cartLines();
$subtotal = array_sum(array_column($lines, 'total'));
$freeMin = (int)setting('free_shipping_min', '0');
?>
<section class="page-head">
  <div class="container">
    <nav class="breadcrumb"><a href="index.php"><?= tx('الرئيسية', 'Accueil') ?></a> / <span><?= tx('سلة التسوق', 'Mon panier') ?></span></nav>
    <h1><?= tx('سلة التسوق', 'Mon panier') ?></h1>
  </div>
</section>

<section class="section">
  <div class="container">
  <?php if (!$lines): ?>
    <div class="empty-state">
      <?= icon('cart', 56) ?>
      <h3><?= tx('سلتك فارغة', 'Votre panier est vide') ?></h3>
      <p><?= tx('لم تضف أي منتج بعد. تصفّح منتجاتنا واختر ما يناسبك.', 'Vous n\'avez encore rien ajouté. Parcourez nos produits.') ?></p>
      <a href="shop.php" class="btn btn-dark"><?= tx('تسوق الآن', 'Découvrir la boutique') ?></a>
    </div>
  <?php else: ?>
    <div class="cart-layout">
      <div class="cart-items">
        <?php foreach ($lines as $l): $p = $l['product']; ?>
        <div class="cart-item" data-key="<?= e($l['key']) ?>">
          <a href="product.php?id=<?= (int)$p['id'] ?>" class="ci-img"><img src="<?= e(productThumb($p)) ?>" alt="<?= e(tr($p, 'name')) ?>"></a>
          <div class="ci-info">
            <span class="p-cat"><?= e(trim((string)($p['brand'] ?? '')) !== '' ? $p['brand'] : (isFr() && !empty($p['category_fr']) ? $p['category_fr'] : ($p['category'] ?? ''))) ?></span>
            <a href="product.php?id=<?= (int)$p['id'] ?>" class="ci-name"><?= e(tr($p, 'name')) ?></a>
            <?php if ($l['color']): ?><span class="ci-color"><span class="dot" style="background:<?= e($l['color']) ?>"></span> <?= e(colorLabel($l['color'])) ?></span><?php endif; ?>
            <?php if (!empty($l['size'])): ?><span class="ci-color"><?= tx('المقاس:', 'Taille :') ?> <b><?= e($l['size']) ?></b></span><?php endif; ?>
            <span class="ci-price"><?= money($p['price']) ?></span>
          </div>
          <div class="qty qty-sm">
            <button type="button" data-cart-qty="-1" aria-label="<?= tx('إنقاص', 'Moins') ?>">−</button>
            <input type="number" value="<?= $l['qty'] ?>" min="1" max="<?= max(1, min(20, (int)$p['stock'])) ?>" data-cart-input aria-label="<?= tx('الكمية', 'Quantité') ?>">
            <button type="button" data-cart-qty="1" aria-label="<?= tx('زيادة', 'Plus') ?>">+</button>
          </div>
          <strong class="ci-total"><?= money($l['total']) ?></strong>
          <button class="icon-btn ci-remove" data-cart-remove aria-label="<?= tx('حذف', 'Supprimer') ?>"><?= icon('trash', 18) ?></button>
        </div>
        <?php endforeach; ?>
        <a href="shop.php" class="btn btn-light"><span class="flip"><?= icon('arrow-l', 16) ?></span> <?= tx('متابعة التسوق', 'Continuer mes achats') ?></a>
      </div>

      <aside class="summary">
        <h3><?= tx('ملخص الطلب', 'Récapitulatif') ?></h3>
        <div class="sum-row"><span><?= tx('المجموع الفرعي', 'Sous-total') ?></span><strong><?= money($subtotal) ?></strong></div>
        <div class="sum-row muted"><span><?= tx('التوصيل', 'Livraison') ?></span><span><?= tx('يحدد حسب الولاية', 'Selon la wilaya') ?></span></div>
        <?php if ($freeMin > 0): ?>
          <?php if ($subtotal >= $freeMin): ?>
            <div class="free-note ok"><?= icon('check', 16) ?> <?= tx('طلبك مؤهل للتوصيل المجاني', 'Livraison gratuite pour votre commande') ?></div>
          <?php else: ?>
            <div class="free-note"><?= tx('أضف ', 'Ajoutez ') ?><?= money($freeMin - $subtotal) ?><?= tx(' للحصول على توصيل مجاني', ' pour la livraison gratuite') ?></div>
          <?php endif; ?>
        <?php endif; ?>
        <a href="checkout.php" class="btn btn-dark btn-lg btn-block"><?= tx('إتمام الطلب', 'Passer la commande') ?></a>
        <?php require_once __DIR__ . '/includes/paypal.php'; if (ppEnabled()): ?>
        <a href="intl.php" class="btn btn-lg btn-block btn-intl">🌍 <?= tx('من خارج الجزائر؟ ادفع بـ PayPal', 'Hors d\'Algérie ? Payer avec PayPal') ?></a>
        <?php endif; ?>
      </aside>
    </div>
  <?php endif; ?>
  </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
