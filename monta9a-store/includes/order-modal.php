<?php
/** "اطلب الآن" popup: one-product order form. Filled by main.js from the button's data-order. */
$wilayas = getDB()->query('SELECT * FROM wilayas WHERE active = 1 ORDER BY id')->fetchAll();
$old = ['name' => '', 'phone' => '', 'wilaya' => '', 'commune' => '', 'desk' => '', 'address' => '', 'delivery' => 'home', 'notes' => '', 'gift_message' => '', 'courier' => ''];
$giftWrapOn = setting('gift_wrap_on') === '1';
$giftMsgOn = setting('gift_msg_on') === '1';
$giftPrice = max(0, (int)setting('gift_wrap_price', '0'));
$wantWrap = false;
$freeMin = (int)setting('free_shipping_min', '0');
?>
<div class="om" id="orderModal" hidden>
  <div class="om-backdrop" data-om-close></div>
  <div class="om-dialog" role="dialog" aria-modal="true" aria-labelledby="omTitle">
    <div class="om-head">
      <h3 id="omTitle"><?= icon('bag', 20) ?> <?= tx('اطلب الآن', 'Commander') ?></h3>
      <button type="button" class="om-x" data-om-close aria-label="<?= tx('إغلاق', 'Fermer') ?>">&times;</button>
    </div>
    <form method="post" action="checkout.php" class="checkout-form om-body" id="checkoutForm" novalidate>
      <?= csrfField() ?>
      <input type="hidden" name="buy_now" id="omId" value="">
      <input type="hidden" name="bn_color" id="omColor" value="">
      <input type="hidden" name="bn_size" id="omSize" value="">

      <div class="om-product">
        <img id="omImg" src="" alt="">
        <div class="om-pinfo">
          <strong id="omName"></strong>
          <span class="om-unit" id="omUnit"></span>
          <div class="om-swatches" id="omColors" hidden></div>
          <div class="om-sizes" id="omSizes" hidden></div>
        </div>
        <div class="qty qty-sm om-qty">
          <button type="button" data-om-qty="-1" aria-label="<?= tx('إنقاص', 'Moins') ?>">−</button>
          <input type="number" name="bn_qty" id="omQty" value="1" min="1" max="20" aria-label="<?= tx('الكمية', 'Quantité') ?>">
          <button type="button" data-om-qty="1" aria-label="<?= tx('زيادة', 'Plus') ?>">+</button>
        </div>
      </div>

      <h4 class="om-sec"><?= tx('معلومات التوصيل', 'Informations de livraison') ?></h4>
<?php include __DIR__ . '/order-fields.php'; ?>

      <div class="om-summary">
        <div class="sum-row"><span><?= tx('سعر المنتج', 'Prix du produit') ?></span><strong id="subtotal" data-v="0">0</strong></div>
        <div class="sum-row"><span><?= tx('التوصيل', 'Livraison') ?></span><strong id="deliveryPrice" data-free="<?= $freeMin ?>"><?= tx('اختر الولاية', 'Choisissez la wilaya') ?></strong></div>
        <?php if ($giftWrapOn): ?><div class="sum-row" id="giftRow" hidden><span><?= tx('تغليف هدية', 'Emballage cadeau') ?></span><strong><?= $giftPrice ? money($giftPrice) : tx('مجاني', 'Gratuit') ?></strong></div><?php endif; ?>
        <div class="sum-row total"><span><?= tx('الإجمالي', 'Total') ?></span><strong id="grandTotal">0</strong></div>
      </div>
      <button type="submit" class="btn btn-dark btn-lg btn-block"><?= tx('تأكيد الطلب — الدفع عند الاستلام', 'Confirmer — paiement à la livraison') ?></button>
      <p class="muted small center"><?= tx('سنتصل بك لتأكيد الطلب قبل الشحن.', "Nous vous appellerons pour confirmer avant l'envoi.") ?></p>
    </form>
  </div>
</div>
