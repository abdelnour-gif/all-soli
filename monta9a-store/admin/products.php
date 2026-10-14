<?php
require_once __DIR__ . '/_init.php';
requireAdmin();
$db = getDB();

if (adminPost()) {
    $pid = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';
    if ($action === 'delete') {
        $st = $db->prepare('SELECT images FROM products WHERE id = ?');
        $st->execute([$pid]);
        foreach (json_decode((string)$st->fetchColumn(), true) ?: [] as $img) deleteUpload($img);
        $db->prepare('DELETE FROM products WHERE id = ?')->execute([$pid]);
        flash('تم حذف المنتج');
    } elseif ($action === 'toggle') {
        $db->prepare('UPDATE products SET active = 1 - active WHERE id = ?')->execute([$pid]);
        flash('تم تحديث حالة المنتج');
    } elseif ($action === 'stock') {
        $db->prepare('UPDATE products SET stock = ? WHERE id = ?')->execute([max(0, (int)($_POST['stock'] ?? '')), $pid]);
        flash('تم تحديث المخزون');
    }
    redirect('products.php' . (!empty($_GET) ? '?' . http_build_query($_GET) : ''));
}

$q = trim((string)($_GET['q'] ?? ''));
$cat = (int)($_GET['cat'] ?? 0);
$where = [];
$params = [];
if ($q !== '') { $where[] = '(p.name LIKE ? OR p.sku LIKE ? OR c.name LIKE ?)'; array_push($params, "%$q%", "%$q%", "%$q%"); }
if ($cat) { $where[] = 'p.category_id IN (' . implode(',', array_map('intval', categoryWithChildren($cat))) . ')'; }
$st = $db->prepare(productSelect() . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY p.id DESC');
$st->execute($params);
$products = $st->fetchAll();

adminHeader('المنتجات', 'products');
?>
<div class="toolbar">
  <form class="search" method="get">
    <select name="cat" onchange="this.form.submit()">
      <option value="">كل الأقسام</option>
      <?= categoryOptions($cat) ?>
    </select>
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="بحث عن منتج">
    <button class="btn btn-dark">بحث</button>
  </form>
  <a href="product-edit.php" class="btn btn-gold">+ إضافة منتج</a>
</div>
<div class="panel">
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th></th><th>المنتج</th><th>القسم</th><th>الألوان</th><th>السعر</th><th>المخزون</th><th>الحالة</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($products as $p): ?>
      <tr class="<?= $p['active'] ? '' : 'dim' ?>">
        <td><img src="../<?= e(productThumb($p)) ?>" class="thumb" alt=""></td>
        <td><b><?= e($p['name']) ?></b><?php if ($p['sku']): ?><br><small>المرجع: <?= e($p['sku']) ?></small><?php endif; ?><?php if ($p['reviews_count']): ?><br><small><?= stars((float)$p['rating'], 11) ?> (<?= (int)$p['reviews_count'] ?>)</small><?php endif; ?>
          <?php if ($p['is_featured']): ?><span class="tag">مميز</span><?php endif; ?><?php if ($p['is_new']): ?><span class="tag">جديد</span><?php endif; ?></td>
        <td><?= e($p['category'] ?? '—') ?></td>
        <td><?php foreach (productColors($p) as $c): ?><span class="dot" style="background:<?= e($c) ?>" title="<?= e(colorName($c)) ?>"></span><?php endforeach; ?><?= productColors($p) ? '' : '—' ?></td>
        <td><?= money($p['price']) ?><?php if ($p['old_price'] > $p['price']): ?><br><del><small><?= money($p['old_price']) ?></small></del><?php endif; ?></td>
        <td>
          <form method="post" class="inline stock-form"><?= csrfField() ?><input type="hidden" name="action" value="stock"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <input type="number" name="stock" value="<?= (int)$p['stock'] ?>" min="0" class="<?= $p['stock'] <= 2 ? 'low' : '' ?>" onchange="this.form.submit()" aria-label="المخزون">
          </form>
        </td>
        <td>
          <form method="post" class="inline"><?= csrfField() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <button class="pill <?= $p['active'] ? 'st-delivered' : 'st-cancelled' ?>"><?= $p['active'] ? 'ظاهر' : 'مخفي' ?></button>
          </form>
        </td>
        <td class="actions">
          <a href="product-edit.php?id=<?= (int)$p['id'] ?>" class="btn btn-light btn-sm">تعديل</a>
          <a href="../product.php?id=<?= (int)$p['id'] ?>" target="_blank" class="btn btn-light btn-sm">عرض</a>
          <form method="post" class="inline" data-confirm="حذف هذا المنتج نهائياً؟"><?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn btn-danger btn-sm">حذف</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$products): ?><tr><td colspan="8" class="empty">لا توجد منتجات</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
<?php adminFooter(); ?>
