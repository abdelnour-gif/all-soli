<?php
/**
 * Orders from outside Algeria — paid online with PayPal (card or PayPal account) in EUR / USD.
 *   intl.php            → the cart
 *   intl.php?buy=ID     → one product (&qty, &color, &size)
 * Algerian customers keep the normal checkout (payment on delivery).
 */
require_once __DIR__ . '/includes/paypal.php';
require_once __DIR__ . '/includes/images.php';
require_once __DIR__ . '/includes/dhl.php';

$cfg = ppCfg();
$buy = (int)($_GET['buy'] ?? 0);
$lines = [];
if ($buy && ($bp = getProduct($buy)) && (int)$bp['stock'] > 0) {
    $colors = productColors($bp); $sizes = productSizes($bp);
    $color = (string)($_GET['color'] ?? ''); $size = (string)($_GET['size'] ?? '');
    if (count($colors) === 1) $color = $colors[0];
    if (count($sizes) === 1) $size = $sizes[0];
    // a product with several sizes/colours: the choice is made on the product page first
    if (($colors && !in_array($color, $colors, true)) || ($sizes && !in_array($size, $sizes, true))) redirect('product.php?id=' . $buy);
    $lines = [['product' => $bp, 'qty' => max(1, min(20, (int)($_GET['qty'] ?? 1), (int)$bp['stock'])), 'color' => $colors ? $color : '', 'size' => $sizes ? $size : '']];
} else {
    $buy = 0;
    $lines = cartLines();
}
$q = $lines ? ppQuote($lines) : null;

$countries = dhlCountries();

$pageTitle = tx('الطلب من خارج الجزائر', 'Commander depuis l\'étranger');
$noOrderModal = true;
require_once __DIR__ . '/includes/header.php';
?>
<section class="page-head slim">
  <div class="container">
    <nav class="breadcrumb"><a href="index.php"><?= tx('الرئيسية', 'Accueil') ?></a> / <span><?= e($pageTitle) ?></span></nav>
    <h1>🌍 <?= e($pageTitle) ?></h1>
  </div>
</section>

<section class="section">
  <div class="container">
  <?php if (!ppEnabled()): ?>
    <div class="empty-state"><?= icon('card', 46) ?><h3><?= tx('الدفع من الخارج غير متوفر حالياً', 'Le paiement depuis l\'étranger n\'est pas disponible pour le moment') ?></h3><a href="contact.php" class="btn btn-dark"><?= tx('تواصل معنا', 'Contactez-nous') ?></a></div>
  <?php elseif (!$lines): ?>
    <div class="empty-state"><?= icon('cartsm', 46) ?><h3><?= tx('السلة فارغة', 'Votre panier est vide') ?></h3><a href="shop.php" class="btn btn-dark"><?= tx('تصفح المنتجات', 'Voir les produits') ?></a></div>
  <?php else: ?>
    <div class="checkout-layout">
      <form class="checkout-form card" id="intlForm" novalidate>
        <input type="hidden" name="buy" value="<?= $buy ?>">
        <?php if ($buy): ?><input type="hidden" name="qty" value="<?= (int)$lines[0]['qty'] ?>"><input type="hidden" name="color" value="<?= e($lines[0]['color']) ?>"><input type="hidden" name="size" value="<?= e($lines[0]['size']) ?>"><?php endif; ?>
        <h3><?= tx('معلومات الشحن', 'Adresse de livraison') ?></h3>
        <p class="muted small"><?= tx('للزبائن خارج الجزائر: الدفع مسبقاً عبر PayPal أو البطاقة البنكية. داخل الجزائر؟', 'Clients hors d\'Algérie : paiement en ligne par PayPal ou carte bancaire. Vous êtes en Algérie ?') ?> <a href="<?= $buy ? 'product.php?id=' . $buy : 'checkout.php' ?>" class="link"><?= tx('اطلب بالدفع عند الاستلام', 'Commandez avec paiement à la livraison') ?></a></p>
        <div class="form-row">
          <label><?= tx('الاسم الكامل', 'Nom complet') ?> *<input type="text" name="name" required autocomplete="name"></label>
          <label><?= tx('البريد الإلكتروني', 'E-mail') ?> *<input type="email" name="email" required autocomplete="email" dir="ltr"></label>
        </div>
        <div class="form-row">
          <label><?= tx('الهاتف (مع رمز الدولة)', 'Téléphone (avec indicatif)') ?> *<input type="tel" name="phone" required autocomplete="tel" placeholder="+33 6 12 34 56 78" dir="ltr"></label>
          <label><?= tx('البلد', 'Pays') ?> *<select name="country" required autocomplete="country"><option value=""><?= tx('اختر البلد', 'Choisissez le pays') ?></option><?php foreach ($countries as $code => $cn): ?><option value="<?= $code ?>"><?= e($cn) ?></option><?php endforeach; ?></select></label>
        </div>
        <div class="form-row">
          <label><?= tx('المدينة', 'Ville') ?> *<input type="text" name="city" required autocomplete="address-level2"></label>
          <label><?= tx('الرمز البريدي', 'Code postal') ?> *<input type="text" name="zip" autocomplete="postal-code" dir="ltr"></label>
        </div>
        <label><?= tx('العنوان الكامل', 'Adresse complète') ?> *<input type="text" name="address" required autocomplete="street-address"></label>
        <label><?= tx('ملاحظات (اختياري)', 'Remarques (facultatif)') ?><textarea name="notes" rows="2"></textarea></label>
        <div class="form-error" id="intlError" hidden></div>
        <div id="ppButtons" class="pp-buttons"></div>
        <p class="muted small center"><?= icon('lock', 14) ?> <?= tx('دفع آمن عبر PayPal — يمكنك الدفع بحساب PayPal أو ببطاقة بنكية.', 'Paiement sécurisé PayPal — avec un compte PayPal ou une carte bancaire.') ?></p>
      </form>

      <aside class="summary">
        <h3><?= tx('طلبك', 'Votre commande') ?></h3>
        <?php foreach ($lines as $i => $l): $it = $q['items'][$i]; ?>
        <div class="sum-item">
          <img src="<?= e(productThumb($l['product'])) ?>" alt="">
          <div><strong><?= e(tr($l['product'], 'name')) ?></strong><?php if (!empty($l['size']) || !empty($l['color'])): ?><span class="muted"><?= e(trim(preg_replace('/\s*\(#[0-9a-fA-F]{6}\)/', '', variantText((string)$l['color'], (string)$l['size'])))) ?></span><?php endif; ?><span class="muted"><?= tx('الكمية:', 'Quantité :') ?> <?= (int)$l['qty'] ?></span></div>
          <b dir="ltr"><?= ppMoney($it['unit'] * $it['qty']) ?></b>
        </div>
        <?php endforeach; ?>
        <div class="sum-row"><span><?= tx('المنتجات', 'Articles') ?></span><strong dir="ltr"><?= ppMoney($q['item_total']) ?></strong></div>
        <div class="sum-row"><span><?= tx('الشحن الدولي', 'Livraison internationale') ?></span><strong dir="ltr"><?= $q['shipping'] ? ppMoney($q['shipping']) : tx('مجاني', 'Gratuite') ?></strong></div>
        <div class="sum-row total"><span><?= tx('الإجمالي', 'Total') ?></span><strong dir="ltr"><?= ppMoney($q['total']) ?></strong></div>
        <p class="muted small"><?= tx('الأسعار محوّلة من الدينار الجزائري.', 'Prix convertis depuis le dinar algérien.') ?></p>
      </aside>
    </div>
  <?php endif; ?>
  </div>
</section>

<?php if (ppEnabled() && $lines): ?>
<script src="https://www.paypal.com/sdk/js?client-id=<?= urlencode($cfg['client']) ?>&currency=<?= $cfg['currency'] ?>&intent=capture&locale=<?= isFr() ? 'fr_FR' : 'ar_EG' ?>" data-namespace="paypal"></script>
<script>
(function () {
  var form = document.getElementById('intlForm'), errBox = document.getElementById('intlError');
  var csrf = document.querySelector('meta[name="csrf"]').content;
  var showErr = function (m) { errBox.textContent = m; errBox.hidden = !m; if (m) errBox.scrollIntoView({ block: 'center', behavior: 'smooth' }); };
  var post = function (data) {
    var body = new URLSearchParams(data); body.append('csrf', csrf);
    return fetch('api/paypal.php', { method: 'POST', body: body, headers: { 'X-Requested-With': 'fetch' } }).then(function (r) { return r.json(); });
  };
  var formData = function () { var o = {}; new FormData(form).forEach(function (v, k) { o[k] = v; }); return o; };
  var valid = function () {
    var f = form, m = '';
    if (f.name.value.trim().length < 3) m = <?= json_encode(tx('يرجى كتابة الاسم الكامل', 'Veuillez saisir votre nom complet'), JSON_UNESCAPED_UNICODE) ?>;
    else if (!/^\S+@\S+\.\S+$/.test(f.email.value.trim())) m = <?= json_encode(tx('البريد الإلكتروني غير صحيح', 'Adresse e-mail invalide'), JSON_UNESCAPED_UNICODE) ?>;
    else if (!/^\+?[\d\s().-]{6,25}$/.test(f.phone.value.trim())) m = <?= json_encode(tx('رقم الهاتف غير صحيح (مع رمز الدولة)', 'Numéro de téléphone invalide (avec indicatif)'), JSON_UNESCAPED_UNICODE) ?>;
    else if (!f.country.value) m = <?= json_encode(tx('يرجى اختيار البلد', 'Veuillez choisir le pays'), JSON_UNESCAPED_UNICODE) ?>;
    else if (f.city.value.trim().length < 2) m = <?= json_encode(tx('يرجى كتابة المدينة', 'Veuillez saisir la ville'), JSON_UNESCAPED_UNICODE) ?>;
    else if (f.address.value.trim().length < 5) m = <?= json_encode(tx('يرجى كتابة العنوان الكامل', 'Veuillez saisir l\'adresse complète'), JSON_UNESCAPED_UNICODE) ?>;
    else if (!f.zip.value.trim() && <?= json_encode(DHL_NO_ZIP) ?>.indexOf(f.country.value) < 0) m = <?= json_encode(tx('يرجى كتابة الرمز البريدي', 'Veuillez saisir le code postal'), JSON_UNESCAPED_UNICODE) ?>;
    showErr(m); return !m;
  };
  if (!window.paypal) { showErr(<?= json_encode(tx('تعذر تحميل PayPal، تحقق من الاتصال ثم أعد تحميل الصفحة', 'Impossible de charger PayPal, vérifiez la connexion et rechargez la page'), JSON_UNESCAPED_UNICODE) ?>); return; }
  paypal.Buttons({
    style: { layout: 'vertical', shape: 'pill', label: 'pay' },
    onClick: function (data, actions) { return valid() ? actions.resolve() : actions.reject(); },
    createOrder: function () {
      var d = formData(); d.action = 'create';
      return post(d).then(function (r) { if (!r.id) { showErr(r.error || 'Error'); throw new Error(r.error); } return r.id; });
    },
    onApprove: function (data, actions) {
      showErr('');
      return post({ action: 'capture', orderID: data.orderID }).then(function (r) {
        if (r.ok) { location.href = r.redirect; return; }
        if (r.restart) return actions.restart();
        showErr(r.error || 'Error');
      });
    },
    onError: function () { showErr(<?= json_encode(tx('حدث خطأ في الدفع، حاول مرة أخرى', 'Erreur de paiement, veuillez réessayer'), JSON_UNESCAPED_UNICODE) ?>); }
  }).render('#ppButtons');
})();
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
