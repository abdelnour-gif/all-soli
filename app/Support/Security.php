<?php
namespace QueuePro\Support;

defined('QP_APP') || exit;

/** Tokens, hashing and input normalisation. */
final class Security
{
    private static string $secret = '';

    public static function setSecret(string $secret): void
    {
        self::$secret = $secret;
    }

    /** 128-bit random token for the customer's ticket link. Only its hash is stored. */
    public static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Short human-readable reference, e.g. TKT-8F3A91 (not a secret). */
    public static function newPublicRef(): string
    {
        return 'TKT-' . strtoupper(bin2hex(random_bytes(3)));
    }

    /** Keyed hash so raw IPs are never stored (privacy) but can still be rate limited. */
    public static function hashIp(?string $ip): ?string
    {
        if (!$ip) {
            return null;
        }
        return hash_hmac('sha256', $ip, self::$secret ?: 'qp');
    }

    public static function hashKey(string $value): string
    {
        return hash_hmac('sha256', $value, self::$secret ?: 'qp');
    }

    /**
     * Normalise to E.164. Local numbers (leading 0) get the country prefix.
     * Algeria: 0[5-7]XXXXXXXX mobile → +213[5-7]XXXXXXXX.
     */
    public static function normalizePhone(string $raw, string $countryPhoneCode = '+213'): ?string
    {
        $digits = preg_replace('/[^\d+]/', '', $raw);
        if ($digits === null || $digits === '') {
            return null;
        }
        if (strpos($digits, '00') === 0) {
            $digits = '+' . substr($digits, 2);
        }
        if ($digits[0] !== '+') {
            $digits = $countryPhoneCode . ltrim($digits, '0');
        }
        if (!preg_match('/^\+[1-9]\d{7,14}$/', $digits)) {
            return null;
        }
        if ($countryPhoneCode === '+213' && strpos($digits, '+213') === 0
            && !preg_match('/^\+213[5-7]\d{8}$/', $digits)) {
            return null; // Algerian mobile only (OTP-capable)
        }
        return $digits;
    }

    public static function cleanName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }
        $name = trim(preg_replace('/\s+/u', ' ', strip_tags($name)));
        if ($name === '') {
            return null;
        }
        return mb_substr($name, 0, 80);
    }

    public static function maskPhone(?string $phone): ?string
    {
        if (!$phone) {
            return null;
        }
        return substr($phone, 0, 4) . str_repeat('•', max(0, strlen($phone) - 7)) . substr($phone, -3);
    }
}
