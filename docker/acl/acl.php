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

    $file = $userDir . DIRECTORY_SEPARATOR . 'acl.php';
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

const FG_USER_CONFIG_MAX_BYTES = 262144;

/**
 * Files Gallery user configuration helpers used by the independent admin UI.
 * Every function below starts with acl_user_dir(), so the directory containment
 * and symlink policy has one authoritative implementation.
 */
function fg_user_config_path(string $username, string $usersRoot, bool $mustExist = true): ?string
{
    $dir = acl_user_dir($username, $usersRoot);
    if ($dir === null) return null;
    $path = $dir . DIRECTORY_SEPARATOR . 'config.php';
    if (!$mustExist && !file_exists($path) && !is_link($path)) return $path;
    if (is_link($path) || !is_file($path) || realpath($path) !== $path) return null;
    return $path;
}

/** @return array{state:'valid'|'missing'|'malformed',content:string,config:array<string,mixed>} */
function fg_user_config_state(string $username, string $usersRoot): array
{
    $path = fg_user_config_path($username, $usersRoot);
    if ($path === null) {
        $dir = acl_user_dir($username, $usersRoot);
        return ['state' => $dir === null ? 'missing' : 'missing', 'content' => '', 'config' => []];
    }
    $content = file_get_contents($path);
    if (!is_string($content)) return ['state' => 'malformed', 'content' => '', 'config' => []];
    try {
        return ['state' => 'valid', 'content' => $content, 'config' => fg_validate_config_content($content)];
    } catch (Throwable) {
        return ['state' => 'malformed', 'content' => $content, 'config' => []];
    }
}

/**
 * Accepts only a PHP file whose sole executable statement is `return` of a
 * literal PHP array. It deliberately rejects variables, function calls,
 * includes and closing tags; no browser-provided PHP is included by Apache.
 *
 * @return array<string,mixed>
 */
function fg_validate_config_content(string $content): array
{
    if ($content === '' || strlen($content) > FG_USER_CONFIG_MAX_BYTES || str_contains($content, "\0")) {
        throw new InvalidArgumentException('Invalid config content.');
    }
    $tmp = tempnam(sys_get_temp_dir(), 'fg-config-check-');
    if ($tmp === false) throw new RuntimeException('Cannot create config validation file.');
    try {
        if (file_put_contents($tmp, $content) === false) throw new RuntimeException('Cannot write config validation file.');
        $output = []; $status = 1;
        @exec('php -n -d display_errors=0 -l ' . escapeshellarg($tmp) . ' 2>&1', $output, $status);
        if ($status !== 0) throw new InvalidArgumentException('Invalid PHP syntax.');

        $tokens = token_get_all($content, TOKEN_PARSE);
        $allowed = [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_RETURN, T_CONSTANT_ENCAPSED_STRING,
            T_LNUMBER, T_DNUMBER, T_DOUBLE_ARROW, T_ARRAY, T_STRING];
        $returnSeen = false;
        foreach ($tokens as $token) {
            if (is_string($token)) {
                if (!in_array($token, ['[', ']', '(', ')', ',', ';'], true)) throw new InvalidArgumentException('Non-literal config.');
                continue;
            }
            [$id, $text] = $token;
            if (!in_array($id, $allowed, true)) throw new InvalidArgumentException('Non-literal config.');
            if ($id === T_RETURN) {
                if ($returnSeen) throw new InvalidArgumentException('Multiple return statements.');
                $returnSeen = true;
            }
            if ($id === T_STRING && !in_array(strtolower($text), ['true', 'false', 'null'], true)) {
                throw new InvalidArgumentException('Non-literal config constant.');
            }
        }
        if (!$returnSeen) throw new InvalidArgumentException('Config must return an array.');

        // The lexical allow-list above permits only literals. Evaluate that
        // restricted expression in a separate CLI process, never via include
        // in the Apache request process.
        $command = ['php', '-n', '-d', 'display_errors=0', '-r', '$v=include $argv[1]; if (!is_array($v)) exit(2); echo base64_encode(serialize($v));', $tmp];
        $pipes = [];
        $proc = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) throw new RuntimeException('Cannot validate config value.');
        $encoded = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $code = proc_close($proc);
        if ($code !== 0 || $errors !== '' || !is_string($encoded)) throw new InvalidArgumentException('Config must return an array.');
        $value = @unserialize(base64_decode($encoded, true) ?: '', ['allowed_classes' => false]);
        if (!is_array($value) || !isset($value['password']) || !is_string($value['password'])
            || (password_get_info($value['password'])['algo'] ?? null) === null) {
            throw new InvalidArgumentException('Config must return a Files Gallery user array.');
        }
        return $value;
    } finally {
        @unlink($tmp);
    }
}

/** @param array<string,mixed> $config */
function fg_format_config_file(array $config): string
{
    return "<?php\n\nreturn " . var_export($config, true) . ";\n";
}

function fg_password_hash(string $password): string
{
    if ($password === '' || strlen($password) > 4096) throw new InvalidArgumentException('Invalid password.');
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if (!is_string($hash) || $hash === '') throw new RuntimeException('Password hash failed.');
    return $hash;
}

/** @param array<string,mixed> $config */
function fg_atomic_save_config(string $username, string $usersRoot, array $config): void
{
    fg_atomic_save_config_content($username, $usersRoot, fg_format_config_file($config));
}

function fg_atomic_save_config_content(string $username, string $usersRoot, string $content): void
{
    $target = fg_user_config_path($username, $usersRoot, false);
    if ($target === null) throw new RuntimeException('User not found.');
    $dir = dirname($target); $tmp = tempnam($dir, '.config.');
    if ($tmp === false) throw new RuntimeException('Cannot create config temporary file.');
    try {
        // Revalidate generated output before it can replace the active file.
        fg_validate_config_content($content);
        if (file_put_contents($tmp, $content) === false || !@chmod($tmp, 0600)) throw new RuntimeException('Config write failed.');
        if (is_file($target)) {
            $previous = $dir . DIRECTORY_SEPARATOR . 'config.php.previous';
            if (is_link($previous) || (file_exists($previous) && realpath($previous) !== $previous)) throw new RuntimeException('Unsafe config backup.');
            $backup = tempnam($dir, '.config-backup.');
            if ($backup === false || !copy($target, $backup) || !@chmod($backup, 0600) || !@rename($backup, $previous)) {
                @unlink((string) $backup); throw new RuntimeException('Config backup failed.');
            }
        }
        if (!@rename($tmp, $target)) throw new RuntimeException('Config replace failed.');
        if (function_exists('opcache_invalidate')) @opcache_invalidate($target, true);
    } finally {
        @unlink($tmp);
    }
}

/** @return list<string> */
function fg_list_users(string $usersRoot, bool $includeAdmin = true): array
{
    $users = [];
    foreach (@scandir($usersRoot) ?: [] as $name) {
        if ((!$includeAdmin && $name === 'admin')) continue;
        try { if (acl_user_dir($name, $usersRoot) !== null) $users[] = $name; } catch (Throwable) {}
    }
    natcasesort($users);
    return array_values($users);
}

/** @param array<string,mixed>|null $copyConfig */
function fg_create_user(string $username, string $password, string $usersRoot, ?array $copyConfig = null, ?array $copyAcl = null): void
{
    $username = acl_validate_username($username);
    $root = realpath($usersRoot);
    if ($root === false || !is_dir($root) || file_exists($root . DIRECTORY_SEPARATOR . $username) || is_link($root . DIRECTORY_SEPARATOR . $username)) {
        throw new RuntimeException('User already exists or users root is invalid.');
    }
    $dir = $root . DIRECTORY_SEPARATOR . $username;
    if (!mkdir($dir, 0700) || !is_dir($dir) || realpath($dir) !== $dir) throw new RuntimeException('User directory creation failed.');
    try {
        $config = $copyConfig ?? [
            'files_exclude' => '/(^|\\/)(\\.|Thumbs\\.db$|desktop\\.ini$|~\\$)/i',
            'dirs_exclude' => '/(^|\\/)(@eaDir|[.][^\\/]*|__MACOSX|\\$RECYCLE\\.BIN)(\\/|$)/i',
        ];
        $config['password'] = fg_password_hash($password);
        // A copied admin configuration must never confer administration or
        // diagnostics privileges on an ordinary account.
        if ($username !== 'admin') {
            $config['allow_settings'] = false;
            $config['allow_tests'] = false;
        }
        fg_atomic_save_config($username, $root, $config);
        if ($copyAcl !== null) {
            $acl = acl_prepare($copyAcl);
            $aclTarget = $dir . DIRECTORY_SEPARATOR . 'acl.php';
            $tmp = tempnam($dir, '.acl.');
            if ($tmp === false || file_put_contents($tmp, acl_format_file($acl['allow'])) === false || !@chmod($tmp, 0600) || !@rename($tmp, $aclTarget)) {
                @unlink((string) $tmp); throw new RuntimeException('ACL copy failed.');
            }
        }
    } catch (Throwable $e) {
        @unlink($dir . '/config.php'); @unlink($dir . '/config.php.previous'); @unlink($dir . '/acl.php'); @rmdir($dir);
        throw $e;
    }
}

function fg_delete_user(string $username, string $usersRoot): void
{
    $username = acl_validate_username($username);
    if ($username === 'admin') throw new InvalidArgumentException('The admin account cannot be deleted.');
    $dir = acl_user_dir($username, $usersRoot);
    if ($dir === null) throw new RuntimeException('User not found.');
    foreach (['config.php', 'config.php.previous', 'acl.php'] as $file) {
        $path = $dir . DIRECTORY_SEPARATOR . $file;
        if (is_link($path)) throw new RuntimeException('Unsafe user file.');
        if (is_file($path) && !@unlink($path)) throw new RuntimeException('User deletion failed.');
    }
    if (!@rmdir($dir)) throw new RuntimeException('User directory is not empty.');
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
