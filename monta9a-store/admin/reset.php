<?php
require_once __DIR__ . '/_init.php';

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$admin = adminByResetToken($token);
$error = '';

if ($admin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $new = cleanNewPassword((string)($_POST['new_password'] ?? ''));
    $confirm = cleanNewPassword((string)($_POST['confirm_password'] ?? ''));
    if (!checkCsrf()) {
        $error = 'انتهت صلاحية الصفحة، أعد المحاولة';
    } elseif (strlen($new) < 8) {
        $error = 'كلمة المرور يجب أن تكون 8 أحرف على الأقل';
    } elseif ($new !== $confirm) {
        $error = 'تأكيد كلمة المرور غير مطابق';
    } else {
        getDB()->prepare("UPDATE admins SET password = ?, reset_hash = '', reset_expires = 0 WHERE id = ?")
               ->execute([password_hash($new, PASSWORD_DEFAULT), $admin['id']]);
        clearLoginAttempts();
        flash('تم تغيير كلمة المرور ✔ — ادخل باسم المستخدم «' . $admin['username'] . '» وكلمة المرور الجديدة.');
        redirect('login.php');
    }
}
?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<meta name="referrer" content="no-referrer">
<title>كلمة مرور جديدة | <?= e(setting('store_name')) ?></title>
<link rel="icon" href="../assets/img/favicon.png" type="image/png">
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/admin.css?v=3">
</head>
<body class="login-page">
  <form method="post" class="login-card" action="reset.php">
    <div class="login-logo"><?= e(setting('store_name')) ?></div>
    <?php if (!$admin): ?>
      <div class="alert alert-error">الرابط غير صالح أو انتهت مدته (ساعة)، أو استُعمل من قبل.</div>
      <a href="forgot.php" class="btn btn-dark btn-block">طلب رابط جديد</a>
    <?php else: ?>
      <p class="muted">كلمة مرور جديدة للحساب «<?= e($admin['username']) ?>»</p>
      <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
      <?= csrfField() ?><input type="hidden" name="token" value="<?= e($token) ?>">
      <label>كلمة المرور الجديدة (8 أحرف على الأقل)
        <span class="pw-wrap"><input type="password" name="new_password" required minlength="8" autocomplete="new-password" autocapitalize="none" autocorrect="off" spellcheck="false" autofocus><button type="button" class="pw-eye" aria-label="إظهار كلمة المرور">👁</button></span>
      </label>
      <label>تأكيد كلمة المرور
        <span class="pw-wrap"><input type="password" name="confirm_password" required minlength="8" autocomplete="new-password" autocapitalize="none" autocorrect="off" spellcheck="false"><button type="button" class="pw-eye" aria-label="إظهار كلمة المرور">👁</button></span>
      </label>
      <button class="btn btn-dark btn-block">حفظ كلمة المرور</button>
    <?php endif; ?>
    <a href="login.php" class="muted small">← العودة إلى تسجيل الدخول</a>
  </form>
  <script src="../assets/js/admin-pw.js?v=1"></script>
</body>
</html>
