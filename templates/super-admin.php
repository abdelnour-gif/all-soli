<?php
/** Super Admin dashboard: domain.com/super-admin/ — admin accounts only. */
defined('QP_APP') || exit;
require_once __DIR__ . '/partials/layout.php';

$lang = qp_lang();
$L = fn(string $ar, string $en) => $lang === 'en' ? $en : $ar;
$auth = \QueuePro\Core\App::auth();
$user = $auth->user();
$logoutUrl = url('/logout?to=admin&t=' . rawurlencode(csrf_token()));

if (!$user || $user['role'] !== 'admin') {
    qp_header($L('لوحة الإدارة', 'Admin') . ' — ' . qp_brand(), ['head' => '<link rel="stylesheet" href="' . esc_url(asset('css/dashboard.css')) . '">']);
    $err = $GLOBALS['qp_login_error'] ?? null;
    $msgs = [
        'login_failed' => $L('بيانات الدخول غير صحيحة.', 'Wrong username or password.'),
        'rate_limited' => $L('محاولات كثيرة. انتظر 15 دقيقة ثم حاول مجدداً.', 'Too many attempts. Wait 15 minutes and try again.'),
        'csrf' => $L('انتهت صلاحية الصفحة، حاول مجدداً.', 'Page expired, please try again.'),
    ];
    ?>
    <div class="qp-section" style="margin-block-start:28px">
      <div class="qp-card qpd-login">
        <div class="qpd-login-icon" aria-hidden="true"><?php echo qp_icon('shield', 28); ?></div>
        <h1 class="qp-title" style="text-align:center"><?php echo esc_html($L('لوحة الإدارة', 'Super Admin')); ?></h1>
        <?php if (!empty($_GET['installed'])): ?>
          <div class="qp-notice qp-notice--ok" style="margin:12px 0"><?php echo esc_html($L('تم التثبيت بنجاح! سجّل الدخول بالحساب الذي أنشأته.', 'Installed! Sign in with the account you just created.')); ?></div>
        <?php endif; ?>
        <?php if ($user): ?>
          <div class="qp-notice qp-notice--error" style="margin:12px 0"><?php echo esc_html($L('هذا الحساب لا يملك صلاحية الإدارة.', 'This account is not an administrator.')); ?></div>
          <a class="qp-btn qp-btn--ghost-danger" href="<?php echo esc_url($logoutUrl); ?>"><?php echo esc_html($L('تسجيل الخروج', 'Sign out')); ?></a>
        <?php else: ?>
          <?php if ($err): ?><div class="qp-notice qp-notice--error" style="margin:12px 0"><?php echo esc_html($msgs[$err] ?? $msgs['login_failed']); ?></div><?php endif; ?>
          <form method="post" action="<?php echo esc_url(url('/super-admin/')); ?>" style="margin-top:14px">
            <input type="hidden" name="qp_login" value="1"><?php echo csrf_field(); ?>
            <div class="qp-field"><label for="log"><?php echo esc_html($L('اسم المستخدم أو البريد', 'Username or e-mail')); ?></label><input id="log" name="log" class="qp-input" dir="ltr" autocomplete="username" autocapitalize="none" required></div>
            <div class="qp-field"><label for="pwd"><?php echo esc_html($L('كلمة المرور', 'Password')); ?></label><input id="pwd" name="pwd" type="password" class="qp-input" dir="ltr" autocomplete="current-password" required></div>
            <button class="qp-btn" type="submit"><?php echo esc_html($L('دخول', 'Sign in')); ?></button>
          </form>
        <?php endif; ?>
      </div>
    </div>
    <?php
    qp_footer();
    return;
}

$self = remove_query_arg('lang');
$config = [
    'api'     => api_url('admin'),
    'nonce'   => csrf_token(),
    'lang'    => $lang,
    'home'    => url('/'),
    'logout'  => $logoutUrl,
    'user'    => $user['display_name'] ?: $user['username'],
    'brand'   => qp_brand(),
];
?><!doctype html>
<html lang="<?php echo esc_attr($lang); ?>" dir="<?php echo $lang === 'ar' ? 'rtl' : 'ltr'; ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0b2236">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html($L('لوحة الإدارة', 'Super Admin') . ' — ' . qp_brand()); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&family=Inter:wght@500;600;700;800&display=swap">
<link rel="stylesheet" href="<?php echo esc_url(asset('css/queuepro.css')); ?>">
<link rel="stylesheet" href="<?php echo esc_url(asset('css/admin.css')); ?>">
<script src="<?php echo esc_url(asset('js/ui.js')); ?>" defer></script>
</head>
<body class="qp qpa">
<aside class="qpa-side" id="qpa-side">
  <a class="qp-brand" href="#/"><span class="qp-brand-mark" aria-hidden="true"></span><?php echo esc_html(qp_brand()); ?></a>
  <nav class="qpa-nav" id="qpa-nav">
    <a href="#/" data-r="home"><?php echo qp_icon('chart', 18); ?><span data-t="nav_home"></span></a>
    <a href="#/providers" data-r="providers"><?php echo qp_icon('store', 18); ?><span data-t="nav_providers"></span></a>
    <a href="#/tickets" data-r="tickets"><?php echo qp_icon('ticket', 18); ?><span data-t="nav_tickets"></span></a>
    <a href="#/packages" data-r="packages"><?php echo qp_icon('grid', 18); ?><span data-t="nav_packages"></span></a>
    <a href="#/ratings" data-r="ratings"><?php echo qp_icon('star', 18); ?><span data-t="nav_ratings"></span></a>
    <a href="#/messages" data-r="messages"><?php echo qp_icon('bell', 18); ?><span data-t="nav_messages"></span></a>
    <a href="#/audit" data-r="audit"><?php echo qp_icon('shield', 18); ?><span data-t="nav_audit"></span></a>
    <a href="#/settings" data-r="settings"><?php echo qp_icon('cog', 18); ?><span data-t="nav_settings"></span></a>
  </nav>
  <div class="qpa-side-foot">
    <a href="<?php echo esc_url(url('/')); ?>" target="_blank" rel="noopener"><?php echo qp_icon('external', 16); ?><span data-t="view_site"></span></a>
    <a href="<?php echo esc_url($logoutUrl); ?>"><?php echo qp_icon('back', 16); ?><span data-t="logout"></span></a>
  </div>
</aside>
<div class="qpa-main">
  <header class="qpa-top">
    <button class="qp-menu-btn qpa-menu" type="button" aria-controls="qpa-side" data-body-toggle="qpa-open"><?php echo qp_icon('menu'); ?></button>
    <h1 id="qpa-title"></h1>
    <div class="qpa-top-end">
      <div class="qp-lang">
        <a href="<?php echo esc_url(add_query_arg('lang', 'ar', $self)); ?>" class="<?php echo $lang === 'ar' ? 'is-active' : ''; ?>">AR</a>
        <a href="<?php echo esc_url(add_query_arg('lang', 'en', $self)); ?>" class="<?php echo $lang === 'en' ? 'is-active' : ''; ?>">EN</a>
      </div>
      <span class="qpa-user"><?php echo qp_icon('user', 16) . esc_html($config['user']); ?></span>
    </div>
  </header>
  <main id="qpa-view" class="qpa-view" aria-live="polite"><div class="qpd-loading"><span class="qp-live-dot"></span></div></main>
</div>
<div class="qpa-scrim" data-body-remove="qpa-open"></div>
<dialog id="qpa-dialog" class="qpa-dialog"><form method="dialog" id="qpa-dialog-form"></form></dialog>
<div id="qpa-toast" class="qpd-toast" role="status" hidden></div>
<script<?php echo nonce_attr(); ?>>window.QPA = <?php echo json_enc($config); ?>;</script>
<script src="<?php echo esc_url(asset('js/admin.js')); ?>"></script>
</body>
</html>
