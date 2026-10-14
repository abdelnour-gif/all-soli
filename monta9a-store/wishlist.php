<?php
require_once __DIR__ . '/includes/functions.php';
if (offerOnly()) redirect('index.php');
$pageTitle = tx('المفضلة', 'Mes favoris');
require_once __DIR__ . '/includes/header.php';
$ids = array_map('intval', wishlist());
$products = [];
if ($ids) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = getDB()->prepare(productSelect() . " WHERE p.active = 1 AND p.id IN ($in)");
    $st->execute($ids);
    $products = $st->fetchAll();
}
?>
<section class="page-head">
  <div class="container">
    <nav class="breadcrumb"><a href="index.php"><?= tx('الرئيسية', 'Accueil') ?></a> / <span><?= tx('المفضلة', 'Mes favoris') ?></span></nav>
    <h1><?= tx('المفضلة', 'Mes favoris') ?></h1>
  </div>
</section>
<section class="section">
  <div class="container">
    <?php if ($products): ?>
      <div class="products-grid grid-4"><?php foreach ($products as $p) include __DIR__ . '/includes/product-card.php'; ?></div>
    <?php else: ?>
      <div class="empty-state"><?= icon('heart', 52) ?><h3><?= tx('قائمة المفضلة فارغة', 'Aucun favori pour l\'instant') ?></h3><p><?= tx('اضغط على القلب في أي منتج لحفظه هنا.', 'Touchez le cœur d’un produit pour le retrouver ici.') ?></p><a href="shop.php" class="btn btn-dark"><?= tx('تصفح المنتجات', 'Voir les produits') ?></a></div>
    <?php endif; ?>
  </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
