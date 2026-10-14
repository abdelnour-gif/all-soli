<?php
require_once __DIR__ . '/_init.php';
if (!empty($_SESSION['admin_id'])) redirect('index.php');

$error = '';
$recovered = emergencyPasswordReset();
$f = $recovered ? ['تمت إعادة تعيين كلمة المرور من ملف الاستعادة. اسم المستخدم: ' . $recovered['username'] . ' — ادخل بكلمة المرور الجديدة.', 'success'] : flash();

const MAX_ATTEMPTS = 5;
const LOCK_SECONDS = 300;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Counted per IP in the database, not in the session: a session counter is
    // reset simply by dropping the cookie, which made the lock-out useless.
    $attempts = loginAttempts();
    $lockedUntil = loginLockedUntil();
    if ($lockedUntil > time()) {
        $mins = (int)ceil(($lockedUntil - time()) / 60);
        $error = 'محاولات خاطئة كثيرة. انتظر ' . ($mins <= 1 ? 'دقيقة واحدة' : ($mins === 2 ? 'دقيقتين' : $mins . ' دقائق')) . ' ثم حاول مرة أخرى، أو استعمل طريقة الاستعادة بالملف الموضّحة أسفله.';
    } elseif (!checkCsrf()) {
        $error = 'انتهت صلاحية الصفحة، أعد المحاولة';
    } else {
        $login = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $admin = findAdmin($login);
        if ($admin && adminPasswordMatches($password, $admin['password'])) {
            session_regenerate_id(true);
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['admin_user'] = $admin['username'];
            unset($_SESSION['csrf']);
            clearLoginAttempts();
            rememberAdminUrl();
            redirect('index.php');
        }
        usleep(400000);
        $attempts = recordLoginFailure();
        if ($attempts >= MAX_ATTEMPTS) {
            lockLogin(LOCK_SECONDS);
            $error = 'محاولات خاطئة كثيرة. انتظر 5 دقائق ثم حاول مرة أخرى، أو استعمل طريقة الاستعادة بالملف الموضّحة أسفله.';
        } else {
            // One message for both cases: a different wording would tell an
            // attacker which usernames exist.
            $error = 'اسم المستخدم أو كلمة المرور غير صحيحة.';
        }
    }
}
?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>تسجيل الدخول | <?= e(setting('store_name')) ?></title>
<link rel="icon" href="../assets/img/favicon.png" type="image/png">
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/admin.css?v=3">
</head>
<body class="login-page">
  <form method="post" class="login-card">
    <div class="login-logo"><?= e(setting('store_name')) ?></div>
    <p class="muted">تسجيل الدخول إلى لوحة التحكم</p>
    <?php if ($f): ?><div class="alert alert-<?= $f[1] === 'error' ? 'error' : 'success' ?>"><?= e($f[0]) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <?= csrfField() ?>
    <label>اسم المستخدم<input type="text" name="username" value="<?= e($_POST['username'] ?? '') ?>" required autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false" autofocus></label>
    <label>كلمة المرور
      <span class="pw-wrap"><input type="password" name="password" required autocomplete="current-password" autocapitalize="none" autocorrect="off" spellcheck="false"><button type="button" class="pw-eye" aria-label="إظهار كلمة المرور">👁</button></span>
    </label>
    <details class="recover-help">
      <summary>نسيت كلمة المرور؟</summary>
      <p><a href="forgot.php"><b>← استعادة كلمة المرور عبر البريد الإلكتروني</b></a></p>
      <p>أو:</p>
      <p>من مدير ملفات الاستضافة، افتح مجلد <code>database</code> وأنشئ ملفاً باسم
      <code dir="ltr">reset-password.txt</code>، اكتب فيه كلمة المرور الجديدة (8 أحرف على الأقل) واحفظه،
      ثم حدّث هذه الصفحة. تُطبَّق كلمة المرور ويُحذف الملف تلقائياً.</p>
    </details>
    <button class="btn btn-dark btn-block">دخول</button>
    <a href="../index.php" class="muted small">← العودة إلى المتجر</a>
  </form>
  <script src="../assets/js/admin-pw.js?v=1"></script>
</body>
</html>
