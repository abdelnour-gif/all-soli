<?php
require_once __DIR__ . '/includes/functions.php';
$id = (int)($_SESSION['last_order'] ?? 0);
if (!$id) redirect('index.php');
$db = getDB();
$st = $db->prepare('SELECT * FROM orders WHERE id = ?');
$st->execute([$id]);
$order = $st->fetch();
if (!$order) redirect('index.php');
$st = $db->prepare('SELECT * FROM order_items WHERE order_id = ?');
$st->execute([$id]);
$items = $st->fetchAll();

// Fire the Facebook "Purchase" event only the first time this page is shown.
// Without this flag a refresh (or revisiting the page later) reports the same
// sale again and again, which quietly inflates the ad reports the owner pays for.
$firstView = empty($_SESSION['last_order_counted']);
$_SESSION['last_order_counted'] = true;
$pageTitle = tx('تم استلام طلبك', 'Commande reçue');
require_once __DIR__ . '/includes/header.php';
?>
<section class="section">
  <div class="container narrow">
    <div class="card success-card">
      <div class="success-icon"><?= icon('check', 40) ?></div>
      <h1><?= tx('شكراً لك، تم استلام طلبك!', 'Merci, votre commande est bien reçue !') ?></h1>
      <?php $isPP = ($order['payment_method'] ?? '') === 'paypal'; ?>
      <?php if ($isPP): ?>
      <p><?= tx('رقم الطلب:', 'Commande n°') ?> <strong>#<?= (int)$order['id'] ?></strong> — <?= tx('تم الدفع بنجاح عبر PayPal. سنرسل لك تفاصيل الشحن على', 'Paiement PayPal reçu. Nous vous enverrons le suivi à') ?> <b dir="ltr"><?= e($order['email']) ?></b>.</p>
      <?php else: ?>
      <p><?= tx('رقم الطلب:', 'Commande n°') ?> <strong>#<?= (int)$order['id'] ?></strong> — <?= tx('سنتصل بك على الرقم', 'Nous vous appellerons au') ?> <b dir="ltr"><?= e($order['phone']) ?></b> <?= tx('لتأكيد الطلب.', 'pour confirmer la commande.') ?></p>
      <?php endif; ?>
      <table class="order-table">
        <?php foreach ($items as $it): ?>
          <tr><td><?= e($it['product_name']) ?><?= $it['color'] ? ' <span class="muted">— ' . e(preg_replace('/\s*\(#[0-9a-fA-F]{6}\)/', '', $it['color'])) . '</span>' : '' ?> × <?= (int)$it['qty'] ?></td><td><?= money($it['price'] * $it['qty']) ?></td></tr>
        <?php endforeach; ?>
        <?php if ($isPP): ?>
        <tr><td><?= tx('الشحن الدولي', 'Livraison internationale') ?> (<?= e($order['country']) ?>)</td><td><?= tx('مشمول', 'Inclus') ?></td></tr>
        <?php else: ?>
        <tr><td><?= tx('التوصيل', 'Livraison') ?> (<?= $order['delivery_type'] === 'office' ? tx('مكتب', 'point relais') : tx('منزل', 'domicile') ?> — <?= e($order['wilaya_name']) ?>)</td><td><?= $order['delivery_price'] ? money($order['delivery_price']) : tx('مجاني', 'Gratuit') ?></td></tr>
        <?php endif; ?>
        <?php if ($order['gift_wrap']): ?><tr><td><?= tx('تغليف هدية', 'Emballage cadeau') ?> 🎁</td><td><?= $order['gift_price'] ? money($order['gift_price']) : tx('مجاني', 'Gratuit') ?></td></tr><?php endif; ?>
        <?php if ($order['gift_message']): ?><tr><td colspan="2" class="gift-msg"><?= tx('بطاقة التهنئة:', 'Carte :') ?> «<?= e($order['gift_message']) ?>»</td></tr><?php endif; ?>
        <?php if ($isPP): ?>
        <tr class="total"><td><?= tx('المبلغ المدفوع عبر PayPal', 'Montant payé via PayPal') ?></td><td dir="ltr"><?= e($order['pay_amount'] . ' ' . $order['pay_currency']) ?></td></tr>
        <?php else: ?>
        <tr class="total"><td><?= tx('الإجمالي عند الاستلام', 'Total à payer à la livraison') ?></td><td><?= money($order['total']) ?></td></tr>
        <?php endif; ?>
      </table>
      <div class="btn-row">
        <a href="shop.php" class="btn btn-dark"><?= tx('متابعة التسوق', 'Continuer mes achats') ?></a>
        <a href="track.php?order=<?= (int)$order['id'] ?>" class="btn btn-light"><?= tx('تتبع الطلب', 'Suivre la commande') ?></a>
      </div>
    </div>
  </div>
</section>
<?php if ($firstView && setting('fb_pixel')): ?>
<script>if (window.fbq) fbq('track', 'Purchase', <?= ($order['payment_method'] ?? '') === 'paypal' ? json_encode(['value' => (float)$order['pay_amount'], 'currency' => $order['pay_currency']]) : json_encode(['value' => (int)$order['total'], 'currency' => 'DZD']) ?>);</script>
<?php endif; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
