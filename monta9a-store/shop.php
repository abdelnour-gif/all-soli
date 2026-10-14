<?php
require_once __DIR__ . '/includes/functions.php';
if (offerOnly()) redirect('index.php#order');
$db = getDB();

$catId   = (int)($_GET['cat'] ?? 0);
$cat     = $catId ? getCategory($catId) : null;
// A category the owner switched off must not keep a public page.
if ($cat && !(int)$cat['active']) { $cat = null; $catId = 0; }
$q       = trim((string)($_GET['q'] ?? ''));
$sale    = !empty($_GET['sale']);
$brand   = trim((string)($_GET['brand'] ?? ''));
$min     = (int)($_GET['min'] ?? 0);
$max     = (int)($_GET['max'] ?? 0);
$sort    = (string)($_GET['sort'] ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 12;

$where = ['p.active = 1'];
$params = [];
if ($cat) {
    $ids = categoryWithChildren($catId);
    $where[] = 'p.category_id IN (' . implode(',', array_map('intval', $ids)) . ')';
}
if ($sale)    { $where[] = 'p.old_price > p.price'; }
if ($brand !== '') { $where[] = 'LOWER(p.brand) = LOWER(?)'; $params[] = $brand; }
if ($min > 0) { $where[] = 'p.price >= ?'; $params[] = $min; }
if ($max > 0) { $where[] = 'p.price <= ?'; $params[] = $max; }
if ($q !== '') {
    $where[] = '(p.name LIKE ? OR p.name_fr LIKE ? OR p.brand LIKE ? OR p.sku LIKE ? OR c.name LIKE ? OR c.name_fr LIKE ? OR p.description LIKE ? OR p.description_fr LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like, $like, $like, $like);
}
$orderBy = [
    'price_asc' => 'p.price ASC', 'price_desc' => 'p.price DESC', 'new' => 'p.created_at DESC, p.id DESC',
    'rating' => 'rating DESC, reviews_count DESC',
][$sort] ?? 'p.is_featured DESC, p.id DESC';

$whereSql = implode(' AND ', $where);
$st = $db->prepare("SELECT COUNT(*) FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE $whereSql");
$st->execute($params);
$total = (int)$st->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);

$st = $db->prepare(productSelect() . " WHERE $whereSql ORDER BY $orderBy LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
$st->execute($params);
$products = $st->fetchAll();

$title = $q !== '' ? tx('نتائج البحث عن: ', 'Résultats pour : ') . $q
    : ($cat ? tr($cat, 'name') : ($brand !== '' ? $brand : ($sale ? st('sale_title', tx('التخفيضات', 'Promotions')) : tx('جميع المنتجات', 'Tous les produits'))));
$pageTitle = $title;
$activeNav = $sale ? 'sale' : ($cat ? 'cat' . ((int)$cat['parent_id'] ?: (int)$cat['id']) : ($brand !== '' ? 'brand' : 'shop'));
$parent = ($cat && $cat['parent_id']) ? getCategory((int)$cat['parent_id']) : null;
$subCats = $cat ? childCategories((int)$cat['id']) : [];

function qs(array $change): string {
    $params = array_merge($_GET, $change);
    return 'shop.php?' . http_build_query(array_filter($params, fn($v) => $v !== '' && $v !== null && $v !== 0));
}

require_once __DIR__ . '/includes/header.php';
?>
<section class="page-head">
  <div class="container">
    <nav class="breadcrumb"><a href="index.php"><?= tx('الرئيسية', 'Accueil') ?></a> /
      <?php if ($parent): ?><a href="shop.php?cat=<?= (int)$parent['id'] ?>"><?= e(tr($parent, 'name')) ?></a> / <?php endif; ?>
      <span><?= e($title) ?></span></nav>
    <h1><?= e($title) ?></h1>
    <?php if ($cat && tr($cat, 'description') !== ''): ?><p class="muted"><?= e(tr($cat, 'description')) ?></p><?php endif; ?>
    <?php if ($subCats): ?>
      <div class="chips">
        <a href="shop.php?cat=<?= (int)$cat['id'] ?>" class="chip active"><?= tx('الكل', 'Tout') ?></a>
        <?php foreach ($subCats as $sc): ?><a href="shop.php?cat=<?= (int)$sc['id'] ?>" class="chip"><?= e(tr($sc, 'name')) ?></a><?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<section class="section">
  <div class="container shop-layout">
    <aside class="filters" id="filters">
      <div class="filters-head"><strong><?= tx('تصفية النتائج', 'Filtrer') ?></strong><button class="icon-btn only-mobile" id="filtersClose" aria-label="<?= tx('إغلاق', 'Fermer') ?>"><?= icon('x', 20) ?></button></div>
      <form method="get" action="shop.php">
        <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>
        <div class="f-group">
          <h4><?= tx('الأقسام', 'Catégories') ?></h4>
          <label><input type="radio" name="cat" value="" <?= !$cat ? 'checked' : '' ?>> <?= tx('الكل', 'Tout') ?></label>
          <?php foreach (topCategories() as $tc): ?>
            <label><input type="radio" name="cat" value="<?= (int)$tc['id'] ?>" <?= $catId === (int)$tc['id'] ? 'checked' : '' ?>> <?= e(tr($tc, 'name')) ?></label>
            <?php foreach (childCategories((int)$tc['id']) as $cc): ?>
              <label class="sub"><input type="radio" name="cat" value="<?= (int)$cc['id'] ?>" <?= $catId === (int)$cc['id'] ? 'checked' : '' ?>> <?= e(tr($cc, 'name')) ?></label>
            <?php endforeach; ?>
          <?php endforeach; ?>
        </div>
        <?php $brandOpts = array_values(array_filter(array_map('trim', $db->query("SELECT DISTINCT brand FROM products WHERE active = 1 AND TRIM(COALESCE(brand, '')) != '' ORDER BY brand")->fetchAll(PDO::FETCH_COLUMN)))); ?>
        <?php if ($brandOpts): ?>
        <div class="f-group">
          <h4><?= tx('العلامة التجارية', 'Marque') ?></h4>
          <select name="brand">
            <option value=""><?= tx('كل العلامات', 'Toutes les marques') ?></option>
            <?php foreach ($brandOpts as $bo): ?><option value="<?= e($bo) ?>" <?= strcasecmp($bo, $brand) === 0 ? 'selected' : '' ?>><?= e($bo) ?></option><?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <div class="f-group">
          <h4><?= tx('السعر (د.ج)', 'Prix (DA)') ?></h4>
          <div class="price-range">
            <input type="number" name="min" min="0" placeholder="<?= tx('من', 'Min') ?>" value="<?= $min ?: '' ?>">
            <input type="number" name="max" min="0" placeholder="<?= tx('إلى', 'Max') ?>" value="<?= $max ?: '' ?>">
          </div>
        </div>
        <div class="f-group">
          <label><input type="checkbox" name="sale" value="1" <?= $sale ? 'checked' : '' ?>> <?= tx('العروض والتخفيضات فقط', 'Promotions uniquement') ?></label>
        </div>
        <input type="hidden" name="sort" value="<?= e($sort) ?>">
        <button class="btn btn-dark btn-block" type="submit"><?= tx('تطبيق', 'Appliquer') ?></button>
        <a href="shop.php" class="btn btn-light btn-block"><?= tx('مسح الفلاتر', 'Effacer les filtres') ?></a>
      </form>
    </aside>

    <div class="shop-main">
      <div class="shop-toolbar">
        <button class="btn btn-light only-mobile" id="filtersOpen"><?= tx('تصفية', 'Filtrer') ?></button>
        <span class="muted"><?= $total ?> <?= tx('منتج', $total > 1 ? 'produits' : 'produit') ?></span>
        <form method="get" class="sort-form">
          <?php foreach ($_GET as $k => $v): if ($k !== 'sort' && $k !== 'page' && is_string($v)): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php endif; endforeach; ?>
          <select name="sort" onchange="this.form.submit()" aria-label="<?= tx('ترتيب', 'Trier') ?>">
            <option value=""><?= tx('الترتيب: الأكثر مبيعاً', 'Trier : meilleures ventes') ?></option>
            <option value="new" <?= $sort === 'new' ? 'selected' : '' ?>><?= tx('الأحدث', 'Nouveautés') ?></option>
            <option value="price_asc" <?= $sort === 'price_asc' ? 'selected' : '' ?>><?= tx('السعر: من الأقل', 'Prix croissant') ?></option>
            <option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : '' ?>><?= tx('السعر: من الأعلى', 'Prix décroissant') ?></option>
            <option value="rating" <?= $sort === 'rating' ? 'selected' : '' ?>><?= tx('الأعلى تقييماً', 'Mieux notés') ?></option>
          </select>
        </form>
      </div>

      <?php if ($products): ?>
        <div class="products-grid grid-4">
          <?php foreach ($products as $p) include __DIR__ . '/includes/product-card.php'; ?>
        </div>
        <?php if ($pages > 1): ?>
        <nav class="pagination">
          <?php for ($i = 1; $i <= $pages; $i++): ?><a href="<?= e(qs(['page' => $i])) ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a><?php endfor; ?>
        </nav>
        <?php endif; ?>
      <?php else: ?>
        <div class="empty-state">
          <?= icon('search', 48) ?>
          <h3><?= tx('لا توجد منتجات مطابقة', 'Aucun produit trouvé') ?></h3>
          <p><?= tx('جرّب تغيير الفلاتر أو البحث بكلمة أخرى.', 'Essayez de modifier les filtres ou un autre mot-clé.') ?></p>
          <a href="shop.php" class="btn btn-dark"><?= tx('عرض كل المنتجات', 'Voir tous les produits') ?></a>
        </div>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
