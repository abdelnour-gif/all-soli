<?php
/** Platform home (replaces the theme front page when "take_homepage" is on). */
defined('QP_APP') || exit;
require_once __DIR__ . '/partials/layout.php';
require_once __DIR__ . '/partials/components.php';

$c = qp();
$lang = qp_lang();
$L = fn(string $ar, string $en) => $lang === 'en' ? $en : $ar;
$db = $c->db;
$cats = $db->getResults("SELECT slug, name_ar, name_en, icon FROM {$db->table('categories')} WHERE is_active = 1 ORDER BY sort_order LIMIT 6");
$cities = $db->getResults("SELECT id, name_ar, name_en FROM {$db->table('cities')} WHERE is_active = 1 ORDER BY id");
$featured = $c->providers->search([], 1, 8);
$support = $c->settings->string('support_phone');

qp_header(qp_brand() . ' — ' . $L('انتظر دورك بكل سهولة', 'Wait for your turn, the easy way'), ['wide' => true, 'nav' => 'home']);
?>
<section class="qp-hero">
  <div class="qp-container qp-hero-grid">
    <div>
      <h1><?php echo esc_html($L('انتظر دورك بكل سهولة', 'Wait for your turn')); ?><em><?php echo esc_html($L('وتوفير الوقت', 'and save time')); ?></em></h1>
      <p class="qp-hero-sub"><?php echo esc_html($L('منصة الطوابير الرقمية متعددة المزوّدين — خذ رقمك من هاتفك وتابع دورك أينما كنت.', 'A multi-provider digital queue — take a number from your phone and follow your turn from anywhere.')); ?></p>
      <div class="qp-feats">
        <div class="qp-feat"><div class="qp-feat-ico"><?php echo qp_icon('store'); ?></div><b><?php echo esc_html($L('متعدد المزوّدين', 'Many providers')); ?></b><small><?php echo esc_html($L('خدمات متنوعة في مكان واحد', 'Many services, one place')); ?></small></div>
        <div class="qp-feat"><div class="qp-feat-ico"><?php echo qp_icon('user'); ?></div><b><?php echo esc_html($L('بدون حساب', 'No account')); ?></b><small><?php echo esc_html($L('رقم هاتفك يكفي', 'Your phone is enough')); ?></small></div>
        <div class="qp-feat"><div class="qp-feat-ico"><?php echo qp_icon('bolt'); ?></div><b><?php echo esc_html($L('سريع وسهل', 'Fast & simple')); ?></b><small><?php echo esc_html($L('احجز رقمك في ثوانٍ', 'Book in seconds')); ?></small></div>
      </div>
    </div>
    <div class="qp-phone" aria-hidden="true">
      <div class="qp-phone-screen">
        <div class="qp-phone-top"><span class="qp-brand-mark"></span><?php echo esc_html(qp_brand()); ?></div>
        <div class="qp-phone-card">
          <small><?php echo esc_html($L('رقمك في الطابور', 'Your number')); ?></small>
          <strong>A027</strong>
          <span><?php echo esc_html($L('صالون الأناقة', 'Elegance Salon')); ?></span>
          <div style="display:flex;justify-content:space-around;margin-top:14px;font-size:11px;color:var(--qp-text-2)">
            <div><b style="display:block;font-size:20px;color:var(--qp-text);font-family:var(--qp-num)">3</b><?php echo esc_html($L('أمامك', 'Ahead')); ?></div>
            <div><b style="display:block;font-size:20px;color:var(--qp-text);font-family:var(--qp-num)">15</b><?php echo esc_html($L('دقيقة', 'min')); ?></div>
          </div>
          <span class="qp-phone-pill"><?php echo esc_html($L('متابعة حيّة', 'Live tracking')); ?></span>
        </div>
      </div>
    </div>
  </div>
</section>

<div class="qp-container qp-search">
  <form action="<?php echo esc_url(url('/explore/')); ?>" method="get" role="search">
    <div class="qp-input-ico"><?php echo qp_icon('search', 18); ?><input class="qp-input" name="q" placeholder="<?php echo esc_attr(qp_t('search_ph')); ?>" aria-label="<?php echo esc_attr(qp_t('search')); ?>"></div>
    <select class="qp-input" name="city_id" aria-label="<?php echo esc_attr(qp_t('all_cities')); ?>">
      <option value=""><?php echo esc_html(qp_t('all_cities')); ?></option>
      <?php foreach ($cities as $ci): ?><option value="<?php echo (int) $ci['id']; ?>"><?php echo esc_html(qp_name($ci)); ?></option><?php endforeach; ?>
    </select>
    <select class="qp-input" name="category" aria-label="<?php echo esc_attr(qp_t('all_categories')); ?>">
      <option value=""><?php echo esc_html(qp_t('all_categories')); ?></option>
      <?php foreach ($cats as $ca): ?><option value="<?php echo esc_attr($ca['slug']); ?>"><?php echo esc_html(qp_name($ca)); ?></option><?php endforeach; ?>
    </select>
    <button class="qp-btn" type="submit"><?php echo qp_icon('search', 18) . esc_html(qp_t('search')); ?></button>
  </form>
</div>

<section class="qp-container qp-sec" id="my-tickets" hidden>
  <div class="qp-sec-head"><h2><?php echo esc_html(qp_t('my_tickets')); ?></h2></div>
  <div class="qp-mytickets" id="qp-mytickets"></div>
</section>

<section class="qp-container qp-sec">
  <div class="qp-sec-head"><h2><?php echo esc_html($L('التصنيفات', 'Categories')); ?></h2><a href="<?php echo esc_url(url('/explore/')); ?>"><?php echo esc_html($L('عرض الكل', 'View all')); ?></a></div>
  <div class="qp-cats">
    <?php foreach ($cats as $ca): ?>
      <a class="qp-cat" href="<?php echo esc_url(add_query_arg('category', $ca['slug'], url('/explore/'))); ?>">
        <span class="qp-cat-ico"><?php echo qp_icon($ca['icon'], 24); ?></span><?php echo esc_html(qp_name($ca)); ?>
      </a>
    <?php endforeach; ?>
  </div>
</section>

<?php if ($featured): ?>
<section class="qp-container qp-sec" style="padding-top:0">
  <div class="qp-sec-head"><h2><?php echo esc_html($L('المزوّدون', 'Providers')); ?></h2><a href="<?php echo esc_url(url('/explore/')); ?>"><?php echo esc_html($L('عرض الكل', 'View all')); ?></a></div>
  <div class="qp-cards"><?php foreach ($featured as $p) echo qp_provider_card($p); ?></div>
</section>
<?php endif; ?>

<section class="qp-container qp-sec" id="how" style="padding-top:0">
  <div class="qp-sec-head"><h2><?php echo esc_html($L('كيف يعمل؟', 'How it works')); ?></h2></div>
  <div class="qp-steps">
    <div class="qp-step"><span class="qp-step-n"></span><div><b><?php echo esc_html($L('اختر المزوّد', 'Pick a provider')); ?></b><p><?php echo esc_html($L('ابحث بالتصنيف أو المدينة، أو امسح رمز QR على باب المحل.', 'Search by category or city, or scan the QR code at the door.')); ?></p></div></div>
    <div class="qp-step"><span class="qp-step-n"></span><div><b><?php echo esc_html($L('خذ رقمك', 'Take a number')); ?></b><p><?php echo esc_html($L('أدخل رقم هاتفك فقط، بدون حساب ولا تطبيق.', 'Just your phone number — no account, no app.')); ?></p></div></div>
    <div class="qp-step"><span class="qp-step-n"></span><div><b><?php echo esc_html($L('تابع دورك', 'Follow your turn')); ?></b><p><?php echo esc_html($L('ترى عدد من أمامك مباشرة، ونخبرك عندما يقترب دورك.', 'See how many are ahead live — we tell you when your turn is near.')); ?></p></div></div>
  </div>
</section>

<section class="qp-container qp-sec" style="padding-top:0">
  <div class="qp-cta">
    <div>
      <h3><?php echo esc_html($L('هل أنت صاحب محل أو عيادة؟', 'Run a shop or a clinic?')); ?></h3>
      <p><?php echo esc_html($L('نظّم طابورك، قلّل الزحام، وابدأ بـ 10 حجوزات مجانية.', 'Organise your queue, end the crowding — start with 10 free bookings.')); ?></p>
    </div>
    <?php if ($support): ?>
      <a class="qp-btn" href="tel:<?php echo esc_attr($support); ?>"><?php echo qp_icon('phone', 18) . esc_html(qp_t('contact')); ?></a>
    <?php else: ?>
      <a class="qp-btn" href="<?php echo esc_url(url('/dashboard/')); ?>"><?php echo esc_html(qp_t('provider_login')); ?></a>
    <?php endif; ?>
  </div>
</section>

<?php qp_public_scripts(); ?>
<script<?php echo nonce_attr(); ?>>
(function () {
  var list = QP.tickets();
  if (!list.length) return;
  var wrap = document.getElementById('qp-mytickets');
  var T = <?php echo json_enc([
      'ahead' => $L('أمامك', 'ahead'), 'turn' => $L('حان دورك!', "It's your turn!"),
      'in_service' => $L('قيد الخدمة', 'In service'), 'waiting' => $L('في الانتظار', 'Waiting'),
  ]); ?>;
  function draw(items) {
    wrap.innerHTML = items.map(function (x) {
      var d = x.d, turn = d.status === 'called';
      var sub = turn ? T.turn : d.status === 'in_service' ? T.in_service : (d.ahead + ' ' + T.ahead);
      return '<a class="qp-mt' + (turn ? ' is-turn' : '') + '" href="' + QP.cfg.home + 't/' + encodeURIComponent(x.t) + '/">' +
        '<span class="qp-mt-code">' + QP.esc(d.code) + '</span><span><b>' + QP.esc(QP.cfg.lang === 'en' && d.provider.name_en ? d.provider.name_en : d.provider.name_ar) + '</b><small>' + QP.esc(sub) + '</small></span>' +
        '<?php echo str_replace("'", "\\'", qp_icon('arrow', 18)); ?></a>';
    }).join('');
    document.getElementById('my-tickets').hidden = !items.length;
  }
  function refresh() {
    Promise.all(QP.tickets().map(function (x) {
      return QP.ticket(x.t).then(function (r) {
        if (r.status === 404 || (r.data && ['completed', 'cancelled', 'no_show', 'expired'].indexOf(r.data.status) >= 0)) { QP.removeTicket(x.t); return null; }
        return r.ok ? { t: x.t, d: r.data } : null;
      }).catch(function () { return null; });
    })).then(function (rows) { draw(rows.filter(Boolean)); });
  }
  refresh();
  setInterval(function () { if (!document.hidden) refresh(); }, 10000);
})();
</script>
<?php qp_footer(['footer' => true]);
