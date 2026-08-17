<?php
declare(strict_types=1);
require __DIR__ . '/../acl.php';

$count = 0;
function check(bool $value, string $message): void { global $count; if (!$value) throw new RuntimeException("Failed: $message"); $count++; }
function invalid(callable $fn, string $message): void { try { $fn(); } catch (InvalidArgumentException) { check(true, $message); return; } throw new RuntimeException("Failed: $message"); }
function acl_file(string $root, string $user, string $contents): void { mkdir("$root/$user", 0700, true); file_put_contents("$root/$user/acl.php", $contents); }

$shared = acl_prepare(['allow' => ['Family/Shared']]);
foreach (['Family/Shared', 'Family/Shared/2025', 'Family/Shared/2025/photo.jpg'] as $path) check(acl_can_read('victor', $path, $shared), "basic read $path");
foreach (['Family', 'Family/photo.jpg', 'Family/Private', 'Family/Private/photo.jpg', 'Family/Shared-old'] as $path) check(!acl_can_read('victor', $path, $shared), "basic deny $path");

$deep = acl_prepare(['allow' => ['A/B/C']]);
foreach (['A', 'A/B', 'A/B/C', 'A/B/C/D'] as $path) check(acl_can_traverse('victor', $path, $deep), "traverse $path");
foreach (['A/X', 'A/B2', 'X'] as $path) check(!acl_can_traverse('victor', $path, $deep), "no traverse $path");
foreach (['A', 'A/B', 'A/file.txt', 'A/B/file.txt'] as $path) check(!acl_can_read('victor', $path, $deep), "traversal-only denies read $path");
foreach (['A/B/C', 'A/B/C/file.txt'] as $path) check(acl_can_read('victor', $path, $deep), "deep read $path");

$multiple = acl_prepare(['allow' => ['Photos/Public', 'Videos', 'Family/Shared']]);
foreach (['Photos/Public/x.jpg', 'Videos/a.mp4', 'Family/Shared/x.jpg'] as $path) check(acl_can_read('victor', $path, $multiple), "independent branch $path");
foreach (['Photos/Private', 'Family/Private', 'Video'] as $path) check(!acl_can_read('victor', $path, $multiple), "independent deny $path");
check(acl_can_read('victor', 'Photos/2025/image.jpg', acl_prepare(['allow' => ['Photos']])), 'root-level branch');

foreach (['Été 2026', 'Photos (2025)', 'A+B', '[Archives]', 'Test.file', 'Alexia LABRUSSE', "L'été", 'Family-2026', 'Family_2026'] as $path) check(acl_can_read('victor', "$path/photo.jpg", acl_prepare(['allow' => [$path]])), "special path $path");
check(acl_normalize_path('Photos//2025/') === 'Photos/2025', 'cosmetic normalization');
foreach (['../Photos', 'Photos/../Private', '/etc/passwd', 'C:\\Windows', "Photos\0bad", "Photos\x01bad", 'Photos\\..\\Private', "\xC3\x28"] as $path) check(acl_normalize_path($path) === null, "unsafe path $path");
foreach (['Family/Shared-old', 'Family/Shared2', 'Family/Shared_backup'] as $path) check(!acl_can_read('victor', $path, $shared), "prefix collision $path");
check(acl_can_read('admin', 'Photos', ['allow' => []]) && acl_can_read('admin', 'Photos/2025', ['allow' => []]) && acl_can_traverse('admin', '', ['allow' => []]), 'admin bypass after valid path');
foreach (['../unsafe', '/etc/passwd', 'C:\\Windows', "bad\0path", "\xC3\x28"] as $path) check(!acl_can_read('admin', $path, ['allow' => []]) && !acl_can_traverse('admin', $path, ['allow' => []]), "admin rejects unsafe path $path");
check(!acl_can_read('victor', 'Anything', ['allow' => []]) && !acl_can_traverse('victor', 'Anything', ['allow' => []]), 'missing acl data denies');
check(!acl_can_read('victor', 'Anything', acl_prepare(['allow' => []])), 'empty acl denies');
check(acl_normalize_allow(['Photos', 'Photos/2025', 'Photos/2025/Holiday']) === ['Photos'], 'redundant entries reduced');
check(acl_prepare(['allow' => ['Photos', 'Photos', 'Photos/2025']]) === ['allow' => ['Photos']], 'duplicate entries reduced');
$siblings = acl_prepare(['allow' => ['A/B', 'A/C']]);
check(acl_can_traverse('victor', 'A', $siblings) && !acl_can_read('victor', 'A', $siblings) && !acl_can_read('victor', 'A/file.jpg', $siblings), 'sibling parent traversal only');
foreach (['A/B', 'A/B/file.jpg', 'A/C', 'A/C/file.jpg'] as $path) check(acl_can_read('victor', $path, $siblings), "sibling branch $path");
check(!acl_can_read('victor', 'A/D', $siblings) && !acl_can_traverse('victor', 'A/D', $siblings), 'sibling deny');

$root = sys_get_temp_dir() . '/fg-acl-' . bin2hex(random_bytes(6)); mkdir($root, 0700);
acl_file($root, 'Victor', "<?php return ['allow' => ['Family/Shared']];");
check(acl_load('Victor', $root) === ['allow' => ['Family/Shared']], 'load valid acl');
acl_file($root, 'NonArray', '<?php return false;'); check(acl_load('NonArray', $root) === ['allow' => []], 'non-array acl denied');
acl_file($root, 'BadAllow', "<?php return ['allow' => 'bad'];"); check(acl_load('BadAllow', $root) === ['allow' => []], 'non-array allow denied');
acl_file($root, 'BadPath', "<?php return ['allow' => ['../bad']];"); check(acl_load('BadPath', $root) === ['allow' => []], 'invalid entry denied');
acl_file($root, 'Mixed', "<?php return ['allow' => ['Good', '../bad']];"); check(acl_load('Mixed', $root) === ['allow' => []], 'mixed entries fail safely');
check(acl_load('Missing', $root) === ['allow' => []], 'missing acl file denied');
foreach (['', '../x', 'a/b', 'a\\b', "bad\0name"] as $name) invalid(fn() => acl_load($name, $root), "unsafe username $name");

mkdir("$root/Editor", 0700); check(acl_user_dir('Editor', $root) === realpath("$root/Editor"), 'real direct user directory accepted');
foreach (['', '../Editor', 'a/b', 'a\\b'] as $name) invalid(fn() => acl_user_dir($name, $root), "invalid editor username $name");
if (function_exists('symlink')) {
    $outside = sys_get_temp_dir() . '/fg-acl-outside-' . bin2hex(random_bytes(4)); mkdir($outside, 0700);
    symlink($outside, "$root/Evil");
    check(acl_user_dir('Evil', $root) === null, 'symlink user directory rejected');
}
check(acl_editor_state('Victor', $root) === ['allow' => ['Family/Shared'], 'state' => 'valid'], 'editor loads valid ACL');
check(acl_editor_state('Missing', $root) === ['allow' => [], 'state' => 'missing'], 'editor missing ACL fails closed');
check(acl_editor_state('BadAllow', $root) === ['allow' => [], 'state' => 'malformed'], 'editor malformed ACL fails closed');
check(acl_format_file(['Family/Shared', 'Videos']) === "<?php\nreturn [\n    'allow' => [\n        'Family/Shared',\n        'Videos',\n    ],\n];\n", 'deterministic ACL format');

echo "OK ($count assertions)\n";
