# Internal ACL library (Phase 1)

This is an internal extension that is **not yet wired into Files Gallery**. It does not patch Files Gallery, its JavaScript, its license mechanism, or runtime-downloaded `index.php`.

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
