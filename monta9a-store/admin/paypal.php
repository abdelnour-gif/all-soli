<?php
/** PayPal — payments from customers outside Algeria (EUR / USD). */
require_once __DIR__ . '/_init.php';
require_once dirname(__DIR__) . '/includes/paypal.php';
requireAdmin();

if (adminPost()) {
    saveSetting('paypal_on', isset($_POST['paypal_on']) ? '1' : '0');
    saveSetting('paypal_mode', ($_POST['paypal_mode'] ?? '') === 'live' ? 'live' : 'sandbox');
    saveSetting('paypal_client_id', trim((string)($_POST['paypal_client_id'] ?? '')));
    $secret = trim((string)($_POST['paypal_secret'] ?? ''));
    if ($secret !== '') saveSetting('paypal_secret', $secret);            // empty = keep the saved one
    if (isset($_POST['clear_secret'])) saveSetting('paypal_secret', '');
    $cur = strtoupper((string)($_POST['paypal_currency'] ?? 'EUR'));
    saveSetting('paypal_currency', isset(PAYPAL_CURRENCIES[$cur]) ? $cur : 'EUR');
    $rate = (float)str_replace(',', '.', (string)($_POST['paypal_rate'] ?? '0'));
    saveSetting('paypal_rate', (string)($rate >= 1 ? $rate : 250));
    $ship = (float)str_replace(',', '.', (string)($_POST['paypal_ship'] ?? '0'));
    saveSetting('paypal_ship', (string)max(0, round($ship, 2)));
    if (isset($_POST['test'])) {
        [$ok, $msg] = ppTest();
        flash($msg, $ok ? 'success' : 'error');
    } else {
        flash('تم حفظ إعدادات PayPal');
    }
    redirect('paypal.php');
}

$c = ppCfg();
$hasSecret = $c['secret'] !== '';
$paid = getDB()->query("SELECT COUNT(*), COALESCE(SUM(total), 0) FROM orders WHERE payment_method = 'paypal' AND payment_status = 'paid'")->fetch(PDO::FETCH_NUM);
adminHeader('PayPal — الدفع من الخارج', 'paypal');
?>
<div class="grid-2">
  <form method="post" class="panel form">
    <?= csrfField() ?>
    <div class="panel-head"><h2>إعدادات PayPal</h2></div>
    <label class="check"><input type="checkbox" name="paypal_on" <?= $c['on'] ? 'checked' : '' ?>> تفعيل الدفع عبر PayPal للزبائن من خارج الجزائر</label>
    <p class="hint">الزبائن في الجزائر يطلبون كالعادة (الدفع عند الاستلام). عند التفعيل يظهر في صفحة كل منتج وفي السلة رابط «تطلب من خارج الجزائر؟» يفتح صفحة الدفع <b>intl.php</b>.</p>
    <label>الوضع
      <select name="paypal_mode">
        <option value="sandbox" <?= $c['mode'] === 'sandbox' ? 'selected' : '' ?>>Sandbox — تجريبي (بدون أموال حقيقية)</option>
        <option value="live" <?= $c['mode'] === 'live' ? 'selected' : '' ?>>Live — حقيقي</option>
      </select>
    </label>
    <label>Client ID<input type="text" name="paypal_client_id" value="<?= e($c['client']) ?>" dir="ltr" autocomplete="off"></label>
    <label>Secret<input type="password" name="paypal_secret" value="" dir="ltr" autocomplete="new-password" placeholder="<?= $hasSecret ? '•••••••• (محفوظ — اتركه فارغاً للإبقاء عليه)' : '' ?>"></label>
    <?php if ($hasSecret): ?><label class="check"><input type="checkbox" name="clear_secret"> حذف الـ Secret المحفوظ</label><?php endif; ?>
    <div class="row">
      <label>العملة
        <select name="paypal_currency">
          <?php foreach (PAYPAL_CURRENCIES as $code => $sym): ?><option value="<?= $code ?>" <?= $c['currency'] === $code ? 'selected' : '' ?>><?= $code ?> (<?= $sym ?>)</option><?php endforeach; ?>
        </select>
      </label>
      <label>سعر الصرف: 1 <?= $c['currency'] ?> = ... د.ج<input type="number" name="paypal_rate" value="<?= e((string)$c['rate']) ?>" min="1" step="0.01" dir="ltr"></label>
    </div>
    <label>سعر الشحن الدولي (بـ <?= $c['currency'] ?> — 0 = مجاني)<input type="number" name="paypal_ship" value="<?= e(ppFmt($c['ship'])) ?>" min="0" step="0.01" dir="ltr"></label>
    <p class="hint">مثال بالإعدادات الحالية: منتج بـ 2,900 د.ج يظهر للزبون الأجنبي بـ <b dir="ltr"><?= ppMoney(ppCents(2900)) ?></b><?= $c['ship'] ? ' + شحن ' . ppMoney($c['ship']) : '' ?>.</p>
    <div class="row">
      <button class="btn btn-gold" name="save" value="1">حفظ</button>
      <button class="btn btn-light" name="test" value="1">حفظ واختبار الاتصال</button>
    </div>
  </form>

  <div>
    <div class="panel">
      <div class="panel-head"><h2>كيف أحصل على Client ID و Secret؟</h2></div>
      <ol class="steps-list">
        <li>ادخل إلى <b dir="ltr">developer.paypal.com</b> بحساب PayPal Business نفسه.</li>
        <li><b>Apps &amp; Credentials</b> ← اختر <b>Sandbox</b> للتجربة أو <b>Live</b> للحقيقي ← <b>Create App</b>.</li>
        <li>انسخ <b>Client ID</b> و <b>Secret</b> وضعهما هنا، واختر نفس الوضع (Sandbox أو Live).</li>
        <li>للتجربة في وضع Sandbox: في نفس الموقع ← <b>Sandbox accounts</b> تجد حساب «Personal» تجريبي تدفع به.</li>
        <li>عندما تنجح التجربة: ضع مفاتيح <b>Live</b> واختر الوضع <b>Live</b>.</li>
      </ol>
      <p class="hint">المبلغ يُحسب دائماً في الخادم، والطلب لا يُسجَّل في لوحة التحكم إلا بعد أن يؤكد PayPal الدفع.</p>
    </div>
    <div class="panel">
      <div class="panel-head"><h2>طلبات مدفوعة عبر PayPal</h2></div>
      <p><b><?= (int)$paid[0] ?></b> طلب — <?= money((int)$paid[1]) ?> (بالدينار)</p>
      <a href="orders.php?pay=paypal" class="btn btn-light">عرض الطلبات</a>
    </div>
  </div>
</div>
<?php adminFooter(); ?>
