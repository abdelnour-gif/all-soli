<?php
namespace QueuePro\Core;

defined('QP_APP') || exit;

/**
 * Web-server protection files (Apache / LiteSpeed .htaccess + index.php placeholders).
 *
 * They ship with the package, but uploading through some file managers or GitHub's web
 * uploader silently drops files whose name starts with a dot. ensure() recreates any that are
 * missing, so the database, sessions and logs are never downloadable and uploaded files are
 * never executed. Nginx ignores these files: use docs/nginx.conf there.
 */
final class Protection
{
    public const DENY = "# QueuePro: private folder — never served\n"
        . "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
        . "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n"
        . "Options -Indexes\n";

    public const UPLOADS = "# QueuePro: uploaded images — static files only, scripts are never executed\n"
        . "Options -Indexes -ExecCGI\n"
        . "<FilesMatch \"\\.(php[0-9]?|phtml|phar|pht|pl|py|cgi|sh|shtml|htaccess)$\">\n"
        . "  <IfModule mod_authz_core.c>\n    Require all denied\n  </IfModule>\n"
        . "  <IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n  </IfModule>\n"
        . "</FilesMatch>\n"
        . "<IfModule mod_php.c>\n  php_flag engine off\n</IfModule>\n"
        . "<IfModule mod_php7.c>\n  php_flag engine off\n</IfModule>\n"
        . "<IfModule mod_headers.c>\n  Header set X-Content-Type-Options nosniff\n"
        . "  Header set Content-Security-Policy \"default-src 'none'; img-src 'self'; sandbox\"\n</IfModule>\n";

    public const ROOT = "# QueuePro (standalone edition) — Apache / LiteSpeed\n"
        . "Options -Indexes -MultiViews\n"
        . "DirectoryIndex index.php\n"
        . "<IfModule mod_rewrite.c>\n"
        . "  RewriteEngine On\n"
        . "  # Let's Encrypt / AutoSSL checks must keep working\n"
        . "  RewriteRule ^\\.well-known/ - [L]\n"
        . "  # Hidden files and internal folders: never served\n"
        . "  RewriteRule (^|/)\\. - [F,L]\n"
        . "  RewriteRule ^(storage|app|bin|tests|templates|docs)(/|$) - [F,L]\n"
        . "  RewriteRule ^index\\.php$ - [L]\n"
        . "  # Real images, CSS and JS are served directly\n"
        . "  RewriteCond %{REQUEST_FILENAME} -f\n"
        . "  RewriteRule ^(assets|uploads)/ - [L]\n"
        . "  # Everything else goes to the application\n"
        . "  RewriteRule ^ index.php [L]\n"
        . "</IfModule>\n"
        . "<FilesMatch \"\\.(sqlite|sqlite3|db|log|md|lock|bak|sql|json|dist|sh)$\">\n"
        . "  <IfModule mod_authz_core.c>\n    Require all denied\n  </IfModule>\n"
        . "  <IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n  </IfModule>\n"
        . "</FilesMatch>\n"
        . "<IfModule mod_headers.c>\n  Header always set X-Content-Type-Options nosniff\n</IfModule>\n";

    private const PLACEHOLDER = "<?php http_response_code(404);\n";

    /**
     * Writes the missing protection files. $standalone also covers the code folders and the
     * root rewrite rules (the WordPress edition only protects its data and upload folders).
     * @return string[] files that could not be written
     */
    public static function ensure(bool $standalone): array
    {
        $files = [
            QP_STORAGE . '/.htaccess' => self::DENY,
            QP_STORAGE . '/index.php' => self::PLACEHOLDER,
            QP_UPLOADS . '/.htaccess' => self::UPLOADS,
            QP_UPLOADS . '/index.php' => self::PLACEHOLDER,
        ];
        if ($standalone) {
            $files[QP_ROOT . '/.htaccess'] = self::ROOT;
            foreach (['app', 'bin', 'tests', 'templates', 'docs'] as $d) {
                if (is_dir(QP_ROOT . '/' . $d)) {
                    $files[QP_ROOT . '/' . $d . '/.htaccess'] = self::DENY;
                }
            }
        }
        $failed = [];
        foreach ($files as $path => $content) {
            if (is_file($path) || !is_dir(dirname($path))) {
                continue;
            }
            if (@file_put_contents($path, $content) === false) {
                $failed[] = $path;
            }
        }
        return $failed;
    }
}
