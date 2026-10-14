<?php
require_once __DIR__ . '/_init.php';
requireAdmin();
$db = getDB();
if (adminPost()) {
    $rid = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';
    if ($action === 'approve') $db->prepare('UPDATE reviews SET approved = 1 WHERE id = ?')->execute([$rid]);
    if ($action === 'hide') $db->prepare('UPDATE reviews SET approved = 0 WHERE id = ?')->execute([$rid]);
    if ($action === 'delete') $db->prepare('DELETE FROM reviews WHERE id = ?')->execute([$rid]);
    if ($action === 'approve_all') $db->exec('UPDATE reviews SET approved = 1 WHERE approved = 0');
    flash('تم التحديث');
    redirect('reviews.php' . (isset($_GET['all']) ? '?all=1' : ''));
}
$all = isset($_GET['all']);
$reviews = $db->query('SELECT r.*, p.name AS product FROM reviews r LEFT JOIN products p ON p.id = r.product_id ' . ($all ? '' : 'WHERE r.approved = 0 ') . 'ORDER BY r.id DESC LIMIT 300')->fetchAll();
$pending = (int)$db->query('SELECT COUNT(*) FROM reviews WHERE approved = 0')->fetchColumn();
adminHeader('تقييمات العملاء', 'reviews');
?>
<div class="toolbar">
  <div class="tabs">
    <a href="reviews.php" class="<?= $all ? '' : 'active' ?>">بانتظار الموافقة (<?= $pending ?>)</a>
    <a href="reviews.php?all=1" class="<?= $all ? 'active' : '' ?>">كل التقييمات</a>
  </div>
  <?php if ($pending): ?><form method="post"><?= csrfField() ?><input type="hidden" name="action" value="approve_all"><button class="btn btn-gold">الموافقة على الكل</button></form><?php endif; ?>
</div>
<p class="hint">التقييمات يكتبها الزبائن في صفحة المنتج. <?= setting('reviews_moderate') === '1' ? 'لا تظهر في المتجر إلا بعد موافقتك.' : 'تظهر مباشرة (المراجعة معطّلة في الإعدادات).' ?> النجوم في بطاقات المنتجات تُحسب من التقييمات الموافق عليها فقط.</p>
<?php foreach ($reviews as $r): ?>
<div class="panel msg <?= $r['approved'] ? '' : 'unread' ?>">
  <div class="panel-head">
    <h2><?= e($r['name']) ?> <?= stars((float)$r['rating'], 14) ?> <?php if (!$r['approved']): ?><span class="tag">بانتظار الموافقة</span><?php endif; ?></h2>
    <small class="muted"><?= e($r['created_at']) ?></small>
  </div>
  <p class="msg-meta">على المنتج: <a href="../product.php?id=<?= (int)$r['product_id'] ?>" target="_blank"><b><?= e($r['product'] ?? 'منتج محذوف') ?></b></a></p>
  <?php if ($r['comment']): ?><p><?= nl2br(e($r['comment'])) ?></p><?php endif; ?>
  <div class="btn-row">
    <form method="post"><?= csrfField() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
      <?php if ($r['approved']): ?><input type="hidden" name="action" value="hide"><button class="btn btn-light btn-sm">إخفاء</button>
      <?php else: ?><input type="hidden" name="action" value="approve"><button class="btn btn-dark btn-sm">موافقة ونشر</button><?php endif; ?>
    </form>
    <form method="post" data-confirm="حذف التقييم؟"><?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-danger btn-sm">حذف</button></form>
  </div>
</div>
<?php endforeach; ?>
<?php if (!$reviews): ?><div class="panel"><p class="empty"><?= $all ? 'لا توجد تقييمات بعد' : 'لا توجد تقييمات بانتظار الموافقة' ?></p></div><?php endif; ?>
<?php adminFooter(); ?>
