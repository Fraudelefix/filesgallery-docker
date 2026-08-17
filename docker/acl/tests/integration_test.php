<?php
declare(strict_types=1);
require __DIR__ . '/../acl.php';
class Config { public static array $config = ['cache_key' => 'operator-key', 'load_files_proxy_php' => true]; public static string $root = '/media'; public static string $storagepath = ''; public static function get(string $k): mixed { return self::$config[$k]; } }
class Login { public static bool $is_logged_in = false; public static bool $is_default_user = false; }
class Path { public static array $paths = []; public static function has_urlpath(string $p): bool { return false; } public static function realpath(string $p): string|false { return self::$paths[$p] ?? $p; } }
class U { public static function get_menu_hash(array $c, string $r): string { return hash('sha256', $c['cache_key'] . $r); } public static function error(string $m, int $c): never { throw new RuntimeException($m); } }
require __DIR__ . '/../integration.php';
function ok2(bool $v, string $m): void { if (!$v) throw new RuntimeException("Failed: $m"); }
function reset2(string $username, array $allow, string $cache = 'operator-key'): void { $r = new ReflectionClass(FilesGalleryAclIntegration::class); foreach (['username' => '', 'acl' => ['allow' => []], 'ready' => false] as $k => $v) { $p = $r->getProperty($k); $p->setValue(null, $v); } Config::$config = ['cache_key' => $cache, 'load_files_proxy_php' => true]; $_SESSION = ['username' => $username]; Login::$is_logged_in = true; Login::$is_default_user = false; $d = sys_get_temp_dir() . '/acl-int-' . bin2hex(random_bytes(4)); mkdir($d . "/users/$username", 0700, true); file_put_contents($d . "/users/$username/acl.php", '<?php return ' . var_export(['allow' => $allow], true) . ';'); Config::$storagepath = $d; Path::$paths = []; FilesGalleryAclIntegration::init(); }
reset2('admin', []);
ok2(!FilesGalleryAclIntegration::excluded('/media/Photos/a.jpg', false), 'admin logical/canonical allow');
ok2(FilesGalleryAclIntegration::excluded('/media/../etc/a.jpg', false), 'outside root deny');
ok2(FilesGalleryAclIntegration::previewAllowed('/media/Photos'), 'admin preview allow');
ok2(FilesGalleryAclIntegration::expectedMenuHash() === U::get_menu_hash(Config::$config, Config::$root), 'menu namespace');
reset2('victor', ['Family/Shared', 'Videos']);
ok2(!FilesGalleryAclIntegration::excluded('/media/Family', true), 'ancestor traversable');
ok2(FilesGalleryAclIntegration::excluded('/media/Family', false), 'ancestor not readable');
ok2(!FilesGalleryAclIntegration::excluded('/media/Family/Shared/a.jpg', false), 'allowed file');
ok2(FilesGalleryAclIntegration::excluded('/media/Family/Private/a.jpg', false), 'private denied');
ok2(!FilesGalleryAclIntegration::previewAllowed('/media/Family'), 'ancestor preview denied');
ok2(FilesGalleryAclIntegration::previewAllowed('/media/Family/Shared'), 'allowed preview');
$hash = Config::$config['cache_key']; reset2('victor', ['Family/Shared', 'Videos']); ok2($hash === Config::$config['cache_key'], 'deterministic namespace');
reset2('victor', ['Family']); ok2($hash !== Config::$config['cache_key'], 'ACL changes namespace');
reset2('other', ['Family/Shared', 'Videos']); ok2($hash !== Config::$config['cache_key'], 'user changes namespace');
reset2('victor', ['Family/Shared', 'Videos']); Path::$paths['/media/Family/Shared/link/a.jpg'] = '/media/Family/Private/a.jpg'; ok2(FilesGalleryAclIntegration::excluded('/media/Family/Shared/link/a.jpg', false), 'canonical mismatch denied');
Path::$paths['/media/Family/Shared/out.jpg'] = '/outside/out.jpg'; ok2(FilesGalleryAclIntegration::excluded('/media/Family/Shared/out.jpg', false), 'canonical outside denied');
reset2('admin', []);
mkdir(Config::$storagepath . '/users/Editor', 0700);
mkdir(Config::$storagepath . '/users/.invalid', 0700);
if (function_exists('symlink')) {
    $outside = sys_get_temp_dir() . '/acl-user-link-' . bin2hex(random_bytes(4)); mkdir($outside, 0700);
    symlink($outside, Config::$storagepath . '/users/Evil');
}
$users = (new ReflectionMethod(FilesGalleryAclIntegration::class, 'adminUsers'))->invoke(null);
ok2(in_array('Editor', $users, true), 'real user directory discovered');
ok2(!in_array('admin', $users, true) && !in_array('.invalid', $users, true) && !in_array('Evil', $users, true), 'admin, invalid and symlink users hidden');
echo "OK adapter\n";
