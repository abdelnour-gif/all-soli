<?php
/**
 * Telegram notifications: every new order is sent to the store owner's Telegram bot.
 * Settings: telegram_token (from @BotFather), telegram_chat (one or more chat IDs, comma separated).
 */
require_once __DIR__ . '/functions.php';

function telegramConfigured(): bool {
    return setting('telegram_on', '1') === '1' && setting('telegram_token') !== '' && setting('telegram_chat') !== '';
}

/** Call a Bot API method. Returns the decoded response (['ok' => bool, ...]). */
function telegramApi(string $method, array $params, int $timeout = 8): array {
    $token = setting('telegram_token');
    if ($token === '') return ['ok' => false, 'description' => 'لم يتم إدخال رمز البوت'];
    $base = trim(setting('telegram_api_base')) ?: 'https://api.telegram.org';   // hidden: tests against a local copy of the API
    $url = rtrim($base, '/') . '/bot' . $token . '/' . $method;
    $body = json_encode($params, JSON_UNESCAPED_UNICODE);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => $timeout,
        ]);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => 'Content-Type: application/json',
            'content' => $body, 'timeout' => $timeout, 'ignore_errors' => true]]);
        $resp = @file_get_contents($url, false, $ctx);
        $err = 'network error';
    }
    if ($resp === false || $resp === '') return ['ok' => false, 'description' => 'تعذر الاتصال بتلغرام: ' . $err];
    return json_decode($resp, true) ?: ['ok' => false, 'description' => 'رد غير مفهوم من تلغرام'];
}

/** Send a text (HTML formatted) to every configured chat. Returns [sentCount, lastError]. */
function telegramSend(string $html): array {
    $sent = 0; $error = '';
    foreach (array_filter(array_map('trim', explode(',', setting('telegram_chat')))) as $chat) {
        $r = telegramApi('sendMessage', ['chat_id' => $chat, 'text' => $html, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true]);
        if (!empty($r['ok'])) $sent++;
        else $error = (string)($r['description'] ?? 'خطأ غير معروف');
    }
    return [$sent, $error];
}

function tg(string $s): string {
    return htmlspecialchars($s, ENT_NOQUOTES, 'UTF-8');
}

/** Base URL of the store, e.g. https://example.com (used for the admin link in the message). */
function storeBaseUrl(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') return '';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    // The running script may be in a sub-folder (api/paypal.php, admin/...): go back up to the store root.
    $script = realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) ?: '';
    $root = realpath(ROOT_PATH) ?: ROOT_PATH;
    if ($script !== '' && str_starts_with($script, $root)) {
        $depth = substr_count(trim(str_replace('\\', '/', substr(dirname($script), strlen($root))), '/'), '/') + (dirname($script) === $root ? 0 : 1);
        for ($i = 0; $i < $depth; $i++) $dir = rtrim(str_replace('\\', '/', dirname($dir === '' ? '/' : $dir)), '/');
    }
    return ($https ? 'https' : 'http') . '://' . $host . $dir;
}

/** Build and send the "new order" message. Never throws — a Telegram problem must not break checkout. */
function telegramNotifyOrder(int $orderId): void {
    try {
        if (!telegramConfigured()) return;
        $db = getDB();
        $st = $db->prepare('SELECT * FROM orders WHERE id = ?');
        $st->execute([$orderId]);
        $o = $st->fetch();
        if (!$o) return;
        $st = $db->prepare('SELECT product_name, color, qty, price FROM order_items WHERE order_id = ?');
        $st->execute([$orderId]);

        $lines = [];
        $lines[] = '🛒 <b>طلب جديد #' . $orderId . '</b> — ' . tg(setting('store_name', 'المتجر'));
        $lines[] = '';
        $lines[] = '👤 ' . tg($o['customer_name']);
        $lines[] = '📞 <code>' . tg($o['phone']) . '</code>';
        $lines[] = '📍 ' . tg($o['wilaya_name']) . ' — ' . tg($o['commune']);
        if (($o['payment_method'] ?? '') === 'paypal') $lines[] = '💳 <b>مدفوع عبر PayPal: ' . tg($o['pay_amount'] . ' ' . $o['pay_currency']) . '</b>' . (!empty($o['email']) ? ' — ' . tg($o['email']) : '');
        if ($o['delivery_type'] === 'intl') {
            $lines[] = '🌍 شحن دولي: ' . tg($o['address']);
        } elseif ($o['delivery_type'] === 'office') {
            $lines[] = '🏢 استلام من المكتب' . (!empty($o['desk_commune']) ? ' (' . tg($o['desk_commune']) . ')' : '');
        } else {
            $lines[] = '🏠 توصيل للمنزل' . (trim((string)$o['address']) !== '' ? ': ' . tg($o['address']) : '');
        }
        if (($o['courier'] ?? '') !== '' && $o['delivery_type'] !== 'intl') {
            require_once __DIR__ . '/courier.php';
            $lines[] = '🚚 الشركة: ' . tg(courierName((string)$o['courier']));
        }
        $lines[] = '';
        foreach ($st->fetchAll() as $it) {
            $color = trim(preg_replace('/\s*\(#[0-9a-fA-F]{6}\)/', '', (string)$it['color']));
            $lines[] = '• ' . tg($it['product_name']) . ($color !== '' ? ' (' . tg($color) . ')' : '') . ' × ' . (int)$it['qty'] . ' = ' . tg(money($it['price'] * $it['qty']));
        }
        $lines[] = '';
        $lines[] = '🚚 التوصيل: ' . ($o['delivery_price'] ? tg(money($o['delivery_price'])) : 'مجاني');
        if ($o['gift_wrap']) $lines[] = '🎁 تغليف هدية: ' . ($o['gift_price'] ? tg(money($o['gift_price'])) : 'مجاني');
        if (trim((string)$o['gift_message']) !== '') $lines[] = '💌 رسالة البطاقة: ' . tg($o['gift_message']);
        $lines[] = '💰 <b>الإجمالي: ' . tg(money($o['total'])) . '</b>';
        if (trim((string)$o['notes']) !== '') $lines[] = '📝 ' . tg($o['notes']);
        $base = storeBaseUrl();
        if ($base !== '') $lines[] = "\n" . '🔗 ' . tg($base . '/admin/order.php?id=' . $orderId);

        [$sent, $error] = telegramSend(implode("\n", $lines));
        saveSetting('telegram_last', date('Y-m-d H:i') . ' — ' . ($sent ? 'تم إرسال الطلب #' . $orderId . ' ✔' : 'فشل إرسال الطلب #' . $orderId . ': ' . $error));
    } catch (Throwable $e) {
        error_log('Telegram notify failed: ' . $e->getMessage());
    }
}

/** Read the chats that recently wrote to the bot (to find the owner's chat ID). */
function telegramFindChats(): array {
    $r = telegramApi('getUpdates', ['limit' => 50, 'timeout' => 0]);
    if (empty($r['ok'])) throw new RuntimeException((string)($r['description'] ?? 'تعذر الاتصال بتلغرام'));
    $chats = [];
    foreach ($r['result'] ?? [] as $u) {
        $c = $u['message']['chat'] ?? $u['my_chat_member']['chat'] ?? $u['channel_post']['chat'] ?? null;
        if (!$c) continue;
        $name = trim(($c['title'] ?? '') ?: (($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')));
        $chats[(string)$c['id']] = $name !== '' ? $name : (string)($c['username'] ?? $c['id']);
    }
    return $chats;
}
