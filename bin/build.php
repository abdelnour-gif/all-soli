<?php
/**
 * Builds the two ready-to-upload packages into dist/:
 *
 *   dist/queuepro-wordpress-plugin.zip   WordPress: Plugins → Add New → Upload Plugin
 *   dist/queuepro-standalone.zip         any PHP hosting without WordPress (unzip into public_html)
 *
 *   php bin/build.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "The PHP zip extension is required.\n");
    exit(1);
}
$root = dirname(__DIR__);
$version = preg_match("/define\\('QP_VERSION', '([^']+)'\\)/", (string) file_get_contents($root . '/queuepro.php'), $m) ? $m[1] : 'dev';

/** Every project file, relative, with forward slashes. Runtime data and tooling are never packaged. */
function project_files(string $root): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
        if (preg_match('#^(\.git/|dist/|\.github/|node_modules/)#', $rel) || in_array($rel, ['.gitignore'], true)) {
            continue;
        }
        // runtime data: keep only the protection files of storage/ and uploads/
        if (preg_match('#^(storage|uploads)/#', $rel) && !preg_match('#^(storage|uploads)/(\.htaccess|index\.php)$#', $rel)) {
            continue;
        }
        if (preg_match('#(^|/)(\.DS_Store|Thumbs\.db)$|\.swp$#', $rel)) {
            continue;
        }
        $out[] = $rel;
    }
    sort($out);
    return $out;
}

function make_zip(string $root, string $zipPath, string $folder, array $files, array $extra = []): int
{
    @unlink($zipPath);
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
        throw new RuntimeException("Cannot create $zipPath");
    }
    foreach ($files as $rel) {
        $zip->addFile($root . '/' . $rel, $folder . '/' . $rel);
    }
    foreach ($extra as $rel => $content) {
        $zip->addFromString($folder . '/' . $rel, $content);
    }
    $n = $zip->numFiles;
    $zip->close();
    return $n;
}

@mkdir($root . '/dist', 0755, true);
$all = project_files($root);

// WordPress plugin: no standalone entry point, no tests, no storage/uploads folders
// (data lives in wp-content/queuepro-data and wp-content/uploads/queuepro).
$wp = array_values(array_filter($all, fn($f) => !preg_match('#^(index\.php|\.htaccess|tests/|storage/|uploads/|bin/build\.php)$|^tests/|^storage/|^uploads/#', $f)));
$wpHtaccess = "# QueuePro plugin folder: WordPress loads the PHP files itself; only assets/ is public.\n"
    . "<FilesMatch \"\\.(php|md|conf|json|lock)$\">\n  <IfModule mod_authz_core.c>\n    Require all denied\n  </IfModule>\n"
    . "  <IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n  </IfModule>\n</FilesMatch>\nOptions -Indexes\n";
$n1 = make_zip($root, $root . '/dist/queuepro-wordpress-plugin.zip', 'queuepro', $wp, ['.htaccess' => $wpHtaccess]);

// Standalone: everything except the WordPress bootstrap.
$sa = array_values(array_filter($all, fn($f) => $f !== 'queuepro.php' && $f !== 'bin/build.php'));
$n2 = make_zip($root, $root . '/dist/queuepro-standalone.zip', 'queuepro', $sa);

printf("QueuePro %s\n  dist/queuepro-wordpress-plugin.zip  %d files\n  dist/queuepro-standalone.zip        %d files\n", $version, $n1, $n2);
