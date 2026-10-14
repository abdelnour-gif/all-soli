<?php
require_once __DIR__ . '/_init.php';
requireAdmin();
$db = getDB();
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="subscribers-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    csvRow($out, ['email', 'date']);
    foreach ($db->query('SELECT email, created_at FROM subscribers ORDER BY id DESC') as $r) csvRow($out, [$r['email'], $r['created_at']]);
    exit;
}
if (adminPost()) {
    $db->prepare('DELETE FROM subscribers WHERE id = ?')->execute([(int)($_POST['id'] ?? '')]);
    flash('تم الحذف');
    redirect('subscribers.php');
}
$subs = $db->query('SELECT * FROM subscribers ORDER BY id DESC')->fetchAll();
adminHeader('النشرة البريدية', 'subscribers');
?>
<div class="toolbar">
  <p class="hint" style="margin:0">المشتركون من خانة "اشترك في نشرتنا البريدية" أسفل الموقع (<?= count($subs) ?> مشترك). يمكنك تصدير القائمة واستعمالها في Gmail أو Mailchimp.</p>
  <?php if ($subs): ?><a href="subscribers.php?export=csv" class="btn btn-light">تصدير CSV</a><?php endif; ?>
</div>
<div class="panel">
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th>البريد الإلكتروني</th><th>التاريخ</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($subs as $s): ?>
      <tr><td dir="ltr" style="text-align:right"><a href="mailto:<?= e($s['email']) ?>"><?= e($s['email']) ?></a></td><td><small><?= e($s['created_at']) ?></small></td>
        <td><form method="post" data-confirm="حذف المشترك؟"><?= csrfField() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button class="btn btn-danger btn-sm">حذف</button></form></td></tr>
    <?php endforeach; ?>
    <?php if (!$subs): ?><tr><td colspan="3" class="empty">لا يوجد مشتركون بعد</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
<?php adminFooter(); ?>
