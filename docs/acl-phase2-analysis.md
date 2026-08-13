# Files Gallery ACL Phase 2A security analysis

Status: analysis only. No Files Gallery code, runtime behaviour, entrypoint, or image is changed by this document.

## Scope and source identity

This review covers only Files Gallery **0.15.3**, commit `82fb6c5878f34e9ef357abf8ea27f8339078aa21`, downloaded from the immutable raw URL in `app/VERSION`. Its SHA-256 was independently verified as `e3d962b3b3a6df1e2e12795024c21be06ad17faf062e0dc3ba172cd82c773eb2`.

The Phase 1 ACL library remains standalone. This review does not assess license or activation code.

## Relevant control flow

`new Config()` (upstream 135-172) loads configuration, invokes `new Login()` at 159, merges a successfully authenticated user config, then resolves `Config::$root`. The top-level dispatcher invokes `new Config()` before any `Request` or `Document`. Therefore an initializer immediately after `new Config()` sees the final user configuration and media root.

`Request` passes `dir` and `file` to `Path::valid_rootpath()` (3856-3877). It constructs a logical root path, resolves it, checks type/readability, and calls `Path::is_exclude($rootpath, $is_dir)` before returning that logical path (1277-1313). `U::readfile()` itself does not call `is_exclude` (778-785): every later PHP read depends on earlier validation.

## Action/path matrix

| Action/path | Input and upstream validation | Final access / cache | ACL conclusion |
| --- | --- | --- | --- |
| Initial document and `start_path` | `Document::get_start_path()` uses `valid_rootpath(path, true)` (3447-3475). | `Dir(root)->get()` and optional start `Dir`; children use `File::__construct()` -> `is_exclude`. Folder JSON cache. | Start directory requires traverse; entries require directory=traverse or file=read. ACL cache namespace required. |
| `files` | `dir` -> `valid_rootpath(dir, true)`. | `Dir(dir)->json()`: cache or `scandir`/`File`. | Directory requires traverse; each child is centrally filtered. Folder JSON cache needs ACL namespace. |
| `dirs` | No requested target; starts at root. Client posts `menu_cache_hash`. | `Dirs::get_dirs()` recursively `glob`s and calls `is_exclude` on every non-root directory (2046-2047). | Traverse mapping works for tree generation. Extra hook required: `Dirs::check_cache()` serves any existing syntactically valid posted hash (1988-2018), not the current user's expected hash. |
| `file` | `file` -> `valid_rootpath(file)`. | `FileResponse` resolves realpath, proxies or serves/creates resize, video, PDF, or conversion output (1568-1611). | File requires read. `FileResponse` does not re-check, so central validation must cover logical and canonical paths. |
| `download` | `file` -> `valid_rootpath(file)`. | `U::download()` -> PHP `readfile`. | File requires read; same logical/canonical requirement. |
| `load_text_file` | `file` -> `valid_rootpath(file)`. | Direct PHP `readfile($file)` (3938-3941). | File requires read; no cache. |
| `preview` | `dir` -> `valid_rootpath(dir, true)`. | Reads default `$dir/_filespreview.jpg`, then cached preview, otherwise scans direct children (4156-4240). | Extra hook required: preview requires directory **read**, not traverse, before default/cache access. |
| `get_downloadables` | Each `items[].path` -> `valid_rootpath`. | Recursive `Filemanager::iterator`; every descendant file calls `is_exclude` (2851-2872). | Initial dir traverse; every returned file read. |
| `download_dir_zip` | `dir` -> `valid_rootpath(dir, true)`. | `Zipper::create`; each item in recursive iterator calls `is_exclude` (2916-2973). | Initial dir traverse; every archive member read. |
| `zip`, `copy`, `move`, `duplicate`, `delete`, `rename`, `text_edit` | Source `items[]` and supplied destination dir are validated. | Filemanager/Zipper reads and writes. | Current read-only mount/disabled actions contain impact. Later write enablement needs separate destination/new-path policy; traversal is not write authorization. |
| `unzip`, `upload`, `new_file`, `new_folder` | Parent `dir` is validated; generated paths are not run back through `valid_rootpath`. | `extractTo`, upload move, `mkdir`, `touch`. | Future writes require explicit destination authorization; central read/traverse hook alone is insufficient. |

`Path::is_exclude()` is strong but **not sufficient alone**: it needs an ACL extension, `preview` needs a stricter action gate, and `Dirs::check_cache()` must bind the cache to the active policy.

## Central hook and symlinks

For a future extension, `Path::is_exclude(path, true)` maps to `acl_can_traverse()` and a file maps to `acl_can_read()`. This covers listings, `File` construction, recursive downloads, ZIP membership, and folder JSON construction.

It must authorize both the logical requested/listed path and its canonical `Path::realpath()` path. Both must pass; a canonical result outside `Config::$root` must deny. `valid_rootpath()` permits a changed realpath that still lies under root (1288-1302). Thus `/media/Public/link -> /media/Private` with ACL `Public` could pass a logical-only check for `Public/link/secret.jpg`; `FileResponse` resolves and reads the target without another exclusion call. Listing has the same logical-path issue. `allow_symlinks=false` is defense in depth only because direct validation calls `is_exclude` without the `$symlinked` flag.

## Folder preview

`preview` is the key exception to directory=traverse. A traversal-only `Family` may first serve a default preview or existing cache before candidate files are checked. It can disclose `Family/parent.jpg` or a prior broad-policy preview. Phase 2B must reject `action=preview` unless `acl_can_read(user, dir)` is true before its default-file and cache branches. The candidate loop later calls `Path::is_exclude(file, false)`, so the central rule covers uncached candidates.

## Cache analysis

| Cache | Key contains `cache_key`? | ACL implication |
| --- | --- | --- |
| Folder JSON (`U::get_dirs_hash`) | Yes | Derive ACL-aware namespace. |
| Menu JSON (`U::get_menu_hash`) | Yes | Derive namespace and bind posted cache hash to current expected namespace. |
| Image resize/video/PDF/convert (`Path::imagecachepath`) | No; path, size, mtime, resize | Validated file action prevents PHP reuse across ACLs; cache must not be directly web accessible. |
| Folder preview (`imagecachepath(dir, dimensions, 0, 0)`) | No | Separate readable-directory preview gate is mandatory. |
| Download ZIP | No; path/name and mtime | Action/member validation protects access; storage ZIP must not be directly served. |
| Index cache | Not ACL suitable | Keep disabled; upstream disallows it with login. |

Derive, do not replace, the configured key: hash `(original cache_key, canonical username, canonical prepared ACL)`, before `Document`, `Request`, `Dirs`, or `Dir` creates hashes. This preserves the existing operator key and separates users and ACL revisions.

`CleanCache::get_hashes()` (3030-3072) derives valid menu/folder hashes from global config and `users/<username>/config.php`, not `acl.php`. Without a later update it deletes ACL-namespaced JSON caches as unknown. Once cache lookup is bound and paths are ACL-validated, this is **performance/cleanup only**, not disclosure; Phase 2B should nevertheless derive the same ACL namespace for each user. Before binding `Dirs::check_cache`, it is security-relevant because another valid cache filename can be requested.

## Direct serving prerequisite

`FileResponse::proxy_file()` only rejects PHP proxying when `load_files_proxy_php` is false and `Path::has_urlpath(realpath)` is true (1599-1611). A direct web-server URL bypasses PHP entirely. ACL integration must require all of:

- `load_files_proxy_php=true`;
- `Path::has_urlpath(Config::$root)===false` and no `root_url_path` mapping; and
- no Apache/Nginx Alias, bind, or symlink exposing `/media` or the cache path.

The current `/media` outside `/var/www/html` meets the intended model. Direct `/media/...` and cache URL attempts must return 404/denied independently of PHP.

## Authentication and initialization

After `new Config()`, `Login::$is_logged_in` is true only after verified login/session state; `$_SESSION['username']` is assigned from the canonical user directory returned by `get_user_config()` (390-414, 452-496). Phase 2B should require logged-in state, reject `Login::$is_default_user`, validate the session username with `acl_validate_username()`, then `acl_load()` `/config/users/<username>/acl.php`. Missing, malformed, and empty ACLs deny normal users. `admin` is policy bypass only after path validation. Unauthenticated/default-user flow is deny-all.

The least invasive insertion is immediately after top-level `new Config()`: root and merged identity then exist. Inserting inside `Config::__construct()` is less resilient because root is assigned only after `new Login()`.

## Minimum Phase 2B hook count

Four upstream changes are the minimum safe set:

1. After `new Config()`, initialize ACL state and derive ACL-aware cache namespace.
2. Extend `Path::is_exclude()` with logical and canonical directory-traverse/file-read checks, denying canonical paths outside root.
3. Bind `Dirs::check_cache()` to the cache hash expected for current ACL namespace.
4. Require readable permission on `$dir` in `preview` before default/cache access.

If writes are enabled later, add a separate fifth destination/new-path authorization hook.

## Future pristine/patched runtime lifecycle

Phase 2A changes nothing. Later, download upstream to a temporary file, verify its pinned SHA/version, and atomically retain a root-owned read-only pristine copy such as `/usr/local/share/filesgallery/upstream-index.php`. On every start, re-verify that pristine file, deterministically transform it with a versioned/checksummed local patch asset into a temporary runtime file, verify patch marker and recorded patched SHA, then atomically install root-owned `/var/www/html/index.php`. Regenerate runtime from pristine on restart; never compare an already patched runtime file to upstream SHA.

## Proposed Phase 2B HTTP attack tests

Use `Family/Shared` and `Videos` allowed with `Family/parent.jpg`, `Family/Private/secret.jpg`, `Family/Shared/shared.jpg`, `Family/Shared/2026/deeper.jpg`, `Videos/video.mp4`, and `Other/other.jpg`.

| Test | Expected result |
| --- | --- |
| `files` on `Family` | Navigable only; no `parent.jpg`, `Private`, or denied descendants. |
| `files` on `Family/Shared` and `2026` | Allowed files/subtree returned. |
| `file`, `download`, `load_text_file` for denied files | 403/404-style failure; no source or cache bytes. |
| Allowed file plus resize/convert/video/PDF thumbnail | Success only for readable source. |
| `preview` on `Family` / `Family/Shared` | First denied; second allowed. Cached/default preview cannot leak. |
| `dirs` own cache / another user's cache hash | Own policy tree only; foreign hash rejected or rebuilt. |
| `get_downloadables`, `download_dir_zip` on `Family` | Shared files only; never parent/Private. |
| Encoded traversal, `..`, malformed admin path | Reject before filesystem access. |
| `Public/link -> Private` | Reject unless logical and canonical paths are both allowed. |
| Precreated menu/folder/preview cache | Narrower ACL never receives broader data. |
| Direct media/cache URL | 404/denied outside PHP. |
| Admin valid versus unsafe path | Valid succeeds; malformed path rejects. |
| Missing/empty ACL | Normal user has no readable or traversable media path. |

The eventual suite must authenticate against a running Files Gallery and assert body absence for every denied file, thumbnail, and download response.
