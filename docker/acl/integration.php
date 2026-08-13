<?php
declare(strict_types=1);

require_once __DIR__ . '/acl.php';

/** Files Gallery 0.15.3 adapter. Loaded only by the deterministic runtime patch. */
final class FilesGalleryAclIntegration
{
    private static string $username = '';
    /** @var array{allow:list<string>} */
    private static array $acl = ['allow' => []];
    private static bool $ready = false;

    public static function init(): void
    {
        if (self::$ready) return;
        self::$ready = true;
        if (!Config::get('load_files_proxy_php') || Path::has_urlpath(Config::$root)) {
            self::fail('ACL requires PHP-proxied media outside the web document root.');
        }

        if (Login::$is_logged_in && !Login::$is_default_user && isset($_SESSION['username']) && is_string($_SESSION['username'])) {
            try {
                self::$username = acl_validate_username($_SESSION['username']);
            } catch (Throwable) {
                // An untrusted session value is never an ACL identity.
                self::$username = '';
            }

            try {
                self::$acl = acl_load(self::$username, Config::$storagepath . '/users');
            } catch (Throwable) {
                // A missing or malformed ACL fails closed for normal users.  The
                // canonical admin identity still retains its policy bypass.
                self::$acl = ['allow' => []];
            }
        }

        $identity = self::$username === 'admin' ? 'admin' : self::$username;
        $payload = json_encode(self::$acl['allow'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        Config::$config['cache_key'] = 'acl-' . hash('sha256', (string) Config::get('cache_key') . "\n" . $identity . "\n" . $payload);
    }

    public static function excluded(string $logicalPath, bool $isDir): bool
    {
        if (!self::$ready) self::fail('ACL was not initialized.');
        $logical = self::relative($logicalPath);
        if ($logical === null) return true;
        if ($logical === '') return false; // application shell/root navigation; children remain filtered.
        if (!self::allowed($logical, $isDir)) return true;

        $canonical = Path::realpath($logicalPath);
        $canonicalRelative = $canonical === false ? null : self::relative($canonical);
        return $canonicalRelative === null || !self::allowed($canonicalRelative, $isDir);
    }

    public static function previewAllowed(string $directory): bool
    {
        $logical = self::relative($directory);
        if ($logical === null || $logical === '') return false;
        if (!acl_can_read(self::$username, $logical, self::$acl)) return false;
        $canonical = Path::realpath($directory);
        $canonicalRelative = $canonical === false ? null : self::relative($canonical);
        return $canonicalRelative !== null && acl_can_read(self::$username, $canonicalRelative, self::$acl);
    }

    public static function expectedMenuHash(): string
    {
        return U::get_menu_hash(Config::$config, Config::$root);
    }

    /** @return string|null */
    private static function relative(string $path): ?string
    {
        $root = rtrim(Config::$root, '/');
        if ($path !== $root && !str_starts_with($path, $root . '/')) return null;
        return acl_normalize_path(ltrim(substr($path, strlen($root)), '/'), true);
    }

    private static function allowed(string $relative, bool $isDir): bool
    {
        return $isDir
            ? acl_can_traverse(self::$username, $relative, self::$acl)
            : acl_can_read(self::$username, $relative, self::$acl);
    }

    private static function fail(string $message): never
    {
        error_log('Files Gallery ACL: ' . $message);
        if (class_exists('U')) U::error($message, 500);
        throw new RuntimeException($message);
    }
}
