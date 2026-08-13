# Internal ACL library (Phase 1)

This is an internal extension enforced by a deterministic, version-pinned runtime patch. The repository does not contain upstream Files Gallery source; the container verifies a pristine upstream file before generating its separate runnable runtime copy. It does not modify Files Gallery JavaScript or its license mechanism.

## Per-user file

The future per-user ACL file is `/config/users/<username>/acl.php`:

```php
<?php

return [
    'allow' => [
        'Numerisation photos/Famille/Partagé',
        'Numerisation video',
    ],
];
```

Entries are relative to the media root, with no leading slash. Each entry permits that directory and all of its descendants. The format has no passwords, regular expressions, `allow_*` settings, license data, or explicit traversal entries.

## Semantics

ACLs deny by default. A missing, empty, or invalid `acl.php` provides no access for a normal user. The fixed `admin` user bypasses ACL policy only after requested-path validation: absolute paths, drive-qualified paths, backslashes, `..`, NUL/control characters, and invalid UTF-8 are denied even for `admin`.

- `acl_can_read()` is true only for an allowed branch or a descendant. With `Family/Shared`, `Family/Shared/photo.jpg` is readable but `Family/photo.jpg` and `Family/Private` are denied.
- `acl_can_traverse()` is true for readable paths and for ancestors needed to reach an allowed branch. With `Family/Shared`, `Family` is traversable but is not readable.

Paths are strict, slash-delimited and component-aware. Cosmetic duplicate slashes and a trailing slash are normalized. Absolute paths, drive-qualified paths, backslashes, `..`, NUL/control characters, and invalid UTF-8 are rejected. Unicode and normal filename characters such as spaces, parentheses, brackets, plus signs, dots, apostrophes, hyphens, and underscores remain valid.

The loader accesses only `<usersRoot>/<validated username>/acl.php`, normalizes, deduplicates, and reduces every `allow` entry once, then fails closed for invalid data. It does not rewrite ACL files in Phase 1. `acl_can_read()` and `acl_can_traverse()` expect this prepared structure; use `acl_prepare()` for manually constructed ACL arrays. Their hot path only normalizes the requested path and compares it with the prepared branches.

## Runtime enforcement

The runtime adapter initializes after Files Gallery authentication/configuration, derives a cache namespace from the existing `cache_key`, canonical username, and prepared ACL, and applies traversal/read checks through the central Files Gallery path filter. Both the logical path and canonical symlink target must be allowed. Menu-cache hashes are bound to the current ACL namespace, and a folder preview requires directory read permission rather than traversal permission.

ACL protection requires `load_files_proxy_php=true` and media outside the web document root, with no web-server alias, bind, or symlink exposing `/media`, cache, or downloads directly. The current scope is read-only media. Write ACL and an ACL Web UI are not implemented.
# Phase 2B runtime enforcement

ACL enforcement is applied only to read paths. Files Gallery's runtime menu and folder JSON caches use a namespace derived from the existing cache key, canonical user name and normalized ACL. A stale or foreign menu hash is treated as a cache miss and rebuilt in the current namespace.

`CleanCache` does not reconstruct ACL namespaces, so it can remove an otherwise-valid ACL cache early; this is performance-only and cannot expose data. Write authorization and an ACL Web UI are deliberately not implemented.
