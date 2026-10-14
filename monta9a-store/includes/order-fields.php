<?php
/**
 * Delivery fields shared by the checkout page and the "اطلب الآن" popup.
 * Expects: $wilayas, $old, $giftWrapOn, $giftMsgOn, $giftPrice, $wantWrap
 */
require_once __DIR__ . '/courier.php';
?>
      <div class="form-row">
        <label><?= tx('الاسم الكامل', 'Nom complet') ?> *<input type="text" name="name" value="<?= e($old['name']) ?>" required autocomplete="name"></label>
        <label><?= tx('رقم الهاتف', 'Téléphone') ?> *<input type="tel" name="phone" value="<?= e($old['phone']) ?>" placeholder="05xxxxxxxx" required inputmode="tel" autocomplete="tel"></label>
      </div>
      <div class="form-row">
        <label><?= tx('الولاية', 'Wilaya') ?> *
          <select name="wilaya" id="wilaya" required>
            <option value=""><?= tx('اختر الولاية', 'Choisissez la wilaya') ?></option>
            <?php foreach ($wilayas as $w): ?>
              <option value="<?= (int)$w['id'] ?>" data-home="<?= (int)$w['home_price'] ?>" data-office="<?= (int)$w['office_price'] ?>" <?= (string)$w['id'] === $old['wilaya'] ? 'selected' : '' ?>><?= (int)$w['id'] ?> - <?= isFr() ? e($w['name_fr']) . ' (' . e($w['name_ar']) . ')' : e($w['name_ar']) . ' (' . e($w['name_fr']) . ')' ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label><?= tx('البلدية', 'Commune') ?> *
          <select name="commune" id="commune" required disabled data-src="assets/data/communes.json?v=1" data-selected="<?= e($old['commune']) ?>">
            <option value=""><?= tx('اختر الولاية أولاً', "Choisissez d'abord la wilaya") ?></option>
          </select>
        </label>
      </div>
      <div class="courier-field" id="courierField" hidden>
        <span class="cf-label"><?= tx('شركة التوصيل', 'Société de livraison') ?> *</span>
        <div class="courier-opts" id="courierOpts"></div>
        <input type="hidden" name="courier" id="courierInput" value="<?= e($old['courier'] ?? '') ?>">
      </div>
      <div class="delivery-types">
        <label class="dt"><input type="radio" name="delivery" value="home" <?= $old['delivery'] !== 'office' ? 'checked' : '' ?>><span><?= icon('truck', 20) ?> <?= tx('توصيل إلى المنزل', 'Livraison à domicile') ?> <b id="homePrice"></b></span></label>
        <label class="dt"><input type="radio" name="delivery" value="office" <?= $old['delivery'] === 'office' ? 'checked' : '' ?>><span><?= icon('box', 20) ?> <?= tx('استلام من المكتب', 'Retrait en point relais') ?> <b id="officePrice"></b></span></label>
      </div>
      <label id="deskField" hidden><span class="desk-lbl"><?= tx('مكتب الاستلام', 'Point relais') ?> — <b id="deskCo"><?= e(courierName()) ?></b> *</span>
        <select name="desk" id="desk" data-src="api/desks.php" data-selected="<?= e($old['desk']) ?>"><option value=""><?= tx('اختر المكتب', 'Choisissez le bureau') ?></option></select>
        <small class="muted" id="deskNote"></small>
      </label>
      <label id="addressField"><?= tx('العنوان', 'Adresse') ?> *<input type="text" name="address" value="<?= e($old['address']) ?>" placeholder="<?= tx('الحي، الشارع، رقم المنزل', 'Quartier, rue, n° de maison') ?>"></label>
      <?php if ($giftWrapOn || $giftMsgOn): ?>
      <div class="gift-box">
        <h4><?= icon('gift', 20) ?> <?= tx('خيارات الهدية', 'Options cadeau') ?></h4>
        <?php if ($giftWrapOn): ?>
        <label class="check-line"><input type="checkbox" name="gift_wrap" value="1" id="giftWrap" data-price="<?= $giftPrice ?>" <?= $wantWrap ? 'checked' : '' ?>> <?= tx('تغليف الطلب كهدية', 'Emballer comme cadeau') ?> <b><?= $giftPrice ? '+' . money($giftPrice) : tx('مجاناً', 'Gratuit') ?></b></label>
        <?php endif; ?>
        <?php if ($giftMsgOn): ?>
        <label><?= tx('رسالة بطاقة التهنئة (اختياري)', 'Message pour la carte (facultatif)') ?><textarea name="gift_message" rows="2" maxlength="300" placeholder="<?= tx('مثلاً: عيد ميلاد سعيد يا أمي ❤', 'Ex. : Joyeux anniversaire maman ❤') ?>"><?= e($old['gift_message']) ?></textarea></label>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <label><?= tx('ملاحظات (اختياري)', 'Remarques (facultatif)') ?><textarea name="notes" rows="2" placeholder="<?= tx('مثلاً: وقت الاتصال المناسب...', 'Ex. : meilleur moment pour vous appeler...') ?>"><?= e($old['notes']) ?></textarea></label>
