<?php
/** Download the DHL label / commercial invoice of an order (kept outside the web root). */
require_once __DIR__ . '/_init.php';
requireAdmin();
$id = (int)($_GET['id'] ?? 0);
$file = ROOT_PATH . '/database/labels/dhl-' . $id . (($_GET['doc'] ?? '') === 'invoice' ? '-invoice' : '') . '.pdf';
if (!$id || !is_file($file)) { http_response_code(404); exit('Not found'); }
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . basename($file) . '"');
header('Content-Length: ' . filesize($file));
readfile($file);
