<?php
// Chargé avant /config/config/config.php : storage_path doit être connu ici.
return [
    'storage_path' => '/config',
    'root' => '/media',
    'root_lock' => '/media',
    'settings_editor' => [
        'template' => <<<'PHP'
<?php

// CONFIG / https://www.files.gallery/docs/config/
return [
  'password' => '$PASSWORD',
  'files_exclude' => '/(^|\/)(\.|Thumbs\.db$|desktop\.ini$|~\$)/i',
  'dirs_exclude' => '/(^|\/)(@eaDir|[.][^\/]*|__MACOSX|\$RECYCLE\.BIN)(\/|$)/i',
];
PHP,
    ],
];
