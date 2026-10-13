<?php
/** Admin password recovery (enabled only while storage/recover.txt exists). Vars: $error, $done, $codeTooShort */
defined('QP_APP') || exit;
require_once __DIR__ . '/partials/layout.php';
$msgs = [
    'login_failed' => 'الرمز أو اسم المستخدم غير صحيح.',
    'weak_password' => 'كلمة المرور يجب أن تكون 10 أحرف على الأقل.',
    'invalid_input' => 'كلمتا المرور غير متطابقتين.',
    'rate_limited' => 'محاولات كثيرة، انتظر ساعة.',
    'csrf' => 'انتهت صلاحية الصفحة، أعد المحاولة.',
];
qp_header('استعادة حساب المدير', ['head' => '<link rel="stylesheet" href="' . esc_url(asset('css/dashboard.css')) . '"><meta name="robots" content="noindex">']);
?>
<div class="qp-section" style="margin-block-start:28px">
  <div class="qp-card qpd-login">
    <div class="qpd-login-icon" aria-hidden="true"><?php echo qp_icon('shield', 28); ?></div>
    <h1 class="qp-title" style="text-align:center">استعادة حساب المدير</h1>
    <?php if ($done): ?>
      <div class="qp-notice qp-notice--ok" style="margin:12px 0">تم تغيير كلمة المرور وحُذف ملف الاستعادة.</div>
      <a class="qp-btn" href="<?php echo esc_url(url('/super-admin/')); ?>">الدخول إلى لوحة الإدارة</a>
    <?php elseif ($codeTooShort): ?>
      <div class="qp-notice qp-notice--warn" style="margin:12px 0;display:block">افتح الملف <b dir="ltr">storage/recover.txt</b> من مدير الملفات واكتب بداخله رمزاً من 8 أحرف على الأقل، ثم أعد تحميل هذه الصفحة.</div>
    <?php else: ?>
      <?php if ($error): ?><div class="qp-notice qp-notice--error" style="margin:12px 0"><?php echo esc_html($msgs[$error] ?? $msgs['login_failed']); ?></div><?php endif; ?>
      <form method="post" action="<?php echo esc_url(url('/recover-admin')); ?>">
        <?php echo csrf_field(); ?>
        <div class="qp-field"><label>الرمز المكتوب في storage/recover.txt</label><input name="code" class="qp-input" dir="ltr" required autocomplete="off"></div>
        <div class="qp-field"><label>اسم مستخدم المدير</label><input name="username" class="qp-input" dir="ltr" required autocomplete="username"></div>
        <div class="qp-field"><label>كلمة المرور الجديدة (10 أحرف على الأقل)</label><input name="password" type="password" class="qp-input" dir="ltr" required minlength="10" autocomplete="new-password"></div>
        <div class="qp-field"><label>تأكيدها</label><input name="password2" type="password" class="qp-input" dir="ltr" required minlength="10" autocomplete="new-password"></div>
        <button class="qp-btn" type="submit">تغيير كلمة المرور</button>
      </form>
    <?php endif; ?>
  </div>
</div>
<?php qp_footer();
