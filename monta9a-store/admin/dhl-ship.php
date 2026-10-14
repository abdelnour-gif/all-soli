<?php
/** DHL actions from the order page: prices (rates) and sending the shipment. */
require_once __DIR__ . '/_init.php';
require_once dirname(__DIR__) . '/includes/dhl.php';
requireAdmin();
$id = (int)($_GET['id'] ?? 0);
$back = 'order.php?id=' . $id . '#dhl';
if (!adminPost() || !$id) redirect($back);
if (!dhlReady()) { flash('أكمل إعدادات DHL أولاً (المفاتيح، رقم الحساب، عنوان المرسِل)', 'error'); redirect('solivr/#c-dhl'); }

$weight = max(0.1, min(70, (float)str_replace(',', '.', (string)($_POST['weight'] ?? dhlCfg()['weight']))));
$_SESSION['dhl_weight'][$id] = $weight;
$action = (string)($_POST['action'] ?? '');
try {
    if ($action === 'rates') {
        $_SESSION['dhl_rates'][$id] = dhlRates(dhlOrder($id), $weight);
        flash($_SESSION['dhl_rates'][$id] ? 'أسعار DHL لهذا الطرد ✔' : 'لم تُرجع DHL أي سعر لهذا المسار', $_SESSION['dhl_rates'][$id] ? 'success' : 'error');
    } elseif ($action === 'ship') {
        $r = dhlShip($id, $weight, (string)($_POST['product'] ?? ''));
        unset($_SESSION['dhl_rates'][$id]);
        $msg = 'تم إنشاء شحنة DHL ✔ — رقم التتبع: ' . $r['tracking'];
        // Tracking e-mail to the customer
        $o = dhlOrder($id);
        if (filter_var($o['email'], FILTER_VALIDATE_EMAIL)) {
            $store = setting('store_name', 'Store');
            $url = dhlTrackUrl($r['tracking']);
            $html = '<div style="font-family:Arial,sans-serif;max-width:520px;margin:auto;line-height:1.6">'
                  . '<h2 style="color:#d1678b">' . e($store) . '</h2>'
                  . '<p>Bonjour ' . e($o['customer_name']) . ',</p>'
                  . '<p>Votre commande <b>#' . $id . '</b> a été expédiée avec <b>DHL Express</b>.<br>Your order <b>#' . $id . '</b> has been shipped with <b>DHL Express</b>.</p>'
                  . '<p>N° de suivi / Tracking: <b>' . e($r['tracking']) . '</b></p>'
                  . '<p><a href="' . e($url) . '" style="background:#d40511;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none">Suivre mon colis / Track</a></p>'
                  . '<p style="color:#888;font-size:13px">' . e($store) . '</p></div>';
            $err = storeMail($o['email'], $store . ' — DHL ' . $r['tracking'], $html);
            $msg .= $err === '' ? ' — وصل بريد التتبع للزبون' : ' — لم يُرسل البريد للزبون (' . $err . ')';
        }
        flash($msg);
    }
} catch (Throwable $e) {
    flash($e->getMessage(), 'error');
}
redirect($back);
