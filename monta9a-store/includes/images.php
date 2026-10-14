<?php
/**
 * Image compression for every upload (products, slider, banners, categories, logo).
 *
 *  - resizes to a sensible maximum per use (see imageProfiles)
 *  - fixes phone-photo rotation (EXIF)
 *  - re-encodes to WebP (or JPEG/PNG when the server has no WebP support)
 *  - keeps transparency (logos / PNG)
 *  - creates a small "-sm" copy for product cards & listings
 *
 * Needs the PHP GD extension (installed on practically every host). Without GD the
 * original file is kept, so uploads never break.
 */

/** Per-folder settings: max width/height, quality, small copy size (0 = none). */
function imageProfiles(): array {
    return [
        // Sizes are 2× the largest size shown on screen (sharp on phones / retina screens),
        // quality 85+ is visually identical to the original; only the invisible extra is removed.
        'products'   => ['max' => 1600, 'q' => 86, 'thumb' => 640],
        'slides'     => ['max' => 2400, 'q' => 85, 'thumb' => 0],
        'categories' => ['max' => 900,  'q' => 86, 'thumb' => 0],
    ];
}

function imageSupportsWebp(): bool {
    return function_exists('imagewebp') && (gd_info()['WebP Support'] ?? false);
}

/** Load an image file into a GD resource (null if unsupported / not an image). */
function imageLoad(string $file, ?int &$type = null) {
    $info = @getimagesize($file);
    if (!$info) return null;
    $type = $info[2];
    if ($info[0] * $info[1] > 60000000) throw new RuntimeException('الصورة كبيرة جداً (أكثر من 60 ميغابكسل) — صغّرها ثم أعد رفعها');
    @ini_set('memory_limit', '512M');
    $img = null;
    switch ($type) {
        case IMAGETYPE_JPEG: $img = @imagecreatefromjpeg($file); break;
        case IMAGETYPE_PNG:  $img = @imagecreatefrompng($file); break;
        case IMAGETYPE_WEBP: $img = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file) : null; break;
        case IMAGETYPE_GIF:  $img = @imagecreatefromgif($file); break;
    }
    if (!$img) return null;
    if (!imageistruecolor($img)) imagepalettetotruecolor($img);
    imagealphablending($img, true);
    imagesavealpha($img, true);

    // Phone photos: apply EXIF orientation
    if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data($file);
        $angle = [3 => 180, 6 => -90, 8 => 90][(int)($exif['Orientation'] ?? 1)] ?? 0;
        if ($angle) {
            $rot = imagerotate($img, $angle, 0);
            if ($rot) { imagedestroy($img); $img = $rot; }
        }
    }
    return $img;
}

/** True if any sampled pixel is (partly) transparent. */
function imageHasAlpha($img): bool {
    $w = imagesx($img); $h = imagesy($img);
    $stepX = max(1, (int)($w / 60)); $stepY = max(1, (int)($h / 60));
    for ($y = 0; $y < $h; $y += $stepY) {
        for ($x = 0; $x < $w; $x += $stepX) {
            if (((imagecolorat($img, $x, $y) >> 24) & 0x7F) > 0) return true;
        }
    }
    return false;
}

/** Downscale so the longest side is at most $max (never upscales). */
function imageFit($img, int $max) {
    $w = imagesx($img); $h = imagesy($img);
    if ($max <= 0 || max($w, $h) <= $max) return $img;
    $ratio = $max / max($w, $h);
    $nw = max(1, (int)round($w * $ratio)); $nh = max(1, (int)round($h * $ratio));
    $out = imagecreatetruecolor($nw, $nh);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagefill($out, 0, 0, imagecolorallocatealpha($out, 255, 255, 255, 127));
    imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
    // A strong reduction softens edges: give back the crispness with a very light sharpen.
    if ($ratio < 0.67 && function_exists('imageconvolution')) {
        imageconvolution($out, [[0, -1, 0], [-1, 20, -1], [0, -1, 0]], 16, 0);
    }
    return $out;
}

/** Write $img to $path in the given format. */
function imageWrite($img, string $path, string $format, int $quality, bool $alpha): bool {
    switch ($format) {
        case 'webp':
            imagesavealpha($img, true);
            return imagewebp($img, $path, $quality);
        case 'png':
            imagesavealpha($img, true);
            return imagepng($img, $path, 9);
        default: // jpg — flatten transparency on white
            if ($alpha) {
                $bg = imagecreatetruecolor(imagesx($img), imagesy($img));
                imagefill($bg, 0, 0, imagecolorallocate($bg, 255, 255, 255));
                imagecopy($bg, $img, 0, 0, 0, 0, imagesx($img), imagesy($img));
                $img = $bg;
            }
            imageinterlace($img, true); // progressive JPEG
            return imagejpeg($img, $path, $quality);
    }
}

/**
 * Compress an uploaded temp file into uploads/$folder. Returns the relative path
 * (e.g. "uploads/products/ab12cd34.webp").
 */
function compressImageUpload(string $tmp, string $folder): string {
    $profile = imageProfiles()[$folder] ?? ['max' => 1600, 'q' => 80, 'thumb' => 0];
    $dir = ROOT_PATH . '/uploads/' . $folder;
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $base = bin2hex(random_bytes(8));

    $img = extension_loaded('gd') ? imageLoad($tmp, $type) : null;
    if (!$img) {
        // No GD (or format GD can't read): keep the original file as-is
        $info = @getimagesize($tmp);
        $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'][$info[2] ?? 0] ?? null;
        if (!$ext) throw new RuntimeException('الملف ليس صورة صالحة (JPG, PNG, WEBP)');
        $dest = "$dir/$base.$ext";
        if (!(is_uploaded_file($tmp) ? move_uploaded_file($tmp, $dest) : copy($tmp, $dest))) throw new RuntimeException('فشل رفع الصورة');
        return "uploads/$folder/$base.$ext";
    }

    $alpha = imageHasAlpha($img);
    $format = imageSupportsWebp() ? 'webp' : ($alpha ? 'png' : 'jpg');
    $main = imageFit($img, $profile['max']);
    $dest = "$dir/$base.$format";
    if (!imageWrite($main, $dest, $format, $profile['q'], $alpha)) throw new RuntimeException('فشل حفظ الصورة');

    // The photo is already small and light (and was not rotated/resized): keep the original
    // bytes instead of a re-encoded copy, so it loses nothing at all.
    $origExt = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp', IMAGETYPE_PNG => 'png'][$type] ?? '';
    $origSize = (int)@filesize($tmp);
    $info = @getimagesize($tmp);
    if ($origExt !== '' && $main === $img && $info && $info[0] === imagesx($img) && $info[1] === imagesy($img)
        && $origSize > 0 && $origSize <= (int)filesize($dest)) {
        @unlink($dest);
        $format = $origExt;
        $dest = "$dir/$base.$format";
        if (!(is_uploaded_file($tmp) ? move_uploaded_file($tmp, $dest) : copy($tmp, $dest))) throw new RuntimeException('فشل رفع الصورة');
        if ($format === 'png') $alpha = true;
    }

    if (!empty($profile['thumb'])) {
        $small = imageFit($main, $profile['thumb']);
        if ($small !== $main) imageWrite($small, "$dir/$base-sm.$format", $format, max(80, $profile['q'] - 2), $alpha);
        if ($small !== $main) imagedestroy($small);
    }
    if ($main !== $img) imagedestroy($main);
    imagedestroy($img);
    return "uploads/$folder/$base.$format";
}

/** Small version of an image for cards/lists (falls back to the full image). */
function imageSmall(string $path): string {
    if (!str_starts_with($path, 'uploads/')) return $path;
    $small = preg_replace('/\.(\w+)$/', '-sm.$1', $path);
    return is_file(ROOT_PATH . '/' . $small) ? $small : $path;
}

/**
 * srcset for an uploaded image that has a small copy: the browser downloads the small copy
 * on phones / small cards and the full image on large or retina screens.
 */
function imageSrcset(string $path): string {
    if (!str_starts_with($path, 'uploads/')) return '';
    $small = imageSmall($path);
    if ($small === $path) return '';
    $big = (int)(imageProfiles()[basename(dirname($path))]['max'] ?? 1600);
    $sm = (int)(imageProfiles()[basename(dirname($path))]['thumb'] ?? 480);
    if (($i = @getimagesize(ROOT_PATH . '/' . $small)) && $i[0]) $sm = $i[0];
    if (($i = @getimagesize(ROOT_PATH . '/' . $path)) && $i[0]) $big = $i[0];
    return $small . ' ' . $sm . 'w, ' . $path . ' ' . $big . 'w';
}

/** Total size of the uploads folder in bytes. */
function uploadsSize(): array {
    $bytes = 0; $count = 0;
    $root = ROOT_PATH . '/uploads';
    if (is_dir($root)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile() && preg_match('/\.(jpe?g|png|webp|gif)$/i', $f->getFilename())) { $bytes += $f->getSize(); $count++; }
        }
    }
    return [$bytes, $count];
}

/**
 * Re-compress images that are already in uploads/ (e.g. uploaded before compression existed).
 * Files keep their name & format so nothing in the database changes. Works in batches.
 * Returns [processed, bytesBefore, bytesAfter, remaining].
 */
function recompressExisting(int $limit = 40): array {
    $done = 0; $before = 0; $after = 0; $remaining = 0;
    $root = ROOT_PATH . '/uploads';
    $marker = ROOT_PATH . '/database/.recompressed';
    $seen = is_file($marker) ? array_flip(file($marker, FILE_IGNORE_NEW_LINES)) : [];
    foreach (imageProfiles() + ['' => ['max' => 1600, 'q' => 80, 'thumb' => 0]] as $folder => $profile) {
        $dir = $folder === '' ? $root : "$root/$folder";
        if (!is_dir($dir)) continue;
        foreach (glob("$dir/*.{jpg,jpeg,png,webp,JPG,JPEG,PNG,WEBP}", GLOB_BRACE) ?: [] as $file) {
            if (str_contains(basename($file), '-sm.')) continue;
            $rel = substr($file, strlen(ROOT_PATH) + 1);
            if (isset($seen[$rel])) continue;
            if ($done >= $limit) { $remaining++; continue; }
            $size = filesize($file);
            $dims = @getimagesize($file);
            // Already within the size limit and light enough: leave it untouched (no quality loss).
            $light = $dims && max($dims[0], $dims[1]) <= $profile['max'] && $size <= $dims[0] * $dims[1] * 0.35;
            $img = $light ? null : imageLoad($file, $type);
            if ($light && !empty($profile['thumb']) && !is_file(preg_replace('/\.(\w+)$/', '-sm.$1', $file))) $img = imageLoad($file, $type);
            if ($img) {
                $alpha = imageHasAlpha($img);
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                $format = $ext === 'jpeg' ? 'jpg' : $ext;
                if ($format === 'webp' && !imageSupportsWebp()) $format = $alpha ? 'png' : 'jpg';
                $main = imageFit($img, $profile['max']);
                $tmp = $file . '.tmp';
                if (!$light && imageWrite($main, $tmp, $format, $profile['q'], $alpha) && filesize($tmp) < $size * 0.9) {
                    rename($tmp, $file);
                } else {
                    @unlink($tmp);
                }
                $small = preg_replace('/\.(\w+)$/', '-sm.$1', $file);
                if (!empty($profile['thumb']) && !is_file($small)) {
                    $s = imageFit($main, $profile['thumb']);
                    if ($s !== $main) imageWrite($s, $small, $format, max(80, $profile['q'] - 2), $alpha);
                }
                imagedestroy($img);
            }
            clearstatcache(true, $file);
            $before += $size; $after += filesize($file); $done++;
            file_put_contents($marker, $rel . "\n", FILE_APPEND);
        }
    }
    return [$done, $before, $after, $remaining];
}

function humanBytes(int $b): string {
    if ($b >= 1048576) return round($b / 1048576, 1) . ' MB';
    if ($b >= 1024) return round($b / 1024) . ' KB';
    return $b . ' B';
}
