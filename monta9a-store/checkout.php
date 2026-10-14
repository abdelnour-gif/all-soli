<?php
require_once __DIR__ . '/includes/courier.php';
require_once __DIR__ . '/includes/telegram.php';
$db = getDB();

// "اطلب الآن": a direct order for one product, sent from the popup on product cards/pages.
// It does not touch the cart; the item travels in the form (buy_now / bn_color / bn_qty).
$buyNow = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['buy_now'])) {
    $bp = getProduct((int)$_POST['buy_now']);
    if (!$bp || (int)$bp['stock'] <= 0) redirect('shop.php');
    $bColors = productColors($bp);
    $bColor = (string)($_POST['bn_color'] ?? '');
    if (!$bColors) $bColor = '';
    elseif (count($bColors) === 1) $bColor = $bColors[0];
    elseif (!in_array($bColor, $bColors, true)) redirect('product.php?id=' . (int)$bp['id']);
    $bSizes = productSizes($bp);
    $bSize = (string)($_POST['bn_size'] ?? '');
    if (!$bSizes) $bSize = '';
    elseif (count($bSizes) === 1) $bSize = $bSizes[0];
    elseif (!in_array($bSize, $bSizes, true)) redirect('product.php?id=' . (int)$bp['id']);
    $bQty = max(1, min(20, (int)($_POST['bn_qty'] ?? 1)));
    $buyNow = ['id' => (int)$bp['id'], 'color' => $bColor, 'size' => $bSize, 'qty' => $bQty];
    $lines = [['key' => 'buy_now', 'product' => $bp, 'color' => $bColor, 'size' => $bSize, 'qty' => $bQty, 'total' => $bQty * (int)$bp['price']]];
} else {
    if (offerOnly()) redirect('index.php#order');   // single-offer store: orders come from the offer form
    $lines = cartLines();
    if (!$lines) redirect('cart.php');
}

$wilayas = $db->query('SELECT * FROM wilayas WHERE active = 1 ORDER BY id')->fetchAll();
$freeMin = (int)setting('free_shipping_min', '0');
$errors = [];
$old = ['name' => '', 'phone' => '', 'wilaya' => '', 'commune' => '', 'desk' => '', 'address' => '', 'delivery' => 'home', 'notes' => '', 'gift_message' => '', 'courier' => ''];
$giftWrapOn = setting('gift_wrap_on') === '1';
$giftMsgOn = setting('gift_msg_on') === '1';
$giftPrice = max(0, (int)setting('gift_wrap_price', '0'));
$wantWrap = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($old as $k => $v) $old[$k] = trim((string)($_POST[$k] ?? $v));
    $phone = normalizePhone($old['phone']);
    $wantWrap = $giftWrapOn && !empty($_POST['gift_wrap']);
    $giftMessage = $giftMsgOn ? mb_substr($old['gift_message'], 0, 300) : '';

    if (!checkCsrf()) $errors[] = tx('انتهت صلاحية الجلسة، يرجى إعادة المحاولة', 'La session a expiré, veuillez réessayer');
    if (mb_strlen($old['name']) < 3) $errors[] = tx('يرجى إدخال الاسم الكامل', 'Veuillez saisir votre nom complet');
    if (!validPhone($phone)) $errors[] = tx('رقم الهاتف غير صحيح (10 أرقام يبدأ بـ 05 أو 06 أو 07)', 'Numéro invalide (10 chiffres commençant par 05, 06 ou 07)');
    $st = $db->prepare('SELECT * FROM wilayas WHERE id = ? AND active = 1');
    $st->execute([(int)$old['wilaya']]);
    $wilaya = $st->fetch();
    if (!$wilaya) $errors[] = tx('يرجى اختيار الولاية', 'Veuillez choisir la wilaya');
    $commune = $wilaya ? findCommune((int)$wilaya['id'], $old['commune']) : null;
    if ($wilaya && !$commune) $errors[] = tx('يرجى اختيار البلدية من القائمة', 'Veuillez choisir la commune dans la liste');
    $delivery = $old['delivery'] === 'office' ? 'office' : 'home';
    if ($delivery === 'home' && mb_strlen($old['address']) < 3) $errors[] = tx('يرجى إدخال العنوان للتوصيل إلى المنزل', 'Veuillez saisir l\'adresse pour la livraison à domicile');
    // Delivery company chosen by the customer (among the companies active in /admin/solivr),
    // with its own prices and offices. Without any active company the store's prices are used.
    $desk = '';
    $courierKey = '';
    $quote = null;
    $activeCouriers = courierActiveList();
    if ($wilaya && $activeCouriers) {
        $courierKey = in_array($old['courier'], $activeCouriers, true) ? $old['courier'] : (count($activeCouriers) === 1 ? $activeCouriers[0] : '');
        if ($courierKey === '') {
            $errors[] = tx('يرجى اختيار شركة التوصيل', 'Veuillez choisir la société de livraison');
        } else {
            try { $quote = courierQuote($courierKey, $wilaya, $commune[1] ?? ''); } catch (Throwable $e) { $quote = null; }
            if ($quote && $delivery === 'office') {
                if ($quote['office'] === null) $errors[] = tx('هذه الشركة لا توفر الاستلام من المكتب في ولايتك، اختر التوصيل إلى المنزل', 'Pas de point relais pour cette société dans votre wilaya, choisissez la livraison à domicile');
                elseif ($quote['desks'] !== null) {   // when the company cannot be reached the order is accepted and the admin picks the office later
                    if (!in_array($old['desk'], $quote['desks'], true)) $errors[] = tx('يرجى اختيار مكتب الاستلام', 'Veuillez choisir le point relais');
                    else $desk = $old['desk'];
                }
            }
        }
    }

    // Simple anti-spam: max 3 orders per phone per hour
    if (!$errors) {
        $st = $db->prepare("SELECT COUNT(*) FROM orders WHERE phone = ? AND created_at > datetime('now', '-1 hour')");
        $st->execute([$phone]);
        if ($st->fetchColumn() >= 3) $errors[] = tx('لقد أرسلت عدة طلبات مؤخراً، سنتصل بك قريباً', 'Vous avez envoyé plusieurs commandes récemment, nous vous appellerons bientôt');
    }

    if (!$errors) {
        try {
            $db->beginTransaction();
            $subtotal = 0;
            $items = [];
            $need = [];   // the same product in two colours/sizes counts once against its stock
            foreach ($lines as $l) {
                $st = $db->prepare('SELECT stock, price, name FROM products WHERE id = ? AND active = 1');
                $st->execute([$l['product']['id']]);
                $fresh = $st->fetch();
                $pid = (int)$l['product']['id'];
                $need[$pid] = ($need[$pid] ?? 0) + (int)$l['qty'];
                if (!$fresh || (int)$fresh['stock'] < $need[$pid]) {
                    throw new RuntimeException(tx('الكمية المطلوبة من "', 'La quantité demandée de « ') . tr($l['product'], 'name') . tx('" غير متوفرة حالياً', ' » n\'est plus disponible'));
                }
                $subtotal += $fresh['price'] * $l['qty'];
                $items[] = [$l['product']['id'], $fresh['name'], variantText((string)$l['color'], (string)($l['size'] ?? '')), $l['qty'], $fresh['price']];
            }
            $basePrice = $quote ? ($delivery === 'office' ? (int)$quote['office'] : (int)$quote['home']) : (int)($delivery === 'office' ? $wilaya['office_price'] : $wilaya['home_price']);
            $deliveryPrice = ($freeMin > 0 && $subtotal >= $freeMin) ? 0 : $basePrice;
            $wrapCost = $wantWrap ? $giftPrice : 0;
            $st = $db->prepare('INSERT INTO orders (customer_name, phone, wilaya_id, wilaya_name, commune, commune_fr, desk_commune, address, delivery_type, delivery_price, subtotal, gift_wrap, gift_price, gift_message, total, notes, ip, courier) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $st->execute([$old['name'], $phone, $wilaya['id'], $wilaya['id'] . ' - ' . $wilaya['name_ar'], $commune[0], $commune[1], $desk, $old['address'], $delivery, $deliveryPrice, $subtotal, $wantWrap ? 1 : 0, $wrapCost, $giftMessage, $subtotal + $deliveryPrice + $wrapCost, $old['notes'], $_SERVER['REMOTE_ADDR'] ?? '', $courierKey]);
            $orderId = (int)$db->lastInsertId();
            $ins = $db->prepare('INSERT INTO order_items (order_id, product_id, product_name, color, qty, price) VALUES (?,?,?,?,?,?)');
            // only takes from the stock when enough is left (two customers ordering the last piece at the same moment)
            $dec = $db->prepare('UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?');
            foreach ($items as $it) {
                $ins->execute([$orderId, $it[0], $it[1], $it[2], $it[3], $it[4]]);
                $dec->execute([$it[3], $it[0], $it[3]]);
                if ($dec->rowCount() !== 1) {
                    throw new RuntimeException(tx('الكمية المطلوبة من "', 'La quantité demandée de « ') . $it[1] . tx('" غير متوفرة حالياً', ' » n\'est plus disponible'));
                }
            }
            $db->commit();
            if (!$buyNow) $_SESSION['cart'] = [];
            unset($_SESSION['last_order_counted']);
            $_SESSION['last_order'] = $orderId;
            if (telegramConfigured()) {
                // Notify Telegram after the customer has already received the response, so a
                // slow or unreachable Telegram never delays or breaks the checkout.
                register_shutdown_function(function () use ($orderId) {
                    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
                    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
                    telegramNotifyOrder($orderId);
                });
            }
            redirect('order-success.php');
        } catch (Throwable $ex) {
            if ($db->inTransaction()) $db->rollBack();
            $errors[] = $ex instanceof RuntimeException ? $ex->getMessage() : tx('حدث خطأ أثناء تسجيل الطلب، حاول مرة أخرى', 'Une erreur s\'est produite, veuillez réessayer');
        }
    }
}

$subtotal = array_sum(array_column($lines, 'total'));
$pageTitle = tx('إتمام الطلب', 'Finaliser la commande');
$noOrderModal = true;   // this page already has the order form
require_once __DIR__ . '/includes/header.php';
?>
<section class="page-head">
  <div class="container">
    <nav class="breadcrumb"><a href="index.php"><?= tx('الرئيسية', 'Accueil') ?></a> / <a href="cart.php"><?= tx('السلة', 'Panier') ?></a> / <span><?= e($pageTitle) ?></span></nav>
    <h1><?= e($pageTitle) ?></h1>
  </div>
</section>

<section class="section">
  <div class="container checkout-layout">
    <form method="post" class="checkout-form card" id="checkoutForm" novalidate>
      <?= csrfField() ?>
      <h3><?= tx('معلومات التوصيل', 'Informations de livraison') ?></h3>
      <?php if ($errors): ?><div class="alert alert-error"><?php foreach ($errors as $er) echo '<div>' . e($er) . '</div>'; ?></div><?php endif; ?>
      <?php if ($buyNow): ?>
      <input type="hidden" name="buy_now" value="<?= $buyNow['id'] ?>"><input type="hidden" name="bn_color" value="<?= e($buyNow['color']) ?>"><input type="hidden" name="bn_size" value="<?= e($buyNow['size']) ?>"><input type="hidden" name="bn_qty" value="<?= $buyNow['qty'] ?>">
      <?php endif; ?>
<?php include __DIR__ . '/includes/order-fields.php'; ?>
      <button type="submit" class="btn btn-dark btn-lg btn-block"><?= tx('تأكيد الطلب — الدفع عند الاستلام', 'Confirmer — paiement à la livraison') ?></button>
      <p class="muted small center"><?= tx('سنتصل بك لتأكيد الطلب قبل الشحن.', "Nous vous appellerons pour confirmer avant l'envoi.") ?></p>
    </form>

    <aside class="summary">
      <h3><?= tx('طلبك', 'Votre commande') ?></h3>
      <?php foreach ($lines as $l): ?>
        <div class="sum-item">
          <img src="<?= e(productThumb($l['product'])) ?>" alt="">
          <div><strong><?= e(tr($l['product'], 'name')) ?></strong><?php if ($l['color']): ?><span class="muted"><span class="dot" style="background:<?= e($l['color']) ?>"></span> <?= e(colorLabel($l['color'])) ?></span><?php endif; ?><?php if (!empty($l['size'])): ?><span class="muted"><?= tx('المقاس:', 'Taille :') ?> <?= e($l['size']) ?></span><?php endif; ?><span class="muted"><?= tx('الكمية:', 'Quantité :') ?> <?= $l['qty'] ?></span></div>
          <b><?= money($l['total']) ?></b>
        </div>
      <?php endforeach; ?>
      <div class="sum-row"><span><?= tx('المجموع الفرعي', 'Sous-total') ?></span><strong id="subtotal" data-v="<?= $subtotal ?>"><?= money($subtotal) ?></strong></div>
      <div class="sum-row"><span><?= tx('التوصيل', 'Livraison') ?></span><strong id="deliveryPrice" data-free="<?= $freeMin ?>"><?= tx('اختر الولاية', 'Choisissez la wilaya') ?></strong></div>
      <?php if ($giftWrapOn): ?><div class="sum-row" id="giftRow" <?= $wantWrap ? '' : 'hidden' ?>><span><?= tx('تغليف هدية', 'Emballage cadeau') ?></span><strong><?= $giftPrice ? money($giftPrice) : tx('مجاني', 'Gratuit') ?></strong></div><?php endif; ?>
      <div class="sum-row total"><span><?= tx('الإجمالي', 'Total') ?></span><strong id="grandTotal"><?= money($subtotal) ?></strong></div>
    </aside>
  </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
