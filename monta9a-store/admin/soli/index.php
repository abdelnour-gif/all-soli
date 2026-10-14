<?php
/**
 * Hidden page: ON/OFF switch of ALL the delivery companies (Yalidine, Guepex, ZR, E-COM, NOEST, Maystro, EcoTrack companies...).
 * Only the switches are here. A company switched ON appears in the dashboard page /admin/solivr,
 * where its details (Token, API link, options) are filled and where it is offered to customers.
 *
 * Not linked from anywhere in the admin panel. Open it by typing
 *   https://your-site/admin/soli
 * It has its OWN password (independent of the admin login). Only a hash of the
 * password is stored below. After 5 wrong attempts the IP is locked for 5 minutes.
 *
 * To change the password: run   php -r 'echo password_hash("NEW-PASSWORD", PASSWORD_DEFAULT);'
 * and paste the result into SOLI_PASSWORD_HASH.
 *
 * The values are saved in database/couriers-config.php (not in the database; secrets never shown elsewhere).
 */
define('ADMIN_BASE', '../');
require_once dirname(__DIR__) . '/_init.php';
require_once ROOT_PATH . '/includes/courier.php';
require_once ROOT_PATH . '/includes/dhl.php';
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

const SOLI_PASSWORD_HASH = '$2y$10$iDUneyDORTZa8pakH0qSSeruSmft5YKRaOHB.IruwepzedYBQPYSe';
const SOLI_SESSION_TTL = 7200; // stay unlocked for 2 hours of inactivity

/** Plain password page (no admin menu, nothing that reveals the store's admin). */
function soliLoginPage(string $error = ''): void {
    header('Content-Type: text/html; charset=utf-8');
    ?><!DOCTYPE html>
<html lang="ar" dir="rtl"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow"><title>🔒</title>
<style>
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f4f4f5;font-family:Tahoma,Arial,sans-serif}
form{background:#fff;padding:28px 24px;border-radius:12px;box-shadow:0 2px 14px rgba(0,0,0,.08);width:100%;max-width:320px;margin:16px}
input{width:100%;box-sizing:border-box;padding:12px;border:1px solid #ccc;border-radius:8px;font-size:16px;margin-bottom:12px}
button{width:100%;padding:12px;border:0;border-radius:8px;background:#111;color:#fff;font-size:16px;cursor:pointer}
.err{color:#b00020;font-size:14px;margin:0 0 12px}
</style></head><body>
<form method="post" autocomplete="off">
  <?= csrfField() ?>
  <?php if ($error !== ''): ?><p class="err"><?= e($error) ?></p><?php endif; ?>
  <input type="password" name="soli_password" placeholder="كلمة السر" required autofocus autocomplete="current-password">
  <button>دخول</button>
</form>
</body></html><?php
    exit;
}

// Leave the page (forget the soli password for this browser).
if (isset($_GET['logout'])) {
    unset($_SESSION['soli_ok'], $_SESSION['soli_seen']);
    redirect('index.php');
}

$unlocked = !empty($_SESSION['soli_ok']) && time() - (int)($_SESSION['soli_seen'] ?? 0) < SOLI_SESSION_TTL;
if (!$unlocked) {
    unset($_SESSION['soli_ok'], $_SESSION['soli_seen']);
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['soli_password'])) {
        if (!checkCsrf()) soliLoginPage('انتهت صلاحية الصفحة، أعد المحاولة');
        $wait = loginLockedUntil() - time();
        if ($wait > 0) soliLoginPage('محاولات كثيرة خاطئة. انتظر ' . ceil($wait / 60) . ' دقيقة ثم أعد المحاولة');
        if (password_verify((string)$_POST['soli_password'], SOLI_PASSWORD_HASH)) {
            clearLoginAttempts();
            session_regenerate_id(true);
            $_SESSION['soli_ok'] = 1;
            $_SESSION['soli_seen'] = time();
            redirect('index.php');
        }
        usleep(400000); // slow down guessing
        if (recordLoginFailure() >= 5) lockLogin(300);
        soliLoginPage('كلمة السر غير صحيحة');
    }
    soliLoginPage();
}
$_SESSION['soli_seen'] = time();

if (adminPost()) {
    $key = (string)($_POST['company'] ?? '');
    if ($key === 'dhl') {
        // DHL Express (international shipping of the orders paid with PayPal) — details filled in /admin/solivr
        $on = setting('dhl_on') === '1' ? '0' : '1';
        saveSetting('dhl_on', $on);
        flash($on === '1'
            ? 'تم تشغيل DHL Express ✔ — املأ معلوماتها في صفحة «شركات التوصيل» في لوحة التحكم'
            : 'تم إيقاف DHL Express — لم تعد تظهر في لوحة التحكم (معلوماتها محفوظة إذا أعدت تشغيلها)');
        redirect('index.php#c-dhl');
    }
    $cat = courierCatalog();
    if (!isset($cat[$key])) { flash('شركة غير معروفة', 'error'); redirect('index.php'); }
    $cfg = courierCfg($key);
    $cfg['on'] = ($cfg['on'] ?? '0') === '1' ? '0' : '1';
    if (!isset($cfg['active'])) $cfg['active'] = '1';
    if (!courierSaveCfg($key, $cfg)) { flash('تعذر الحفظ — مجلد database غير قابل للكتابة', 'error'); redirect('index.php#c-' . $key); }
    flash($cfg['on'] === '1'
        ? 'تم تشغيل ' . $cat[$key][0] . ' ✔ — املأ معلوماتها في صفحة «شركات التوصيل» في لوحة التحكم'
        : 'تم إيقاف ' . $cat[$key][0] . ' — لم تعد تظهر في «شركات التوصيل» ولا للزبائن (معلوماتها محفوظة إذا أعدت تشغيلها)');
    redirect('index.php#c-' . $key);
}

$cat = courierCatalog();
$groups = ['yalidine' => 'منصة Yalidine', 'zr' => 'ZR Express', 'ecom' => 'E-COM', 'noest' => 'NOEST', 'maystro' => 'Maystro', 'ecotrack' => 'شركات منصة EcoTrack'];
adminHeader('ربط شركات التوصيل', '');
?>
<style>
.co-list { display: grid; gap: 10px; }
.co { border: 1px solid #e3e6ea; border-radius: 12px; background: #fff; overflow: hidden; }
.co-head { display: flex; align-items: center; gap: 12px; padding: 12px 16px; }
.co-head b { font-size: 15.5px; }
.co-head small { color: #6b7079; }
.co-head .grow { flex: 1; display: flex; flex-direction: column; }
.co.on { border-color: #9fd1b5; }
.co.on .co-head { background: #f3faf6; }
.sw { position: relative; width: 52px; height: 28px; border-radius: 20px; background: #cfd4da; border: 0; cursor: pointer; transition: .2s; flex: none; }
.sw::after { content: ''; position: absolute; top: 3px; inset-inline-start: 3px; width: 22px; height: 22px; border-radius: 50%; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.25); transition: .2s; }
.sw.on { background: #1a8a55; }
.sw.on::after { inset-inline-start: 27px; }
.sw-lbl { font-weight: 700; font-size: 13px; min-width: 34px; }
.grp-title { margin: 18px 0 8px; font-size: 15px; color: #444; }
</style>
<div class="panel">
  <div class="panel-head"><h2>🚚 كل شركات التوصيل الجزائرية</h2></div>
  <p class="hint">هذه الصفحة مخفية وتُفتح بالرابط <code dir="ltr">/admin/soli</code> وكلمة السر الخاصة بها. هنا <b>التشغيل والإيقاف فقط</b>: اضغط <b>ON</b> أمام الشركة التي لديك حساب فيها، فتظهر في صفحة <a href="../solivr/"><b>شركات التوصيل</b></a> في لوحة التحكم، وهناك تملأ معلوماتها (Token...) وتختار أيها يظهر للزبائن. <a href="index.php?logout=1">خروج من هذه الصفحة</a></p>
  <div class="kv">
    <div><span>الشركات المشغّلة</span><b><?= count(courierOnList()) ?></b></div>
    <div><span>المربوطة (معلوماتها كاملة)</span><b><?= count(courierReadyList()) ?></b></div>
    <div><span>الظاهرة للزبائن</span><b><?= count(courierActiveList()) ?></b></div>
    <div><span>مجلد database قابل للكتابة</span><b><?= is_writable(dirname(COURIERS_FILE)) ? '✔ نعم' : '✘ لا' ?></b></div>
  </div>
</div>

<?php foreach ($groups as $platform => $gTitle): ?>
<h3 class="grp-title"><?= e($gTitle) ?></h3>
<div class="co-list">
<?php foreach ($cat as $key => $co): if ($co[1] !== $platform) continue; $c = courierCfg($key); $on = ($c['on'] ?? '0') === '1'; $ready = courierReady($key); ?>
  <div class="co <?= $on ? 'on' : '' ?>" id="c-<?= e($key) ?>">
    <div class="co-head">
      <span class="grow"><b><?= e($co[0]) ?></b><small dir="ltr"><?= e(preg_replace('#^https://#', '', $co[2])) ?><?= $co[3] ? ' — ' . e($co[3]) : '' ?></small></span>
      <?php if ($ready): ?><span class="pill st-delivered">مربوطة ✔</span><?php elseif ($on): ?><a href="../solivr/#c-<?= e($key) ?>" class="pill">املأ معلوماتها ←</a><?php endif; ?>
      <form method="post" class="inline"><?= csrfField() ?><input type="hidden" name="company" value="<?= e($key) ?>">
        <span class="sw-lbl"><?= $on ? 'ON' : 'OFF' ?></span> <button class="sw <?= $on ? 'on' : '' ?>" aria-label="تشغيل / إيقاف <?= e($co[0]) ?>"></button>
      </form>
    </div>
  </div>
<?php endforeach; ?>
</div>
<?php endforeach; ?>

<?php $dOn = setting('dhl_on') === '1'; $dReady = dhlReady(); ?>
<h3 class="grp-title">🌍 الشحن الدولي (للزبائن خارج الجزائر)</h3>
<div class="co-list">
  <div class="co <?= $dOn ? 'on' : '' ?>" id="c-dhl">
    <div class="co-head">
      <span class="grow"><b>DHL Express</b><small>للطلبات المدفوعة من الخارج عبر PayPal — لا تظهر للزبون الجزائري</small></span>
      <?php if ($dReady): ?><span class="pill st-delivered">مربوطة ✔</span><?php elseif ($dOn): ?><a href="../solivr/#c-dhl" class="pill">املأ معلوماتها ←</a><?php endif; ?>
      <form method="post" class="inline"><?= csrfField() ?><input type="hidden" name="company" value="dhl">
        <span class="sw-lbl"><?= $dOn ? 'ON' : 'OFF' ?></span> <button class="sw <?= $dOn ? 'on' : '' ?>" aria-label="تشغيل / إيقاف DHL Express"></button>
      </form>
    </div>
  </div>
</div>
<?php adminFooter(); ?>
