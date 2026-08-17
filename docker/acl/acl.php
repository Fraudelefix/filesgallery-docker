<?php
declare(strict_types=1);

/**
 * Internal folder ACL helpers. This library is deliberately independent from
 * Files Gallery and is not wired into its request handling in Phase 1.
 */

function acl_validate_username(string $username): string
{
    if ($username === '' || strlen($username) > 64 || str_contains($username, '..')
        || preg_match('/[\\\\\/\x00-\x1F\x7F]/u', $username)
        || !preg_match('/^[\pL\pN][\pL\pN ._-]*$/u', $username)) {
        throw new InvalidArgumentException('Invalid ACL username.');
    }

    return $username;
}

/**
 * Returns a canonical, relative slash-delimited path, or null for unsafe input.
 * Empty paths are only accepted internally when $allowEmpty is true.
 */
function acl_normalize_path(string $path, bool $allowEmpty = false): ?string
{
    if (preg_match('//u', $path) !== 1 || str_contains($path, "\0") || str_contains($path, '\\') || preg_match('~^[\\/]~', $path)
        || preg_match('~^[A-Za-z]:~', $path)) {
        return null;
    }

    $parts = array_values(array_filter(explode('/', $path), static fn(string $part): bool => $part !== ''));
    if (!$parts) return $allowEmpty ? '' : null;

    foreach ($parts as $part) {
        if ($part === '.' || $part === '..' || preg_match('/[\x00-\x1F\x7F]/u', $part)) return null;
    }

    return implode('/', $parts);
}

/**
 * Prepares an ACL once for acl_can_read() / acl_can_traverse().
 *
 * @return array{allow:list<string>}
 */
function acl_prepare(array $acl): array
{
    $allow = $acl['allow'] ?? [];
    if (!is_array($allow)) throw new InvalidArgumentException('Invalid ACL allow list.');
    return ['allow' => acl_normalize_allow($allow)];
}

/** @return list<string> */
function acl_normalize_allow(array $allow): array
{
    $normal = [];
    foreach ($allow as $path) {
        if (!is_string($path) || ($path = acl_normalize_path($path)) === null) {
            throw new InvalidArgumentException('Invalid ACL allow path.');
        }
        $normal[$path] = true;
    }

    $paths = array_keys($normal);
    sort($paths, SORT_STRING);
    $reduced = [];
    foreach ($paths as $path) {
        $redundant = false;
        foreach ($reduced as $parent) {
            if ($path === $parent || str_starts_with($path, $parent . '/')) {
                $redundant = true;
                break;
            }
        }
        if (!$redundant) $reduced[] = $path;
    }

    return $reduced;
}

/**
 * Resolves only a real, direct child directory of the configured users root.
 * Invalid usernames throw; absent or symlinked candidates are not users.
 */
function acl_user_dir(string $username, string $usersRoot): ?string
{
    $username = acl_validate_username($username);
    $root = realpath($usersRoot);
    if ($root === false || !is_dir($root)) throw new InvalidArgumentException('Invalid ACL users root.');

    $path = $root . DIRECTORY_SEPARATOR . $username;
    $resolved = realpath($path);
    if (is_link($path) || $resolved === false || !is_dir($resolved) || $resolved !== $path) return null;
    return $path;
}

/**
 * Loads only <usersRoot>/<validated username>/acl.php.
 * Missing or invalid ACL data denies all for non-admin users.
 *
 * @return array{allow:list<string>}
 */
function acl_load(string $username, string $usersRoot): array
{
    $userDir = acl_user_dir($username, $usersRoot);
    if ($userDir === null) return ['allow' => []];

    $file = $resolvedDir . DIRECTORY_SEPARATOR . 'acl.php';
    $resolvedFile = realpath($file);
    if ($resolvedFile === false || !is_file($resolvedFile) || is_link($file) || $resolvedFile !== $file) return ['allow' => []];

    try {
        $acl = include $resolvedFile;
        if (!is_array($acl)) return ['allow' => []];
        $allow = $acl['allow'] ?? [];
        if (!is_array($allow)) return ['allow' => []];
        return acl_prepare(['allow' => $allow]);
    } catch (Throwable) {
        return ['allow' => []];
    }
}

/** @return array{allow:list<string>,state:'valid'|'missing'|'malformed'} */
function acl_editor_state(string $username, string $usersRoot): array
{
    $userDir = acl_user_dir($username, $usersRoot);
    if ($userDir === null) return ['allow' => [], 'state' => 'missing'];
    $file = $userDir . DIRECTORY_SEPARATOR . 'acl.php';
    if (!is_file($file) || is_link($file) || realpath($file) !== $file) return ['allow' => [], 'state' => 'missing'];

    try {
        set_error_handler(static fn() => throw new RuntimeException('Invalid ACL file.'));
        try { $value = include $file; } finally { restore_error_handler(); }
        if (!is_array($value)) throw new RuntimeException('Invalid ACL file.');
        return ['allow' => acl_prepare($value)['allow'], 'state' => 'valid'];
    } catch (Throwable) {
        return ['allow' => [], 'state' => 'malformed'];
    }
}

/** @param list<string> $allow */
function acl_format_file(array $allow): string
{
    $body = "<?php\nreturn [\n    'allow' => [\n";
    foreach ($allow as $path) $body .= '        ' . var_export($path, true) . ",\n";
    return $body . "    ],\n];\n";
}

function acl_is_admin(string $username): bool
{
    return $username === 'admin';
}

function acl_is_branch(string $path, string $branch): bool
{
    return $path === $branch || str_starts_with($path, $branch . '/');
}

/** True only for an allowed branch and its descendants. */
function acl_can_read(string $username, string $path, array $acl): bool
{
    $path = acl_normalize_path($path, true);
    if ($path === null || $path === '') return false;
    if (acl_is_admin($username)) return true;

    $allow = $acl['allow'] ?? [];
    if (!is_array($allow)) return false;
    foreach ($allow as $branch) {
        if (is_string($branch) && acl_is_branch($path, $branch)) return true;
    }

    return false;
}

/** True for readable paths and the ancestors required to reach an allowed branch. */
function acl_can_traverse(string $username, string $path, array $acl): bool
{
    $path = acl_normalize_path($path, true);
    if ($path === null) return false;
    if (acl_is_admin($username)) return true;

    $allow = $acl['allow'] ?? [];
    if (!is_array($allow)) return false;
    if ($path === '') return $allow !== [];
    foreach ($allow as $branch) {
        if (is_string($branch) && (acl_is_branch($path, $branch) || str_starts_with($branch, $path . '/'))) return true;
    }

    return false;
}
