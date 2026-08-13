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
    if (str_contains($path, "\0") || str_contains($path, '\\') || preg_match('~^[\\/]~', $path)
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
 * Loads only <usersRoot>/<validated username>/acl.php.
 * Missing or invalid ACL data denies all for non-admin users.
 *
 * @return array{allow:list<string>}
 */
function acl_load(string $username, string $usersRoot): array
{
    acl_validate_username($username);
    $root = realpath($usersRoot);
    if ($root === false || !is_dir($root)) throw new InvalidArgumentException('Invalid ACL users root.');

    $userDir = $root . DIRECTORY_SEPARATOR . $username;
    $resolvedDir = realpath($userDir);
    if ($resolvedDir === false || !is_dir($resolvedDir) || !str_starts_with($resolvedDir, $root . DIRECTORY_SEPARATOR)) {
        return ['allow' => []];
    }

    $file = $resolvedDir . DIRECTORY_SEPARATOR . 'acl.php';
    $resolvedFile = realpath($file);
    if ($resolvedFile === false || !is_file($resolvedFile) || is_link($file) || $resolvedFile !== $file) return ['allow' => []];

    try {
        $acl = include $resolvedFile;
        if (!is_array($acl)) return ['allow' => []];
        $allow = $acl['allow'] ?? [];
        if (!is_array($allow)) return ['allow' => []];
        return ['allow' => acl_normalize_allow($allow)];
    } catch (Throwable) {
        return ['allow' => []];
    }
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
    if (acl_is_admin($username)) return true;
    $path = acl_normalize_path($path, true);
    if ($path === null || $path === '') return false;

    try {
        foreach (acl_normalize_allow(is_array($acl['allow'] ?? null) ? $acl['allow'] : []) as $branch) {
            if (acl_is_branch($path, $branch)) return true;
        }
    } catch (InvalidArgumentException) {
        return false;
    }

    return false;
}

/** True for readable paths and the ancestors required to reach an allowed branch. */
function acl_can_traverse(string $username, string $path, array $acl): bool
{
    if (acl_is_admin($username)) return true;
    $path = acl_normalize_path($path, true);
    if ($path === null) return false;

    try {
        $allow = acl_normalize_allow(is_array($acl['allow'] ?? null) ? $acl['allow'] : []);
        if ($path === '') return $allow !== [];
        foreach ($allow as $branch) {
            if (acl_is_branch($path, $branch) || str_starts_with($branch, $path . '/')) return true;
        }
    } catch (InvalidArgumentException) {
        return false;
    }

    return false;
}
