<?php
namespace QueuePro\Core;

use QueuePro\Support\QueueException;

defined('QP_APP') || exit;

/**
 * Image uploads (provider logo / cover).
 *  - JPEG / PNG / WebP only, ≤ 3 MB, verified from the file content (not the name).
 *  - With GD: re-encoded and resized (max 1600 px) → strips metadata and any hidden payload.
 *  - Stored under uploads/YYYY/MM/ (QP_UPLOADS) with a random name; uploads/.htaccess forbids running scripts.
 */
final class Upload
{
    private const MAX_BYTES = 3 * 1024 * 1024;
    private const MAX_DIM = 1600;
    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    /** @return string public URL */
    public static function image(array $file, string $prefix): string
    {
        if (empty($file['tmp_name']) || !empty($file['error']) || !is_uploaded_file($file['tmp_name'])) {
            throw new QueueException('invalid_input', 422, ['field' => 'file']);
        }
        if ((int) $file['size'] > self::MAX_BYTES || filesize($file['tmp_name']) > self::MAX_BYTES) {
            throw new QueueException('payload_too_large', 413);
        }
        $info = @getimagesize($file['tmp_name']);
        $mime = $info['mime'] ?? '';
        if (!isset(self::TYPES[$mime]) || $info[0] < 16 || $info[1] < 16 || $info[0] * $info[1] > 40000000) {
            throw new QueueException('invalid_input', 422, ['field' => 'file', 'reason' => 'type']);
        }
        $ext = self::TYPES[$mime];
        $rel = gmdate('Y/m');
        $dir = QP_UPLOADS . '/' . $rel;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            throw new \RuntimeException('Cannot create upload directory');
        }
        $name = preg_replace('/[^a-z0-9-]/', '', strtolower($prefix)) . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
        $dest = $dir . '/' . $name;

        if (self::reencode($file['tmp_name'], $mime, $info[0], $info[1], $dest)) {
            @unlink($file['tmp_name']);
        } elseif (!move_uploaded_file($file['tmp_name'], $dest)) {
            throw new \RuntimeException('Cannot store upload');
        }
        @chmod($dest, 0644);
        return uploads_url($rel . '/' . $name);
    }

    private static function reencode(string $src, string $mime, int $w, int $h, string $dest): bool
    {
        if (!function_exists('imagecreatefromstring')) {
            return false;
        }
        $img = @imagecreatefromstring((string) file_get_contents($src));
        if (!$img) {
            return false;
        }
        $scale = min(1, self::MAX_DIM / max($w, $h));
        if ($scale < 1) {
            $nw = (int) round($w * $scale);
            $nh = (int) round($h * $scale);
            $dst = imagecreatetruecolor($nw, $nh);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($img);
            $img = $dst;
        } else {
            imagealphablending($img, false);
            imagesavealpha($img, true);
        }
        switch ($mime) {
            case 'image/png':
                $ok = imagepng($img, $dest, 6);
                break;
            case 'image/webp':
                $ok = function_exists('imagewebp') ? imagewebp($img, $dest, 85) : false;
                break;
            default:
                $ok = imagejpeg($img, $dest, 85);
        }
        imagedestroy($img);
        return (bool) $ok;
    }
}
