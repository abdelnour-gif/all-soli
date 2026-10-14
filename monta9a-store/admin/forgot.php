<?php
require_once __DIR__ . '/_init.php';
if (!empty($_SESSION['admin_id'])) redirect('index.php');

$done = false;
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!checkCsrf()) {
        $error = 'انتهت صلاحية الصفحة، أعد المحاولة';
    } else {
        $admin = findAdminByLoginOrEmail((string)($_POST['login'] ?? ''));
        if (!$admin) {
            $error = 'لا يوجد حساب بهذا الاسم أو البريد.';
        } else {
            $r = sendPasswordReset($admin);
            $hasMail = filter_var($admin['email'], FILTER_VALIDATE_EMAIL) !== false;
            $sentTo = $hasMail ? maskEmail($admin['email']) : '';
            if ($r['wait']) $error = 'أُرسل رابط منذ قليل — انتظر دقيقتين ثم أعد المحاولة.';
            elseif ($r['mail'] === 'none') $error = 'هذا الحساب ليس له بريد مسجّل ولا تلغرام — أضف بريدك في الإعدادات ← بيانات الحساب، أو استعمل طريقة الملف من صفحة الدخول.';
            elseif (!$r['ok']) $error = 'تعذر الإرسال: ' . $r['mail'];
            else $done = true;
        }
    }
}
?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>نسيت كلمة المرور | <?= e(setting('store_name')) ?></title>
<link rel="icon" href="../assets/img/favicon.png" type="image/png">
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/admin.css?v=3">
</head>
<body class="login-page">
  <form method="post" class="login-card">
    <div class="login-logo"><?= e(setting('store_name')) ?></div>
    <p class="muted">استعادة كلمة المرور</p>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <?php if ($done): ?>
      <div class="alert alert-success">تم إرسال رابط الاستعادة ✔
        <?php if ($r['mail'] === ''): ?><br>📧 إلى بريدك <b dir="ltr"><?= e($sentTo) ?></b><?php endif; ?>
        <?php if ($r['tg']): ?><br>📲 وإلى تلغرام<?php endif; ?>
        <br>الرابط صالح لمدة ساعة.</div>
    <?php else: ?>
      <p class="muted small">اكتب اسم المستخدم أو البريد الإلكتروني المسجّل في الإعدادات ← بيانات الحساب.</p>
      <?= csrfField() ?>
      <label>اسم المستخدم أو البريد الإلكتروني<input type="text" name="login" required autocapitalize="none" autocorrect="off" spellcheck="false" dir="auto" autofocus></label>
      <button class="btn btn-dark btn-block">إرسال رابط الاستعادة</button>
    <?php endif; ?>
    <a href="login.php" class="muted small">← العودة إلى تسجيل الدخول</a>
  </form>
</body>
</html>
