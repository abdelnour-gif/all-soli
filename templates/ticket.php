<?php
/** Customer ticket: domain.com/t/{token}. The token in the URL is the only key (no account). */
defined('QP_APP') || exit;
require_once __DIR__ . '/partials/layout.php';
require_once __DIR__ . '/partials/components.php';

use QueuePro\Support\Messages;
use QueuePro\Support\QueueException;

$c = qp();
$lang = qp_lang();
$L = fn(string $ar, string $en) => $lang === 'en' ? $en : $ar;
$token = (string) $token;
try {
    $t = $c->queue->ticketByToken($token);
} catch (QueueException $e) {
    http_status(404);
    qp_header('404');
    echo '<div class="qp-section" style="margin-top:24px"><div class="qp-empty">' . qp_icon('ticket', 32) . '<p>' . esc_html(Messages::get('ticket_not_found', $lang)) . '</p><a href="' . esc_url(url('/')) . '">' . esc_html(qp_t('home')) . '</a></div></div>';
    qp_footer();
    return;
}
$providerUrl = provider_url($t['provider']['slug']);
qp_header($t['code'] . ' — ' . qp_t('my_ticket'));
?>
<div class="qp-section" style="margin-top:16px">
  <a href="<?php echo esc_url($providerUrl); ?>" class="qp-linkbtn" style="text-decoration:none;display:inline-flex;align-items:center;gap:6px;padding:0 0 10px"><span class="qp-flip-rtl"><?php echo qp_icon('back', 18); ?></span><?php echo esc_html(qp_name($t['provider'])); ?></a>
  <div class="qp-ticket" id="qp-ticket">
    <div class="qp-ticket-top" id="qp-top">
      <div class="qp-ticket-label" id="qp-label"></div>
      <div class="qp-ticket-code" id="qp-code"><?php echo esc_html($t['code']); ?></div>
      <div class="qp-ticket-provider"><?php echo esc_html(qp_name($t['provider'])); ?></div>
      <div class="qp-ticket-ref"><?php echo esc_html($t['ref']); ?></div>
      <div style="margin-top:12px"><span class="qp-pill" id="qp-status"></span></div>
    </div>
    <div id="qp-live-part">
      <div class="qp-ticket-cut"></div>
      <div class="qp-ticket-grid">
        <div><small id="l-ahead"></small><strong id="qp-ahead">—</strong></div>
        <div><small id="l-current"></small><strong id="qp-current">—</strong></div>
      </div>
      <div class="qp-ticket-eta" id="qp-eta-wrap"><small id="l-eta" class="qp-muted"></small><br><strong id="qp-eta">—</strong></div>
      <div class="qp-ticket-actions">
        <button class="qp-btn qp-btn--light qp-alert-btn" id="qp-alerts" type="button" hidden></button>
        <button class="qp-btn qp-btn--ghost-danger" id="qp-cancel" type="button" style="margin-top:10px"></button>
      </div>
    </div>
    <div id="qp-end" hidden></div>
  </div>
  <p class="qp-hint" id="qp-live-hint"><span class="qp-live-dot"></span> <span id="l-auto"></span></p>
  <p class="qp-hint" id="l-keep"></p>
</div>

<?php qp_public_scripts(['token' => $token, 'slug' => $t['provider']['slug'], 'providerUrl' => $providerUrl]); ?>
<script<?php echo nonce_attr(); ?>>
(function () {
  var C = QP.cfg, $ = function (id) { return document.getElementById(id); };
  var T = <?php echo json_enc($lang === 'en' ? [
      'label' => 'Your number', 'ahead' => 'Ahead of you', 'current' => 'Now serving', 'eta' => 'Estimated wait', 'min' => 'min',
      'cancel' => 'Cancel booking', 'confirm' => 'Cancel your booking?', 'auto' => 'Your position updates automatically',
      'keep' => 'Keep this page — it is your ticket. It is also saved on this phone.', 'turn' => "It's your turn! Please come in",
      'delayed' => 'Delayed', 'alerts' => 'Alert me with sound when it is my turn', 'alerts_on' => 'Sound alert is on ✓',
      's' => ['waiting' => 'Waiting', 'called' => 'Called', 'in_service' => 'In service', 'completed' => 'Completed', 'cancelled' => 'Cancelled', 'no_show' => 'No-show', 'expired' => 'Expired'],
      'done_t' => 'Thank you!', 'done_p' => 'Your service is complete. How was it?', 'rate' => 'Send rating', 'comment' => 'Add a comment (optional)',
      'rated' => 'Thanks for your rating!', 'cancel_t' => 'Booking cancelled', 'cancel_p' => 'Your ticket was cancelled successfully.',
      'noshow_t' => 'Ticket closed', 'noshow_p' => 'Your turn passed while you were away.', 'exp_t' => 'Ticket expired', 'exp_p' => 'This ticket ended with the end of the day.',
      'again' => 'Take a new number', 'home' => 'Back to home', 'yourturn_title' => 'Your turn!',
  ] : [
      'label' => 'رقمك في الطابور', 'ahead' => 'أمامك', 'current' => 'الرقم الحالي', 'eta' => 'الوقت التقريبي للانتظار', 'min' => 'دقيقة',
      'cancel' => 'إلغاء الحجز', 'confirm' => 'هل تريد إلغاء حجزك؟', 'auto' => 'يتم تحديث موقعك تلقائياً',
      'keep' => 'هذه الصفحة هي تذكرتك، وهي محفوظة على هاتفك أيضاً.', 'turn' => 'حان دورك! تفضّل بالدخول',
      'delayed' => 'مؤجَّل', 'alerts' => 'نبّهني بصوت عندما يحين دوري', 'alerts_on' => 'التنبيه الصوتي مفعّل ✓',
      's' => ['waiting' => 'في الانتظار', 'called' => 'تم استدعاؤك', 'in_service' => 'قيد الخدمة', 'completed' => 'اكتملت الخدمة', 'cancelled' => 'ملغاة', 'no_show' => 'لم يحضر', 'expired' => 'منتهية'],
      'done_t' => 'شكراً لك!', 'done_p' => 'اكتملت خدمتك. كيف كانت تجربتك؟', 'rate' => 'إرسال التقييم', 'comment' => 'أضف تعليقاً (اختياري)',
      'rated' => 'شكراً على تقييمك!', 'cancel_t' => 'تم إلغاء الحجز بنجاح', 'cancel_p' => 'تم إلغاء تذكرتك، يمكنك الحجز من جديد متى شئت.',
      'noshow_t' => 'أُغلقت التذكرة', 'noshow_p' => 'مرّ دورك ولم تكن حاضراً.', 'exp_t' => 'انتهت صلاحية التذكرة', 'exp_p' => 'انتهت هذه التذكرة بنهاية يوم العمل.',
      'again' => 'احجز رقماً جديداً', 'home' => 'العودة للرئيسية', 'yourturn_title' => 'حان دورك!',
  ]); ?>;
  var ICON = { check: <?php echo json_enc(qp_icon('check')); ?>, x: <?php echo json_enc(qp_icon('ticket')); ?>, star: <?php echo json_enc(qp_icon('star')); ?> };
  ['ahead', 'current', 'eta', 'auto', 'keep'].forEach(function (k) { $('l-' + k).textContent = T[k]; });
  $('qp-cancel').textContent = T.cancel;

  var last = null, timer = null, alerts = false, baseTitle = document.title, blink = null;
  QP.addTicket({ t: C.token, s: C.slug, c: <?php echo json_enc($t['code']); ?> });

  function endView(d) {
    var html = '';
    if (d.status === 'completed') {
      html = '<div class="qp-state"><div class="qp-state-ico">' + ICON.check + '</div><h2>' + QP.esc(T.done_t) + '</h2><p>' + QP.esc(d.rating ? T.rated : T.done_p) + '</p>';
      if (d.rating) {
        html += '<div class="qp-starline" style="font-size:28px">' + '★'.repeat(d.rating.stars) + '<span style="color:#d6dde3">' + '★'.repeat(5 - d.rating.stars) + '</span></div>';
      } else if (d.can_rate) {
        html += '<form class="qp-rate" id="qp-rate"><div class="qp-rate-stars" role="radiogroup">' + [1, 2, 3, 4, 5].map(function (n) { return '<button type="button" data-star="' + n + '" aria-label="' + n + '">' + ICON.star + '</button>'; }).join('') + '</div>' +
          '<textarea class="qp-input" name="comment" maxlength="500" placeholder="' + QP.esc(T.comment) + '"></textarea><button class="qp-btn" type="submit" disabled>' + QP.esc(T.rate) + '</button></form>';
      }
      html += '</div>';
    } else {
      var m = { cancelled: [T.cancel_t, T.cancel_p, true], no_show: [T.noshow_t, T.noshow_p], expired: [T.exp_t, T.exp_p] }[d.status] || ['', ''];
      html = '<div class="qp-state' + (m[2] ? '' : ' qp-state--muted') + '"><div class="qp-state-ico">' + (m[2] ? ICON.check : ICON.x) + '</div><h2>' + QP.esc(m[0]) + '</h2><p>' + QP.esc(m[1]) + '</p>' +
        '<a class="qp-btn" href="' + C.providerUrl + '">' + QP.esc(T.again) + '</a><p style="margin-top:10px"><a href="' + C.home + '">' + QP.esc(T.home) + '</a></p></div>';
    }
    return html;
  }

  function bindRate() {
    var f = $('qp-rate'); if (!f) return;
    var stars = 0;
    f.querySelectorAll('[data-star]').forEach(function (b) {
      b.addEventListener('click', function () {
        stars = +b.dataset.star;
        f.querySelectorAll('[data-star]').forEach(function (x) { x.classList.toggle('is-on', +x.dataset.star <= stars); });
        f.querySelector('[type=submit]').disabled = false;
      });
    });
    f.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var btn = f.querySelector('[type=submit]'); btn.disabled = true;
      QP.post('tickets/' + C.token + '/rate', { stars: stars, comment: f.comment.value }).then(render).catch(function (e) { alert(e.message); btn.disabled = false; });
    });
  }

  function render(d) {
    var active = ['waiting', 'called', 'in_service'].indexOf(d.status) >= 0;
    var turn = d.status === 'called';
    $('qp-top').classList.toggle('qp-turn', turn);
    $('qp-label').textContent = turn ? T.turn : T.label;
    $('qp-status').className = 'qp-pill qp-pill--' + d.status;
    $('qp-status').textContent = T.s[d.status] + (d.delayed && d.status === 'waiting' ? ' · ' + T.delayed : '');
    $('qp-live-part').hidden = !active;
    $('qp-live-hint').hidden = !active;
    $('qp-ahead').textContent = d.ahead != null ? d.ahead : '—';
    $('qp-current').textContent = d.current || '—';
    $('qp-eta-wrap').hidden = d.estimated_wait == null;
    $('qp-eta').textContent = d.estimated_wait != null ? (d.estimated_wait + ' ' + T.min) : '—';
    $('qp-cancel').hidden = !d.can_cancel;
    $('qp-alerts').hidden = !active || turn;
    $('qp-alerts').textContent = alerts ? T.alerts_on : T.alerts;
    var endEl = $('qp-end');
    if (!active) {
      if (endEl.dataset.state !== d.status + (d.rating ? 'r' : '')) {
        endEl.innerHTML = endView(d); endEl.dataset.state = d.status + (d.rating ? 'r' : ''); bindRate();
      }
      endEl.hidden = false;
      QP.removeTicket(C.token);
      if (timer) { clearInterval(timer); timer = null; }
    } else { endEl.hidden = true; }
    if (turn && last && last !== 'called') {
      if (alerts) QP.chime(); else if (navigator.vibrate) navigator.vibrate([300, 150, 300]);
      clearInterval(blink);
      blink = setInterval(function () { document.title = document.title === baseTitle ? '🔔 ' + T.yourturn_title : baseTitle; }, 1000);
    }
    if (!turn && blink) { clearInterval(blink); blink = null; document.title = baseTitle; }
    last = d.status;
  }

  function refresh() {
    if (document.hidden && !alerts) return;
    QP.ticket(C.token).then(function (r) { if (r.ok && r.data) render(r.data); }).catch(function () {});
  }
  render(<?php echo json_enc($t); ?>);
  if (['waiting', 'called', 'in_service'].indexOf(last) >= 0) timer = setInterval(refresh, C.poll);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) refresh(); });

  $('qp-alerts').addEventListener('click', function () { alerts = true; QP.chime(); $('qp-alerts').textContent = T.alerts_on; });
  $('qp-cancel').addEventListener('click', function () {
    if (!confirm(T.confirm)) return;
    $('qp-cancel').disabled = true;
    QP.post('tickets/' + C.token + '/cancel', {}).then(render).catch(function (e) { alert(e.message); $('qp-cancel').disabled = false; });
  });
})();
</script>
<?php qp_footer();
