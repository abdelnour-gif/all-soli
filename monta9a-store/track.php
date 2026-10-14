<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/courier.php';
$db = getDB();
$orderId = (int)($_REQUEST['order'] ?? 0);
$phone = normalizePhone((string)($_POST['phone'] ?? ''));
$order = null;
$items = [];
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!checkCsrf()) {
        $error = tx('انتهت صلاحية الجلسة، حاول مرة أخرى', 'La session a expiré, réessayez');
    } else {
        $st = $db->prepare('SELECT * FROM orders WHERE id = ? AND phone = ?');
        $st->execute([$orderId, $phone]);
        $order = $st->fetch();
        if ($order) {
            $st = $db->prepare('SELECT * FROM order_items WHERE order_id = ?');
            $st->execute([$orderId]);
            $items = $st->fetchAll();
        } else {
            $error = tx('لم يتم العثور على طلب بهذا الرقم ورقم الهاتف', 'Aucune commande trouvée avec ce numéro et ce téléphone');
        }
    }
}
$steps = ['new' => tx('تم الاستلام', 'Reçue'), 'confirmed' => tx('مؤكد', 'Confirmée'), 'shipped' => tx('تم الشحن', 'Expédiée'), 'delivered' => tx('تم التسليم', 'Livrée')];
$pageTitle = tx('تتبع طلبي', 'Suivre ma commande');
require_once __DIR__ . '/includes/header.php';
?>
<section class="page-head">
  <div class="container">
    <nav class="breadcrumb"><a href="index.php"><?= tx('الرئيسية', 'Accueil') ?></a> / <span><?= e($pageTitle) ?></span></nav>
    <h1><?= e($pageTitle) ?></h1>
  </div>
</section>
<section class="section">
  <div class="container narrow">
    <form method="post" class="card track-form">
      <?= csrfField() ?>
      <p class="muted"><?= tx('أدخل رقم الطلب ورقم الهاتف المستعمل عند الطلب.', 'Saisissez le numéro de commande et le téléphone utilisé lors de la commande.') ?></p>
      <div class="form-row">
        <label><?= tx('رقم الطلب', 'N° de commande') ?><input type="number" name="order" value="<?= $orderId ?: '' ?>" required></label>
        <label><?= tx('رقم الهاتف', 'Téléphone') ?><input type="tel" name="phone" value="<?= e($_POST['phone'] ?? '') ?>" placeholder="05xxxxxxxx" required></label>
      </div>
      <button class="btn btn-dark btn-block"><?= tx('بحث', 'Rechercher') ?></button>
      <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    </form>

    <?php if ($order): $status = $order['status']; ?>
    <div class="card">
      <h3><?= tx('الطلب', 'Commande') ?> #<?= (int)$order['id'] ?> <span class="status-pill st-<?= e($status) ?>"><?= e(orderStatuses()[$status] ?? $status) ?></span></h3>
      <?php if (isset($steps[$status])): $reached = true; ?>
      <ol class="steps">
        <?php foreach ($steps as $k => $label): ?>
          <li class="<?= $reached ? 'done' : '' ?>"><?= e($label) ?></li>
          <?php if ($k === $status) $reached = false; ?>
        <?php endforeach; ?>
      </ol>
      <?php endif; ?>
      <?php if (!empty($order['tracking']) && !str_starts_with($order['tracking'], 'sending')): ?>
      <?php if (($order['courier'] ?? '') === 'dhl'): require_once __DIR__ . '/includes/dhl.php'; ?>
      <p class="muted"><?= tx('شركة الشحن:', 'Transporteur :') ?> <b>DHL Express</b> — <?= tx('رقم التتبع:', 'N° de suivi :') ?> <a href="<?= e(dhlTrackUrl($order['tracking'])) ?>" target="_blank" rel="noopener" dir="ltr"><b><?= e($order['tracking']) ?></b></a></p>
      <?php else: ?>
      <p class="muted"><?= tx('شركة التوصيل:', 'Transporteur :') ?> <b><?= e(courierName(orderCourier($order))) ?></b> — <?= tx('رقم التتبع:', 'N° de suivi :') ?> <b dir="ltr"><?= e($order['tracking']) ?></b></p>
      <?php endif; ?>
      <?php endif; ?>
      <table class="order-table">
        <?php foreach ($items as $it): ?><tr><td><?= e($it['product_name']) ?> × <?= (int)$it['qty'] ?></td><td><?= money($it['price'] * $it['qty']) ?></td></tr><?php endforeach; ?>
        <tr><td><?= tx('التوصيل', 'Livraison') ?></td><td><?= $order['delivery_price'] ? money($order['delivery_price']) : tx('مجاني', 'Gratuit') ?></td></tr>
        <?php if ($order['gift_wrap']): ?><tr><td><?= tx('تغليف هدية', 'Emballage cadeau') ?></td><td><?= $order['gift_price'] ? money($order['gift_price']) : tx('مجاني', 'Gratuit') ?></td></tr><?php endif; ?>
        <tr class="total"><td><?= tx('الإجمالي', 'Total') ?></td><td><?= money($order['total']) ?></td></tr>
      </table>
      <p class="muted small"><?= tx('تاريخ الطلب:', 'Date :') ?> <?= e($order['created_at']) ?></p>
    </div>
    <?php endif; ?>
  </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
