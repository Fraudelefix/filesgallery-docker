<?php
declare(strict_types=1);
require __DIR__ . '/../acl.php';

$count = 0;
function check(bool $value, string $message): void { global $count; if (!$value) throw new RuntimeException("Failed: $message"); $count++; }
function invalid(callable $fn, string $message): void { try { $fn(); } catch (InvalidArgumentException) { check(true, $message); return; } throw new RuntimeException("Failed: $message"); }
function acl_file(string $root, string $user, string $contents): void { mkdir("$root/$user", 0700, true); file_put_contents("$root/$user/acl.php", $contents); }

$shared = ['allow' => ['Family/Shared']];
foreach (['Family/Shared', 'Family/Shared/2025', 'Family/Shared/2025/photo.jpg'] as $path) check(acl_can_read('victor', $path, $shared), "basic read $path");
foreach (['Family', 'Family/photo.jpg', 'Family/Private', 'Family/Private/photo.jpg', 'Family/Shared-old'] as $path) check(!acl_can_read('victor', $path, $shared), "basic deny $path");

$deep = ['allow' => ['A/B/C']];
foreach (['A', 'A/B', 'A/B/C', 'A/B/C/D'] as $path) check(acl_can_traverse('victor', $path, $deep), "traverse $path");
foreach (['A/X', 'A/B2', 'X'] as $path) check(!acl_can_traverse('victor', $path, $deep), "no traverse $path");
foreach (['A', 'A/B', 'A/file.txt', 'A/B/file.txt'] as $path) check(!acl_can_read('victor', $path, $deep), "traversal-only denies read $path");
foreach (['A/B/C', 'A/B/C/file.txt'] as $path) check(acl_can_read('victor', $path, $deep), "deep read $path");

$multiple = ['allow' => ['Photos/Public', 'Videos', 'Family/Shared']];
foreach (['Photos/Public/x.jpg', 'Videos/a.mp4', 'Family/Shared/x.jpg'] as $path) check(acl_can_read('victor', $path, $multiple), "independent branch $path");
foreach (['Photos/Private', 'Family/Private', 'Video'] as $path) check(!acl_can_read('victor', $path, $multiple), "independent deny $path");
check(acl_can_read('victor', 'Photos/2025/image.jpg', ['allow' => ['Photos']]), 'root-level branch');

foreach (['Été 2026', 'Photos (2025)', 'A+B', '[Archives]', 'Test.file', 'Alexia LABRUSSE'] as $path) check(acl_can_read('victor', "$path/photo.jpg", ['allow' => [$path]]), "special path $path");
check(acl_normalize_path('Photos//2025/') === 'Photos/2025', 'cosmetic normalization');
foreach (['../Photos', 'Photos/../Private', '/etc/passwd', 'C:\\Windows', "Photos\0bad", 'Photos\\..\\Private'] as $path) check(acl_normalize_path($path) === null, "unsafe path $path");
foreach (['Family/Shared-old', 'Family/Shared2', 'Family/Shared_backup'] as $path) check(!acl_can_read('victor', $path, $shared), "prefix collision $path");
check(acl_can_read('admin', 'anything/at/all', ['allow' => []]) && acl_can_traverse('admin', '../unsafe', ['allow' => []]), 'admin bypass');
check(!acl_can_read('victor', 'Anything', []) && !acl_can_traverse('victor', 'Anything', []), 'missing acl data denies');
check(!acl_can_read('victor', 'Anything', ['allow' => []]), 'empty acl denies');
check(acl_normalize_allow(['Photos', 'Photos/2025', 'Photos/2025/Holiday']) === ['Photos'], 'redundant entries reduced');

$root = sys_get_temp_dir() . '/fg-acl-' . bin2hex(random_bytes(6)); mkdir($root, 0700);
acl_file($root, 'Victor', "<?php return ['allow' => ['Family/Shared']];");
check(acl_load('Victor', $root) === ['allow' => ['Family/Shared']], 'load valid acl');
acl_file($root, 'NonArray', '<?php return false;'); check(acl_load('NonArray', $root) === ['allow' => []], 'non-array acl denied');
acl_file($root, 'BadAllow', "<?php return ['allow' => 'bad'];"); check(acl_load('BadAllow', $root) === ['allow' => []], 'non-array allow denied');
acl_file($root, 'BadPath', "<?php return ['allow' => ['../bad']];"); check(acl_load('BadPath', $root) === ['allow' => []], 'invalid entry denied');
acl_file($root, 'Mixed', "<?php return ['allow' => ['Good', '../bad']];"); check(acl_load('Mixed', $root) === ['allow' => []], 'mixed entries fail safely');
check(acl_load('Missing', $root) === ['allow' => []], 'missing acl file denied');
foreach (['', '../x', 'a/b', 'a\\b', "bad\0name"] as $name) invalid(fn() => acl_load($name, $root), "unsafe username $name");

echo "OK ($count assertions)\n";
