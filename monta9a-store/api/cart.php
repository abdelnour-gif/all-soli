<?php
require_once dirname(__DIR__) . '/includes/functions.php';
header('Content-Type: application/json; charset=utf-8');

function out(bool $ok, string $msg = '', array $extra = []): void {
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg, 'count' => cartCount(), 'total' => money(cartTotal()), 'wish_count' => count(wishlist())], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(false, tx('طلب غير صالح', 'Requête invalide'));
if (!checkCsrf()) out(false, tx('انتهت صلاحية الجلسة، يرجى تحديث الصفحة', 'Session expirée, rechargez la page'));

$action = $_POST['action'] ?? '';

switch ($action) {
    case 'add':
        if (offerOnly()) out(false, tx('اطلب من صفحة العرض', "Commandez depuis la page de l'offre"));
        [$ok, $msg] = cartAdd((int)($_POST['id'] ?? 0), (string)($_POST['color'] ?? ''), (int)($_POST['qty'] ?? 1), (string)($_POST['size'] ?? ''));
        out($ok, $msg);

    case 'update':
        $key = (string)($_POST['key'] ?? '');
        $qty = (int)($_POST['qty'] ?? 1);
        if (!isset($_SESSION['cart'][$key])) out(false, tx('العنصر غير موجود', 'Article introuvable'));
        $p = getProduct((int)$_SESSION['cart'][$key]['id']);
        if (!$p) { unset($_SESSION['cart'][$key]); out(false, tx('المنتج لم يعد متوفراً', 'Ce produit n\'est plus disponible')); }
        if ($qty < 1) { unset($_SESSION['cart'][$key]); out(true, tx('تم الحذف', 'Supprimé')); }
        if ($qty > (int)$p['stock']) out(false, tx('الكمية المتوفرة: ', 'Quantité disponible : ') . (int)$p['stock']);
        $_SESSION['cart'][$key]['qty'] = min(20, $qty);
        cartMergeBundle();
        out(true);

    case 'remove':
        unset($_SESSION['cart'][(string)($_POST['key'] ?? '')]);
        out(true, tx('تم حذف المنتج من السلة', 'Produit retiré du panier'));

    case 'wish':
        $id = (int)($_POST['id'] ?? 0);
        if (!getProduct($id)) out(false, tx('المنتج غير موجود', 'Produit introuvable'));
        $list = wishlist();
        if (in_array($id, $list, true)) {
            $_SESSION['wishlist'] = array_values(array_diff($list, [$id]));
            out(true, tx('تمت الإزالة من المفضلة', 'Retiré des favoris'), ['active' => false]);
        }
        $_SESSION['wishlist'][] = $id;
        out(true, tx('تمت الإضافة إلى المفضلة', 'Ajouté aux favoris'), ['active' => true]);
}
out(false, tx('إجراء غير معروف', 'Action inconnue'));
