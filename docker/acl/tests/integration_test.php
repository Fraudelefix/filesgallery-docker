<?php
declare(strict_types=1);
require __DIR__ . '/../acl.php';
class Config { public static array $config = ['cache_key' => 'operator-key', 'load_files_proxy_php' => true]; public static string $root = '/media'; public static string $storagepath = '/tmp/no-users'; public static function get(string $k): mixed { return self::$config[$k]; } }
class Login { public static bool $is_logged_in = false; public static bool $is_default_user = false; }
class Path { public static function has_urlpath(string $p): bool { return false; } public static function realpath(string $p): string|false { return $p; } }
class U { public static function get_menu_hash(array $c, string $r): string { return hash('sha256', $c['cache_key'] . $r); } public static function error(string $m, int $c): never { throw new RuntimeException($m); } }
require __DIR__ . '/../integration.php';
function ok2(bool $v, string $m): void { if (!$v) throw new RuntimeException("Failed: $m"); }
$_SESSION = ['username' => 'admin']; Login::$is_logged_in = true; FilesGalleryAclIntegration::init();
ok2(!FilesGalleryAclIntegration::excluded('/media/Photos/a.jpg', false), 'admin logical/canonical allow');
ok2(FilesGalleryAclIntegration::excluded('/media/../etc/a.jpg', false), 'outside root deny');
ok2(FilesGalleryAclIntegration::previewAllowed('/media/Photos'), 'admin preview allow');
ok2(FilesGalleryAclIntegration::expectedMenuHash() === U::get_menu_hash(Config::$config, Config::$root), 'menu namespace');
echo "OK adapter\n";
