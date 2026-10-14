<?php
// Shows the shipping label (bordereau) of an order, from the company it was sent with,
// so the API credentials never reach the browser.
require_once __DIR__ . '/_init.php';
require_once dirname(__DIR__) . '/includes/courier.php';
requireAdmin();
$id = (int)($_GET['id'] ?? 0);
$st = getDB()->prepare('SELECT * FROM orders WHERE id = ?');
$st->execute([$id]);
$order = $st->fetch() ?: [];
$tracking = (string)($order['tracking'] ?? '');
if (!hasTracking($tracking)) { flash('هذا الطلب لم يُرسل إلى شركة التوصيل بعد', 'error'); redirect('orders.php'); }
$provider = orderCourier($order);
$safe = preg_replace('/[^A-Za-z0-9_-]/', '', $tracking);

if ($provider === 'ecom') {
    // E-COM returns the label link when the parcel is created.
    $label = (string)($order['courier_label'] ?? '');
    if ($label !== '' && preg_match('#^https?://#i', $label)) {
        // Try to stream it (works when the link needs our Token/Key); otherwise open the link itself.
        $pdf = '';
        if (function_exists('curl_init') && ecomConfigured()) {
            $ch = curl_init($label);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
                CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 25,
                CURLOPT_HTTPHEADER => ['Token: ' . ecom('token'), 'Key: ' . ecom('key')],
            ]);
            $pdf = (string)curl_exec($ch);
            $ok = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200;
            curl_close($ch);
            if (!$ok) $pdf = '';
        }
        if (str_starts_with($pdf, '%PDF')) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="ecom-' . $safe . '.pdf"');
            echo $pdf;
            exit;
        }
        header('Location: ' . $label);
        exit;
    }
    flash('E-COM Delivery لم ترسل رابط الملصق لهذا الطرد — اطبعه من لوحة E-COM برقم التتبع ' . $tracking, 'error');
    redirect('order.php?id=' . $id);
}

$res = courierLabel($order);
if (!empty($res['pdf'])) {
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $provider . '-' . $safe . '.pdf"');
    echo $res['pdf'];
    exit;
}
if (!empty($res['url'])) { header('Location: ' . $res['url']); exit; }
flash('تعذر جلب الملصق من ' . courierName($provider) . ' — يمكنك طباعته من لوحة الشركة برقم التتبع ' . $tracking, 'error');
redirect('order.php?id=' . $id);
