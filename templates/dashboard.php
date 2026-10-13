<?php
/** Provider dashboard: domain.com/dashboard/ — mobile-first, talks to /api/me/* with the session cookie + CSRF token. */
defined('QP_APP') || exit;
require_once __DIR__ . '/partials/layout.php';

$c = qp();
$lang = qp_lang();
$auth = \QueuePro\Core\App::auth();
$user = $auth->user();
$provider = $user && $user['role'] === 'provider' ? $c->providers->byUserId((int) $user['id']) : null;
$L = fn(string $ar, string $en) => $lang === 'en' ? $en : $ar;
$logoutUrl = url('/logout?t=' . rawurlencode(csrf_token()));
$css = '<link rel="stylesheet" href="' . esc_url(asset('css/dashboard.css')) . '">';

// ---------------------------------------------------------------- logged out → login
if (!$user) {
    qp_header($L('دخول المزوّد', 'Provider login') . ' — ' . qp_brand(), ['head' => $css]);
    $err = $GLOBALS['qp_login_error'] ?? null;
    $msgs = [
        'login_failed' => $L('اسم المستخدم أو كلمة المرور غير صحيحة.', 'Wrong username or password.'),
        'rate_limited' => $L('محاولات كثيرة. انتظر 15 دقيقة ثم حاول مجدداً.', 'Too many attempts. Wait 15 minutes and try again.'),
        'csrf' => $L('انتهت صلاحية الصفحة، حاول مجدداً.', 'Page expired, please try again.'),
    ];
    ?>
    <div class="qp-section" style="margin-block-start:28px">
      <div class="qp-card qpd-login">
        <div class="qpd-login-icon" aria-hidden="true"><?php echo qp_icon('menu', 28); ?></div>
        <h1 class="qp-title" style="text-align:center"><?php echo esc_html($L('لوحة المزوّد', 'Provider dashboard')); ?></h1>
        <p class="qp-hint" style="margin:0 0 18px"><?php echo esc_html($L('أدِر طابورك من هاتفك', 'Run your queue from your phone')); ?></p>
        <?php if ($err): ?>
          <div class="qp-notice qp-notice--error" style="margin-block-end:12px"><?php echo esc_html($msgs[$err] ?? $msgs['login_failed']); ?></div>
        <?php endif; ?>
        <form method="post" action="<?php echo esc_url(url('/dashboard/')); ?>">
          <input type="hidden" name="qp_login" value="1"><?php echo csrf_field(); ?>
          <div class="qp-field"><label for="log"><?php echo esc_html($L('اسم المستخدم أو البريد', 'Username or e-mail')); ?></label>
            <input id="log" name="log" class="qp-input" dir="ltr" autocomplete="username" autocapitalize="none" required></div>
          <div class="qp-field"><label for="pwd"><?php echo esc_html($L('كلمة المرور', 'Password')); ?></label>
            <input id="pwd" name="pwd" type="password" class="qp-input" dir="ltr" autocomplete="current-password" required></div>
          <button class="qp-btn" type="submit"><?php echo esc_html($L('دخول', 'Sign in')); ?></button>
        </form>
        <p class="qp-hint"><?php echo esc_html($L('نسيت كلمة المرور؟ تواصل مع إدارة المنصة لإنشاء كلمة جديدة.', 'Forgot your password? Ask the platform team for a new one.')); ?></p>
      </div>
    </div>
    <?php
    qp_footer();
    return;
}

// ---------------------------------------------------------------- signed in, but not a provider
if (!$provider) {
    qp_header(qp_brand(), ['head' => $css]);
    echo '<div class="qp-section" style="margin-block-start:28px"><div class="qp-card">';
    if ($user['role'] === 'admin') {
        echo '<p>' . esc_html($L('أنت مسجّل كمدير للمنصة.', 'You are signed in as a platform admin.')) . '</p>';
        echo '<p><a class="qp-btn" href="' . esc_url(url('/super-admin/')) . '">' . esc_html($L('الذهاب إلى لوحة الإدارة', 'Go to the admin dashboard')) . '</a></p>';
    } else {
        echo '<p>' . esc_html($L('هذا الحساب غير مرتبط بأي مزوّد.', 'This account is not linked to a provider.')) . '</p>';
    }
    echo '<p><a href="' . esc_url($logoutUrl) . '">' . esc_html($L('تسجيل الخروج', 'Sign out')) . '</a></p>';
    echo '</div></div>';
    qp_footer();
    return;
}
if ($provider['status'] === 'blocked') {
    qp_header(qp_brand(), ['head' => $css]);
    echo '<div class="qp-section" style="margin-block-start:28px"><div class="qp-notice qp-notice--error">' . esc_html($L('تم إيقاف حسابك. تواصل مع إدارة المنصة.', 'Your account has been suspended. Please contact the platform.')) . '</div>';
    echo '<p style="text-align:center"><a href="' . esc_url($logoutUrl) . '">' . esc_html($L('تسجيل الخروج', 'Sign out')) . '</a></p></div>';
    qp_footer();
    return;
}

// ---------------------------------------------------------------- the app
qp_header($L('لوحة المزوّد', 'Dashboard') . ' — ' . $provider['name_ar'], ['head' => $css]);
$config = [
    'api'       => api_url('me'),
    'nonce'     => csrf_token(),
    'lang'      => $lang,
    'publicUrl' => provider_url($provider['slug']),
    'logoutUrl' => $logoutUrl,
    'name'      => $lang === 'en' && $provider['name_en'] ? $provider['name_en'] : $provider['name_ar'],
    'poll'      => 5000,
];
?>
<div id="qpd" class="qpd" aria-live="polite">
  <div class="qpd-loading"><span class="qp-live-dot"></span></div>
</div>

<nav class="qpd-tabs" role="tablist">
  <button role="tab" data-tab="queue" aria-selected="true">
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h10"/></svg><span data-t="tab_queue"></span>
  </button>
  <button role="tab" data-tab="services" aria-selected="false">
    <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="4" width="7" height="7" rx="2"/><rect x="13" y="4" width="7" height="7" rx="2"/><rect x="4" y="13" width="7" height="7" rx="2"/><rect x="13" y="13" width="7" height="7" rx="2"/></svg><span data-t="tab_services"></span>
  </button>
  <button role="tab" data-tab="account" aria-selected="false">
    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/></svg><span data-t="tab_account"></span>
  </button>
</nav>

<div id="qpd-toast" class="qpd-toast" role="status" hidden></div>

<dialog id="qpd-dialog" class="qpd-dialog"><form method="dialog" id="qpd-dialog-form"></form></dialog>

<div id="qpd-print" class="qpd-print" aria-hidden="true"></div>

<script<?php echo nonce_attr(); ?>>window.QPD = <?php echo json_enc($config); ?>;</script>
<script src="<?php echo esc_url(asset('js/vendor/qrcode.js')); ?>"></script>
<script src="<?php echo esc_url(asset('js/dashboard.js')); ?>"></script>
<?php qp_footer();
