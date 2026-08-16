<?php
declare(strict_types=1);

require_once __DIR__ . '/acl.php';

/** Files Gallery 0.15.3 adapter. Loaded only by the deterministic runtime patch. */
final class FilesGalleryAclIntegration
{
    private static string $username = '';
    /** @var array{allow:list<string>} */
    private static array $acl = ['allow' => []];
    private static bool $ready = false;

    public static function init(): void
    {
        if (self::$ready) return;
        self::$ready = true;
        if (!Config::get('load_files_proxy_php') || Path::has_urlpath(Config::$root)) {
            self::fail('ACL requires PHP-proxied media outside the web document root.');
        }

        if (Login::$is_logged_in && !Login::$is_default_user && isset($_SESSION['username']) && is_string($_SESSION['username'])) {
            try {
                self::$username = acl_validate_username($_SESSION['username']);
            } catch (Throwable) {
                // An untrusted session value is never an ACL identity.
                self::$username = '';
            }

            try {
                self::$acl = acl_load(self::$username, Config::$storagepath . '/users');
            } catch (Throwable) {
                // A missing or malformed ACL fails closed for normal users.  The
                // canonical admin identity still retains its policy bypass.
                self::$acl = ['allow' => []];
            }
        }

        $identity = self::$username === 'admin' ? 'admin' : self::$username;
        $payload = json_encode(self::$acl['allow'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        Config::$config['cache_key'] = 'acl-' . hash('sha256', (string) Config::get('cache_key') . "\n" . $identity . "\n" . $payload);
    }

    public static function excluded(string $logicalPath, bool $isDir): bool
    {
        if (!self::$ready) self::fail('ACL was not initialized.');
        $logical = self::relative($logicalPath);
        if ($logical === null) return true;
        if ($logical === '') return false; // application shell/root navigation; children remain filtered.
        if (!self::allowed($logical, $isDir)) return true;

        $canonical = Path::realpath($logicalPath);
        $canonicalRelative = $canonical === false ? null : self::relative($canonical);
        return $canonicalRelative === null || !self::allowed($canonicalRelative, $isDir);
    }

    public static function previewAllowed(string $directory): bool
    {
        $logical = self::relative($directory);
        if ($logical === null || $logical === '') return false;
        if (!acl_can_read(self::$username, $logical, self::$acl)) return false;
        $canonical = Path::realpath($directory);
        $canonicalRelative = $canonical === false ? null : self::relative($canonical);
        return $canonicalRelative !== null && acl_can_read(self::$username, $canonicalRelative, self::$acl);
    }

    public static function expectedMenuHash(): string
    {
        return U::get_menu_hash(Config::$config, Config::$root);
    }

    /** Handles the isolated Phase 3 ACL editor routes. Returns true when served. */
    public static function handleAdmin(): bool
    {
        $action = U::get('action');
        if (!in_array($action, ['acl_admin', 'acl_admin_tree', 'acl_admin_data'], true)) return false;
        if (self::$username !== 'admin') self::adminError('ACL administration requires the admin account.', 403);
        if ($action === 'acl_admin_tree') { self::treeJson((string) ($_GET['path'] ?? '')); return true; }
        if ($action === 'acl_admin_data') { self::aclJson((string) ($_GET['user'] ?? '')); return true; }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') self::saveAdmin();
        self::adminPage();
        return true;
    }

    /** @return list<string> */
    private static function adminUsers(): array
    {
        $root = Config::$storagepath . '/users'; $users = [];
        foreach (@scandir($root) ?: [] as $name) {
            if ($name === 'admin') continue;
            try { if (is_dir($root . '/' . acl_validate_username($name))) $users[] = $name; } catch (Throwable) {}
        }
        natcasesort($users); return array_values($users);
    }

    private static function treeJson(string $path): never
    {
        $relative = acl_normalize_path($path, true);
        if ($relative === null) self::adminError('Invalid folder path.', 400);
        $base = Config::$root . ($relative === '' ? '' : '/' . $relative); $resolved = Path::realpath($base);
        if ($resolved === false || self::relative($resolved) !== $relative) self::adminError('Folder not found.', 404);
        $items = [];
        foreach (@scandir($resolved) ?: [] as $name) {
            $full = $resolved . '/' . $name;
            if ($name === '.' || $name === '..' || is_link($full) || !is_dir($full) || Path::is_exclude($full, true)) continue;
            $items[] = ['name' => $name, 'path' => $relative === '' ? $name : "$relative/$name"];
        }
        usort($items, static fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
        header('content-type: application/json'); exit(json_encode(['items' => $items], JSON_UNESCAPED_UNICODE));
    }

    /** Returns the current, fail-closed ACL state for the selected editable user. */
    private static function aclJson(string $user): never
    {
        try { $user = acl_validate_username($user); } catch (Throwable) { self::adminError('Invalid user.', 400); }
        if ($user === 'admin' || !in_array($user, self::adminUsers(), true)) self::adminError('User not found.', 404);
        $file = Config::$storagepath . "/users/$user/acl.php";
        if (!is_file($file) || is_link($file)) {
            header('content-type: application/json'); exit(json_encode(['allow' => [], 'state' => 'missing']));
        }
        try {
            set_error_handler(static fn() => throw new RuntimeException('Invalid ACL file.'));
            try { $value = include $file; } finally { restore_error_handler(); }
            if (!is_array($value)) throw new RuntimeException('Invalid ACL file.');
            $acl = acl_prepare($value);
            $state = 'valid';
        } catch (Throwable) {
            $acl = ['allow' => []]; $state = 'malformed';
        }
        header('content-type: application/json'); exit(json_encode(['allow' => $acl['allow'], 'state' => $state], JSON_UNESCAPED_UNICODE));
    }

    private static function saveAdmin(): never
    {
        if (!isset($_SESSION['token']) || !is_string($_POST['token'] ?? null) || !hash_equals($_SESSION['token'], $_POST['token'])) self::adminError('Invalid CSRF token.', 403);
        try { $user = acl_validate_username((string) ($_POST['user'] ?? '')); } catch (Throwable) { self::adminError('Invalid user.', 400); }
        if ($user === 'admin' || !in_array($user, self::adminUsers(), true)) self::adminError('User not found.', 404);
        $allow = $_POST['allow'] ?? []; if (!is_array($allow)) self::adminError('Invalid ACL.', 400);
        foreach ($allow as $path) {
            if (!is_string($path) || ($path = acl_normalize_path($path)) === null) self::adminError('Invalid folder path.', 400);
            $full = Config::$root . '/' . $path; $real = Path::realpath($full);
            if ($real === false || self::relative($real) !== $path || !is_dir($real) || is_link($full)) self::adminError('Folder not found.', 400);
        }
        try { $acl = acl_prepare(['allow' => $allow]); } catch (Throwable) { self::adminError('Invalid ACL.', 400); }
        $target = Config::$storagepath . "/users/$user/acl.php"; $tmp = tempnam(dirname($target), '.acl.');
        if ($tmp === false || file_put_contents($tmp, self::formatAcl($acl['allow'])) === false || !@chmod($tmp, 0600) || !@rename($tmp, $target)) { @unlink((string)$tmp); self::adminError('ACL save failed.', 500); }
        if (function_exists('opcache_invalidate')) @opcache_invalidate($target, true);
        header('content-type: application/json'); exit(json_encode(['ok' => true, 'allow' => $acl['allow']], JSON_UNESCAPED_UNICODE));
    }

    /** @param list<string> $allow */
    private static function formatAcl(array $allow): string
    {
        $body = "<?php\nreturn [\n    'allow' => [\n";
        foreach ($allow as $path) $body .= '        ' . var_export($path, true) . ",\n";
        return $body . "    ],\n];\n";
    }

    private static function adminPage(): never
    {
        $users = self::adminUsers(); $token = json_encode((string)($_SESSION['token'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); $opts = '';
        foreach ($users as $u) $opts .= '<option>' . htmlspecialchars($u, ENT_QUOTES) . '</option>';
        header('content-type: text/html; charset=UTF-8');
        exit("<!doctype html><meta charset=utf-8><title>ACL Administration</title><style>body{font:16px sans-serif;max-width:760px;margin:2rem auto}li{margin:.25rem}.state{margin:1rem 0}.hint{color:#555}button{margin:.5rem}.warn{color:#8a3b00}</style><h1>ACL Administration</h1><p>Read visibility only. A checked folder grants recursive read. A ◐ folder is traversal-only, computed from a selected descendant and never stored.</p><p class=hint>ACLs are allow-only: to exclude a child, remove its selected parent and select only the desired branches.</p><label>User <select id=u>$opts</select></label><button id=save>Save permissions</button><p id=status class=state></p><ul id=tree></ul><script>const t=$token,q=document.querySelector.bind(document);let sel=new Set;const esc=s=>s;function ancestor(p){for(const x of sel)if(p!==x&&p.startsWith(x+'/'))return x}function descendant(p){for(const x of sel)if(x.startsWith(p+'/'))return true}function sync(c,p,label){let parent=ancestor(p),exact=sel.has(p);c.checked=exact||!!parent;c.indeterminate=!exact&&!parent&&descendant(p);c.disabled=!!parent;label.textContent=(c.indeterminate?'◐ ':c.checked?'☑ ':'☐ ')+(parent?'inherited: ':'')+p.split('/').pop()}function drawItem(x,el){let l=document.createElement('li'),c=document.createElement('input'),label=document.createElement('span');c.type='checkbox';sync(c,x.path,label);c.onchange=()=>{if(c.checked){for(const v of [...sel])if(v.startsWith(x.path+'/'))sel.delete(v);sel.add(x.path)}else sel.delete(x.path);refresh()};l.append(c,label);let a=document.createElement('button');a.textContent='expand';a.onclick=()=>{let z=document.createElement('ul');l.append(z);load(x.path,z);a.remove()};l.append(a);el.append(l)}function refresh(){for(const x of document.querySelectorAll('#tree input'))sync(x,x.dataset.path,x.nextSibling)}async function load(p='',el=q('#tree')){let r=await fetch('?action=acl_admin_tree&path='+encodeURIComponent(p));if(!r.ok)throw Error(await r.text());let j=await r.json();for(const x of j.items){let before=el.children.length;drawItem(x,el);el.children[before].querySelector('input').dataset.path=x.path}}async function user(){sel=new Set;q('#tree').replaceChildren();let r=await fetch('?action=acl_admin_data&user='+encodeURIComponent(q('#u').value));if(!r.ok){q('#status').textContent=await r.text();return}let j=await r.json();sel=new Set(j.allow);q('#status').textContent=j.state==='malformed'?'Warning: the existing ACL is malformed. It grants no access until you explicitly save a replacement.':j.state==='missing'?'No ACL file: this user currently has no read access.':'Loaded canonical ACL.';q('#status').className=j.state==='malformed'?'state warn':'state';await load()}q('#u').onchange=user;q('#save').onclick=async()=>{let f=new FormData();f.append('token',t);f.append('user',q('#u').value);for(const x of sel)f.append('allow[]',x);let r=await fetch('?action=acl_admin',{method:'POST',body:f});let j;try{j=await r.json()}catch{q('#status').textContent=await r.text();return}if(!r.ok){q('#status').textContent='Save failed';return}sel=new Set(j.allow);q('#status').textContent='Saved. ACL reloaded from disk.';await user()};if(q('#u').value)user();else q('#status').textContent='No editable users.';</script>");
    }

    private static function adminError(string $message, int $code): never { http_response_code($code); exit(htmlspecialchars($message, ENT_QUOTES)); }

    /** @return string|null */
    private static function relative(string $path): ?string
    {
        $root = rtrim(Config::$root, '/');
        if ($path !== $root && !str_starts_with($path, $root . '/')) return null;
        return acl_normalize_path(ltrim(substr($path, strlen($root)), '/'), true);
    }

    private static function allowed(string $relative, bool $isDir): bool
    {
        return $isDir
            ? acl_can_traverse(self::$username, $relative, self::$acl)
            : acl_can_read(self::$username, $relative, self::$acl);
    }

    private static function fail(string $message): never
    {
        error_log('Files Gallery ACL: ' . $message);
        if (class_exists('U')) U::error($message, 500);
        throw new RuntimeException($message);
    }
}
