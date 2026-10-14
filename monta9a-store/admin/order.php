<?php
require_once __DIR__ . '/_init.php';
require_once dirname(__DIR__) . '/includes/courier.php';
requireAdmin();
$db = getDB();
$id = (int)($_GET['id'] ?? 0);
$st = $db->prepare('SELECT * FROM orders WHERE id = ?');
$st->execute([$id]);
$order = $st->fetch();
if (!$order) { flash('الطلب غير موجود', 'error'); redirect('orders.php'); }
$statuses = orderStatuses();
$communeChoices = null;   // filled when the delivery company does not recognise the commune
$choiceKind = 'commune';  // 'desk' when office pickup is impossible in the chosen commune

if (adminPost()) {
    $action = $_POST['action'] ?? '';
    $back = ($_POST['back'] ?? '') === 'list' ? 'orders.php' : 'order.php?id=' . $id;
    if ($action === 'ship') {
        // The orders list sends with fetch() and expects JSON; the order page posts a normal form.
        $ajax = ($_POST['ajax'] ?? '') === '1';
        $reply = function (bool $ok, string $msg, string $to, array $extra = []) use ($ajax) {
            if ($ajax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => $ok, 'message' => $msg, 'redirect' => $to] + $extra, JSON_UNESCAPED_UNICODE);
                exit;
            }
            flash($msg, $ok ? 'success' : 'error');
            redirect($to);
        };
        if (!courierConfigured()) $reply(false, 'الربط مع شركة التوصيل غير مفعّل بعد', $back);
        @set_time_limit(120);
        // Lock the order so a double click cannot create two parcels. A lock older than
        // 2 minutes is stale (the server was cut off mid-request) and is taken over.
        $lock = $db->prepare("UPDATE orders SET tracking = 'sending:' || strftime('%s','now') WHERE id = ? AND (tracking IS NULL OR tracking = '' OR (tracking LIKE 'sending%' AND CAST(substr(tracking, 9) AS INTEGER) < strftime('%s','now') - 120))");
        $lock->execute([$id]);
        if (!$lock->rowCount()) {
            $reply(false, hasTracking($order['tracking']) ? 'الطلب #' . $id . ' أُرسل من قبل — رقم التتبع: ' . $order['tracking'] : 'الطلب #' . $id . ' قيد الإرسال الآن، انتظر دقيقة ثم حدّث الصفحة', $back);
        }
        try {
            $mode = in_array($_POST['mode'] ?? '', ['home', 'office'], true) ? $_POST['mode'] : '';
            $sendKey = in_array($_POST['company'] ?? '', courierReadyList(), true) ? (string)$_POST['company'] : orderCourier($order);
            $sent = courierSendOrder($order, trim((string)($_POST['packers_commune'] ?? '')), $mode, $sendKey);
            $tracking = $sent['tracking'];
            $newStatus = courierSetShipped($sent['provider']) && in_array($order['status'], ['new', 'confirmed'], true) ? 'shipped' : $order['status'];
            $type = $mode !== '' ? $mode : $order['delivery_type'];
            $deskName = $type === 'office' && $mode === 'office' ? trim((string)($_POST['packers_commune'] ?? '')) : ($type === 'office' ? (string)($order['desk_commune'] ?? '') : '');
            $db->prepare("UPDATE orders SET tracking = ?, shipped_at = datetime('now'), status = ?, delivery_type = ?, desk_commune = ?, courier = ?, courier_label = ? WHERE id = ?")->execute([$tracking, $newStatus, $type, $deskName, $sent['provider'], $sent['label'], $id]);
            $reply(true, 'تم إرسال الطلب #' . $id . ' إلى ' . courierName($sent['provider']) . ' ✔ — رقم التتبع: ' . $tracking, $back, ['tracking' => $tracking, 'status' => $newStatus, 'status_label' => $statuses[$newStatus] ?? $newStatus]);
        } catch (Throwable $ex) {
            $db->prepare("UPDATE orders SET tracking = '' WHERE id = ? AND tracking LIKE 'sending%'")->execute([$id]);
            if ($ex instanceof CourierCommuneException) {
                $communeChoices = $ex->choices;
                $choiceKind = $ex->kind;
                // From the list: open the order page where the admin can pick the commune / office.
                if ($ajax || ($_POST['back'] ?? '') === 'list') $reply(false, $ex->getMessage(), 'order.php?id=' . $id . '&co=' . urlencode($sendKey) . '&pick=' . ($ex->kind === 'desk' ? 'desk' : '1'), ['needs_choice' => true]);
                flash($ex->getMessage(), 'error');
            } else {
                $reply(false, 'الطلب #' . $id . ': ' . ($ex instanceof RuntimeException ? $ex->getMessage() : 'خطأ غير متوقع أثناء الإرسال'), $back);
            }
        }
    }
    if ($action === 'unlink') {
        // Forget a tracking number (e.g. the parcel was deleted in the company's dashboard) so the order can be sent again.
        $db->prepare("UPDATE orders SET tracking = '', shipped_at = NULL, courier = '', courier_label = '' WHERE id = ?")->execute([$id]);
        flash('تم فك ربط الطلب #' . $id . ' برقم التتبع — يمكنك إرساله من جديد');
        redirect('order.php?id=' . $id);
    }
    if ($action === 'status' && isset($statuses[$_POST['status'] ?? ''])) {
        $new = $_POST['status'];
        $wasReleased = in_array($order['status'], ['cancelled', 'returned'], true);
        $isReleased = in_array($new, ['cancelled', 'returned'], true);
        $db->beginTransaction();
        // Put stock back when an order is cancelled/returned, take it again if reactivated.
        if ($wasReleased !== $isReleased) {
            $sign = $isReleased ? '+' : '-';
            $items = $db->prepare('SELECT product_id, qty FROM order_items WHERE order_id = ?');
            $items->execute([$id]);
            $upd = $db->prepare("UPDATE products SET stock = MAX(0, stock $sign ?) WHERE id = ?");
            foreach ($items->fetchAll() as $it) $upd->execute([$it['qty'], $it['product_id']]);
        }
        $db->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute([$new, $id]);
        $db->commit();
        flash('تم تحديث حالة الطلب #' . $id);
        redirect($back);
    }
    if ($action === 'notes') {
        $db->prepare('UPDATE orders SET notes = ? WHERE id = ?')->execute([trim((string)($_POST['notes'] ?? '')), $id]);
        flash('تم حفظ الملاحظات');
        redirect('order.php?id=' . $id);
    }
    if ($action === 'delete') {
        // One transaction: restocking and deleting must both happen or neither,
        // otherwise a failure half way leaves the stock credited twice.
        $db->beginTransaction();
        if (!in_array($order['status'], ['cancelled', 'returned', 'delivered'], true)) {
            $items = $db->prepare('SELECT product_id, qty FROM order_items WHERE order_id = ?');
            $items->execute([$id]);
            $upd = $db->prepare('UPDATE products SET stock = stock + ? WHERE id = ?');
            foreach ($items->fetchAll() as $it) $upd->execute([$it['qty'], $it['product_id']]);
        }
        $db->prepare('DELETE FROM orders WHERE id = ?')->execute([$id]);
        $db->commit();
        flash('تم حذف الطلب #' . $id);
        redirect('orders.php');
    }
}

$sendKey = $sendKey ?? (in_array($_GET['co'] ?? '', courierReadyList(), true) ? (string)$_GET['co'] : orderCourier($order));
if ($communeChoices === null && isset($_GET['pick']) && courierConfigured() && ($order['tracking'] ?? '') === '') {
    $choiceKind = $_GET['pick'] === 'desk' ? 'desk' : 'commune';
    try { $communeChoices = $choiceKind === 'desk' ? courierDesks((int)$order['wilaya_id'], $sendKey) : courierCommunes((int)$order['wilaya_id'], $sendKey); } catch (Throwable $e) { $communeChoices = []; }
}
$st = $db->prepare('SELECT oi.*, p.images FROM order_items oi LEFT JOIN products p ON p.id = oi.product_id WHERE order_id = ?');
$st->execute([$id]);
$items = $st->fetchAll();
$waPhone = '213' . ltrim($order['phone'], '0');
$cName = courierName(hasTracking($order['tracking']) ? orderCourier($order) : $sendKey);
$readyCo = courierLinked();
// Live parcel status at the delivery company (one quick API call, only on this page).
$parcel = courierParcelStatus($order);
adminHeader('الطلب #' . $id, 'orders');
?>
<a href="orders.php" class="back">← كل الطلبات</a>
<div class="grid-2">
  <div class="panel">
    <div class="panel-head"><h2>المنتجات</h2><span class="pill st-<?= e($order['status']) ?>"><?= e($statuses[$order['status']] ?? '') ?></span></div>
    <table class="table">
      <thead><tr><th></th><th>المنتج</th><th>اللون / المقاس</th><th>الكمية</th><th>السعر</th></tr></thead>
      <tbody>
      <?php foreach ($items as $it): $img = $it['images'] ? (json_decode($it['images'], true)[0] ?? '') : ''; ?>
        <tr>
          <td><?php if ($img): ?><img src="../<?= e($img) ?>" class="thumb" alt=""><?php endif; ?></td>
          <td><?= e($it['product_name']) ?></td>
          <td><?php if ($it['color']): preg_match('/#[0-9a-fA-F]{6}/', $it['color'], $m); ?><?php if ($m): ?><span class="dot" style="background:<?= e($m[0]) ?>"></span> <?php endif; ?><?= e(preg_replace('/\s*\(#[0-9a-fA-F]{6}\)/', '', $it['color'])) ?><?php else: ?>—<?php endif; ?></td>
          <td><?= (int)$it['qty'] ?></td>
          <td><?= money($it['price'] * $it['qty']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="totals">
      <div><span>المجموع الفرعي</span><b><?= money($order['subtotal']) ?></b></div>
      <div><span>التوصيل (<?= $order['delivery_type'] === 'intl' ? 'شحن دولي' : ($order['delivery_type'] === 'office' ? 'مكتب' : 'منزل') ?>)</span><b><?= $order['delivery_price'] ? money($order['delivery_price']) : 'مجاني' ?></b></div>
      <?php if ($order['gift_wrap']): ?><div><span>🎁 تغليف هدية</span><b><?= $order['gift_price'] ? money($order['gift_price']) : 'مجاني' ?></b></div><?php endif; ?>
      <div class="grand"><span>الإجمالي</span><b><?= money($order['total']) ?></b></div>
    </div>
  </div>

  <div>
    <div class="panel">
      <div class="panel-head"><h2>الزبون</h2></div>
      <div class="kv">
        <div><span>الاسم</span><b><?= e($order['customer_name']) ?></b></div>
        <div><span>الهاتف</span><b dir="ltr"><a href="tel:<?= e($order['phone']) ?>"><?= e($order['phone']) ?></a></b></div>
        <?php if (($order['delivery_type'] ?? '') === 'intl'): ?>
        <div><span>البلد</span><b><?= e($order['country']) ?></b></div>
        <div><span>المدينة</span><b><?= e($order['commune']) ?></b></div>
        <?php if (!empty($order['email'])): ?><div><span>البريد</span><b dir="ltr"><a href="mailto:<?= e($order['email']) ?>"><?= e($order['email']) ?></a></b></div><?php endif; ?>
        <?php else: ?>
        <div><span>الولاية</span><b><?= e($order['wilaya_name']) ?></b></div>
        <div><span>البلدية</span><b><?= e($order['commune']) ?></b></div>
        <?php endif; ?>
        <?php if ($order['delivery_type'] === 'office' && !empty($order['desk_commune'])): ?><div><span>مكتب الاستلام</span><b><?= e($cName) ?> — <?= e($order['desk_commune']) ?></b></div><?php endif; ?>
        <?php if ($order['address']): ?><div><span>العنوان</span><b><?= e($order['address']) ?></b></div><?php endif; ?>
        <div><span>التاريخ</span><b><?= e($order['created_at']) ?></b></div>
      </div>
      <div class="btn-row">
        <a href="tel:<?= e($order['phone']) ?>" class="btn btn-dark btn-sm">اتصال</a>
        <a href="https://wa.me/<?= e($waPhone) ?>" target="_blank" rel="noopener" class="btn btn-light btn-sm">واتساب</a>
      </div>
    </div>
    <?php if ($order['gift_wrap'] || $order['gift_message']): ?>
    <div class="panel gift-panel">
      <div class="panel-head"><h2>🎁 طلب هدية</h2></div>
      <?php if ($order['gift_wrap']): ?><p><b>التغليف:</b> نعم — غلّف الطلب كهدية.</p><?php endif; ?>
      <?php if ($order['gift_message']): ?><p><b>رسالة البطاقة:</b></p><blockquote><?= nl2br(e($order['gift_message'])) ?></blockquote><?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if (($order['payment_method'] ?? '') === 'paypal'): ?>
    <div class="panel pp-panel">
      <div class="panel-head"><h2>💳 مدفوع عبر PayPal</h2></div>
      <div class="kv">
        <div><span>المبلغ المدفوع</span><b dir="ltr"><?= e($order['pay_amount']) ?> <?= e($order['pay_currency']) ?></b></div>
        <div><span>الحالة</span><b><?= $order['payment_status'] === 'paid' ? 'مدفوع ✔' : e($order['payment_status']) ?></b></div>
        <div><span>رقم العملية</span><b dir="ltr"><?= e($order['payment_ref']) ?></b></div>
      </div>
      <p class="hint">طلب من خارج الجزائر: يُشحن عبر DHL Express (اللوحة أدناه) أو يدوياً، ولا يُرسل لشركات التوصيل المحلية. تجد العملية في حسابك على PayPal بنفس الرقم.</p>
    </div>
    <?php endif; ?>
    <?php if (($order['delivery_type'] ?? '') === 'intl'): require_once dirname(__DIR__) . '/includes/dhl.php'; $dc = dhlCfg(); ?>
    <div class="panel dhl-panel" id="dhl">
      <div class="panel-head"><h2>✈️ DHL Express — الشحن الدولي</h2></div>
      <?php if (hasTracking($order['tracking']) && ($order['courier'] ?? '') === 'dhl'): ?>
        <div class="kv">
          <div><span>رقم التتبع</span><b dir="ltr"><a href="<?= e(dhlTrackUrl($order['tracking'])) ?>" target="_blank" rel="noopener"><?= e($order['tracking']) ?></a></b></div>
          <div><span>تاريخ الإرسال</span><b><?= e((string)$order['shipped_at']) ?></b></div>
        </div>
        <div class="btn-row">
          <?php if (is_file(ROOT_PATH . '/database/labels/dhl-' . $id . '.pdf')): ?><a href="dhl-label.php?id=<?= $id ?>" target="_blank" class="btn btn-dark btn-sm">🖨 البوليصة (PDF)</a><?php endif; ?>
          <?php if (is_file(ROOT_PATH . '/database/labels/dhl-' . $id . '-invoice.pdf')): ?><a href="dhl-label.php?id=<?= $id ?>&doc=invoice" target="_blank" class="btn btn-light btn-sm">الفاتورة التجارية</a><?php endif; ?>
          <a href="<?= e(dhlTrackUrl($order['tracking'])) ?>" target="_blank" rel="noopener" class="btn btn-light btn-sm">تتبع على DHL</a>
        </div>
      <?php elseif (!dhlReady()): ?>
        <p class="hint">لإرسال هذا الطلب عبر DHL مباشرة من هنا، أكمل <a href="solivr/#c-dhl"><b>إعدادات DHL</b></a> (المفاتيح، رقم الحساب، عنوان المرسِل). أو اشحنه يدوياً واكتب رقم التتبع في الملاحظات.</p>
      <?php else: $rates = $_SESSION['dhl_rates'][$id] ?? []; $w = $_SESSION['dhl_weight'][$id] ?? $dc['weight']; ?>
        <p class="hint">إلى: <?= e($order['customer_name']) ?> — <?= e($order['commune']) ?>، <?= e($order['country']) ?> (<?= e($order['country_code']) ?>) — المدفوع عبر PayPal: <?= e($order['pay_amount']) ?> <?= e($order['pay_currency']) ?> (يُصرَّح للجمارك بقيمة المنتجات فقط)</p>
        <form method="post" action="dhl-ship.php?id=<?= $id ?>" class="form">
          <?= csrfField() ?>
          <label>وزن الطرد (كغ)<input type="number" name="weight" value="<?= e((string)$w) ?>" min="0.1" max="70" step="0.1" dir="ltr"></label>
          <?php if ($rates): ?>
          <label>الخدمة
            <select name="product"><?php foreach ($rates as $r): ?><option value="<?= e($r['code']) ?>" <?= $r['code'] === $dc['product'] ? 'selected' : '' ?>><?= e($r['name']) ?> — <?= e((string)$r['price']) ?> <?= e($r['currency']) ?><?= $r['days'] ? ' — ' . (int)$r['days'] . ' أيام' : '' ?></option><?php endforeach; ?></select>
          </label>
          <?php endif; ?>
          <div class="btn-row">
            <button class="btn btn-light" name="action" value="rates">احسب السعر</button>
            <button class="btn btn-gold" name="action" value="ship" onclick="return confirm('إنشاء شحنة DHL لهذا الطلب؟')">✈️ إرسال عبر DHL</button>
          </div>
        </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if (($order['delivery_type'] ?? '') !== 'intl'): ?>
    <div class="panel courier-panel" id="courier">
      <div class="panel-head"><h2>🚚 شركة التوصيل — <?= e($cName) ?></h2></div>
      <?php if (hasTracking($order['tracking'])): ?>
        <div class="kv">
          <div><span>رقم التتبع</span><b dir="ltr"><?= e($order['tracking']) ?></b></div>
          <?php if ($order['shipped_at']): ?><div><span>أُرسل في</span><b><?= e($order['shipped_at']) ?></b></div><?php endif; ?>
          <?php if ($parcel): ?><div><span>الحالة لدى الشركة</span><b dir="ltr"><?= e(trim($parcel['step'] . ($parcel['status'] !== '' && $parcel['status'] !== $parcel['step'] ? ' — ' . $parcel['status'] : ''))) ?><?= $parcel['date'] !== '' ? ' <small>(' . e($parcel['date']) . ')</small>' : '' ?></b></div><?php endif; ?>
        </div>
        <div class="btn-row">
          <a href="courier-label.php?id=<?= $id ?>" target="_blank" class="btn btn-dark btn-sm">طباعة الملصق (Bordereau)</a>
          <button type="button" class="btn btn-light btn-sm" onclick="navigator.clipboard.writeText('<?= e($order['tracking']) ?>');this.textContent='تم النسخ ✔'">نسخ رقم التتبع</button>
        </div>
        <form method="post" data-confirm="فك ربط الطلب برقم التتبع؟ استعمل هذا فقط إذا حذفت الطرد من لوحة <?= e($cName) ?> وتريد إرساله من جديد.">
          <?= csrfField() ?><input type="hidden" name="action" value="unlink">
          <button class="link-btn">فك الربط وإعادة الإرسال</button>
        </form>
      <?php elseif (!courierConfigured()): ?>
        <p class="hint">الربط مع شركة التوصيل غير مفعّل بعد.</p>
      <?php elseif (in_array($order['status'], ['cancelled', 'returned'], true)): ?>
        <p class="hint">لا يمكن إرسال طلب ملغى أو مرتجع.</p>
      <?php else: ?>
        <p class="hint">سيُرسل: <?= e($order['customer_name']) ?> — <?= e($order['phone']) ?> — <?= e($order['wilaya_name']) ?> / <?= e($order['commune']) ?> — <?= $order['delivery_type'] === 'office' ? 'استلام من المكتب (Stop desk)' : 'توصيل للمنزل' ?> — مبلغ التحصيل <b><?= money($order['total']) ?></b></p>
        <?php if ($choiceKind === 'desk' && $communeChoices !== null): ?>
          <?php if ($communeChoices): ?>
          <form method="post" data-confirm="إرسال الطرد إلى المكتب المختار؟ أخبر الزبون بمكان الاستلام.">
            <?= csrfField() ?><input type="hidden" name="action" value="ship"><input type="hidden" name="mode" value="office"><input type="hidden" name="company" value="<?= e($sendKey) ?>">
            <label>الحل 1: الاستلام من أقرب مكتب لـ <?= e($cName) ?> في الولاية
              <select name="packers_commune" required>
                <option value="">اختر المكتب</option>
                <?php foreach ($communeChoices as $c): ?><option value="<?= e($c['name']) ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
              </select>
            </label>
            <button class="btn btn-gold btn-block">🏢 إرسال للاستلام من هذا المكتب</button>
          </form>
          <?php else: ?>
          <p class="hint">لا يوجد أي مكتب لـ <?= e($cName) ?> في هذه الولاية.</p>
          <?php endif; ?>
          <form method="post" data-confirm="إرسال الطلب كتوصيل إلى المنزل؟ تأكد من عنوان الزبون.">
            <?= csrfField() ?><input type="hidden" name="action" value="ship"><input type="hidden" name="mode" value="home"><input type="hidden" name="company" value="<?= e($sendKey) ?>">
            <p class="hint" style="margin-top:12px">الحل <?= $communeChoices ? '2' : '' ?>: التوصيل إلى المنزل. المبلغ المحصَّل يبقى <?= money($order['total']) ?>. اتصل بالزبون لأخذ عنوانه إن لم يكن مكتوباً، واكتبه في الملاحظات.</p>
            <button class="btn btn-dark btn-block">🏠 إرسال كتوصيل إلى المنزل</button>
          </form>
        <?php else: ?>
        <form method="post" data-confirm="إرسال الطلب #<?= $id ?> إلى شركة التوصيل المختارة؟">
          <?= csrfField() ?><input type="hidden" name="action" value="ship">
          <?php if ($communeChoices): ?><input type="hidden" name="company" value="<?= e($sendKey) ?>"><?php elseif (count($readyCo) > 1): ?>
            <label>الإرسال عبر <?= ($order['courier'] ?? '') !== '' ? '<small class="muted">(اختارها الزبون: ' . e(courierName((string)$order['courier'])) . ')</small>' : '' ?>
              <select name="company"><?php foreach ($readyCo as $rk => $rn): ?><option value="<?= e($rk) ?>" <?= $rk === $sendKey ? 'selected' : '' ?>><?= e($rn) ?></option><?php endforeach; ?></select>
            </label>
          <?php else: ?><input type="hidden" name="company" value="<?= e($sendKey) ?>"><?php endif; ?>
          <?php if ($communeChoices): ?>
            <label>البلدية كما هي عند <?= e($cName) ?> *
              <select name="packers_commune" required>
                <option value="">اختر البلدية</option>
                <?php foreach ($communeChoices as $c): ?><option value="<?= e($c['name']) ?>"><?= e($c['name']) ?><?= $order['delivery_type'] === 'office' && !$c['stop_desk'] ? ' (بدون مكتب)' : '' ?></option><?php endforeach; ?>
              </select>
            </label>
          <?php endif; ?>
          <button class="btn btn-gold btn-block">🚚 إرسال<?= count($readyCo) > 1 && !$communeChoices ? '' : ' إلى ' . e($cName) ?></button>
        </form>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="panel">
      <div class="panel-head"><h2>الحالة</h2></div>
      <form method="post" class="form-inline">
        <?= csrfField() ?><input type="hidden" name="action" value="status">
        <select name="status"><?php foreach ($statuses as $k => $label): ?><option value="<?= $k ?>" <?= $order['status'] === $k ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select>
        <button class="btn btn-dark">حفظ</button>
      </form>
      <p class="hint">عند الإلغاء أو الإرجاع تعود الكمية إلى المخزون تلقائياً.</p>
    </div>
    <div class="panel">
      <div class="panel-head"><h2>ملاحظات</h2></div>
      <form method="post" class="form">
        <?= csrfField() ?><input type="hidden" name="action" value="notes">
        <textarea name="notes" rows="3"><?= e($order['notes']) ?></textarea>
        <button class="btn btn-light">حفظ الملاحظات</button>
      </form>
    </div>
    <form method="post" data-confirm="حذف هذا الطلب نهائياً؟">
      <?= csrfField() ?><input type="hidden" name="action" value="delete">
      <button class="btn btn-danger btn-block">حذف الطلب</button>
    </form>
  </div>
</div>
<?php adminFooter(); ?>
