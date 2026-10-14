<?php
/**
 * PayPal for orders from abroad (used by intl.php).
 *   POST action=create  : checks the form, computes the amount on the server, creates the PayPal order.
 *   POST action=capture : captures the approved payment, checks status + amount + currency,
 *                         and only then records the store order (status "confirmed", paid).
 */
require_once dirname(__DIR__) . '/includes/paypal.php';
require_once dirname(__DIR__) . '/includes/telegram.php';
require_once dirname(__DIR__) . '/includes/dhl.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function ppOut(array $a, int $code = 200): void {
    http_response_code($code);
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') ppOut(['error' => 'Bad request'], 405);
if (!checkCsrf()) ppOut(['error' => tx('انتهت صلاحية الصفحة، أعد تحميلها', 'La page a expiré, rechargez-la')], 403);
if (!ppEnabled()) ppOut(['error' => tx('الدفع عبر PayPal غير مفعّل', 'Le paiement PayPal est désactivé')], 400);

/** The lines being paid: the cart, or one product ("buy" = product id). */
function ppLines(): array {
    $buy = (int)($_POST['buy'] ?? 0);
    if ($buy) {
        $p = getProduct($buy);
        if (!$p || (int)$p['stock'] <= 0) throw new RuntimeException(tx('المنتج غير متوفر', 'Produit indisponible'));
        $colors = productColors($p); $sizes = productSizes($p);
        $color = (string)($_POST['color'] ?? ''); $size = (string)($_POST['size'] ?? '');
        if (count($colors) === 1) $color = $colors[0];
        if (count($sizes) === 1) $size = $sizes[0];
        if ($colors && !in_array($color, $colors, true)) throw new RuntimeException(tx('يرجى اختيار اللون', 'Veuillez choisir la couleur'));
        if ($sizes && !in_array($size, $sizes, true)) throw new RuntimeException(tx('يرجى اختيار المقاس', 'Veuillez choisir la taille'));
        if (!$colors) $color = '';
        if (!$sizes) $size = '';
        $qty = max(1, min(20, (int)($_POST['qty'] ?? 1), (int)$p['stock']));
        return [['product' => $p, 'qty' => $qty, 'color' => $color, 'size' => $size]];
    }
    $lines = cartLines();
    if (!$lines) throw new RuntimeException(tx('السلة فارغة', 'Le panier est vide'));
    foreach ($lines as $l) {
        if ((int)$l['product']['stock'] < (int)$l['qty']) throw new RuntimeException(tx('الكمية غير متوفرة: ', 'Quantité indisponible : ') . tr($l['product'], 'name'));
    }
    return $lines;
}

/** Customer details (shipping abroad). */
function ppForm(): array {
    $f = [];
    foreach (['name' => 80, 'email' => 120, 'phone' => 30, 'country' => 60, 'city' => 80, 'address' => 200, 'zip' => 20, 'notes' => 300] as $k => $max) {
        $f[$k] = mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
    }
    if (mb_strlen($f['name']) < 3) throw new RuntimeException(tx('يرجى كتابة الاسم الكامل', 'Veuillez saisir votre nom complet'));
    if (!filter_var($f['email'], FILTER_VALIDATE_EMAIL)) throw new RuntimeException(tx('البريد الإلكتروني غير صحيح', 'Adresse e-mail invalide'));
    if (!preg_match('/^\+?[\d\s().-]{6,25}$/', $f['phone'])) throw new RuntimeException(tx('رقم الهاتف غير صحيح (مع رمز الدولة)', 'Numéro de téléphone invalide (avec indicatif)'));
    $f['country_code'] = strtoupper(substr($f['country'], 0, 2));
    $countries = dhlCountries();
    if (!isset($countries[$f['country_code']])) throw new RuntimeException(tx('يرجى اختيار البلد', 'Veuillez choisir le pays'));
    $f['country'] = $countries[$f['country_code']];
    if ($f['zip'] === '' && !in_array($f['country_code'], DHL_NO_ZIP, true)) throw new RuntimeException(tx('يرجى كتابة الرمز البريدي', 'Veuillez saisir le code postal'));
    if (mb_strlen($f['city']) < 2) throw new RuntimeException(tx('يرجى كتابة المدينة', 'Veuillez saisir la ville'));
    if (mb_strlen($f['address']) < 5) throw new RuntimeException(tx('يرجى كتابة العنوان الكامل', 'Veuillez saisir l\'adresse complète'));
    return $f;
}

$action = (string)($_POST['action'] ?? '');

if ($action === 'create') {
    try {
        $lines = ppLines();
        $form = ppForm();
        $q = ppQuote($lines);
        if ($q['total'] < 100) throw new RuntimeException('Amount too small');
        $ref = 'S' . bin2hex(random_bytes(5));
        $ppId = ppCreateOrder($q, $ref);
        // Kept in the session until PayPal confirms the payment; at most 10 pending payments.
        $pending = $_SESSION['pp'] ?? [];
        $pending[$ppId] = [
            'lines' => array_map(fn($l) => ['id' => (int)$l['product']['id'], 'qty' => (int)$l['qty'], 'color' => (string)($l['color'] ?? ''), 'size' => (string)($l['size'] ?? '')], $lines),
            'form' => $form, 'quote' => $q, 'from_cart' => empty($_POST['buy']), 'ref' => $ref, 'time' => time(),
        ];
        $_SESSION['pp'] = array_slice($pending, -10, null, true);
        ppOut(['id' => $ppId]);
    } catch (Throwable $e) {
        ppOut(['error' => $e instanceof RuntimeException ? $e->getMessage() : tx('تعذر بدء الدفع، حاول مرة أخرى', 'Impossible de démarrer le paiement, réessayez')], 400);
    }
}

if ($action === 'capture') {
    $ppId = (string)($_POST['orderID'] ?? '');
    $p = $_SESSION['pp'][$ppId] ?? null;
    if (!$p) ppOut(['error' => tx('انتهت صلاحية عملية الدفع، أعد المحاولة', 'Session de paiement expirée, réessayez')], 400);
    try {
        $cap = ppCapture($ppId);
    } catch (Throwable $e) {
        if ($e->getMessage() === 'INSTRUMENT_DECLINED') ppOut(['restart' => true, 'error' => tx('رُفضت وسيلة الدفع، اختر وسيلة أخرى', 'Moyen de paiement refusé, choisissez-en un autre')], 402);
        ppOut(['error' => $e->getMessage()], 502);
    }
    $q = $p['quote'];
    if ($cap['status'] !== 'COMPLETED' || $cap['currency'] !== $q['currency'] || $cap['value'] !== (int)$q['total']) {
        error_log('PayPal capture mismatch ' . $ppId . ': ' . json_encode($cap));
        ppOut(['error' => tx('لم يكتمل الدفع. إذا خُصم المبلغ تواصل معنا مع رقم العملية: ', 'Paiement non finalisé. Si vous avez été débité, contactez-nous avec la référence : ') . ($cap['capture_id'] ?: $ppId)], 402);
    }

    // Paid: record the order (amounts kept in DZD like every order + the amount paid in EUR/USD).
    $db = getDB();
    $f = $p['form'];
    $rate = ppCfg()['rate'];
    try {
        $db->beginTransaction();
        $subtotal = 0; $items = [];
        foreach ($p['lines'] as $l) {
            $st = $db->prepare('SELECT id, name, price FROM products WHERE id = ?');
            $st->execute([$l['id']]);
            $prod = $st->fetch();
            if (!$prod) continue;
            $subtotal += (int)$prod['price'] * $l['qty'];
            $items[] = [$prod['id'], $prod['name'], variantText($l['color'], $l['size']), $l['qty'], (int)$prod['price']];
        }
        $shipDzd = (int)round($q['shipping'] / 100 * $rate);
        $notes = trim('PayPal: ' . ppMoney($cap['value'], $cap['currency']) . ($cap['payer'] !== '' ? ' — ' . $cap['payer'] : '') . ($f['notes'] !== '' ? "\n" . $f['notes'] : ''));
        $st = $db->prepare("INSERT INTO orders (customer_name, phone, wilaya_id, wilaya_name, commune, commune_fr, desk_commune, address, delivery_type, delivery_price, subtotal, gift_wrap, gift_price, gift_message, total, notes, ip, courier, status, email, country, payment_method, payment_status, payment_ref, pay_currency, pay_amount, country_code)
                            VALUES (?, ?, 0, ?, ?, ?, '', ?, 'intl', ?, ?, 0, 0, '', ?, ?, ?, '', 'confirmed', ?, ?, 'paypal', 'paid', ?, ?, ?, ?)");
        $st->execute([$f['name'], $f['phone'], '🌍 ' . $f['country'], $f['city'], $f['city'], trim($f['address'] . ($f['zip'] !== '' ? ' — ' . $f['zip'] : '')), $shipDzd, $subtotal, $subtotal + $shipDzd, $notes, $_SERVER['REMOTE_ADDR'] ?? '',
                      $f['email'], $f['country'], $cap['capture_id'], $cap['currency'], ppFmt($cap['value']), $f['country_code']]);
        $orderId = (int)$db->lastInsertId();
        $ins = $db->prepare('INSERT INTO order_items (order_id, product_id, product_name, color, qty, price) VALUES (?,?,?,?,?,?)');
        $dec = $db->prepare('UPDATE products SET stock = MAX(0, stock - ?) WHERE id = ?');
        foreach ($items as $it) { $ins->execute([$orderId, $it[0], $it[1], $it[2], $it[3], $it[4]]); $dec->execute([$it[3], $it[0]]); }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('PayPal paid but order not saved ' . $cap['capture_id'] . ': ' . $e->getMessage());
        ppOut(['error' => tx('تم الدفع لكن تعذر تسجيل الطلب. تواصل معنا مع رقم العملية: ', 'Paiement reçu mais commande non enregistrée. Contactez-nous avec la référence : ') . $cap['capture_id']], 500);
    }
    unset($_SESSION['pp'][$ppId]);
    if ($p['from_cart']) $_SESSION['cart'] = [];
    unset($_SESSION['last_order_counted']);
    $_SESSION['last_order'] = $orderId;
    if (telegramConfigured()) {
        register_shutdown_function(function () use ($orderId) {
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            telegramNotifyOrder($orderId);
        });
    }
    ppOut(['ok' => true, 'redirect' => 'order-success.php']);
}

ppOut(['error' => 'Unknown action'], 400);
