<?php
require_once __DIR__ . '/_init.php';
require_once dirname(__DIR__) . '/includes/courier.php';
requireAdmin();
$courierOn = courierConfigured();
$db = getDB();
$statuses = orderStatuses();

// CSV export
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="orders-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    csvRow($out, ['#', 'التاريخ', 'الاسم', 'الهاتف', 'الولاية', 'البلدية', 'العنوان', 'التوصيل', 'المنتجات', 'سعر التوصيل', 'تغليف هدية', 'رسالة البطاقة', 'الإجمالي', 'الحالة', 'رقم التتبع', 'ملاحظات']);
    $items = $db->prepare('SELECT product_name, color, qty FROM order_items WHERE order_id = ?');
    foreach ($db->query('SELECT * FROM orders ORDER BY id DESC') as $o) {
        $items->execute([$o['id']]);
        $list = implode(' | ', array_map(fn($i) => $i['product_name'] . ($i['color'] ? ' [' . $i['color'] . ']' : '') . ' x' . $i['qty'], $items->fetchAll()));
        csvRow($out, [$o['id'], $o['created_at'], $o['customer_name'], $o['phone'], $o['wilaya_name'], $o['commune'], $o['address'], $o['delivery_type'] === 'office' ? 'مكتب' : 'منزل', $list, $o['delivery_price'], $o['gift_wrap'] ? 'نعم (' . $o['gift_price'] . ')' : '', $o['gift_message'], $o['total'], $statuses[$o['status']] ?? $o['status'], hasTracking($o['tracking']) ? $o['tracking'] : '', $o['notes']]);
    }
    exit;
}

$status = isset($statuses[$_GET['status'] ?? '']) ? $_GET['status'] : '';
$q = trim((string)($_GET['q'] ?? ''));
$where = [];
$params = [];
if ($status) { $where[] = 'status = ?'; $params[] = $status; }
if (($_GET['pay'] ?? '') === 'paypal') { $where[] = "payment_method = 'paypal'"; }
if ($q !== '') { $where[] = '(customer_name LIKE ? OR phone LIKE ? OR id = ? OR tracking = ?)'; array_push($params, "%$q%", "%$q%", (int)$q, $q); }
$sql = 'SELECT o.*, (SELECT SUM(qty) FROM order_items WHERE order_id = o.id) AS items FROM orders o' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC LIMIT 300';
$st = $db->prepare($sql);
$st->execute($params);
$orders = $st->fetchAll();
$counts = $db->query('SELECT status, COUNT(*) c FROM orders GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);

adminHeader('الطلبات', 'orders');
?>
<div class="toolbar">
  <div class="tabs">
    <a href="orders.php" class="<?= !$status ? 'active' : '' ?>">الكل (<?= array_sum($counts) ?>)</a>
    <?php foreach ($statuses as $k => $label): ?>
      <a href="orders.php?status=<?= $k ?>" class="<?= $status === $k ? 'active' : '' ?>"><?= $label ?> (<?= (int)($counts[$k] ?? 0) ?>)</a>
    <?php endforeach; ?>
  </div>
  <form class="search" method="get">
    <?php if ($status): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="بحث بالاسم، الهاتف، رقم الطلب أو التتبع">
    <button class="btn btn-dark">بحث</button>
    <a href="orders.php?export=csv" class="btn btn-light">تصدير CSV</a>
  </form>
</div>
<div id="shipNotice" hidden></div>
<div class="panel">
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th>#</th><th>التاريخ</th><th>الزبون</th><th>باكرز</th><th>الولاية</th><th>القطع</th><th>الإجمالي</th><th>الحالة</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($orders as $o): ?>
      <tr>
        <td><?= (int)$o['id'] ?></td>
        <td><small><?= e(substr($o['created_at'], 0, 16)) ?></small></td>
        <td><?= e($o['customer_name']) ?><br><a href="tel:<?= e($o['phone']) ?>" dir="ltr"><small><?= e($o['phone']) ?></small></a></td>
        <td class="ship-cell">
          <?php if (hasTracking($o['tracking'])): ?>
            <small class="tracking" dir="ltr" title="رقم التتبع لدى <?= e(courierName(orderCourier($o))) ?>">📦 <?= e($o['tracking']) ?></small>
          <?php elseif (($o['delivery_type'] ?? '') === 'intl'): ?><small class="muted" title="طلب من الخارج — يُشحن يدوياً">🌍 دولي</small>
          <?php elseif ($courierOn && !in_array($o['status'], ['cancelled', 'returned', 'delivered'], true)): ?>
            <form method="post" action="order.php?id=<?= (int)$o['id'] ?>" class="inline ship-form">
              <?= csrfField() ?><input type="hidden" name="action" value="ship"><input type="hidden" name="back" value="list">
              <button class="btn btn-gold btn-sm">🚚 إرسال</button>
            </form>
          <?php else: ?><small class="muted">—</small><?php endif; ?>
        </td>
        <td><?= e($o['wilaya_name']) ?><br><small><?= e($o['commune']) ?></small></td>
        <td><?= (int)$o['items'] ?></td>
        <td><b><?= money($o['total']) ?></b><?= $o['gift_wrap'] ? ' <span title="تغليف هدية">🎁</span>' : '' ?><?php if (($o['payment_method'] ?? '') === 'paypal'): ?><br><small class="pp-paid" dir="ltr">PayPal ✔ <?= e($o['pay_amount']) ?> <?= e($o['pay_currency']) ?></small><?php endif; ?></td>
        <td>
          <form method="post" action="order.php?id=<?= (int)$o['id'] ?>" class="inline">
            <?= csrfField() ?><input type="hidden" name="action" value="status"><input type="hidden" name="back" value="list">
            <select name="status" onchange="this.form.submit()" class="pill-select st-<?= e($o['status']) ?>">
              <?php foreach ($statuses as $k => $label): ?><option value="<?= $k ?>" <?= $o['status'] === $k ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
            </select>
          </form>
        </td>
        <td><a href="order.php?id=<?= (int)$o['id'] ?>" class="btn btn-light btn-sm">التفاصيل</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$orders): ?><tr><td colspan="9" class="empty">لا توجد طلبات</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
<script>
// Send to Packers from the list without leaving the page:
// first tap asks for confirmation inside the button, second tap sends, and the row shows the result.
document.querySelectorAll('.ship-form').forEach(function (form) {
  var btn = form.querySelector('button'), label = btn.textContent, armed = null;
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (btn.disabled) return;
    if (!armed) {
      btn.textContent = 'تأكيد الإرسال؟';
      btn.classList.add('btn-dark');
      armed = setTimeout(function () { armed = null; btn.textContent = label; btn.classList.remove('btn-dark'); }, 4000);
      return;
    }
    clearTimeout(armed); armed = null;
    btn.disabled = true; btn.classList.remove('btn-dark'); btn.textContent = '⏳ جاري الإرسال...';
    var data = new FormData(form); data.append('ajax', '1');
    fetch(form.getAttribute('action'), { method: 'POST', body: data, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        var cell = form.closest('td');
        if (res.ok) {
          cell.innerHTML = '<small class="tracking" dir="ltr">📦 ' + String(res.tracking).replace(/[<>&"]/g, '') + '</small>';
          var sel = cell.closest('tr').querySelector('select[name="status"]');
          if (sel && res.status) { sel.value = res.status; sel.className = 'pill-select st-' + res.status; }
          adminNotice(res.message, false);
        } else if (res.needs_choice) {
          adminNotice(res.message + ' — جاري فتح صفحة الطلب...', true);
          setTimeout(function () { location.href = res.redirect; }, 1500);
        } else {
          btn.disabled = false; btn.textContent = label;
          adminNotice(res.message, true);
        }
      })
      .catch(function () {
        // Unknown outcome (connection cut): reload so the list shows the real state.
        adminNotice('انقطع الاتصال أثناء الإرسال — جاري تحديث الصفحة للتحقق...', true);
        setTimeout(function () { location.reload(); }, 1500);
      });
  });
});
function adminNotice(msg, isError) {
  var box = document.getElementById('shipNotice');
  box.className = 'alert ' + (isError ? 'alert-error' : 'alert-success');
  box.textContent = msg; box.hidden = false;
  box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}
</script>
<?php adminFooter(); ?>
