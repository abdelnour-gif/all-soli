<?php
require_once __DIR__ . '/_init.php';
requireAdmin();
$db = getDB();

if (adminPost()) {
    $st = $db->prepare('UPDATE wilayas SET home_price = ?, office_price = ?, active = ? WHERE id = ?');
    $db->beginTransaction();
    foreach ((array)($_POST['w'] ?? []) as $id => $w) {
        $st->execute([max(0, (int)($w['home'] ?? 0)), max(0, (int)($w['office'] ?? 0)), isset($w['active']) ? 1 : 0, (int)$id]);
    }
    $db->commit();
    saveSetting('free_shipping_min', (string)max(0, (int)($_POST['free_shipping_min'] ?? '')));
    flash('تم حفظ أسعار التوصيل');
    redirect('delivery.php');
}
$wilayas = $db->query('SELECT * FROM wilayas ORDER BY id')->fetchAll();
adminHeader('أسعار التوصيل', 'delivery');
?>
<form method="post" class="form">
  <?= csrfField() ?>
  <div class="panel form-inline">
    <label>التوصيل مجاني للطلبات فوق (د.ج) — ضع 0 لإلغاء التوصيل المجاني:
      <input type="number" name="free_shipping_min" value="<?= e(setting('free_shipping_min', '0')) ?>" min="0" style="max-width:160px">
    </label>
    <button type="button" class="btn btn-light" id="fillAll">تطبيق نفس السعر على الكل</button>
    <button class="btn btn-gold">حفظ</button>
  </div>
  <div class="panel">
    <div class="table-wrap">
    <table class="table">
      <thead><tr><th>#</th><th>الولاية</th><th>للمنزل (د.ج)</th><th>للمكتب (د.ج)</th><th>متاحة</th></tr></thead>
      <tbody>
      <?php foreach ($wilayas as $w): ?>
        <tr>
          <td><?= (int)$w['id'] ?></td>
          <td><?= e($w['name_ar']) ?> <small class="muted"><?= e($w['name_fr']) ?></small></td>
          <td><input type="number" name="w[<?= (int)$w['id'] ?>][home]" value="<?= (int)$w['home_price'] ?>" min="0" class="home-price"></td>
          <td><input type="number" name="w[<?= (int)$w['id'] ?>][office]" value="<?= (int)$w['office_price'] ?>" min="0" class="office-price"></td>
          <td><input type="checkbox" name="w[<?= (int)$w['id'] ?>][active]" <?= $w['active'] ? 'checked' : '' ?>></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
  <button class="btn btn-gold">حفظ الأسعار</button>
</form>
<script>
document.getElementById('fillAll').onclick = function () {
  var h = prompt('سعر التوصيل للمنزل لكل الولايات:'); if (h === null) return;
  var o = prompt('سعر التوصيل للمكتب لكل الولايات:'); if (o === null) return;
  document.querySelectorAll('.home-price').forEach(function (i) { i.value = parseInt(h) || 0; });
  document.querySelectorAll('.office-price').forEach(function (i) { i.value = parseInt(o) || 0; });
};
</script>
<?php adminFooter(); ?>
