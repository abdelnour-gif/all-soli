</main>
<?php
$footerPages = getDB()->query('SELECT slug, title, title_fr FROM pages WHERE in_footer = 1 ORDER BY sort, id')->fetchAll();
$quickCats = array_slice(topCategories(), 0, 8);
$storeName = setting('store_name', 'MONTA9A');
// WhatsApp number: the one in Settings, or the store phone when it is empty.
$waNum = preg_replace('/\D/', '', setting('whatsapp') ?: setting('phone'));
if (!str_starts_with($waNum, '213') && str_starts_with($waNum, '0')) $waNum = '213' . substr($waNum, 1);
?>
<?php if (setting('newsletter_on') === '1'): ?>
<section class="news-strip">
  <div class="container news-inner">
    <div class="news-copy">
      <strong><?= e(st('newsletter_title')) ?></strong>
      <?php if ($nt = st('newsletter_text')): ?><span><?= e($nt) ?></span><?php endif; ?>
    </div>
    <form class="news-form" id="newsForm">
      <input type="email" name="email" placeholder="<?= tx('أدخل بريدك الإلكتروني', 'Votre adresse e-mail') ?>" required aria-label="<?= tx('البريد الإلكتروني', 'E-mail') ?>">
      <button type="submit"><?= icon('send', 17) ?> <span><?= tx('اشترك', "S'inscrire") ?></span></button>
    </form>
  </div>
</section>
<?php endif; ?>

<footer class="site-footer">
  <div class="container footer-grid">
    <div class="f-col f-brand">
      <a href="index.php" class="f-logo">
        <?php if (($logo = setting('logo_footer')) && setting('logo_with_name') !== '1'): ?><img src="<?= e($logo) ?>" alt="<?= e($storeName) ?>">
        <?php else: ?><?php if ($logo): ?><img class="logo-badge" src="<?= e($logo) ?>" alt="" width="64" height="64"><?php else: ?><span class="f-lotus"><?= icon(setting('logo_icon') ?: 'lotus', 38) ?></span><?php endif; ?><span class="f-words"><b><?= e($storeName) ?></b><small><?= e(setting('store_tagline')) ?></small></span><?php endif; ?>
      </a>
      <p><?= e(st('footer_about')) ?></p>
      <div class="f-social">
        <?php foreach (['facebook', 'instagram', 'tiktok', 'youtube', 'pinterest'] as $s): if ($url = setting($s)): ?>
          <a href="<?= e($url) ?>" target="_blank" rel="noopener" aria-label="<?= $s ?>"><?= icon($s, 18) ?></a>
        <?php endif; endforeach; ?>
        <?php if ($waNum): ?>
          <a href="https://wa.me/<?= e($waNum) ?>" target="_blank" rel="noopener" aria-label="WhatsApp"><?= icon('whatsapp', 18) ?></a>
        <?php endif; ?>
      </div>
    </div>
    <div class="f-col">
      <h4><?= offerOnly() ? tx('الكتب', 'Les livres') : tx('أقسام المنتجات', 'Catégories') ?></h4>
      <?php if (offerOnly()): foreach ([(int)setting('landing_bundle'), (int)setting('landing_p1'), (int)setting('landing_p2')] as $bid): if ($bp = getProduct($bid)): ?><a href="index.php?pick=<?= $bid ?>#order"><?= e(tr($bp, 'name')) ?></a><?php endif; endforeach; else: ?>
      <?php foreach ($quickCats as $qc): ?><a href="shop.php?cat=<?= (int)$qc['id'] ?>"><?= e(tr($qc, 'name')) ?></a><?php endforeach; ?>
      <?php if ((int)getDB()->query('SELECT COUNT(*) FROM products WHERE active = 1 AND old_price > price')->fetchColumn()): ?><a href="shop.php?sale=1"><?= e(st('sale_title', tx('التخفيضات', 'Promotions'))) ?></a><?php endif; ?>
      <?php endif; ?>
    </div>
    <div class="f-col">
      <h4><?= tx('روابط سريعة', 'Liens rapides') ?></h4>
      <a href="shop.php"><?= tx('المنتجات', 'Produits') ?></a>
      <a href="cart.php"><?= tx('سلة المشتريات', 'Panier') ?></a>
      <a href="wishlist.php"><?= tx('المفضلة', 'Mes favoris') ?></a>
      <a href="track.php"><?= tx('تتبع الطلبات', 'Suivi de commande') ?></a>
      <a href="contact.php"><?= tx('تواصل معنا', 'Contactez-nous') ?></a>
    </div>
    <div class="f-col">
      <h4><?= tx('السياسات', 'Politiques') ?></h4>
      <?php foreach ($footerPages as $fp): ?><a href="page.php?p=<?= e($fp['slug']) ?>"><?= e(tr($fp, 'title')) ?></a><?php endforeach; ?>
    </div>
    <div class="f-col f-contact">
      <h4><?= tx('تواصل معنا', 'Contact') ?></h4>
      <?php if ($ph = setting('phone')): ?>
      <a href="tel:<?= e(preg_replace('/\s+/', '', $ph)) ?>" class="f-line"><?= icon('phone', 16) ?> <span dir="ltr"><?= e($ph) ?></span></a>
      <?php endif; ?>
      <?php if ($waNum): ?>
      <a href="https://wa.me/<?= e($waNum) ?>" target="_blank" rel="noopener" class="f-line"><?= icon('whatsapp', 16) ?> <span><?= tx('تواصل عبر واتساب', 'Écrivez-nous sur WhatsApp') ?></span></a>
      <?php endif; ?>
      <?php if ($ad = st('address')): ?>
      <span class="f-line"><?= icon('pin', 16) ?> <span><?= e($ad) ?></span></span>
      <?php endif; ?>
      <div class="pay-badges">
        <span class="pay"><?= icon('cash', 14) ?> <?= tx('الدفع عند الاستلام', 'Paiement à la livraison') ?></span>
        <span class="pay"><?= icon('truck', 14) ?> <?= tx('58 ولاية', '58 wilayas') ?></span>
      </div>
    </div>
  </div>
  <div class="footer-bottom">
    <div class="container fb-inner">
      <span>© <?= date('Y') ?> <?= e($storeName) ?>. <?= tx('جميع الحقوق محفوظة.', 'Tous droits réservés.') ?></span>
      <?php if ($tl = st('footer_tagline')): ?><span class="fb-tag"><?= icon('heart', 14) ?> <?= e($tl) ?></span><?php endif; ?>
    </div>
  </div>
</footer>

<?php if (!offerOnly()): $curPage = basename($_SERVER['SCRIPT_NAME'] ?? ''); ?>
<nav class="mbar" aria-label="<?= tx('التنقل السريع', 'Navigation rapide') ?>">
  <a href="index.php" class="<?= $curPage === 'index.php' ? 'on' : '' ?>"><?= icon('home', 21) ?><span><?= tx('الرئيسية', 'Accueil') ?></span></a>
  <a href="shop.php" class="<?= $curPage === 'shop.php' ? 'on' : '' ?>"><?= icon('grid', 21) ?><span><?= tx('المنتجات', 'Produits') ?></span></a>
  <a href="cart.php" class="<?= $curPage === 'cart.php' ? 'on' : '' ?>"><span class="mb-ic"><?= icon('cartsm', 21) ?><i id="mCartCount"><?= cartCount() ?></i></span><span><?= tx('السلة', 'Panier') ?></span></a>
  <a href="wishlist.php" class="<?= $curPage === 'wishlist.php' ? 'on' : '' ?>"><span class="mb-ic"><?= icon('heart', 21) ?><i id="mWishCount"><?= count(wishlist()) ?></i></span><span><?= tx('المفضلة', 'Favoris') ?></span></a>
  <a href="track.php" class="<?= $curPage === 'track.php' ? 'on' : '' ?>"><?= icon('user', 21) ?><span><?= tx('طلبي', 'Commande') ?></span></a>
</nav>
<?php endif; ?>
<button type="button" class="to-top" id="toTop" aria-label="<?= tx('إلى الأعلى', 'Haut de page') ?>" hidden><?= icon('chev-d', 18) ?></button>
<script>(function(){var b=document.getElementById('toTop');addEventListener('scroll',function(){b.hidden=scrollY<500},{passive:true});b.onclick=function(){scrollTo({top:0,behavior:'smooth'})};})();</script>

<?php if (setting('wa_float_on', '1') === '1' && $waNum): ?>
<a class="wa-float" href="https://wa.me/<?= e($waNum) ?>" target="_blank" rel="noopener" aria-label="WhatsApp"><?= icon('whatsapp', 28) ?></a>
<?php endif; ?>

<?php
// Floating "shop now" button: taken from the first visible slide that has button text. Hidden on cart/checkout.
$page = basename($_SERVER['SCRIPT_NAME'] ?? '');
if (setting('float_cta_on') === '1' && !in_array($page, ['checkout.php', 'cart.php', 'order-success.php'], true)):
    $fc = getDB()->query("SELECT btn_text, btn_text_fr, btn_link FROM slides WHERE active = 1 AND TRIM(COALESCE(btn_text, '')) != '' ORDER BY sort, id LIMIT 1")->fetch();
    $fcHref = $fc ? ($fc['btn_link'] ?: 'shop.php') : '';
    if ($fc && !($page === 'shop.php' && basename(parse_url($fcHref, PHP_URL_PATH) ?: '') === 'shop.php')): ?>
<a class="float-cta" href="<?= e($fcHref) ?>"><?= icon('bag', 19) ?><span><?= e(tr($fc, 'btn_text')) ?></span></a>
<?php endif; endif; ?>

<?php if (empty($noOrderModal)) include __DIR__ . '/order-modal.php'; ?>

<script>window.T = <?= json_encode(jsTexts(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>
<script src="assets/js/main.js?v=50"></script>
</body>
</html>
