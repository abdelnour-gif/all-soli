<?php
require_once __DIR__ . '/_init.php';
requireAdmin();
$db = getDB();

$textKeys = ['store_name', 'store_tagline', 'owner_name', 'phone', 'whatsapp', 'address',
    'facebook', 'instagram', 'tiktok', 'youtube', 'pinterest', 'return_days', 'fb_pixel', 'gift_wrap_price', 'address_fr'];
$checkKeys = ['gift_wrap_on', 'gift_msg_on', 'reviews_on', 'reviews_moderate'];

require_once dirname(__DIR__) . '/includes/smtp.php';
if (adminPost()) {
    $section = $_POST['section'] ?? '';
    if ($section === 'general') {
        foreach ($textKeys as $k) if (isset($_POST[$k])) saveSetting($k, trim((string)$_POST[$k]));
        foreach ($checkKeys as $k) saveSetting($k, isset($_POST[$k]) ? '1' : '0');
        if (trim((string)($_POST['store_name'] ?? '')) === '') saveSetting('store_name', 'GlowSkin');
        saveSetting('default_lang', ($_POST['default_lang'] ?? '') === 'fr' ? 'fr' : 'ar');
        flash('تم حفظ الإعدادات');
    } elseif ($section === 'account') {
        ensureAdminEmailColumns();
        $st = $db->prepare('SELECT * FROM admins WHERE id = ?');
        $st->execute([$_SESSION['admin_id']]);
        $admin = $st->fetch();
        if (!$admin) { unset($_SESSION['admin_id'], $_SESSION['admin_user']); redirect('login.php'); }
        $newUser = trim((string)($_POST['username'] ?? ''));
        $newEmail = strtolower(trim((string)($_POST['email'] ?? '')));
        $new = cleanNewPassword((string)($_POST['new_password'] ?? ''));
        $confirm = cleanNewPassword((string)($_POST['confirm_password'] ?? ''));
        // Only a NEW username can be "taken": saving the e-mail or password alone never checks it.
        $taken = $db->prepare('SELECT COUNT(*) FROM admins WHERE lower(username) = lower(?) AND id <> ?');
        $taken->execute([$newUser, $admin['id']]);
        $isTaken = mb_strtolower($newUser) !== mb_strtolower((string)$admin['username']) && (int)$taken->fetchColumn() > 0;
        if (!adminPasswordMatches((string)($_POST['current_password'] ?? ''), $admin['password'])) {
            flash('كلمة المرور الحالية غير صحيحة — لم يتم حفظ أي تغيير', 'error');
        } elseif (!preg_match('/^[\p{L}\p{N}_.\-@]{3,40}$/u', $newUser)) {
            flash('اسم المستخدم يجب أن يكون من 3 إلى 40 حرفاً (حروف، أرقام، _ . -) وبدون مسافات', 'error');
        } elseif ($isTaken) {
            flash('اسم المستخدم مستعمل', 'error');
        } elseif ($newEmail !== '' && !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            flash('البريد الإلكتروني غير صحيح — مثال: name@gmail.com', 'error');
        } elseif ($new !== '' && strlen($new) < 8) {
            flash('كلمة المرور الجديدة يجب أن تكون 8 أحرف على الأقل', 'error');
        } elseif ($new !== $confirm) {
            flash('تأكيد كلمة المرور غير مطابق', 'error');
        } else {
            $hash = $new !== '' ? password_hash($new, PASSWORD_DEFAULT) : $admin['password'];
            $db->prepare('UPDATE admins SET username = ?, password = ?, email = ? WHERE id = ?')
               ->execute([$newUser, $hash, $newEmail, $admin['id']]);
            $_SESSION['admin_user'] = $newUser;
            flash('تم الحفظ ✔ — للدخول في المرة القادمة استعمل اسم المستخدم «' . $newUser . '»' . ($new !== '' ? ' مع كلمة المرور الجديدة التي كتبتها للتو.' : ' مع نفس كلمة المرور.'));
        }
        redirect('settings.php#account');
    } elseif ($section === 'test_mail') {
        ensureAdminEmailColumns();
        $st = $db->prepare('SELECT email FROM admins WHERE id = ?');
        $st->execute([$_SESSION['admin_id']]);
        $to = (string)$st->fetchColumn();
        $err = storeMail($to, 'رسالة تجريبية — ' . setting('store_name'), '<div dir="rtl" style="font-family:Tahoma,Arial;font-size:15px">✅ البريد يعمل. ستصلك هنا روابط «نسيت كلمة المرور» الخاصة بلوحة التحكم.</div>');
        flash($err === '' ? 'تم إرسال رسالة تجريبية إلى ' . $to . ' ✔ — إن لم تجدها خلال دقيقتين في البريد الوارد، تفقّد مجلد Spam.' : 'فشل الإرسال: ' . $err, $err === '' ? 'success' : 'error');
        redirect('settings.php#account');
    } elseif ($section === 'smtp') {
        $preset = smtpPresets()[$_POST['smtp_preset'] ?? ''] ?? null;
        $host = strtolower(trim((string)($_POST['smtp_host'] ?? '')));
        $port = (int)($_POST['smtp_port'] ?? 0);
        $sec = ($_POST['smtp_secure'] ?? '') === 'tls' ? 'tls' : 'ssl';
        if ($preset && $host === '') { $host = $preset[1]; $port = $preset[2]; $sec = $preset[3]; }
        $user = strtolower(trim((string)($_POST['smtp_user'] ?? '')));
        if ($user !== '' && !filter_var($user, FILTER_VALIDATE_EMAIL)) {
            flash('بريد الإرسال غير صحيح — مثال: name@gmail.com', 'error');
            redirect('settings.php#smtp');
        }
        if ($host !== '' && !preg_match('/^[a-z0-9.\-]+$/', $host)) {
            flash('عنوان خادم SMTP غير صحيح', 'error');
            redirect('settings.php#smtp');
        }
        saveSetting('smtp_on', isset($_POST['smtp_on']) ? '1' : '0');
        saveSetting('smtp_host', $host);
        saveSetting('smtp_port', (string)($port ?: ($sec === 'tls' ? 587 : 465)));
        saveSetting('smtp_secure', $sec);
        saveSetting('smtp_user', $user);
        saveSetting('smtp_from_name', trim((string)($_POST['smtp_from_name'] ?? '')));
        // Google shows the App Password in groups of 4 with spaces: remove them.
        $pass = (string)($_POST['smtp_pass'] ?? '');
        if (trim($pass) !== '') saveSetting('smtp_pass', preg_match('/^\s*[a-z]{4}(\s+[a-z]{4}){3}\s*$/i', $pass) || str_contains($host, 'gmail') ? preg_replace('/\s+/', '', $pass) : $pass);
        if (isset($_POST['smtp_pass_clear'])) saveSetting('smtp_pass', '');
        if (($_POST['do'] ?? '') === 'test') {
            ensureAdminEmailColumns();
            $st = $db->prepare('SELECT email FROM admins WHERE id = ?');
            $st->execute([$_SESSION['admin_id']]);
            $to = (string)$st->fetchColumn() ?: $user;
            $err = storeMail($to, 'رسالة تجريبية — ' . setting('store_name'), '<div dir="rtl" style="font-family:Tahoma,Arial;font-size:15px">✅ البريد يعمل. ستصلك هنا روابط «نسيت كلمة المرور» الخاصة بلوحة التحكم.</div>');
            flash($err === '' ? 'تم إرسال رسالة تجريبية إلى ' . $to . ' ✔ — يجب أن تصلك في البريد الوارد خلال لحظات.' : 'فشل الإرسال: ' . $err, $err === '' ? 'success' : 'error');
        } else {
            flash('تم حفظ إعدادات بريد الإرسال');
        }
        redirect('settings.php#smtp');
    } elseif ($section === 'telegram') {
        require_once dirname(__DIR__) . '/includes/telegram.php';
        $token = trim((string)($_POST['telegram_token'] ?? ''));
        if ($token !== '') {
            if (!preg_match('/^\d{5,}:[A-Za-z0-9_-]{30,}$/', $token)) {
                flash('رمز البوت غير صحيح — يجب أن يكون بالشكل 123456789:ABCdef... كما أعطاك إياه BotFather', 'error');
                redirect('settings.php#telegram');
            }
            saveSetting('telegram_token', $token);
        }
        if (isset($_POST['telegram_token_clear'])) saveSetting('telegram_token', '');
        $chats = implode(',', array_filter(array_map('trim', explode(',', (string)($_POST['telegram_chat'] ?? ''))), fn($c) => preg_match('/^-?\d{3,}$|^@[A-Za-z0-9_]{4,}$/', $c)));
        saveSetting('telegram_chat', $chats);
        saveSetting('telegram_on', isset($_POST['telegram_on']) ? '1' : '0');
        $do = $_POST['do'] ?? '';
        if ($do === 'find') {
            try {
                $found = telegramFindChats();
                if (!$found) {
                    flash('لم نجد أي محادثة. افتح البوت في تلغرام، اضغط «Start» أو أرسل له أي رسالة، ثم اضغط الزر مرة أخرى.', 'error');
                } else {
                    $all = array_unique(array_merge(array_filter(explode(',', $chats)), array_keys($found)));
                    saveSetting('telegram_chat', implode(',', $all));
                    flash('تمت إضافة: ' . implode('، ', array_map(fn($id, $n) => $n . ' (' . $id . ')', array_keys($found), $found)) . ' ✔ — اضغط «إرسال رسالة تجريبية» للتأكد.');
                }
            } catch (Throwable $ex) {
                flash('تلغرام: ' . $ex->getMessage(), 'error');
            }
        } elseif ($do === 'test') {
            if (setting('telegram_token') === '' || setting('telegram_chat') === '') {
                flash('أدخل رمز البوت ورقم المحادثة (Chat ID) أولاً', 'error');
            } else {
                [$sent, $err] = telegramSend('✅ <b>' . tg(setting('store_name', 'المتجر')) . '</b>' . "\n" . 'تم ربط المتجر بتلغرام بنجاح. ستصلك هنا كل الطلبات الجديدة.');
                if ($sent && $err === '') flash('تم إرسال رسالة تجريبية إلى تلغرام ✔');
                else flash(($sent ? 'وصلت الرسالة إلى ' . $sent . ' محادثة، وفشلت محادثة أخرى: ' : 'فشل الإرسال: ') . $err . ' — تأكد من رقم المحادثة وأنك ضغطت «Start» في البوت', 'error');
            }
        } else {
            flash('تم حفظ إعدادات تلغرام');
        }
        redirect('settings.php#telegram');
    } elseif ($section === 'recompress') {
        require_once dirname(__DIR__) . '/includes/images.php';
        @set_time_limit(120);
        [$n, $before, $after, $left] = recompressExisting(40);
        flash($n ? "تمت معالجة $n صورة: من " . humanBytes($before) . ' إلى ' . humanBytes($after) . ($left ? " — بقيت $left صورة، اضغط الزر مرة أخرى" : ' ✔') : 'كل الصور مضغوطة بالفعل ✔');
        redirect('settings.php#images');
    }
    redirect('settings.php');
}
$get = fn($k) => e(setting($k));
adminHeader('الإعدادات', 'settings');
?>
<form method="post" class="form">
  <?= csrfField() ?><input type="hidden" name="section" value="general">
  <div class="grid-2">
    <div>
      <div class="panel form">
        <div class="panel-head"><h2>معلومات المتجر</h2></div>
        <div class="row">
          <label>اسم المتجر<input type="text" name="store_name" value="<?= $get('store_name') ?>"></label>
          <label>الشعار الفرعي (تحت الاسم)<input type="text" name="store_tagline" value="<?= $get('store_tagline') ?>"></label>
        </div>
        <label>اسم المالك<input type="text" name="owner_name" value="<?= $get('owner_name') ?>"></label>
        <div class="row">
          <label>الهاتف<input type="text" name="phone" value="<?= $get('phone') ?>" dir="ltr"></label>
          <label>واتساب (يظهر زر عائم)<input type="text" name="whatsapp" value="<?= $get('whatsapp') ?>" dir="ltr" placeholder="0550000000"></label>
        </div>
        <div class="row">
          <label>العنوان<input type="text" name="address" value="<?= $get('address') ?>"></label>
          <label>العنوان بالفرنسية<input type="text" name="address_fr" value="<?= $get('address_fr') ?>" dir="ltr"></label>
        </div>
        <label>لغة المتجر الافتراضية (للزائر الذي لم يختر لغة بعد)
          <select name="default_lang"><option value="ar" <?= setting('default_lang', 'ar') !== 'fr' ? 'selected' : '' ?>>العربية</option><option value="fr" <?= setting('default_lang') === 'fr' ? 'selected' : '' ?>>Français</option></select>
        </label>
        <p class="hint">الزائر يغيّر اللغة من زر اللغة أعلى المتجر. النصوص الفرنسية للمنتجات والأقسام والسلايدر تُكتب في صفحاتها، ونصوص الواجهة في <a href="appearance.php"><b>الواجهة والمظهر</b></a>.</p>
        <label>مدة الإرجاع (أيام — 0 لإخفائها)<input type="number" name="return_days" value="<?= $get('return_days') ?>" min="0"></label>
        <p class="hint">نص "من نحن" والصفحات الأخرى في <a href="pages.php"><b>الصفحات</b></a>، والألوان والشعار والبنرات في <a href="appearance.php"><b>الواجهة والمظهر</b></a>.</p>
      </div>
      <div class="panel form">
        <div class="panel-head"><h2>مواقع التواصل</h2></div>
        <?php foreach (['facebook' => 'Facebook', 'instagram' => 'Instagram', 'tiktok' => 'TikTok', 'youtube' => 'YouTube', 'pinterest' => 'Pinterest'] as $k => $l): ?>
          <label><?= $l ?><input type="url" name="<?= $k ?>" value="<?= $get($k) ?>" dir="ltr" placeholder="https://"></label>
        <?php endforeach; ?>
        <label>Facebook Pixel ID<input type="text" name="fb_pixel" value="<?= $get('fb_pixel') ?>" dir="ltr" placeholder="123456789012345"></label>
      </div>
    </div>
    <div>
      <div class="panel form">
        <div class="panel-head"><h2>خيارات الهدية (عند إتمام الطلب)</h2></div>
        <label class="check"><input type="checkbox" name="gift_wrap_on" <?= setting('gift_wrap_on') === '1' ? 'checked' : '' ?>> السماح للزبون باختيار "تغليف هدية"</label>
        <label>سعر التغليف (د.ج — 0 = مجاني)<input type="number" name="gift_wrap_price" value="<?= $get('gift_wrap_price') ?>" min="0"></label>
        <label class="check"><input type="checkbox" name="gift_msg_on" <?= setting('gift_msg_on') === '1' ? 'checked' : '' ?>> السماح بكتابة رسالة لبطاقة التهنئة</label>
      </div>
      <div class="panel form">
        <div class="panel-head"><h2>تقييمات العملاء</h2></div>
        <label class="check"><input type="checkbox" name="reviews_on" <?= setting('reviews_on') === '1' ? 'checked' : '' ?>> تفعيل التقييمات في صفحات المنتجات</label>
        <label class="check"><input type="checkbox" name="reviews_moderate" <?= setting('reviews_moderate') === '1' ? 'checked' : '' ?>> لا يظهر التقييم إلا بعد موافقتي (يُنصح به)</label>
      </div>
      <button class="btn btn-gold btn-block">حفظ الإعدادات</button>
    </div>
  </div>
</form>

<?php
require_once dirname(__DIR__) . '/includes/smtp.php';
ensureAdminEmailColumns();
rememberAdminUrl();
$me = $db->prepare('SELECT username, email FROM admins WHERE id = ?');
$me->execute([$_SESSION['admin_id']]);
$me = $me->fetch();
?>
<div class="grid-2">
<form method="post" class="panel form" id="account" autocomplete="off">
  <?= csrfField() ?><input type="hidden" name="section" value="account">
  <div class="panel-head"><h2>بيانات الحساب (الدخول إلى لوحة التحكم)</h2></div>
  <p class="hint">لتغيير <b>اسم المستخدم</b> أو <b>كلمة المرور</b>: عدّل الحقل المطلوب، ثم اكتب كلمة المرور الحالية للتأكيد واضغط "حفظ". اترك خانتي كلمة المرور الجديدة فارغتين إذا لم ترد تغييرها.</p>
  <div class="row">
    <label>اسم المستخدم<input type="text" name="username" value="<?= e($me['username']) ?>" required minlength="3" maxlength="40" autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false"></label>
    <label>البريد الإلكتروني (لاستعادة كلمة المرور)<input type="email" name="email" value="<?= e($me['email'] ?? '') ?>" dir="ltr" placeholder="name@gmail.com" maxlength="120" autocomplete="email" autocapitalize="none" autocorrect="off" spellcheck="false"></label>
  </div>
  <div class="row">
    <label>كلمة المرور الجديدة (اختياري — 8 أحرف على الأقل)<span class="pw-wrap"><input type="password" name="new_password" minlength="8" autocomplete="new-password" autocapitalize="none" autocorrect="off" spellcheck="false"><button type="button" class="pw-eye" aria-label="إظهار كلمة المرور">👁</button></span></label>
    <label>تأكيد كلمة المرور الجديدة<span class="pw-wrap"><input type="password" name="confirm_password" autocomplete="new-password" autocapitalize="none" autocorrect="off" spellcheck="false"><button type="button" class="pw-eye" aria-label="إظهار كلمة المرور">👁</button></span></label>
  </div>
  <label>كلمة المرور الحالية * (مطلوبة لحفظ أي تغيير)<span class="pw-wrap"><input type="password" name="current_password" required autocomplete="current-password" autocapitalize="none" autocorrect="off" spellcheck="false"><button type="button" class="pw-eye" aria-label="إظهار كلمة المرور">👁</button></span></label>
  <button class="btn btn-dark">حفظ بيانات الحساب</button>
  <p class="hint">إذا نسيت كلمة المرور: أنشئ ملفاً باسم <code dir="ltr">reset-password.txt</code> داخل مجلد <code>database</code> من مدير ملفات الاستضافة، واكتب فيه كلمة المرور الجديدة، ثم افتح صفحة الدخول.</p>
</form>
<form method="post" class="panel form">
  <?= csrfField() ?><input type="hidden" name="section" value="test_mail">
  <p class="hint">بعد حفظ بريدك أعلاه، جرّب الإرسال للتأكد أن «نسيت كلمة المرور» ستعمل<?= !empty($me['email']) ? ' (إلى <b dir="ltr">' . e($me['email']) . '</b>)' : '' ?>:</p>
  <?php if ($lm = setting('last_mail_status')): ?><div class="alert <?= strpos($lm, 'فشل') !== false ? 'alert-error' : 'alert-success' ?>"><b>آخر محاولة إرسال:</b> <?= e($lm) ?></div><?php endif; ?>
  <button class="btn btn-light">إرسال رسالة تجريبية إلى بريدي</button>
</form>
<?php $smPass = setting('smtp_pass'); $smOn = smtpConfigured(); ?>
<form method="post" class="panel form" id="smtp" autocomplete="off">
  <?= csrfField() ?><input type="hidden" name="section" value="smtp">
  <div class="panel-head"><h2>بريد الإرسال (SMTP) — لتصل الرسائل إلى البريد الوارد وليس Spam</h2></div>
  <div class="alert <?= $smOn ? 'alert-success' : 'alert-error' ?>"><?= $smOn ? '✔ الرسائل تُرسل الآن عبر <b dir="ltr">' . e(setting('smtp_user')) . '</b>' : 'غير مفعّل: الرسائل تُرسل من الاستضافة مباشرة، وقد تصل إلى Spam.' ?></div>
  <p class="hint"><b>الطريقة الأسهل (Gmail):</b> الرسالة تُرسل من حساب Gmail الخاص بك إلى بريدك، فتصل إلى البريد الوارد مع إشعار في الهاتف.</p>
  <ol class="hint steps-hint">
    <li>في حساب Google فعّل <b>التحقق بخطوتين</b> (الأمان ← التحقق بخطوتين).</li>
    <li>افتح <a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener"><b dir="ltr">myaccount.google.com/apppasswords</b></a>، اكتب اسماً (مثلاً «المتجر») واضغط «إنشاء».</li>
    <li>انسخ كلمة المرور المكوّنة من 16 حرفاً والصقها هنا، واكتب بريد Gmail نفسه، ثم اضغط «حفظ وإرسال رسالة تجريبية».</li>
  </ol>
  <label class="check"><input type="checkbox" name="smtp_on" <?= setting('smtp_on') === '1' ? 'checked' : '' ?>> الإرسال عبر SMTP</label>
  <label>مزوّد البريد
    <select name="smtp_preset" id="smtpPreset">
      <option value="">— اختر لملء الخادم تلقائياً —</option>
      <?php foreach (smtpPresets() as $k => $pr): ?><option value="<?= $k ?>" data-host="<?= e($pr[1]) ?>" data-port="<?= $pr[2] ?>" data-sec="<?= $pr[3] ?>"><?= e($pr[0]) ?></option><?php endforeach; ?>
    </select>
  </label>
  <div class="row">
    <label>البريد المرسِل (اسم المستخدم)<input type="email" name="smtp_user" value="<?= $get('smtp_user') ?>" dir="ltr" placeholder="name@gmail.com" autocapitalize="none" spellcheck="false"></label>
    <label>كلمة المرور (App Password)
      <input type="password" name="smtp_pass" dir="ltr" autocomplete="new-password" placeholder="<?= $smPass ? 'محفوظة: ••••' . e(substr($smPass, -2)) . ' — اتركها فارغة للإبقاء عليها' : 'abcd efgh ijkl mnop' ?>">
    </label>
  </div>
  <?php if ($smPass): ?><label class="check"><input type="checkbox" name="smtp_pass_clear"> حذف كلمة المرور المحفوظة</label><?php endif; ?>
  <div class="row">
    <label>خادم SMTP<input type="text" name="smtp_host" id="smtpHost" value="<?= $get('smtp_host') ?>" dir="ltr" placeholder="smtp.gmail.com"></label>
    <label>المنفذ<input type="number" name="smtp_port" id="smtpPort" value="<?= e(setting('smtp_port', '465')) ?>" dir="ltr"></label>
    <label>التشفير<select name="smtp_secure" id="smtpSec"><option value="ssl" <?= setting('smtp_secure', 'ssl') !== 'tls' ? 'selected' : '' ?>>SSL (465)</option><option value="tls" <?= setting('smtp_secure') === 'tls' ? 'selected' : '' ?>>STARTTLS (587)</option></select></label>
  </div>
  <label>اسم المرسِل الظاهر (اختياري)<input type="text" name="smtp_from_name" value="<?= $get('smtp_from_name') ?>" placeholder="<?= $get('store_name') ?>"></label>
  <div class="btn-row">
    <button class="btn btn-dark">حفظ</button>
    <button class="btn btn-light" name="do" value="test">حفظ وإرسال رسالة تجريبية</button>
  </div>
  <p class="hint">💡 إذا ربطت <a href="#telegram"><b>تلغرام</b></a>، يصلك رابط «نسيت كلمة المرور» أيضاً على تلغرام بإشعار فوري.</p>
</form>
<script>
(function () {
  var p = document.getElementById('smtpPreset');
  if (p) p.addEventListener('change', function () {
    var o = p.options[p.selectedIndex];
    if (!o.dataset.host) return;
    document.getElementById('smtpHost').value = o.dataset.host;
    document.getElementById('smtpPort').value = o.dataset.port;
    document.getElementById('smtpSec').value = o.dataset.sec;
  });
})();
</script>
<script src="../assets/js/admin-pw.js?v=1"></script>
<?php $tgTok = setting('telegram_token'); ?>
<form method="post" class="panel form" id="telegram" autocomplete="off">
  <?= csrfField() ?><input type="hidden" name="section" value="telegram">
  <div class="panel-head"><h2>إشعارات تلغرام (كل طلب جديد يصلك على تلغرام)</h2></div>
  <ol class="hint steps-hint">
    <li>في تلغرام افتح <a href="https://t.me/BotFather" target="_blank" rel="noopener"><b>@BotFather</b></a>، أرسل <code dir="ltr">/newbot</code> واتبع الخطوات (اسم البوت ثم اسم مستخدم ينتهي بـ bot).</li>
    <li>انسخ الرمز الذي يعطيك إياه (مثل <code dir="ltr">123456789:ABC...</code>) والصقه هنا واضغط «حفظ».</li>
    <li>افتح البوت الجديد في تلغرام واضغط <b>Start</b>.</li>
    <li>ارجع هنا واضغط <b>«اكتشاف رقم المحادثة تلقائياً»</b>، ثم <b>«إرسال رسالة تجريبية»</b>.</li>
  </ol>
  <label class="check"><input type="checkbox" name="telegram_on" <?= setting('telegram_on', '1') === '1' ? 'checked' : '' ?>> تفعيل إشعارات الطلبات على تلغرام</label>
  <label>رمز البوت (Bot Token)
    <input type="password" name="telegram_token" dir="ltr" autocomplete="new-password" placeholder="<?= $tgTok ? 'محفوظ: ••••' . e(substr($tgTok, -4)) . ' — اتركه فارغاً للإبقاء عليه' : '123456789:ABCdefGhIJKlmNoPQRstuVWxyz' ?>">
  </label>
  <?php if ($tgTok): ?><label class="check"><input type="checkbox" name="telegram_token_clear"> حذف رمز البوت</label><?php endif; ?>
  <label>رقم المحادثة (Chat ID) — يمكن وضع أكثر من رقم مفصولة بفاصلة
    <input type="text" name="telegram_chat" dir="ltr" value="<?= $get('telegram_chat') ?>" placeholder="123456789, -1001234567890">
  </label>
  <p class="hint">لإرسال الطلبات إلى مجموعة (لك ولعمالك): أضف البوت إلى المجموعة، أرسل فيها أي رسالة، ثم اضغط «اكتشاف رقم المحادثة».</p>
  <?php if ($last = setting('telegram_last')): ?><p class="hint"><b>آخر إشعار:</b> <?= e($last) ?></p><?php endif; ?>
  <div class="btn-row">
    <button class="btn btn-dark">حفظ</button>
    <?php if ($tgTok): ?>
      <button class="btn btn-light" name="do" value="find">اكتشاف رقم المحادثة تلقائياً</button>
      <button class="btn btn-light" name="do" value="test">إرسال رسالة تجريبية</button>
    <?php endif; ?>
  </div>
</form>
</div>
<?php require_once dirname(__DIR__) . '/includes/images.php'; [$upBytes, $upCount] = uploadsSize(); ?>
<form method="post" class="panel form" id="images">
  <?= csrfField() ?><input type="hidden" name="section" value="recompress">
  <div class="panel-head"><h2>ضغط الصور</h2></div>
  <p>كل صورة تُرفع (منتجات، سلايدر، بنرات، أقسام، شعار) تُضغط تلقائياً: تصغير الأبعاد (المنتجات 1600px، السلايدر 2400px، الأقسام 900px — ضعف الحجم المعروض حتى تبقى حادة على شاشات الهواتف)، وتحويلها إلى <?= imageSupportsWebp() ? '<b>WebP</b>' : '<b>JPEG/PNG</b> (الاستضافة لا تدعم WebP)' ?> بجودة عالية (85+) لا يلاحظ الفرق فيها، مع نسخة صغيرة لبطاقات المنتجات. إذا كانت الصورة المرفوعة صغيرة وخفيفة أصلاً تُحفظ كما هي دون أي إعادة ضغط.</p>
  <p class="hint">الزبون يرى الصورة بجودتها الكاملة؛ ما يُحذف هو فقط البكسلات الزائدة التي لا تظهر على أي شاشة. النتيجة: صفحات أسرع، استهلاك أقل للمساحة والإنترنت ولموارد الاستضافة، والصور تُحفظ في متصفح الزائر لمدة سنة فلا تُحمَّل مرة ثانية.</p>
  <div class="kv">
    <div><span>عدد الصور المرفوعة</span><b><?= $upCount ?></b></div>
    <div><span>الحجم الكلي</span><b><?= humanBytes($upBytes) ?></b></div>
    <div><span>مكتبة معالجة الصور (GD)</span><b><?= extension_loaded('gd') ? '✔ متوفرة' : '✘ غير متوفرة — الصور تُحفظ بدون ضغط' ?></b></div>
  </div>
  <p class="hint">إذا كانت هناك صور رُفعت قبل تفعيل الضغط، اضغط الزر لضغطها (40 صورة في كل مرة، بدون تغيير أي رابط). الصور الخفيفة أصلاً لا تُلمس.</p>
  <button class="btn btn-light">ضغط الصور القديمة</button>
</form>
<?php adminFooter(); ?>
