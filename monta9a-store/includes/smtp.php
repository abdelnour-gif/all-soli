<?php
/**
 * Minimal SMTP client (no library needed): SSL (port 465) or STARTTLS (port 587), AUTH LOGIN.
 *
 * Why: mail() sends from the hosting server with an address that is not a real mailbox, so Gmail
 * often files it as Spam. Sending through a real mailbox (your Gmail with an App Password, or a
 * mailbox created in the hosting cPanel) is signed (SPF + DKIM) by that provider and lands in the
 * inbox — with the phone notification.
 *
 * Settings: smtp_on, smtp_host, smtp_port, smtp_secure (ssl|tls), smtp_user, smtp_pass, smtp_from_name.
 */

function smtpConfigured(): bool {
    return setting('smtp_on') === '1' && setting('smtp_host') !== '' && setting('smtp_user') !== '' && setting('smtp_pass') !== '';
}

/** Presets shown in the settings page. */
function smtpPresets(): array {
    return [
        'gmail'   => ['Gmail', 'smtp.gmail.com', 465, 'ssl'],
        'outlook' => ['Outlook / Hotmail', 'smtp-mail.outlook.com', 587, 'tls'],
        'yahoo'   => ['Yahoo', 'smtp.mail.yahoo.com', 465, 'ssl'],
        'zoho'    => ['Zoho', 'smtp.zoho.com', 465, 'ssl'],
        'hosting' => ['بريد الاستضافة (cPanel)', 'mail.' . preg_replace('/^www\./', '', (string)($_SERVER['HTTP_HOST'] ?? 'example.com')), 465, 'ssl'],
    ];
}

/**
 * Send a ready MIME message ($headers without To/Subject lines are completed here).
 * Returns '' on success or an Arabic error message.
 */
function smtpSend(string $to, string $subjectEncoded, string $headers, string $body): string {
    $host = trim(setting('smtp_host'));
    $port = (int)setting('smtp_port', '465') ?: 465;
    $secure = setting('smtp_secure', $port === 465 ? 'ssl' : 'tls');
    $user = trim(setting('smtp_user'));
    $pass = (string)setting('smtp_pass');
    if (!preg_match('/^[A-Za-z0-9.\-]+$/', $host)) return 'عنوان خادم SMTP غير صحيح';

    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $host]]);
    $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) return 'تعذر الاتصال بخادم البريد ' . $host . ':' . $port . ' (' . trim($errstr ?: 'الاستضافة تمنع هذا المنفذ') . ')';
    stream_set_timeout($fp, 20);

    $read = function () use ($fp): array {
        $data = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        return [(int)substr($data, 0, 3), trim($data)];
    };
    $cmd = function (string $c, array $ok) use ($fp, $read): array {
        fwrite($fp, $c . "\r\n");
        [$code, $msg] = $read();
        if (!in_array($code, $ok, true)) throw new RuntimeException($msg !== '' ? $msg : 'لا رد من الخادم');
        return [$code, $msg];
    };

    try {
        [$code, $msg] = $read();
        if ($code !== 220) throw new RuntimeException($msg ?: 'لا رد من الخادم');
        $ehlo = preg_replace('/[^A-Za-z0-9.\-]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
        $cmd('EHLO ' . $ehlo, [250]);
        if ($secure === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new RuntimeException('فشل تشفير الاتصال (STARTTLS)');
            }
            $cmd('EHLO ' . $ehlo, [250]);
        }
        $cmd('AUTH LOGIN', [334]);
        $cmd(base64_encode($user), [334]);
        try {
            $cmd(base64_encode($pass), [235]);
        } catch (RuntimeException $e) {
            throw new RuntimeException('البريد أو كلمة المرور غير مقبولة. مع Gmail يجب استعمال «كلمة مرور التطبيقات» (App Password) وليس كلمة مرور حسابك. (' . $e->getMessage() . ')');
        }
        $cmd('MAIL FROM:<' . $user . '>', [250]);
        $cmd('RCPT TO:<' . $to . '>', [250, 251]);
        $cmd('DATA', [354]);
        $msgData = 'To: <' . $to . ">\r\nSubject: " . $subjectEncoded . "\r\n" . $headers . "\r\n\r\n" . $body;
        // Dot-stuffing: a line starting with "." must be doubled.
        $msgData = preg_replace('/^\./m', '..', str_replace(["\r\n", "\n"], ["\n", "\r\n"], $msgData));
        $cmd($msgData . "\r\n.", [250]);
        try { $cmd('QUIT', [221]); } catch (Throwable $e) {}
        fclose($fp);
        return '';
    } catch (Throwable $e) {
        @fclose($fp);
        return 'خادم البريد رفض الإرسال: ' . $e->getMessage();
    }
}
