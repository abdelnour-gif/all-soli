<?php
/**
 * Dashboard page "شركات التوصيل".
 * Lists the companies switched ON in the hidden page /admin/soli (that page only has the ON/OFF switches).
 * Here the owner fills each company's details (Token, API link, options), tests the connection,
 * chooses which ones customers see at checkout, where the prices come from, and the default company.
 */
define('ADMIN_BASE', '../');
require_once dirname(__DIR__) . '/_init.php';
require_once ROOT_PATH . '/includes/courier.php';
require_once ROOT_PATH . '/includes/dhl.php';
requireAdmin();

if (adminPost()) {
    $key = (string)($_POST['company'] ?? '');
    $do = (string)($_POST['do'] ?? '');
    $back = 'index.php' . ($key !== '' ? '#c-' . preg_replace('/[^a-z0-9_-]/i', '', $key) : '');
    if ($do === 'refresh') {
        courierClearCache();
        flash('تم تحديث قوائم البلديات والمكاتب والأسعار — تُجلب من جديد من الشركات');
        redirect('index.php');
    }
    if ($do === 'dhl_save' || $do === 'dhl_test') {
        // DHL Express (international) — switched ON/OFF in /admin/soli, details filled here.
        if (setting('dhl_on') !== '1') { flash('DHL غير متاحة', 'error'); redirect('index.php'); }
        saveSetting('dhl_mode', ($_POST['dhl_mode'] ?? '') === 'live' ? 'live' : 'test');
        saveSetting('dhl_incoterm', ($_POST['dhl_incoterm'] ?? '') === 'DDP' ? 'DDP' : 'DAP');
        foreach (['dhl_key', 'dhl_account', 'dhl_company', 'dhl_contact', 'dhl_phone', 'dhl_email', 'dhl_address', 'dhl_city', 'dhl_zip',
                  'dhl_weight', 'dhl_length', 'dhl_width', 'dhl_height', 'dhl_product', 'dhl_hs', 'dhl_desc'] as $k) {
            saveSetting($k, mb_substr(trim((string)($_POST[$k] ?? '')), 0, 120));
        }
        $secret = trim((string)($_POST['dhl_secret'] ?? ''));
        if ($secret !== '') saveSetting('dhl_secret', $secret);
        if ($do === 'dhl_test') { [$ok, $msg] = dhlTest(); flash('DHL Express: ' . $msg, $ok ? 'success' : 'error'); }
        else flash('تم حفظ معلومات DHL Express' . (dhlReady() ? ' ✔' : ' — أكمل الخانات المطلوبة (*)'), dhlReady() ? 'success' : 'error');
        redirect('index.php#c-dhl');
    }
    $cat = courierCatalog();
    // Only companies switched ON in /admin/soli can be managed here.
    if (!isset($cat[$key]) || !in_array($key, courierOnList(), true)) { flash('هذه الشركة غير مشغّلة', 'error'); redirect('index.php'); }
    $cfg = courierCfg($key);
    $name = $cat[$key][0];

    if ($do === 'save' || $do === 'save_test') {
        $platform = $cat[$key][1];
        $oldCreds = '';
        foreach (courierFields($platform) as $f => $def) {
            $oldCreds .= '|' . ($cfg[$f] ?? '');
            $v = trim((string)($_POST[$f] ?? ''));
            if ($f === 'tenant' && $v !== '' && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v)) {
                flash('Tenant ID غير صحيح — يجب أن يكون بالشكل xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx', 'error'); redirect($back);
            }
            if ($f === 'from_wilaya') { $cfg[$f] = (string)max(1, min(58, (int)$v ?: 16)); continue; }
            if ($v !== '' || !$def[1]) $cfg[$f] = $v;   // empty secret field = keep the saved one
        }
        if (isset($_POST['clear'])) foreach (courierFields($platform) as $f => $def) if ($def[1]) $cfg[$f] = '';
        $url = trim((string)($_POST['url'] ?? ''));
        if ($url !== '' && !preg_match('#^https://[a-z0-9.\-]+(/.*)?$#i', $url)) { flash('رابط API يجب أن يبدأ بـ https://', 'error'); redirect($back); }
        $cfg['url'] = $url;
        $cfg['note'] = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 150);
        foreach (['fragile', 'set_shipped', 'auto_validate'] as $k) $cfg[$k] = isset($_POST[$k]) ? '1' : '0';
        if ($platform === 'zr') $cfg['label_format'] = ($_POST['label_format'] ?? '') === 'a4' ? 'a4' : 'a6';
        if (!isset($cfg['active'])) $cfg['active'] = '1';
        if (!courierSaveCfg($key, $cfg)) { flash('تعذر حفظ الملف database/couriers-config.php — تأكد أن مجلد database قابل للكتابة', 'error'); redirect($back); }
        $newCreds = '';
        foreach (courierFields($platform) as $f => $def) $newCreds .= '|' . ($cfg[$f] ?? '');
        if ($newCreds !== $oldCreds) courierClearCache();
        if ($do === 'save_test' && courierReady($key)) {
            courierClearCache();
            try { [$ok, $msg] = courierTestToken($key); } catch (Throwable $ex) { [$ok, $msg] = [false, $ex->getMessage()]; }
            flash($name . ': ' . $msg, $ok ? 'success' : 'error');
        } else {
            flash('تم حفظ معلومات ' . $name . (courierReady($key) ? ' ✔' : ' — أكمل كل الخانات المطلوبة'), courierReady($key) ? 'success' : 'error');
        }
        redirect($back);
    }

    if (!courierReady($key)) { flash('أكمل معلومات ' . $name . ' أولاً', 'error'); redirect($back); }
    if ($do === 'toggle') {
        $cfg['active'] = ($cfg['active'] ?? '1') === '1' ? '0' : '1';
        courierSaveCfg($key, $cfg);
        flash($name . ($cfg['active'] === '1' ? ' تظهر الآن للزبائن عند الطلب ✔' : ' لم تعد تظهر للزبائن'));
    } elseif ($do === 'prices') {
        $cfg['prices'] = ($_POST['prices'] ?? '') === 'store' ? 'store' : 'api';
        courierSaveCfg($key, $cfg);
        flash('تم حفظ مصدر أسعار ' . $name);
    } elseif ($do === 'default') {
        courierSetActive($key);
        flash($name . ' هي الآن الشركة الافتراضية للإرسال');
    } elseif ($do === 'test') {
        try { [$ok, $msg] = courierTestToken($key); } catch (Throwable $ex) { [$ok, $msg] = [false, $ex->getMessage()]; }
        flash($name . ': ' . $msg, $ok ? 'success' : 'error');
    }
    redirect($back);
}

$cat = courierCatalog();
$onList = courierOnList();
$default = courierProvider();
$wilayas = getDB()->query('SELECT * FROM wilayas WHERE active = 1 ORDER BY id')->fetchAll();
$pw = (int)($_GET['w'] ?? 0);
adminHeader('شركات التوصيل', 'solivr');
?>
<style>
.co { border: 1px solid #e3e6ea; border-radius: 12px; background: #fff; margin-bottom: 12px; overflow: hidden; }
.co.ready { border-color: #9fd1b5; }
.co.hidden-c { opacity: .8; }
.co-head { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; padding: 14px 16px; }
.co.ready .co-head { background: #f3faf6; }
.co-head .grow { flex: 1; min-width: 180px; display: flex; flex-direction: column; }
.co-head b { font-size: 15.5px; }
.co-head small { color: #6b7079; }
.co-head form { margin: 0; }
.co-head select { width: auto; }
.co-body { padding: 4px 16px 16px; border-top: 1px solid #eef0f2; }
.co-body summary { cursor: pointer; font-weight: 700; padding: 12px 0 4px; }
.co-body .row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.co-body .checks { display: flex; flex-wrap: wrap; gap: 6px 18px; margin: 6px 0 12px; }
.co-body .btns { display: flex; gap: 8px; flex-wrap: wrap; }
.sw { position: relative; width: 52px; height: 28px; border-radius: 20px; background: #cfd4da; border: 0; cursor: pointer; transition: .2s; vertical-align: middle; }
.sw::after { content: ''; position: absolute; top: 3px; inset-inline-start: 3px; width: 22px; height: 22px; border-radius: 50%; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.25); transition: .2s; }
.sw.on { background: #1a8a55; }
.sw.on::after { inset-inline-start: 27px; }
.co-body h4 { margin: 16px 0 6px; font-size: 14.5px; color: #333; }
.pill-warn { background: #fff4e0; color: #9a5b00; }
@media (max-width: 700px) { .co-body .row { grid-template-columns: 1fr; } }
</style>
<div class="panel">
  <div class="panel-head"><h2>🚚 شركات التوصيل</h2>
    <?php if (courierReadyList()): ?><form method="post" class="inline"><?= csrfField() ?><input type="hidden" name="do" value="refresh"><button class="btn btn-light btn-sm">تحديث المكاتب والأسعار</button></form><?php endif; ?>
  </div>
  <p class="hint">لكل شركة: <b>املأ معلوماتها</b> (Token...) ثم «حفظ واختبار الاتصال». بعد ربطها: <b>تظهر للزبائن</b> = يراها الزبون عند الطلب مع أسعارها ومكاتبها في ولايته، و<b>الافتراضية</b> = تُستعمل لإرسال الطلبات التي لم يختر لها الزبون شركة.</p>
</div>

<?php foreach ($onList as $key):
    $co = $cat[$key]; $platform = $co[1];
    $c = courierCfg($key); $ready = courierReady($key); $shown = ($c['active'] ?? '1') === '1';
    $apiPrices = in_array($platform, ['ecotrack', 'yalidine'], true); ?>
<div class="co <?= $ready ? 'ready' : '' ?> <?= $ready && !$shown ? 'hidden-c' : '' ?>" id="c-<?= e($key) ?>">
  <div class="co-head">
    <span class="grow"><b><?= e($co[0]) ?></b>
      <small><?php if (!$ready): ?>⚠ تنقصها معلومات الربط<?php else: ?><?= $shown ? '✔ تظهر للزبائن' : '✘ مخفية عن الزبائن' ?><?= $key === $default ? ' · ⭐ الافتراضية للإرسال' : '' ?><?php endif; ?></small>
    </span>
    <?php if (!$ready): ?>
      <span class="pill pill-warn">غير مربوطة</span>
    <?php else: ?>
      <form method="post"><?= csrfField() ?><input type="hidden" name="do" value="toggle"><input type="hidden" name="company" value="<?= e($key) ?>">
        <span style="font-weight:700;font-size:13px"><?= $shown ? 'تظهر للزبائن' : 'مخفية' ?></span> <button class="sw <?= $shown ? 'on' : '' ?>" aria-label="إظهار / إخفاء للزبائن"></button>
      </form>
      <form method="post"><?= csrfField() ?><input type="hidden" name="do" value="prices"><input type="hidden" name="company" value="<?= e($key) ?>">
        <select name="prices" onchange="this.form.submit()" aria-label="مصدر الأسعار">
          <?php if ($apiPrices): ?><option value="api" <?= ($c['prices'] ?? 'api') === 'api' ? 'selected' : '' ?>>أسعار الشركة (من حسابك لديها)</option><?php endif; ?>
          <option value="store" <?= !$apiPrices || ($c['prices'] ?? 'api') === 'store' ? 'selected' : '' ?>>أسعار المتجر (صفحة أسعار التوصيل)</option>
        </select>
      </form>
      <form method="post"><?= csrfField() ?><input type="hidden" name="do" value="default"><input type="hidden" name="company" value="<?= e($key) ?>">
        <button class="btn btn-light btn-sm" <?= $key === $default ? 'disabled' : '' ?>>⭐ افتراضية</button>
      </form>
      <form method="post"><?= csrfField() ?><input type="hidden" name="do" value="test"><input type="hidden" name="company" value="<?= e($key) ?>">
        <button class="btn btn-light btn-sm">اختبار الاتصال</button>
      </form>
    <?php endif; ?>
  </div>
  <div class="co-body">
    <details <?= $ready ? '' : 'open' ?>>
      <summary><?= $ready ? 'معلومات الربط (تعديل)' : 'املأ معلومات الربط' ?></summary>
      <form method="post" class="form" autocomplete="off">
        <?= csrfField() ?><input type="hidden" name="company" value="<?= e($key) ?>">
        <div class="row">
        <?php foreach (courierFields($platform) as $f => $def):
            $saved = (string)($c[$f] ?? '');
            $masked = $saved !== '' ? 'محفوظ: ••••' . mb_substr($saved, -4) : 'الصق هنا'; ?>
          <label><?= e($def[0]) ?><?= $f === 'from_wilaya' ? '' : ' *' ?>
            <?php if ($def[1]): ?>
              <input type="password" name="<?= e($f) ?>" value="" placeholder="<?= e($masked) ?>" dir="ltr" autocomplete="new-password">
            <?php else: ?>
              <input type="text" name="<?= e($f) ?>" value="<?= e($saved !== '' ? $saved : ($f === 'from_wilaya' ? '16' : '')) ?>" dir="ltr">
            <?php endif; ?>
            <?php if ($def[2] !== ''): ?><small class="hint"><?= e($def[2]) ?></small><?php endif; ?>
          </label>
        <?php endforeach; ?>
        </div>
        <?php if (array_filter(courierFields($platform), fn($d) => $d[1])): ?><p class="hint">الخانات السرية لا تُعرض بعد الحفظ. اتركها فارغة للإبقاء على القيمة المحفوظة.</p><?php endif; ?>
        <div class="row">
          <label>رابط API (اختياري)<input type="text" name="url" value="<?= e($c['url'] ?? '') ?>" placeholder="<?= e($co[2]) ?>" dir="ltr"><small class="hint">اتركه فارغاً لاستعمال الرابط الافتراضي.</small></label>
          <label>ملاحظة للزبون (اختياري)<input type="text" name="note" value="<?= e($c['note'] ?? '') ?>" maxlength="150"></label>
        </div>
        <?php if ($platform === 'zr'): ?>
          <label>حجم البوليصة
            <select name="label_format"><option value="a6" <?= ($c['label_format'] ?? 'a6') === 'a6' ? 'selected' : '' ?>>A6</option><option value="a4" <?= ($c['label_format'] ?? '') === 'a4' ? 'selected' : '' ?>>A4</option></select>
          </label>
        <?php endif; ?>
        <div class="checks">
          <label class="check"><input type="checkbox" name="fragile" <?= ($c['fragile'] ?? '0') === '1' ? 'checked' : '' ?>> طرد قابل للكسر</label>
          <label class="check"><input type="checkbox" name="set_shipped" <?= ($c['set_shipped'] ?? '1') === '1' ? 'checked' : '' ?>> تغيير حالة الطلب إلى «تم الشحن» بعد الإرسال</label>
          <?php if (in_array($platform, ['ecotrack', 'ecom', 'noest'], true)): ?>
            <label class="check"><input type="checkbox" name="auto_validate" <?= ($c['auto_validate'] ?? '0') === '1' ? 'checked' : '' ?>> تأكيد الطرد تلقائياً لدى الشركة (جاهز للاستلام)</label>
          <?php endif; ?>
          <?php if ($ready): ?>
            <label class="check" style="color:#b00020"><input type="checkbox" name="clear"> مسح المفاتيح السرية</label>
          <?php endif; ?>
        </div>
        <div class="btns">
          <button class="btn btn-gold" name="do" value="save">حفظ</button>
          <button class="btn btn-light" name="do" value="save_test">حفظ واختبار الاتصال</button>
        </div>
      </form>
    </details>
  </div>
</div>
<?php endforeach; ?>

<?php if (setting('dhl_on') === '1'): $dReady = dhlReady(); $d = dhlCfg(); $v = fn(string $k) => e(setting($k)); $dSent = (int)getDB()->query("SELECT COUNT(*) FROM orders WHERE courier = 'dhl'")->fetchColumn(); ?>
<div class="co <?= $dReady ? 'ready' : '' ?>" id="c-dhl">
  <div class="co-head">
    <span class="grow"><b>🌍 DHL Express — الشحن الدولي</b>
      <small><?= $dReady ? '✔ مربوطة — للطلبات المدفوعة من الخارج عبر PayPal · الوضع: ' . ($d['mode'] === 'live' ? 'حقيقي' : 'تجريبي') . ' · شحنات أُرسلت: ' . $dSent : '⚠ تنقصها معلومات الربط — للطلبات المدفوعة من الخارج عبر PayPal' ?></small>
    </span>
    <?php if (!$dReady): ?><span class="pill pill-warn">غير مربوطة</span><?php endif; ?>
  </div>
  <div class="co-body">
    <details <?= $dReady ? '' : 'open' ?>>
      <summary><?= $dReady ? 'معلومات DHL (تعديل)' : 'املأ معلومات DHL' ?></summary>
      <form method="post" class="form" autocomplete="off">
        <?= csrfField() ?>
        <h4>حساب DHL Express</h4>
        <div class="row">
          <label>API Key *<input type="text" name="dhl_key" value="<?= $v('dhl_key') ?>" dir="ltr"></label>
          <label>API Secret *<input type="password" name="dhl_secret" value="" dir="ltr" autocomplete="new-password" placeholder="<?= $d['secret'] !== '' ? '•••••••• (محفوظ)' : 'الصق هنا' ?>"></label>
        </div>
        <div class="row">
          <label>رقم حساب DHL Express (Account Number) *<input type="text" name="dhl_account" value="<?= $v('dhl_account') ?>" dir="ltr" placeholder="9xxxxxxxx"></label>
          <label>الوضع
            <select name="dhl_mode">
              <option value="test" <?= $d['mode'] === 'test' ? 'selected' : '' ?>>Test — تجريبي (الشحنات لا تُنفَّذ)</option>
              <option value="live" <?= $d['mode'] === 'live' ? 'selected' : '' ?>>Production — حقيقي</option>
            </select>
          </label>
        </div>
        <p class="hint">المفاتيح من <b dir="ltr">developer.dhl.com</b> ← My Apps ← تطبيق مفعّل لـ <b>MyDHL API</b>. رقم الحساب من عقدك مع DHL Express الجزائر. السر لا يُعرض بعد الحفظ — اتركه فارغاً للإبقاء عليه.</p>
        <h4>المرسِل (أنت)</h4>
        <div class="row">
          <label>اسم المتجر / الشركة<input type="text" name="dhl_company" value="<?= $v('dhl_company') ?: e(setting('store_name')) ?>"></label>
          <label>اسم المسؤول<input type="text" name="dhl_contact" value="<?= $v('dhl_contact') ?>"></label>
        </div>
        <div class="row">
          <label>الهاتف *<input type="text" name="dhl_phone" value="<?= $v('dhl_phone') ?: e(setting('phone')) ?>" dir="ltr" placeholder="+213 5..."></label>
          <label>البريد<input type="email" name="dhl_email" value="<?= $v('dhl_email') ?>" dir="ltr"></label>
        </div>
        <label>العنوان (بالحروف اللاتينية) *<input type="text" name="dhl_address" value="<?= $v('dhl_address') ?>" dir="ltr" placeholder="12 Rue Didouche Mourad"></label>
        <div class="row">
          <label>المدينة (بالحروف اللاتينية) *<input type="text" name="dhl_city" value="<?= $v('dhl_city') ?>" dir="ltr" placeholder="Alger"></label>
          <label>الرمز البريدي<input type="text" name="dhl_zip" value="<?= $v('dhl_zip') ?>" dir="ltr" placeholder="16000"></label>
        </div>
        <h4>الطرد والجمارك</h4>
        <div class="row">
          <label>الوزن الافتراضي (كغ)<input type="number" name="dhl_weight" value="<?= e((string)$d['weight']) ?>" min="0.1" step="0.1" dir="ltr"></label>
          <label>الخدمة (Product code)<input type="text" name="dhl_product" value="<?= e($d['product']) ?>" dir="ltr"><small class="hint">P = Express Worldwide (طرود)</small></label>
        </div>
        <div class="row">
          <label>الطول (سم)<input type="number" name="dhl_length" value="<?= $d['length'] ?>" min="1" dir="ltr"></label>
          <label>العرض (سم)<input type="number" name="dhl_width" value="<?= $d['width'] ?>" min="1" dir="ltr"></label>
        </div>
        <div class="row">
          <label>الارتفاع (سم)<input type="number" name="dhl_height" value="<?= $d['height'] ?>" min="1" dir="ltr"></label>
          <label>رمز HS الجمركي<input type="text" name="dhl_hs" value="<?= e($d['hs']) ?>" dir="ltr"><small class="hint">مستحضرات التجميل عادةً 330499 — تأكد منه مع DHL</small></label>
        </div>
        <div class="row">
          <label>وصف البضاعة (بالإنجليزية)<input type="text" name="dhl_desc" value="<?= e($d['desc']) ?>" dir="ltr"></label>
          <label>الرسوم الجمركية
            <select name="dhl_incoterm">
              <option value="DAP" <?= $d['incoterm'] === 'DAP' ? 'selected' : '' ?>>DAP — يدفعها الزبون عند الاستلام (إن وُجدت)</option>
              <option value="DDP" <?= $d['incoterm'] === 'DDP' ? 'selected' : '' ?>>DDP — تدفعها أنت</option>
            </select>
          </label>
        </div>
        <div class="btns" style="margin-top:12px">
          <button class="btn btn-gold" name="do" value="dhl_save">حفظ</button>
          <button class="btn btn-light" name="do" value="dhl_test">حفظ واختبار الاتصال</button>
        </div>
        <p class="hint" style="margin-top:10px">بعد الربط: افتح الطلب الدولي ← لوحة <b>DHL Express</b> ← اكتب الوزن ← <b>إرسال عبر DHL</b> ← تُطبع البوليصة ويصل للزبون رابط التتبع.</p>
      </form>
    </details>
  </div>
</div>
<?php endif; ?>

<?php if (courierActiveList()): ?>
<div class="panel">
  <div class="panel-head"><h2>معاينة ما يراه الزبون</h2></div>
  <form method="get" class="form-inline">
    <select name="w" onchange="this.form.submit()"><option value="">اختر ولاية</option><?php foreach ($wilayas as $w): ?><option value="<?= (int)$w['id'] ?>" <?= $pw === (int)$w['id'] ? 'selected' : '' ?>><?= (int)$w['id'] ?> - <?= e($w['name_ar']) ?></option><?php endforeach; ?></select>
  </form>
  <?php if ($pw): $wrow = null; foreach ($wilayas as $w) if ((int)$w['id'] === $pw) $wrow = $w; ?>
  <?php if ($wrow): @set_time_limit(90); ?>
  <table class="table" style="margin-top:12px">
    <thead><tr><th>الشركة</th><th>توصيل للمنزل</th><th>استلام من المكتب</th><th>المكاتب في الولاية</th></tr></thead>
    <tbody>
    <?php foreach (courierActiveList() as $key): try { $q = courierQuote($key, $wrow); } catch (Throwable $ex) { $q = null; } ?>
      <tr>
        <td><b><?= e(courierName($key)) ?></b></td>
        <?php if ($q): ?>
        <td><?= money($q['home']) ?></td>
        <td><?= $q['office'] === null ? '—' : money($q['office']) ?></td>
        <td><?= $q['desks'] === null ? '<span class="muted">تعذّر جلبها الآن</span>' : ($q['desks'] ? count($q['desks']) . ' : ' . e(implode('، ', array_slice($q['desks'], 0, 6))) . (count($q['desks']) > 6 ? '...' : '') : 'لا يوجد') ?></td>
        <?php else: ?><td colspan="3" class="muted">تعذّر الاتصال بالشركة</td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; endif; ?>
</div>
<?php endif; ?>
<?php adminFooter(); ?>
