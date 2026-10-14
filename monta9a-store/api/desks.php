<?php
// Delivery companies offered at checkout for a wilaya (and commune): their prices and offices.
require_once dirname(__DIR__) . '/includes/courier.php';
header('Content-Type: application/json; charset=utf-8');
$w = (int)($_GET['w'] ?? 0);
$commune = trim((string)($_GET['c'] ?? ''));

$active = courierActiveList();
if (!$active || $w < 1 || $w > 58) { echo '{"on":false}'; exit; }
$st = getDB()->prepare('SELECT * FROM wilayas WHERE id = ? AND active = 1');
$st->execute([$w]);
$wilaya = $st->fetch();
if (!$wilaya) { echo '{"on":false}'; exit; }
@set_time_limit(90);

$out = [];
foreach ($active as $key) {
    try { $q = courierQuote($key, $wilaya, $commune); } catch (Throwable $e) { continue; }
    if (isset($_GET['debug']) && !empty($_SESSION['admin_id'])) $q['source'] = courierPrices($key, $wilaya, $commune)['source'];
    $q['info'] = (object)$q['info'];
    $out[] = $q;
}
if (!$out) { echo '{"on":false}'; exit; }
if (!isset($_GET['debug'])) header('Cache-Control: private, max-age=600');
echo json_encode(['on' => true, 'companies' => $out], JSON_UNESCAPED_UNICODE | (isset($_GET['debug']) ? JSON_PRETTY_PRINT : 0));
