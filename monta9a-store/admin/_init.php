<?php
require_once dirname(__DIR__) . '/includes/functions.php';

/** Relative path back to /admin/ — '' for pages in admin/, '../' for pages in a sub-folder (admin/soli/). */
function adminBase(): string {
    return defined('ADMIN_BASE') ? ADMIN_BASE : '';
}

function requireAdmin(): void {
    if (empty($_SESSION['admin_id'])) redirect(adminBase() . 'login.php');
}
/** Verify CSRF on every admin POST. */
function adminPost(): bool {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return false;
    if (!checkCsrf()) {
        flash('انتهت صلاحية الجلسة، أعد المحاولة', 'error');
        // Redirect to this script only. Echoing REQUEST_URI back into Location
    // allows "//evil.example" style protocol-relative targets.
    redirect(basename($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
    }
    return true;
}

function adminHeader(string $title, string $active): void {
    $db = getDB();
    $newOrders = (int)$db->query("SELECT COUNT(*) FROM orders WHERE status = 'new'")->fetchColumn();
    $unread = (int)$db->query('SELECT COUNT(*) FROM messages WHERE is_read = 0')->fetchColumn();
    $pendingReviews = (int)$db->query('SELECT COUNT(*) FROM reviews WHERE approved = 0')->fetchColumn();
    $menu = [
        'dashboard'   => ['index.php', 'لوحة التحكم', 'box'],
        'orders'      => ['orders.php', 'الطلبات', 'cart', $newOrders],
        'products'    => ['products.php', 'المنتجات', 'tag'],
        'categories'  => ['categories.php', 'الأقسام', 'menu'],
        'reviews'     => ['reviews.php', 'التقييمات', 'star', $pendingReviews],
        'appearance'  => ['appearance.php', 'الواجهة والمظهر', 'sparkle'],
        'slides'      => ['slides.php', 'السلايدر', 'return'],
        'pages'       => ['pages.php', 'الصفحات', 'card'],
        'delivery'    => ['delivery.php', 'أسعار التوصيل', 'tag'],
        'solivr'      => ['solivr/', 'شركات التوصيل', 'truck'],
        'paypal'      => ['paypal.php', 'PayPal (الخارج)', 'card'],
        'subscribers' => ['subscribers.php', 'النشرة البريدية', 'send'],
        'messages'    => ['messages.php', 'الرسائل', 'mail', $unread],
        'settings'    => ['settings.php', 'الإعدادات', 'user'],
    ];
    $f = flash();
    $b = adminBase();
    ?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e($title) ?> | لوحة التحكم</title>
<link rel="icon" href="<?= $b ?>../assets/img/favicon.png" type="image/png">
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= $b ?>../assets/css/admin.css?v=8">
</head>
<body>
<aside class="sidebar" id="sidebar">
  <div class="sb-brand"><?= e(setting('store_name')) ?><small>لوحة التحكم</small></div>
  <nav>
    <?php foreach ($menu as $key => $m): ?>
      <a href="<?= $b . $m[0] ?>" class="<?= $key === $active ? 'active' : '' ?>"><?= icon($m[2], 18) ?> <?= $m[1] ?><?php if (!empty($m[3])): ?><span class="sb-badge"><?= (int)$m[3] ?></span><?php endif; ?></a>
    <?php endforeach; ?>
    <a href="<?= $b ?>../index.php" target="_blank"><?= icon('search', 18) ?> عرض المتجر</a>
    <a href="<?= $b ?>logout.php"><?= icon('x', 18) ?> تسجيل الخروج</a>
  </nav>
</aside>
<div class="sb-overlay" id="sbOverlay"></div>
<div class="main">
  <header class="topbar">
    <button class="sb-toggle" id="sbToggle" aria-label="القائمة"><?= icon('menu', 22) ?></button>
    <h1><?= e($title) ?></h1>
    <span class="who"><?= e($_SESSION['admin_user'] ?? '') ?></span>
  </header>
  <div class="content">
  <?php if ($f): ?><div class="alert alert-<?= $f[1] === 'error' ? 'error' : 'success' ?>"><?= e($f[0]) ?></div><?php endif; ?>
<?php
}

function adminFooter(): void {
    ?>
  </div>
</div>
<script>
(function(){
  var sb=document.getElementById('sidebar'),ov=document.getElementById('sbOverlay');
  document.getElementById('sbToggle').onclick=function(){sb.classList.add('open');ov.classList.add('show');};
  ov.onclick=function(){sb.classList.remove('open');ov.classList.remove('show');};
  document.querySelectorAll('[data-confirm]').forEach(function(el){
    el.addEventListener('submit',function(e){ if(!confirm(el.dataset.confirm)) e.preventDefault(); });
  });
})();
</script>
<script src="<?= adminBase() ?>../assets/js/admin-upload.js?v=1"></script>
</body>
</html>
<?php
}
/* ---------- Account helpers ---------- */

/** Find an admin by username (case-insensitive). */
function findAdmin(string $login): ?array {
    $login = trim($login);
    if ($login === '') return null;
    $st = getDB()->prepare('SELECT * FROM admins WHERE lower(username) = lower(?) ORDER BY id LIMIT 1');
    $st->execute([$login]);
    return $st->fetch() ?: null;
}

/** Check a password; tolerates accidental spaces at the start/end (common on phone keyboards). */
function adminPasswordMatches(string $password, string $hash): bool {
    if (password_verify($password, $hash)) return true;
    $trimmed = trim($password);
    return $trimmed !== $password && password_verify($trimmed, $hash);
}

/** Normalise a new password before saving it (removes accidental leading/trailing spaces). */
function cleanNewPassword(string $password): string {
    return trim($password);
}

/**
 * Emergency reset: create the file database/reset-password.txt from the hosting
 * File Manager, containing the new password (or leave it empty to reset to the default).
 * On the next visit to the login page the password of the first admin is replaced and the file is deleted.
 */
function emergencyPasswordReset(): ?array {
    $file = ROOT_PATH . '/database/reset-password.txt';
    if (!is_file($file)) return null;
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', (string)file_get_contents($file)); // strip BOM from some editors
    // One line = new password. Two lines = new username on the first line, new password on the second.
    $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $raw)), 'strlen'));
    $newUser = count($lines) >= 2 && preg_match('/^[\p{L}\p{N}_.\-@]{3,40}$/u', $lines[0]) ? $lines[0] : '';
    $new = cleanNewPassword((string)($newUser !== '' ? $lines[1] : ($lines[0] ?? '')));
    if (strlen($new) < 8) $new = DEFAULT_ADMIN_PASSWORD;
    $db = getDB();
    $admin = $db->query('SELECT * FROM admins ORDER BY id LIMIT 1')->fetch();
    if (!$admin) return null;
    if ($newUser !== '') {
        $db->prepare('UPDATE admins SET username = ?, password = ? WHERE id = ?')
           ->execute([$newUser, password_hash($new, PASSWORD_DEFAULT), $admin['id']]);
        $admin['username'] = $newUser;
    } else {
        $db->prepare('UPDATE admins SET password = ? WHERE id = ?')
           ->execute([password_hash($new, PASSWORD_DEFAULT), $admin['id']]);
    }
    if (!@unlink($file)) @file_put_contents($file, ''); // never leave the password in the file
    unset($_SESSION['login_locked'], $_SESSION['login_attempts']);
    clearLoginAttempts();
    return $admin;
}


/* ---------- Password reset by e-mail ---------- */

/** Add the e-mail / reset columns to the admins table once (older databases don't have them). */
function ensureAdminEmailColumns(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $db = getDB();
    $have = array_column($db->query('PRAGMA table_info(admins)')->fetchAll(), 'name');
    foreach (['email' => "TEXT DEFAULT ''", 'reset_hash' => "TEXT DEFAULT ''", 'reset_expires' => 'INTEGER DEFAULT 0', 'reset_sent' => 'INTEGER DEFAULT 0'] as $col => $def) {
        if (!in_array($col, $have, true)) {
            try { $db->exec("ALTER TABLE admins ADD COLUMN $col $def"); } catch (Throwable $e) {}
        }
    }
}

/** Address of the /admin/ folder for this request, e.g. https://shop.dz/admin */
function currentAdminUrl(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if (!preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $host)) $host = 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/admin/x.php'))), '/');
    return ($https ? 'https' : 'http') . '://' . $host . $dir;
}

/** Saved while the owner is logged in; the reset link is built from it, not from the Host header. */
function rememberAdminUrl(): void {
    $url = currentAdminUrl();
    if (setting('admin_url') !== $url) saveSetting('admin_url', $url);
}

/** Find an admin by username or e-mail (case-insensitive). */
function findAdminByLoginOrEmail(string $login): ?array {
    ensureAdminEmailColumns();
    $login = trim($login);
    if ($login === '') return null;
    $st = getDB()->prepare("SELECT * FROM admins WHERE lower(username) = lower(?) OR (email <> '' AND lower(email) = lower(?)) ORDER BY id LIMIT 1");
    $st->execute([$login, $login]);
    return $st->fetch() ?: null;
}

/**
 * Send an HTML e-mail. Returns '' on success or the reason.
 *
 *  - When SMTP is set up (الإعدادات ← بريد الإرسال) the message goes through that real mailbox
 *    (e.g. your Gmail with an App Password): it is signed by the provider and lands in the inbox.
 *  - Otherwise through the hosting's mail() — this often ends in Spam because the hosting
 *    address is not a real, authenticated mailbox.
 */
function storeMail(string $to, string $subject, string $html): string {
    require_once dirname(__DIR__) . '/includes/smtp.php';
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return 'لم يتم تسجيل بريد صحيح في الإعدادات ← بيانات الحساب';
    $useSmtp = smtpConfigured();
    if (!$useSmtp && !function_exists('mail')) return 'دالة mail() معطّلة في هذه الاستضافة — فعّل «بريد الإرسال (SMTP)» في الإعدادات';
    $base = setting('admin_url') ?: currentAdminUrl();
    $domain = preg_replace('/^www\./i', '', (string)parse_url($base, PHP_URL_HOST)) ?: 'localhost';
    // Send from the main domain (e.g. la-maison-du-parfum.webdz.de -> webdz.de): it is the one that has
    // SPF and DKIM on this hosting, so Gmail accepts it. Sub-domains have no SPF/DKIM and are refused.
    $parts = explode('.', $domain);
    if (count($parts) > 2 && !filter_var($domain, FILTER_VALIDATE_IP)) $domain = implode('.', array_slice($parts, -2));
    $from = $useSmtp ? trim(setting('smtp_user')) : 'no-reply@' . preg_replace('/[^A-Za-z0-9.\-]/', '', $domain);
    $msgDomain = substr(strrchr($from, '@') ?: '@' . $domain, 1);
    $enc = fn($v) => '=?UTF-8?B?' . base64_encode($v) . '?=';
    // multipart/alternative (plain text + HTML) with Date and Message-ID: an HTML-only message without
    // these headers scores high in the spam filters of the hosting and of Gmail.
    $text = preg_replace_callback('#<a [^>]*href="([^"]+)"[^>]*>(.*?)</a>#is', fn($m) => strip_tags($m[2]) === $m[1] ? $m[1] : strip_tags($m[2]) . ': ' . $m[1], $html);
    $text = trim(html_entity_decode(strip_tags(preg_replace(['#<br\s*/?>#i', '#</(p|h\d|div)>#i'], ["\n", "\n\n"], $text)), ENT_QUOTES, 'UTF-8'));
    $text = preg_replace("/\n{3,}/", "\n\n", preg_replace('/[ \t]+/', ' ', $text));
    $boundary = 'b' . bin2hex(random_bytes(12));
    $fromName = setting('smtp_from_name') ?: setting('store_name', 'Store');
    $headers = implode("\r\n", [
        'From: ' . $enc($fromName) . ' <' . $from . '>',
        'Reply-To: ' . $from,
        'Date: ' . date('r'),
        'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $msgDomain . '>',
        'MIME-Version: 1.0',
        'Auto-Submitted: auto-generated',
        'X-Auto-Response-Suppress: All',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    ]);
    $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text))
          . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
          . chunk_split(base64_encode('<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><title>' . e($subject) . '</title></head><body>' . $html . '</body></html>'))
          . "--$boundary--\r\n";
    if ($useSmtp) {
        $err = smtpSend($to, $enc($subject), $headers, $body);
        saveSetting('last_mail_status', date('Y-m-d H:i') . ' — SMTP — ' . ($err === '' ? 'تم الإرسال إلى ' . $to . ' ✔' : 'فشل: ' . $err));
        return $err;
    }
    $ok = @mail($to, $enc($subject), $body, $headers, '-f' . $from);
    if (!$ok) $ok = @mail($to, $enc($subject), $body, $headers); // some hosts refuse the -f option
    saveSetting('last_mail_status', date('Y-m-d H:i') . ' — mail() — ' . ($ok ? 'تم الإرسال إلى ' . $to . ' ✔' : 'فشل الإرسال'));
    return $ok ? '' : 'الاستضافة رفضت الإرسال (دالة mail())';
}

/**
 * Send a one-hour, single-use reset link by e-mail and (when set up) by Telegram — Telegram gives
 * an instant phone notification and never goes to Spam.
 * Returns ['ok' => bool, 'wait' => bool, 'mail' => ''|error|null, 'tg' => bool].
 */
function sendPasswordReset(array $admin): array {
    require_once dirname(__DIR__) . '/includes/telegram.php';
    $res = ['ok' => false, 'wait' => false, 'mail' => null, 'tg' => false];
    if (time() - (int)$admin['reset_sent'] < 120) { $res['wait'] = true; return $res; }
    $hasMail = filter_var($admin['email'] ?? '', FILTER_VALIDATE_EMAIL) !== false;
    $hasTg = telegramConfigured();
    if (!$hasMail && !$hasTg) { $res['mail'] = 'none'; return $res; }

    $token = bin2hex(random_bytes(32));
    $db = getDB();
    $db->prepare('UPDATE admins SET reset_hash = ?, reset_expires = ?, reset_sent = ? WHERE id = ?')
       ->execute([hash('sha256', $token), time() + 3600, time(), $admin['id']]);
    $link = (setting('admin_url') ?: currentAdminUrl()) . '/reset.php?token=' . $token;
    $store = setting('store_name', 'Store');

    if ($hasMail) {
        $html = '<div dir="rtl" style="font-family:Tahoma,Arial,sans-serif;font-size:15px;line-height:1.8;color:#222;max-width:560px">'
              . '<p>مرحباً ' . e($admin['username']) . '،</p>'
              . '<p>طلبتَ تغيير كلمة مرور لوحة التحكم في متجر <b>' . e($store) . '</b>.</p>'
              . '<p style="margin:22px 0"><a href="' . e($link) . '" style="background:#1f7a5c;color:#fff;padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:bold">اختيار كلمة مرور جديدة</a></p>'
              . '<p style="font-size:13px;color:#555">الرابط صالح لمدة ساعة ولمرة واحدة. إذا لم يعمل الزر انسخ هذا الرابط:<br><span dir="ltr" style="word-break:break-all">' . e($link) . '</span></p>'
              . '<p style="font-size:13px;color:#555">إذا لم تطلب ذلك فتجاهل هذه الرسالة، ولن يتغير شيء.</p></div>';
        $res['mail'] = storeMail($admin['email'], 'إعادة تعيين كلمة المرور — ' . $store, $html);
        if ($res['mail'] !== '') error_log('Password reset mail failed: ' . $res['mail']);
    }
    if ($hasTg) {
        [$sent] = telegramSend('🔐 <b>' . tg($store) . '</b>' . "\n" . 'طلب تغيير كلمة مرور لوحة التحكم (' . tg($admin['username']) . ').' . "\n\n"
            . '<a href="' . htmlspecialchars($link, ENT_QUOTES) . '">اضغط هنا لاختيار كلمة مرور جديدة</a>' . "\n" . 'صالح لمدة ساعة. إذا لم تطلب ذلك فتجاهل الرسالة.');
        $res['tg'] = $sent > 0;
    }
    $res['ok'] = $res['mail'] === '' || $res['tg'];
    if (!$res['ok']) {
        $db->prepare("UPDATE admins SET reset_hash = '', reset_expires = 0, reset_sent = 0 WHERE id = ?")->execute([$admin['id']]);
    }
    return $res;
}

/** Hide most of an email address: fas•••••@gmail.com */
function maskEmail(string $email): string {
    [$u, $d] = array_pad(explode('@', $email, 2), 2, '');
    $keep = max(1, min(3, (int)floor(mb_strlen($u) / 2)));
    return mb_substr($u, 0, $keep) . str_repeat('•', max(3, mb_strlen($u) - $keep)) . '@' . $d;
}

/** The admin a reset token belongs to, if valid and not expired. */
function adminByResetToken(string $token): ?array {
    ensureAdminEmailColumns();
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $st = getDB()->prepare('SELECT * FROM admins WHERE reset_hash = ? AND reset_expires > ? LIMIT 1');
    $st->execute([hash('sha256', $token), time()]);
    return $st->fetch() ?: null;
}


/** <option> list of categories (sub-categories indented). */
function categoryOptions(int $selected = 0, int $exclude = 0): string {
    $out = '';
    $walk = function (int $parent, int $depth) use (&$walk, &$out, $selected, $exclude) {
        foreach (allCategories(false) as $c) {
            if ((int)$c['parent_id'] !== $parent || (int)$c['id'] === $exclude) continue;
            $out .= '<option value="' . (int)$c['id'] . '"' . ((int)$c['id'] === $selected ? ' selected' : '') . '>'
                . str_repeat('— ', $depth) . e($c['name']) . ((int)$c['active'] ? '' : ' (مخفي)') . '</option>';
            $walk((int)$c['id'], $depth + 1);
        }
    };
    $walk(0, 0);
    return $out;
}


/* ---------------------------------------------------------------------------
 * Login throttling.
 *
 * Keyed on the client IP and kept in the database. The previous version stored
 * the counter in the session, which an attacker bypasses completely by simply
 * not sending the cookie back.
 * ------------------------------------------------------------------------- */

/** The client IP, trimmed to a sane length. */
function loginIp(): string {
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

/** Current row for this IP, after clearing anything older than an hour. */
function loginRow(): array {
    $db = getDB();
    $db->prepare('DELETE FROM login_attempts WHERE updated_at < ?')->execute([time() - 3600]);
    $st = $db->prepare('SELECT * FROM login_attempts WHERE ip = ?');
    $st->execute([loginIp()]);
    $row = $st->fetch();
    return $row ?: ['ip' => loginIp(), 'fails' => 0, 'locked_until' => 0, 'updated_at' => 0];
}

/** Number of failed attempts recorded for this IP. */
function loginAttempts(): int {
    return (int)loginRow()['fails'];
}

/** Unix time until which this IP is locked out (0 = not locked). */
function loginLockedUntil(): int {
    return (int)loginRow()['locked_until'];
}

/** Record one failure and return the new count. */
function recordLoginFailure(): int {
    $row = loginRow();
    $fails = (int)$row['fails'] + 1;
    getDB()->prepare('INSERT INTO login_attempts (ip, fails, locked_until, updated_at) VALUES (?,?,?,?)
                      ON CONFLICT(ip) DO UPDATE SET fails = ?, updated_at = ?')
           ->execute([loginIp(), $fails, 0, time(), $fails, time()]);
    return $fails;
}

/** Lock this IP out for the given number of seconds and reset its counter. */
function lockLogin(int $seconds): void {
    getDB()->prepare('INSERT INTO login_attempts (ip, fails, locked_until, updated_at) VALUES (?,0,?,?)
                      ON CONFLICT(ip) DO UPDATE SET fails = 0, locked_until = ?, updated_at = ?')
           ->execute([loginIp(), time() + $seconds, time(), time() + $seconds, time()]);
}

/** Forget this IP after a successful login. */
function clearLoginAttempts(): void {
    getDB()->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([loginIp()]);
}
