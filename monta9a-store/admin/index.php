<?php
require_once __DIR__ . '/_init.php';
requireAdmin();
$db = getDB();
$stats = [
    'orders_today' => $db->query("SELECT COUNT(*) FROM orders WHERE date(created_at) = date('now')")->fetchColumn(),
    'new'          => $db->query("SELECT COUNT(*) FROM orders WHERE status = 'new'")->fetchColumn(),
    'revenue'      => $db->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status = 'delivered'")->fetchColumn(),
    'month'        => $db->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status NOT IN ('cancelled','returned') AND strftime('%Y-%m', created_at) = strftime('%Y-%m','now')")->fetchColumn(),
    'products'     => $db->query('SELECT COUNT(*) FROM products WHERE active = 1')->fetchColumn(),
    'low'          => $db->query('SELECT COUNT(*) FROM products WHERE active = 1 AND stock <= 2')->fetchColumn(),
];
$recent = $db->query('SELECT * FROM orders ORDER BY id DESC LIMIT 8')->fetchAll();
$lowStock = $db->query('SELECT id, name, stock FROM products WHERE active = 1 AND stock <= 2 ORDER BY stock LIMIT 8')->fetchAll();
$top = $db->query("SELECT product_name, SUM(qty) q FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE o.status NOT IN ('cancelled','returned') GROUP BY product_name ORDER BY q DESC LIMIT 5")->fetchAll();
$me = $db->query('SELECT password FROM admins WHERE id = ' . (int)$_SESSION['admin_id'])->fetch();
$defaultPass = password_verify(DEFAULT_ADMIN_PASSWORD, (string)$me['password']);
// The seeded testimonials are published so the homepage looks complete out of
// the box, but the owner must not ship them: warn until they are gone.
$sampleReviews = (int)$db->query("SELECT COUNT(*) FROM reviews WHERE approved = 1 AND name LIKE '%(نموذج)%'")->fetchColumn();
adminHeader('لوحة التحكم', 'dashboard');
?>
<?php if ($sampleReviews): ?>
  <div class="alert alert-error">
    <strong>تنبيه:</strong> هناك <?= $sampleReviews ?> تقييمات <strong>نموذجية</strong> (وهمية) ظاهرة الآن في الصفحة الرئيسية.
    احذفها قبل نشر المتجر وضع مكانها تقييمات زبائنك الحقيقيين —
    <a href="reviews.php">اذهب إلى التقييمات</a>.
  </div>
<?php endif; ?>
<?php
?>
<?php if ($defaultPass): ?><div class="alert alert-error">أنت تستعمل كلمة المرور الافتراضية. <a href="settings.php#account"><b>غيّرها الآن</b></a> قبل نشر الموقع.</div><?php endif; ?>
<div class="stats">
  <div class="stat"><span>طلبات اليوم</span><strong><?= (int)$stats['orders_today'] ?></strong></div>
  <div class="stat accent"><span>طلبات جديدة تنتظر التأكيد</span><strong><?= (int)$stats['new'] ?></strong></div>
  <div class="stat"><span>مبيعات هذا الشهر</span><strong><?= money($stats['month']) ?></strong></div>
  <div class="stat"><span>إيرادات الطلبات المسلّمة</span><strong><?= money($stats['revenue']) ?></strong></div>
  <div class="stat"><span>المنتجات النشطة</span><strong><?= (int)$stats['products'] ?></strong></div>
  <div class="stat <?= $stats['low'] ? 'warn' : '' ?>"><span>منتجات قاربت على النفاد</span><strong><?= (int)$stats['low'] ?></strong></div>
</div>

<div class="grid-2">
  <div class="panel">
    <div class="panel-head"><h2>آخر الطلبات</h2><a href="orders.php">عرض الكل</a></div>
    <div class="table-wrap">
    <table class="table">
      <thead><tr><th>#</th><th>الزبون</th><th>الولاية</th><th>الإجمالي</th><th>الحالة</th></tr></thead>
      <tbody>
      <?php foreach ($recent as $o): ?>
        <tr onclick="location='order.php?id=<?= (int)$o['id'] ?>'" class="clickable">
          <td><?= (int)$o['id'] ?></td><td><?= e($o['customer_name']) ?><br><small dir="ltr"><?= e($o['phone']) ?></small></td>
          <td><?= e($o['wilaya_name']) ?></td><td><?= money($o['total']) ?></td>
          <td><span class="pill st-<?= e($o['status']) ?>"><?= e(orderStatuses()[$o['status']] ?? $o['status']) ?></span></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$recent): ?><tr><td colspan="5" class="empty">لا توجد طلبات بعد</td></tr><?php endif; ?>
      </tbody>
    </table>
    </div>
  </div>
  <div>
    <div class="panel">
      <div class="panel-head"><h2>الأكثر مبيعاً</h2></div>
      <?php foreach ($top as $t): ?><div class="row-line"><span><?= e($t['product_name']) ?></span><b><?= (int)$t['q'] ?></b></div><?php endforeach; ?>
      <?php if (!$top): ?><p class="empty">لا توجد مبيعات بعد</p><?php endif; ?>
    </div>
    <div class="panel">
      <div class="panel-head"><h2>مخزون منخفض</h2><a href="products.php">المنتجات</a></div>
      <?php foreach ($lowStock as $l): ?><div class="row-line"><a href="product-edit.php?id=<?= (int)$l['id'] ?>"><?= e($l['name']) ?></a><b class="<?= $l['stock'] <= 0 ? 'red' : '' ?>"><?= (int)$l['stock'] ?></b></div><?php endforeach; ?>
      <?php if (!$lowStock): ?><p class="empty">المخزون جيد</p><?php endif; ?>
    </div>
  </div>
</div>
<?php adminFooter(); ?>
