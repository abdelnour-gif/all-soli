<?php
/** Public provider page: domain.com/{slug}/. Server-rendered profile; live numbers poll the API. */
defined('QP_APP') || exit;
require_once __DIR__ . '/partials/layout.php';
require_once __DIR__ . '/partials/components.php';

use QueuePro\Support\Messages;
use QueuePro\Support\QueueException;

$c = qp();
$lang = qp_lang();
$L = fn(string $ar, string $en) => $lang === 'en' ? $en : $ar;
try {
    $p = $c->providers->getPublicBySlug((string) $slug);
} catch (QueueException $e) {
    http_status(404);
    qp_header('404');
    echo '<div class="qp-section" style="margin-top:24px"><div class="qp-empty">' . qp_icon('search', 32) . '<p>' . esc_html(Messages::get('provider_not_found', $lang)) . '</p><a href="' . esc_url(url('/explore/')) . '">' . esc_html(qp_t('providers')) . '</a></div></div>';
    qp_footer();
    return;
}
$pid = (int) $p['id'];
$db = $c->db;
$name = qp_name($p);
$desc = $lang === 'en' && $p['description_en'] ? $p['description_en'] : $p['description_ar'];
$services = $c->providers->services($pid);
$live = $c->queue->liveStatus($pid);
$symbol = $db->getVar("SELECT symbol FROM {$db->table('currencies')} WHERE code = %s", [$p['currency_code']]) ?: $p['currency_code'];
$cat = $p['category_id'] ? $db->getRow("SELECT name_ar, name_en, icon FROM {$db->table('categories')} WHERE id = %d", [(int) $p['category_id']]) : null;
$city = $p['city_id'] ? $db->getRow("SELECT name_ar, name_en FROM {$db->table('cities')} WHERE id = %d", [(int) $p['city_id']]) : null;
$reviews = $c->ratings->listPublic($pid, 20);
$dist = $c->ratings->distribution($pid);
$otp = $c->queue->otpRequired();
$recovery = $c->otp->available();
$mapsUrl = $p['maps_url'] ?: ($p['address'] ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($p['address'] . ($city ? ', ' . $city['name_en'] : '')) : null);
$icon = $cat['icon'] ?? 'store';

qp_header($name . ' — ' . qp_brand(), ['body_class' => 'qp-provider-page']);
?>
<div class="qp-pv-cover" style="<?php echo $p['cover_url'] ? 'background-image:url(' . esc_url($p['cover_url']) . ')' : ''; ?>"></div>
<div class="qp-pv-head">
  <div class="qp-pv-logo" style="<?php echo $p['logo_url'] ? 'background-image:url(' . esc_url($p['logo_url']) . ')' : ''; ?>"><?php echo $p['logo_url'] ? '' : qp_icon($icon, 30); ?></div>
  <h1><?php echo esc_html($name); ?></h1>
  <div class="qp-pcard-meta">
    <?php if ((int) $p['rating_count'] > 0): ?>
      <span class="qp-stars"><?php echo qp_icon('star', 14) . esc_html(number_format((float) $p['rating_avg'], 1)); ?> <small>(<?php echo (int) $p['rating_count'] . ' ' . esc_html(qp_t('reviews')); ?>)</small></span>
    <?php endif; ?>
    <?php if ($city): ?><span><?php echo qp_icon('pin', 14) . esc_html(qp_name($city)); ?></span><?php endif; ?>
    <?php if ($cat): ?><span class="qp-chip"><?php echo esc_html(qp_name($cat)); ?></span><?php endif; ?>
  </div>
  <?php if ($desc): ?><p class="qp-muted" style="margin:10px 0 0;font-size:14px"><?php echo esc_html($desc); ?></p><?php endif; ?>
</div>

<div class="qp-pv-stats" aria-live="polite">
  <div class="qp-pv-now"><small><span class="qp-live-dot"></span> <?php echo esc_html(qp_t('now_serving')); ?></small><strong id="qp-current"><?php echo esc_html($live['current'] ?? '—'); ?></strong></div>
  <div class="qp-pv-stat"><?php echo qp_icon('users', 18); ?><small><?php echo esc_html(qp_t('waiting')); ?></small><strong id="qp-waiting"><?php echo (int) $live['waiting']; ?></strong></div>
  <div class="qp-pv-stat"><?php echo qp_icon('clock', 18); ?><small><?php echo esc_html(qp_t('eta')); ?></small><strong><span id="qp-eta"><?php echo (int) $live['estimated_wait']; ?></span> <small><?php echo esc_html(qp_t('min')); ?></small></strong></div>
</div>

<div class="qp-section" id="qp-existing" hidden>
  <a class="qp-mt" id="qp-existing-link" href="#" style="margin-top:12px">
    <span class="qp-mt-code" id="qp-existing-code"></span><span><b><?php echo esc_html(qp_t('have_ticket')); ?></b><small id="qp-existing-sub"></small></span><?php echo qp_icon('arrow', 18); ?>
  </a>
</div>

<div class="qp-pv-book">
  <div id="qp-paused" class="qp-notice qp-notice--warn" style="margin-bottom:10px" <?php echo $live['accepting'] ? 'hidden' : ''; ?>><?php echo qp_icon('bell', 18); ?><span id="qp-paused-text"><?php echo esc_html($live['reason'] ? Messages::get($live['reason'], $lang) : ''); ?></span></div>
  <button class="qp-btn" id="qp-open-book" type="button" <?php echo $live['accepting'] ? '' : 'disabled'; ?>><?php echo qp_icon('ticket', 20) . esc_html(qp_t('book_now')); ?></button>
  <?php if ($recovery): ?><p class="qp-hint" style="margin-top:6px"><button class="qp-linkbtn" type="button" id="qp-open-recover"><?php echo esc_html($L('فقدت رابط تذكرتك؟', 'Lost your ticket link?')); ?></button></p><?php endif; ?>
</div>

<div class="qp-tabs" role="tablist">
  <button role="tab" aria-selected="true" data-tab="services"><?php echo esc_html(qp_t('services')); ?></button>
  <button role="tab" aria-selected="false" data-tab="reviews"><?php echo esc_html(qp_t('reviews_tab')); ?><?php echo (int) $p['rating_count'] ? ' (' . (int) $p['rating_count'] . ')' : ''; ?></button>
  <button role="tab" aria-selected="false" data-tab="location"><?php echo esc_html(qp_t('location')); ?></button>
</div>

<div class="qp-tabpanel" data-panel="services">
  <?php if ($services): ?>
    <p class="qp-hint" style="text-align:start;margin:0 0 10px"><?php echo esc_html($L('اختر الخدمة (اختياري) ثم احجز دورك', 'Pick a service (optional), then take a number')); ?></p>
    <?php foreach ($services as $s): ?>
      <button type="button" class="qp-svc" aria-pressed="false" data-id="<?php echo (int) $s['id']; ?>" data-name="<?php echo esc_attr(qp_name($s)); ?>">
        <span class="qp-svc-ico"><?php echo qp_icon($icon, 20); ?></span>
        <span><b><?php echo esc_html(qp_name($s)); ?></b><small><?php echo qp_icon('clock', 12) . ' ' . (int) $s['duration_minutes'] . ' ' . esc_html(qp_t('min')); ?></small></span>
        <span class="qp-svc-price"><?php echo (float) $s['price'] > 0 ? esc_html(qp_money($s['price'], $symbol)) : ''; ?></span>
      </button>
    <?php endforeach; ?>
  <?php else: ?>
    <div class="qp-empty"><?php echo esc_html($L('لم يضف المزوّد خدماته بعد.', 'No services listed yet.')); ?></div>
  <?php endif; ?>
</div>

<div class="qp-tabpanel" data-panel="reviews" hidden>
  <?php if ((int) $p['rating_count'] > 0): $max = max(1, max($dist)); ?>
    <div class="qp-rv-sum">
      <div class="qp-rv-big"><strong><?php echo esc_html(number_format((float) $p['rating_avg'], 1)); ?></strong><span class="qp-starline"><?php echo str_repeat('★', (int) round((float) $p['rating_avg'])) . '<span style="color:#d6dde3">' . str_repeat('★', 5 - (int) round((float) $p['rating_avg'])) . '</span>'; ?></span><br><small><?php echo (int) $p['rating_count'] . ' ' . esc_html(qp_t('reviews')); ?></small></div>
      <div class="qp-rv-bars"><?php foreach ($dist as $star => $n): ?><div><span><?php echo (int) $star; ?></span><i><span style="width:<?php echo round($n / $max * 100); ?>%"></span></i><span><?php echo (int) $n; ?></span></div><?php endforeach; ?></div>
    </div>
    <?php foreach ($reviews as $r): ?>
      <div class="qp-rv">
        <div class="qp-rv-top"><b><?php echo esc_html($r['customer_name'] ? mb_substr($r['customer_name'], 0, 20) : $L('عميل', 'Customer')); ?></b><span class="qp-starline"><?php echo str_repeat('★', (int) $r['stars']) . '<span style="color:#d6dde3">' . str_repeat('★', 5 - (int) $r['stars']) . '</span>'; ?></span></div>
        <?php if ($r['comment']): ?><p><?php echo esc_html($r['comment']); ?></p><?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <div class="qp-empty"><?php echo qp_icon('star', 28); ?><p><?php echo esc_html($L('لا توجد تقييمات بعد. التقييم متاح لمن أكمل خدمته.', 'No reviews yet. Customers can rate after their service.')); ?></p></div>
  <?php endif; ?>
</div>

<div class="qp-tabpanel" data-panel="location" hidden>
  <div class="qp-loc">
    <?php if ($p['address']): ?><div class="qp-loc-row"><?php echo qp_icon('pin'); ?><span><?php echo esc_html($p['address'] . ($city ? '، ' . qp_name($city) : '')); ?></span></div><?php endif; ?>
    <?php if ($mapsUrl): ?><a class="qp-loc-row" href="<?php echo esc_url($mapsUrl); ?>" target="_blank" rel="noopener"><?php echo qp_icon('map'); ?><span><?php echo esc_html($L('فتح في خرائط Google', 'Open in Google Maps')); ?></span></a><?php endif; ?>
    <?php if ($p['phone']): ?><a class="qp-loc-row" href="tel:<?php echo esc_attr($p['phone']); ?>"><?php echo qp_icon('phone'); ?><span dir="ltr"><?php echo esc_html($p['phone']); ?></span></a><?php endif; ?>
    <?php if (!$p['address'] && !$p['phone']): ?><div class="qp-empty"><?php echo esc_html($L('لم تُضف معلومات الموقع بعد.', 'No location details yet.')); ?></div><?php endif; ?>
  </div>
</div>
<div style="height:24px"></div>

<!-- Booking sheet -->
<dialog class="qp-sheet" id="qp-book">
  <form class="qp-sheet-in" id="qp-book-form" novalidate>
    <div class="qp-sheet-head"><h2><?php echo esc_html(qp_t('book_now')); ?></h2><button type="button" class="qp-x" data-close aria-label="close">×</button></div>
    <div class="qp-selected-svc" id="qp-svc-line" hidden><span><?php echo esc_html(qp_t('services')); ?>: <b id="qp-svc-name"></b></span><button class="qp-linkbtn" type="button" id="qp-svc-clear"><?php echo esc_html($L('إزالة', 'Remove')); ?></button></div>
    <div id="qp-step-form">
      <div class="qp-field"><label for="qp-phone"><?php echo esc_html(qp_t('phone')); ?></label>
        <input id="qp-phone" name="phone" class="qp-input" type="tel" inputmode="tel" autocomplete="tel" dir="ltr" placeholder="05xx xx xx xx" required></div>
      <div class="qp-field"><label for="qp-name"><?php echo esc_html(qp_t('name')); ?></label>
        <input id="qp-name" name="name" class="qp-input" type="text" autocomplete="given-name" maxlength="80"></div>
    </div>
    <div id="qp-step-code" hidden>
      <p class="qp-muted" style="margin:0 0 10px;font-size:14px"><?php echo esc_html($L('أرسلنا رمزاً من 6 أرقام إلى', 'We sent a 6-digit code to')); ?> <b dir="ltr" id="qp-code-phone"></b> <button type="button" class="qp-linkbtn" id="qp-change-phone"><?php echo esc_html($L('تغيير', 'Change')); ?></button></p>
      <div class="qp-field"><label for="qp-code"><?php echo esc_html($L('رمز التحقق', 'Verification code')); ?></label>
        <input id="qp-code" name="otp_code" class="qp-input qp-otp" inputmode="numeric" autocomplete="one-time-code" maxlength="6" dir="ltr"></div>
      <p class="qp-hint" style="margin:-4px 0 10px"><button type="button" class="qp-linkbtn" id="qp-resend" disabled></button></p>
    </div>
    <div class="qp-hp" aria-hidden="true"><input name="website" tabindex="-1" autocomplete="off"></div>
    <div id="qp-error" class="qp-notice qp-notice--error" style="margin-bottom:12px" hidden></div>
    <button class="qp-btn" id="qp-submit" type="submit"></button>
    <p class="qp-hint"><?php echo esc_html(qp_t('no_account')); ?></p>
  </form>
</dialog>

<?php if ($recovery): ?>
<dialog class="qp-sheet" id="qp-recover">
  <form class="qp-sheet-in" id="qp-recover-form" novalidate>
    <div class="qp-sheet-head"><h2><?php echo esc_html($L('استرجاع تذكرتك', 'Recover your ticket')); ?></h2><button type="button" class="qp-x" data-close aria-label="close">×</button></div>
    <p class="qp-muted" style="margin:0 0 12px;font-size:14px"><?php echo esc_html($L('أدخل رقم الهاتف الذي حجزت به وسنرسل لك رمزاً.', 'Enter the phone you booked with and we will text you a code.')); ?></p>
    <div class="qp-field"><input name="phone" class="qp-input" type="tel" inputmode="tel" dir="ltr" placeholder="05xx xx xx xx" required></div>
    <div class="qp-field" data-code hidden><input name="code" class="qp-input qp-otp" inputmode="numeric" autocomplete="one-time-code" maxlength="6" dir="ltr" placeholder="••••••"></div>
    <div class="qp-notice qp-notice--error" data-err style="margin-bottom:12px" hidden></div>
    <button class="qp-btn" type="submit"><?php echo esc_html($L('إرسال الرمز', 'Send code')); ?></button>
  </form>
</dialog>
<?php endif; ?>

<?php qp_public_scripts(['slug' => $p['slug'], 'otp' => $otp]); ?>
<script<?php echo nonce_attr(); ?>>
(function () {
  var C = QP.cfg, $ = function (id) { return document.getElementById(id); };
  var T = <?php echo json_enc([
      'send_code' => $L('إرسال رمز التحقق', 'Send verification code'),
      'confirm'   => $L('تأكيد الحجز', 'Confirm booking'),
      'book'      => qp_t('book_now'),
      'resend'    => $L('إعادة الإرسال', 'Resend code'),
      'resend_in' => $L('إعادة الإرسال بعد {s} ث', 'Resend in {s}s'),
      'ahead'     => $L('أمامك', 'ahead'),
      'turn'      => $L('حان دورك!', "It's your turn!"),
      'verify'    => $L('تأكيد', 'Verify'),
      'reasons'   => [
          'queue_closed' => Messages::get('queue_closed', $lang),
          'booking_paused' => Messages::get('booking_paused', $lang),
          'provider_unavailable' => Messages::get('provider_unavailable', $lang),
      ],
  ]); ?>;

  // ---- tabs
  document.querySelectorAll('.qp-tabs button').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('.qp-tabs button').forEach(function (x) { x.setAttribute('aria-selected', String(x === b)); });
      document.querySelectorAll('.qp-tabpanel').forEach(function (p) { p.hidden = p.dataset.panel !== b.dataset.tab; });
    });
  });

  // ---- existing ticket on this phone
  var saved = QP.ticketFor(C.slug);
  if (saved) {
    QP.ticket(saved).then(function (r) {
      if (!r.ok || ['waiting', 'called', 'in_service'].indexOf(r.data.status) < 0) { QP.removeTicket(saved); return; }
      $('qp-existing').hidden = false;
      $('qp-existing-link').href = C.home + 't/' + encodeURIComponent(saved) + '/';
      $('qp-existing-code').textContent = r.data.code;
      $('qp-existing-sub').textContent = r.data.status === 'called' ? T.turn : (r.data.ahead != null ? r.data.ahead + ' ' + T.ahead : '');
    });
  }

  // ---- live numbers
  function refresh() {
    if (document.hidden) return;
    QP.live(C.slug).then(function (r) {
      if (!r.ok || !r.data) return;
      var d = r.data;
      $('qp-current').textContent = d.current || '—';
      $('qp-waiting').textContent = d.waiting;
      $('qp-eta').textContent = d.estimated_wait;
      $('qp-paused').hidden = d.accepting;
      if (!d.accepting && d.reason) $('qp-paused-text').textContent = T.reasons[d.reason] || '';
      $('qp-open-book').disabled = !d.accepting;
    }).catch(function () {});
  }
  setInterval(refresh, C.poll);
  document.addEventListener('visibilitychange', refresh);

  // ---- service picking
  var serviceId = 0;
  function setService(id, name) {
    serviceId = id;
    document.querySelectorAll('.qp-svc').forEach(function (x) { x.setAttribute('aria-pressed', String(+x.dataset.id === id)); });
    $('qp-svc-line').hidden = !id;
    $('qp-svc-name').textContent = name || '';
  }
  document.querySelectorAll('.qp-svc').forEach(function (b) {
    b.addEventListener('click', function () { var id = +b.dataset.id; setService(serviceId === id ? 0 : id, b.dataset.name); });
  });
  $('qp-svc-clear').addEventListener('click', function () { setService(0, ''); });

  // ---- sheets
  function openSheet(d) { if (d.showModal) d.showModal(); else d.setAttribute('open', ''); var i = d.querySelector('input:not([type=hidden]):not([tabindex="-1"])'); if (i) setTimeout(function () { i.focus(); }, 50); }
  document.querySelectorAll('[data-close]').forEach(function (b) { b.addEventListener('click', function () { b.closest('dialog').close(); }); });

  // ---- booking (with optional SMS code step)
  var idem = QP.uuid(), step = 'form', timer = null;
  var form = $('qp-book-form'), err = $('qp-error'), btn = $('qp-submit');
  // needCode: SMS code required for every booking (setting), or asked by the server for this booking
  // (many bookings from the same network, e.g. shop Wi-Fi).
  var needCode = !!C.otp;
  function label() { btn.textContent = needCode ? (step === 'form' ? T.send_code : T.confirm) : T.book; }
  function showErr(m) { err.textContent = m; err.hidden = !m; }
  function setStep(s) { step = s; $('qp-step-form').hidden = s !== 'form'; $('qp-step-code').hidden = s !== 'code'; label(); showErr(''); if (s === 'code') setTimeout(function () { $('qp-code').focus(); }, 50); }
  function countdown(sec) {
    var r = $('qp-resend'); clearInterval(timer); r.disabled = true;
    timer = setInterval(function () { sec--; r.textContent = sec > 0 ? T.resend_in.replace('{s}', sec) : T.resend; if (sec <= 0) { clearInterval(timer); r.disabled = false; } }, 1000);
    r.textContent = T.resend_in.replace('{s}', sec);
  }
  function sendCode() {
    return QP.post('providers/' + C.slug + '/otp', { phone: form.phone.value, purpose: 'join' }).then(function (d) {
      $('qp-code-phone').textContent = form.phone.value; setStep('code'); countdown(d.resend_in || 60);
    });
  }
  $('qp-open-book').addEventListener('click', function () { setStep('form'); openSheet($('qp-book')); });
  $('qp-change-phone').addEventListener('click', function () { setStep('form'); });
  $('qp-resend').addEventListener('click', function () { sendCode().catch(function (e) { showErr(e.message); }); });
  label();

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    showErr(''); btn.disabled = true;
    var p;
    if (needCode && step === 'form') {
      p = sendCode();
    } else {
      p = QP.post('providers/' + C.slug + '/join', {
        phone: form.phone.value, name: form.name.value, service_id: serviceId, website: form.website.value,
        otp_code: needCode ? form.otp_code.value : undefined
      }, { 'Idempotency-Key': idem }).then(function (d) {
        QP.addTicket({ t: d.token, s: C.slug, c: d.ticket.code });
        location.href = d.ticket_url;
      }).catch(function (e) {
        if (e.code === 'otp_required' && !needCode) { needCode = true; return sendCode(); } // server asks to confirm the phone
        throw e;
      });
    }
    p.catch(function (e) { showErr(e.message); }).then(function () { btn.disabled = false; });
  });

  // ---- lost link recovery
  var rf = $('qp-recover-form');
  if (rf) {
    var rStep = 'phone';
    $('qp-open-recover').addEventListener('click', function () { rStep = 'phone'; rf.querySelector('[data-code]').hidden = true; rf.querySelector('[data-err]').hidden = true; openSheet($('qp-recover')); });
    rf.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var e = rf.querySelector('[data-err]'), b = rf.querySelector('button[type=submit]'); e.hidden = true; b.disabled = true;
      var p = rStep === 'phone'
        ? QP.post('providers/' + C.slug + '/otp', { phone: rf.phone.value, purpose: 'recover' }).then(function () { rStep = 'code'; rf.querySelector('[data-code]').hidden = false; b.textContent = T.verify; rf.code.focus(); })
        : QP.post('providers/' + C.slug + '/recover', { phone: rf.phone.value, code: rf.code.value }).then(function (d) { QP.addTicket({ t: d.token, s: C.slug, c: d.ticket.code }); location.href = d.ticket_url; });
      p.catch(function (x) { e.textContent = x.message; e.hidden = false; }).then(function () { b.disabled = false; });
    });
  }
})();
</script>
<?php qp_footer();
