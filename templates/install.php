<?php
/** First-run installer page. Variables: $reqs, $errors, $old, $token, $driver */
defined('QP_APP') || exit;
$v = fn(string $k, string $d = '') => esc_attr($old[$k] ?? $d);
$allOk = true;
foreach ($reqs as [$n, $ok, , $required]) {
    if ($required && !$ok) {
        $allOk = false;
    }
}
?><!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>تثبيت QueuePro</title>
<link rel="stylesheet" href="<?php echo esc_url(asset('css/queuepro.css')); ?>">
<style>
  .qi { max-width: 620px; margin: 0 auto; padding: 28px 16px 60px; }
  .qi h1 { margin: 0 0 4px; font-size: 26px; }
  .qi h2 { font-size: 16px; margin: 0 0 12px; }
  .qi .qp-card { margin-top: 16px; }
  .qi-req { list-style: none; padding: 0; margin: 0; display: grid; gap: 6px; font-size: 14px; }
  .qi-req li { display: flex; justify-content: space-between; gap: 10px; padding: 8px 10px; border-radius: 8px; background: var(--qp-bg); }
  .qi-req .ok { color: var(--qp-green-700); font-weight: 700; }
  .qi-req .bad { color: var(--qp-danger); font-weight: 700; }
  .qi-req .opt { color: var(--qp-waiting); font-weight: 700; }
  .qi-two { display: grid; gap: 12px; grid-template-columns: 1fr 1fr; }
  .qi-radio { display: flex; gap: 10px; align-items: flex-start; padding: 12px; border: 1.5px solid var(--qp-border); border-radius: 10px; margin-bottom: 8px; cursor: pointer; }
  .qi-radio input { margin-top: 4px; accent-color: var(--qp-green-600); }
  .qi-radio small { display: block; color: var(--qp-text-2); }
  details { margin-top: 8px; }
  summary { cursor: pointer; font-weight: 600; color: var(--qp-text-2); }
  @media (max-width: 520px) { .qi-two { grid-template-columns: 1fr; } }
</style>
</head>
<body class="qp">
<header class="qp-topbar"><div class="qp-topbar-in"><span class="qp-brand"><span class="qp-brand-mark" aria-hidden="true"></span>QueuePro</span></div></header>
<main class="qi">
  <h1>تثبيت المنصة</h1>
  <p class="qp-muted">خطوة واحدة: أنشئ حساب المدير وستكون المنصة جاهزة.</p>

  <?php if ($errors): ?>
    <div class="qp-notice qp-notice--error" style="margin-top:16px;display:block">
      <?php foreach ($errors as $e): ?><div>• <?php echo esc_html($e); ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <section class="qp-card">
    <h2>متطلبات الخادم</h2>
    <ul class="qi-req">
      <?php foreach ($reqs as [$name, $ok, $info, $required]): ?>
        <li><span><?php echo esc_html($name); ?> <?php echo $info ? '<small class="qp-muted">(' . esc_html($info) . ')</small>' : ''; ?></span>
          <span class="<?php echo $ok ? 'ok' : ($required ? 'bad' : 'opt'); ?>"><?php echo $ok ? '✓' : ($required ? '✗ مطلوب' : 'اختياري'); ?></span></li>
      <?php endforeach; ?>
    </ul>
    <?php if (!$allOk): ?>
      <p class="qp-notice qp-notice--warn" style="margin-top:12px">أصلح العناصر المطلوبة أولاً (غالباً: صلاحيات الكتابة 755 على مجلدي storage و uploads، أو تفعيل pdo_sqlite من لوحة الاستضافة ← Select PHP Version).</p>
    <?php endif; ?>
  </section>

  <form method="post" action="<?php echo esc_url(url('/install')); ?>" autocomplete="off">
    <input type="hidden" name="_t" value="<?php echo esc_attr($token); ?>">
    <section class="qp-card">
      <h2>المنصة</h2>
      <div class="qi-two">
        <div class="qp-field"><label for="brand">اسم المنصة</label><input id="brand" class="qp-input" name="brand_name" value="<?php echo $v('brand_name', 'QueuePro'); ?>" maxlength="60"></div>
        <div class="qp-field"><label for="sp">هاتف الدعم (يظهر للزوار)</label><input id="sp" class="qp-input" name="support_phone" dir="ltr" value="<?php echo $v('support_phone'); ?>" maxlength="30"></div>
      </div>
    </section>

    <section class="qp-card">
      <h2>حساب المدير (Super Admin)</h2>
      <div class="qi-two">
        <div class="qp-field"><label for="u">اسم المستخدم *</label><input id="u" class="qp-input" name="username" dir="ltr" required pattern="[A-Za-z0-9][A-Za-z0-9._\-]{2,59}" value="<?php echo $v('username', 'admin'); ?>" autocomplete="username"></div>
        <div class="qp-field"><label for="e">البريد (اختياري)</label><input id="e" class="qp-input" name="email" type="email" dir="ltr" value="<?php echo $v('email'); ?>"></div>
        <div class="qp-field"><label for="p1">كلمة المرور * (10 أحرف على الأقل)</label><input id="p1" class="qp-input" name="password" type="password" dir="ltr" required minlength="10" autocomplete="new-password"></div>
        <div class="qp-field"><label for="p2">تأكيد كلمة المرور *</label><input id="p2" class="qp-input" name="password2" type="password" dir="ltr" required minlength="10" autocomplete="new-password"></div>
      </div>
    </section>

    <section class="qp-card">
      <h2>قاعدة البيانات</h2>
      <label class="qi-radio"><input type="radio" name="db_driver" value="sqlite" <?php echo $driver === 'sqlite' ? 'checked' : ''; ?>>
        <span><b>SQLite (موصى به)</b><small>ملف واحد داخل مجلد storage — لا يحتاج أي إعداد.</small></span></label>
      <label class="qi-radio"><input type="radio" name="db_driver" value="mysql" <?php echo $driver === 'mysql' ? 'checked' : ''; ?>>
        <span><b>MySQL / MariaDB</b><small>للمنصات الكبيرة جداً. أنشئ قاعدة بيانات من لوحة الاستضافة ثم املأ البيانات أدناه.</small></span></label>
      <details <?php echo $driver === 'mysql' ? 'open' : ''; ?>><summary>بيانات MySQL</summary>
        <div class="qi-two" style="margin-top:10px">
          <div class="qp-field"><label>الخادم</label><input class="qp-input" name="db_host" dir="ltr" value="<?php echo $v('db_host', 'localhost'); ?>"></div>
          <div class="qp-field"><label>المنفذ</label><input class="qp-input" name="db_port" dir="ltr" value="<?php echo $v('db_port', '3306'); ?>"></div>
          <div class="qp-field"><label>اسم القاعدة</label><input class="qp-input" name="db_name" dir="ltr" value="<?php echo $v('db_name'); ?>"></div>
          <div class="qp-field"><label>المستخدم</label><input class="qp-input" name="db_user" dir="ltr" value="<?php echo $v('db_user'); ?>"></div>
          <div class="qp-field"><label>كلمة المرور</label><input class="qp-input" name="db_pass" type="password" dir="ltr"></div>
        </div>
      </details>
      <details><summary>متقدم</summary>
        <label class="qi-radio" style="margin-top:10px"><input type="checkbox" name="trust_proxy" value="1" <?php echo !empty($old['trust_proxy']) ? 'checked' : ''; ?>>
          <span><b>الموقع خلف Cloudflare أو بروكسي</b><small>لقراءة عنوان IP الحقيقي للزوار. لا تفعّله إن لم تكن متأكداً.</small></span></label>
      </details>
    </section>

    <button class="qp-btn" type="submit" style="margin-top:16px" <?php echo $allOk ? '' : 'disabled'; ?>>تثبيت المنصة</button>
    <p class="qp-hint">رابط المنصة: <span dir="ltr"><?php echo esc_html(url('/')); ?></span></p>
  </form>
</main>
</body>
</html>
