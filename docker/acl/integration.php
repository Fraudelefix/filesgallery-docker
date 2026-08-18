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
        $actions = ['admin', 'admin_users', 'admin_acl', 'admin_acl_tree', 'admin_acl_data', 'admin_user',
            'admin_user_create', 'admin_user_password', 'admin_user_delete', 'admin_user_clone',
            'admin_config', 'admin_config_validate', 'admin_config_save', 'admin_config_guided'];
        if (!in_array($action, $actions, true)) return false;
        if (self::$username !== 'admin') self::adminError('Docker administration requires the admin account.', 403);
        if ($action === 'admin') { self::adminHome(); return true; }
        if ($action === 'admin_acl_tree') { self::treeJson((string) ($_GET['path'] ?? '')); return true; }
        if ($action === 'admin_acl_data') { self::aclJson((string) ($_GET['user'] ?? '')); return true; }
        if ($action === 'admin_user') { self::userJson((string) ($_GET['user'] ?? '')); return true; }
        if ($action === 'admin_config') { self::configJson((string) ($_GET['user'] ?? '')); return true; }
        if ($action === 'admin_config_validate') { self::validateConfig(); return true; }
        if ($action === 'admin_config_save') { self::saveConfig(); return true; }
        if ($action === 'admin_config_guided') { self::saveGuidedConfig(); return true; }
        if ($action === 'admin_user_create') { self::createUser(); return true; }
        if ($action === 'admin_user_password') { self::changePassword(); return true; }
        if ($action === 'admin_user_delete') { self::deleteUser(); return true; }
        if ($action === 'admin_user_clone') { self::cloneUser(); return true; }
        if ($action === 'admin_users') { self::usersPage(); return true; }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') self::saveAdmin();
        self::adminPage();
        return true;
    }

    /** @return list<string> */
    private static function adminUsers(): array
    {
        return fg_list_users(Config::$storagepath . '/users', false);
    }

    private static function csrf(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SESSION['token']) || !is_string($_POST['token'] ?? null)
            || !hash_equals($_SESSION['token'], $_POST['token'])) self::adminError('Invalid CSRF token.', 403);
    }

    private static function editableUser(string $user): string
    {
        try { $user = acl_validate_username($user); } catch (Throwable) { self::adminError('Invalid user.', 400); }
        if (acl_user_dir($user, Config::$storagepath . '/users') === null) self::adminError('User not found.', 404);
        return $user;
    }

    private static function json(array $value): never
    {
        header('content-type: application/json'); exit(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
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
        $state = acl_editor_state($user, Config::$storagepath . '/users');
        header('content-type: application/json'); exit(json_encode($state, JSON_UNESCAPED_UNICODE));
    }

    private static function userJson(string $user): never
    {
        $user = self::editableUser($user); $root = Config::$storagepath . '/users';
        $config = fg_user_config_state($user, $root); $acl = acl_editor_state($user, $root);
        self::json(['username' => $user, 'config' => $config['state'], 'acl' => $user === 'admin' ? 'admin' : $acl['state']]);
    }

    private static function configJson(string $user): never
    {
        $user = self::editableUser($user);
        $state = fg_user_config_state($user, Config::$storagepath . '/users');
        if ($state['state'] !== 'valid') self::adminError('Config not available.', 404);
        self::json(['username' => $user, 'content' => $state['content'], 'config' => $state['config']]);
    }

    private static function validateConfig(): never
    {
        self::csrf(); self::editableUser((string) ($_POST['user'] ?? ''));
        try { $config = fg_validate_config_content((string) ($_POST['content'] ?? '')); }
        catch (Throwable) { self::adminError('Invalid PHP configuration.', 400); }
        self::json(['ok' => true, 'keys' => array_keys($config)]);
    }

    private static function saveConfig(): never
    {
        self::csrf(); $user = self::editableUser((string) ($_POST['user'] ?? ''));
        $content = (string) ($_POST['content'] ?? '');
        try { fg_atomic_save_config_content($user, Config::$storagepath . '/users', $content); }
        catch (InvalidArgumentException) { self::adminError('Invalid PHP configuration.', 400); }
        catch (Throwable) { self::adminError('Configuration save failed.', 500); }
        self::json(['ok' => true]);
    }

    /** Saves the small guided form through the same validated atomic helper as Advanced. */
    private static function saveGuidedConfig(): never
    {
        self::csrf(); $user = self::editableUser((string) ($_POST['user'] ?? ''));
        $lang = (string) ($_POST['lang_default'] ?? '');
        if (preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', $lang) !== 1) self::adminError('Invalid language.', 400);
        try {
            $state = fg_user_config_state($user, Config::$storagepath . '/users');
            if ($state['state'] !== 'valid') self::adminError('Config not available.', 404);
            $config = $state['config']; $config['lang_default'] = $lang;
            fg_atomic_save_config($user, Config::$storagepath . '/users', $config);
        } catch (Throwable) { self::adminError('Guided configuration save failed.', 500); }
        self::json(['ok' => true, 'lang_default' => $lang]);
    }

    private static function createUser(): never
    {
        self::csrf();
        $username = (string) ($_POST['username'] ?? ''); $password = (string) ($_POST['password'] ?? '');
        if (!hash_equals($password, (string) ($_POST['password_confirm'] ?? ''))) self::adminError('Passwords do not match.', 400);
        $source = (string) ($_POST['copy_from'] ?? ''); $copyConfig = null; $copyAcl = null; $root = Config::$storagepath . '/users';
        try {
            if ($source !== '') {
                $source = self::editableUser($source); $sourceConfig = fg_user_config_state($source, $root);
                if ($sourceConfig['state'] !== 'valid') self::adminError('Source config is invalid.', 400);
                $copyConfig = $sourceConfig['config'];
                if ((string) ($_POST['copy_acl'] ?? '') === '1' && $source !== 'admin') $copyAcl = acl_editor_state($source, $root)['allow'];
            }
            fg_create_user($username, $password, $root, $copyConfig, $copyAcl);
        } catch (InvalidArgumentException) { self::adminError('Invalid user or password.', 400); }
        catch (Throwable) { self::adminError('User creation failed.', 400); }
        self::json(['ok' => true]);
    }

    private static function changePassword(): never
    {
        self::csrf(); $user = self::editableUser((string) ($_POST['user'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        if (!hash_equals($password, (string) ($_POST['password_confirm'] ?? ''))) self::adminError('Passwords do not match.', 400);
        try {
            $state = fg_user_config_state($user, Config::$storagepath . '/users');
            if ($state['state'] !== 'valid') self::adminError('Config not available.', 404);
            $config = $state['config']; $config['password'] = fg_password_hash($password);
            fg_atomic_save_config($user, Config::$storagepath . '/users', $config);
        } catch (InvalidArgumentException) { self::adminError('Invalid password.', 400); }
        catch (Throwable) { self::adminError('Password update failed.', 500); }
        self::json(['ok' => true]);
    }

    private static function cloneUser(): never
    {
        self::csrf(); $source = self::editableUser((string) ($_POST['source'] ?? ''));
        $username = (string) ($_POST['username'] ?? ''); $password = (string) ($_POST['password'] ?? '');
        if (!hash_equals($password, (string) ($_POST['password_confirm'] ?? ''))) self::adminError('Passwords do not match.', 400);
        $root = Config::$storagepath . '/users'; $state = fg_user_config_state($source, $root);
        if ($state['state'] !== 'valid') self::adminError('Source config is invalid.', 400);
        try {
            $copyAcl = ((string) ($_POST['copy_acl'] ?? '') === '1' && $source !== 'admin') ? acl_editor_state($source, $root)['allow'] : null;
            fg_create_user($username, $password, $root, $state['config'], $copyAcl);
        } catch (Throwable) { self::adminError('User duplication failed.', 400); }
        self::json(['ok' => true]);
    }

    private static function deleteUser(): never
    {
        self::csrf(); $user = (string) ($_POST['user'] ?? '');
        try { fg_delete_user($user, Config::$storagepath . '/users'); }
        catch (InvalidArgumentException) { self::adminError('The admin account cannot be deleted.', 400); }
        catch (Throwable) { self::adminError('User deletion failed.', 404); }
        self::json(['ok' => true]);
    }

    private static function adminHeader(string $section): string
    {
        return '<h1>Administration (Docker version)</h1><nav><a href="/?action=admin_users">Users</a> · '
            . '<a href="/?action=admin_acl">ACL</a> · <a href="/?action=tests">Tests</a></nav>'
            . '<p><a href="/">Back to gallery</a></p>'
            . ($section === '' ? '' : '<h2>' . htmlspecialchars($section, ENT_QUOTES) . '</h2>');
    }

    private static function adminHome(): never
    {
        header('content-type: text/html; charset=UTF-8');
        exit('<!doctype html><meta charset="utf-8"><title>Administration (Docker version)</title>'
            . '<style>body{font:16px sans-serif;max-width:760px;margin:2rem auto}.cards{display:flex;gap:1rem;flex-wrap:wrap}.card{border:1px solid #ccc;padding:1rem;min-width:14rem;text-decoration:none;color:inherit}</style>'
            . self::adminHeader('') . '<div class="cards"><a class="card" href="/?action=admin_users"><strong>Users</strong><br>Manage Files Gallery users</a>'
            . '<a class="card" href="/?action=admin_acl"><strong>ACL</strong><br>Manage folder permissions</a></div>');
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
        try { $userDir = acl_user_dir($user, Config::$storagepath . '/users'); } catch (Throwable) { $userDir = null; }
        if ($userDir === null) self::adminError('User not found.', 404);
        $target = $userDir . '/acl.php'; $tmp = tempnam($userDir, '.acl.');
        if ($tmp === false || file_put_contents($tmp, acl_format_file($acl['allow'])) === false || !@chmod($tmp, 0600) || !@rename($tmp, $target)) { @unlink((string)$tmp); self::adminError('ACL save failed.', 500); }
        if (function_exists('opcache_invalidate')) @opcache_invalidate($target, true);
        header('content-type: application/json'); exit(json_encode(['ok' => true, 'allow' => $acl['allow']], JSON_UNESCAPED_UNICODE));
    }

    private static function usersPage(): never
    {
        $root = Config::$storagepath . '/users'; $rows = ''; $options = '<option value="">none</option>';
        foreach (fg_list_users($root) as $user) {
            $config = fg_user_config_state($user, $root)['state'];
            $acl = $user === 'admin' ? 'admin' : acl_editor_state($user, $root)['state'];
            $safe = htmlspecialchars($user, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $rows .= "<tr><td>$safe</td><td>$config</td><td>$acl</td><td><button data-user=\"$safe\">Manage</button>" . ($user === 'admin' ? '' : " <a href=\"?action=admin_acl&amp;user=" . rawurlencode($user) . "\">ACL</a>") . '</td></tr>';
            $options .= "<option value=\"$safe\">$safe</option>";
        }
        $token = htmlspecialchars((string) ($_SESSION['token'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        header('content-type: text/html; charset=UTF-8');
        exit(strtr(<<<'HTML'
<!doctype html><meta charset="utf-8"><title>User administration</title>
<style>body{font:16px sans-serif;max-width:960px;margin:2rem auto}table{border-collapse:collapse;width:100%}th,td{border:1px solid #ccc;padding:.45rem;text-align:left}fieldset{margin:1rem 0}textarea{width:100%;min-height:22rem;font:13px monospace}.row{display:flex;gap:1rem;flex-wrap:wrap}.row>*{flex:1}button{margin:.3rem}.status{min-height:1.3rem;color:#174e17}.error{color:#992000}</style>
__HEADER__
<h2>Users</h2><table><thead><tr><th>User</th><th>config.php</th><th>ACL</th><th>Actions</th></tr></thead><tbody>__ROWS__</tbody></table>
<fieldset><legend>Create user</legend><div class="row"><label>Username <input id="new-name"></label><label>Password <input id="new-pass" type="password"></label><label>Confirm <input id="new-confirm" type="password"></label></div><label>Copy settings from <select id="new-copy">__OPTIONS__</select></label> <label><input id="new-acl" type="checkbox"> Copy ACL</label><button id="create">Create user</button></fieldset>
<fieldset id="editor" hidden><legend>User: <span id="selected"></span></legend><p><button id="load">Reload</button> <a id="acl-link" href="?action=acl_admin">Edit ACL</a></p><div class="row"><label>New password <input id="pass" type="password"></label><label>Confirm <input id="confirm" type="password"></label><button id="password">Change password</button><button id="delete">Delete user</button></div><div class="row"><label>Duplicate as <input id="clone-name"></label><label>Password <input id="clone-pass" type="password"></label><label>Confirm <input id="clone-confirm" type="password"></label><label><input id="clone-acl" type="checkbox"> Copy ACL</label><button id="clone">Duplicate</button></div><h3>Guided configuration</h3><label>Language <select id="lang"><option value="fr">French</option><option value="en">English</option><option value="de">German</option><option value="es">Spanish</option><option value="it">Italian</option></select></label> <button id="save-lang">Save language</button><h3>Advanced</h3><p>Advanced config.php editor. Only a PHP file returning a literal array can be saved. The previous valid version is retained once as <code>config.php.previous</code>.</p><textarea id="content" spellcheck="false"></textarea><p><button id="validate">Validate</button><button id="save">Save configuration</button></p></fieldset><p id="status" class="status"></p>
<script>
const token='__TOKEN__',q=s=>document.querySelector(s);let current='';
async function call(action,data){let f=new FormData;f.append('token',token);for(const [k,v] of Object.entries(data))f.append(k,v);let r=await fetch('?action='+action,{method:'POST',body:f}),t=await r.text(),j;try{j=JSON.parse(t)}catch{throw Error(t||'Request failed')};if(!r.ok||!j.ok)throw Error(j.error||'Request failed');return j}
function msg(v,bad=false){q('#status').textContent=v;q('#status').className=bad?'status error':'status'}
async function choose(user){current=user;q('#selected').textContent=user;q('#editor').hidden=false;q('#acl-link').href='?action=admin_acl&user='+encodeURIComponent(user);await load()}
async function load(){try{let r=await fetch('?action=admin_config&user='+encodeURIComponent(current)),t=await r.text();if(!r.ok)throw Error(t);let j=JSON.parse(t);q('#content').value=j.content;if(j.config.lang_default&&q('#lang').querySelector('[value="'+j.config.lang_default+'"]'))q('#lang').value=j.config.lang_default;msg('Configuration loaded.')}catch(e){msg(e.message,true)}}
document.querySelectorAll('[data-user]').forEach(b=>b.onclick=()=>choose(b.dataset.user));q('#load').onclick=load;
q('#create').onclick=async()=>{try{await call('admin_user_create',{username:q('#new-name').value,password:q('#new-pass').value,password_confirm:q('#new-confirm').value,copy_from:q('#new-copy').value,copy_acl:q('#new-acl').checked?'1':''});location.reload()}catch(e){msg(e.message,true)}};
q('#password').onclick=async()=>{try{await call('admin_user_password',{user:current,password:q('#pass').value,password_confirm:q('#confirm').value});msg('Password updated.')}catch(e){msg(e.message,true)}};
q('#clone').onclick=async()=>{try{await call('admin_user_clone',{source:current,username:q('#clone-name').value,password:q('#clone-pass').value,password_confirm:q('#clone-confirm').value,copy_acl:q('#clone-acl').checked?'1':''});location.reload()}catch(e){msg(e.message,true)}};
q('#save-lang').onclick=async()=>{try{await call('admin_config_guided',{user:current,lang_default:q('#lang').value});msg('Language saved atomically.');await load()}catch(e){msg(e.message,true)}};
q('#validate').onclick=async()=>{try{let j=await call('admin_config_validate',{user:current,content:q('#content').value});msg('Valid configuration ('+j.keys.length+' keys).')}catch(e){msg(e.message,true)}};
q('#save').onclick=async()=>{try{await call('admin_config_save',{user:current,content:q('#content').value});msg('Configuration saved atomically.')}catch(e){msg(e.message,true)}};
q('#delete').onclick=async()=>{if(!confirm('Delete '+current+'?'))return;try{await call('admin_user_delete',{user:current});location.reload()}catch(e){msg(e.message,true)}};
</script>
HTML, ['__HEADER__' => self::adminHeader('Users'), '__ROWS__' => $rows, '__OPTIONS__' => $options, '__TOKEN__' => $token,
            '<title>User administration</title>' => '<title>Administration (Docker version)</title>', '?action=acl_admin' => '?action=admin_acl']));
    }

    private static function adminPage(): never
    {
        $users = self::adminUsers(); $token = json_encode((string)($_SESSION['token'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); $opts = ''; $header = self::adminHeader('ACL');
        $selected = (string) ($_GET['user'] ?? '');
        foreach ($users as $u) $opts .= '<option' . ($u === $selected ? ' selected' : '') . '>' . htmlspecialchars($u, ENT_QUOTES) . '</option>';
        header('content-type: text/html; charset=UTF-8');
        ob_start(static fn(string $html): string => strtr($html, [
            '<title>ACL Administration</title>' => '<title>Administration (Docker version)</title>',
            "<h1>ACL Administration</h1><p><a href='?action=acl_admin_users'>Users</a> · ACL</p>" => $header,
            '?action=acl_admin_tree' => '?action=admin_acl_tree', '?action=acl_admin_data' => '?action=admin_acl_data',
            '?action=acl_admin' => '?action=admin_acl',
        ]));
        exit("<!doctype html><meta charset=utf-8><title>ACL Administration</title><style>body{font:16px sans-serif;max-width:760px;margin:2rem auto}li{margin:.25rem}.state{margin:1rem 0}.hint{color:#555}button{margin:.5rem}.warn{color:#8a3b00}</style><h1>ACL Administration</h1><p><a href='?action=acl_admin_users'>Users</a> · ACL</p><p>Read visibility only. A checked folder grants recursive read. A ◐ folder is traversal-only, computed from a selected descendant and never stored.</p><p class=hint>ACLs are allow-only: to exclude a child, remove its selected parent and select only the desired branches.</p><label>User <select id=u>$opts</select></label><button id=save>Save permissions</button><p id=status class=state></p><ul id=tree></ul><script>const t=$token,q=document.querySelector.bind(document);let sel=new Set;function ancestor(p){for(const x of sel)if(p!==x&&p.startsWith(x+'/'))return x}function descendant(p){for(const x of sel)if(x.startsWith(p+'/'))return true}function sync(c,p,label){let parent=ancestor(p),exact=sel.has(p);c.checked=exact||!!parent;c.indeterminate=!exact&&!parent&&descendant(p);c.disabled=!!parent;label.textContent=(c.indeterminate?'◐ ':c.checked?'☑ ':'☐ ')+(parent?'inherited: ':'')+p.split('/').pop()}function drawItem(x,el){let l=document.createElement('li'),c=document.createElement('input'),label=document.createElement('span');c.type='checkbox';sync(c,x.path,label);c.onchange=()=>{if(c.checked){for(const v of [...sel])if(v.startsWith(x.path+'/'))sel.delete(v);sel.add(x.path)}else sel.delete(x.path);refresh()};l.append(c,label);let a=document.createElement('button');a.textContent='expand';a.onclick=()=>{let z=document.createElement('ul');l.append(z);load(x.path,z);a.remove()};l.append(a);el.append(l)}function refresh(){for(const x of document.querySelectorAll('#tree input'))sync(x,x.dataset.path,x.nextSibling)}async function load(p='',el=q('#tree')){let r=await fetch('?action=acl_admin_tree&path='+encodeURIComponent(p)),text=await r.text();if(!r.ok)throw Error(text||'Tree load failed.');let j=JSON.parse(text);for(const x of j.items){let before=el.children.length;drawItem(x,el);el.children[before].querySelector('input').dataset.path=x.path}}async function user(){sel=new Set;q('#tree').replaceChildren();let r=await fetch('?action=acl_admin_data&user='+encodeURIComponent(q('#u').value)),text=await r.text();if(!r.ok){q('#status').textContent=text||'Unable to load ACL.';return}let j=JSON.parse(text);sel=new Set(j.allow);q('#status').textContent=j.state==='malformed'?'Warning: the existing ACL is malformed. It grants no access until you explicitly save a replacement.':j.state==='missing'?'No ACL file: this user currently has no read access.':'Loaded canonical ACL.';q('#status').className=j.state==='malformed'?'state warn':'state';await load()}q('#u').onchange=user;q('#save').onclick=async()=>{let f=new FormData();f.append('token',t);f.append('user',q('#u').value);for(const x of sel)f.append('allow[]',x);let r=await fetch('?action=acl_admin',{method:'POST',body:f}),text=await r.text(),j;try{j=JSON.parse(text)}catch{q('#status').textContent=text||'Save failed.';return}if(!r.ok||!j.ok){q('#status').textContent=j.error||'Save failed.';return}sel=new Set(j.allow);q('#status').textContent='Saved. ACL reloaded from disk.';await user()};if(q('#u').value)user();else q('#status').textContent='No editable users.';</script>");
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
