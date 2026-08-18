<?php
declare(strict_types=1);

if ($argc !== 3) throw new RuntimeException('Usage: patch.php pristine.php runtime.php');
$source = file_get_contents($argv[1]);
if (!is_string($source)) throw new RuntimeException('Cannot read pristine source.');

$replacements = [
    "new Config();\n\n// process actions ?action=" => "new Config();\nrequire_once '/usr/local/share/filesgallery/acl/integration.php';\nFilesGalleryAclIntegration::init(); // FILESGALLERY_ACL_INIT_V1\nif(FilesGalleryAclIntegration::handleAdmin()) exit; // FILESGALLERY_ACL_ADMIN_V1\n\n// process actions ?action=",
    "    // check files_include and files_exclude\n    if(!\$is_dir){" => "    if(FilesGalleryAclIntegration::excluded(\$path, \$is_dir)) return true; // FILESGALLERY_ACL_FILTER_V1\n\n    // check files_include and files_exclude\n    if(!\$is_dir){",
    "    // assign cache file when cache is enabled / check if file exists, or write to this file when re-creating\n    \$this->cache_file" => "    if(\$hash !== FilesGalleryAclIntegration::expectedMenuHash()) \$hash = FilesGalleryAclIntegration::expectedMenuHash(); // FILESGALLERY_ACL_MENU_V1\n\n    // assign cache file when cache is enabled / check if file exists, or write to this file when re-creating\n    \$this->cache_file",
    "  } else if(\$action === 'preview'){\n\n    // allow folder preview image" => "  } else if(\$action === 'preview'){\n\n    if(!FilesGalleryAclIntegration::previewAllowed(\$dir)) \$request->error('ACL preview access denied', 403); // FILESGALLERY_ACL_PREVIEW_V1\n\n    // allow folder preview image",
    "U::uinclude('js/custom.js');" => "U::uinclude('js/custom.js');\nif(Login::\$is_logged_in && Config::get('username') === 'admin') echo '<script src=\"/docker-admin/admin.js\" defer></script>'; // FILESGALLERY_ACL_ADMIN_ASSET_V1",
];
foreach ($replacements as $needle => $replacement) {
    if (substr_count($source, $needle) !== 1) throw new RuntimeException('Expected upstream patch anchor not found exactly once.');
    $source = str_replace($needle, $replacement, $source, $count);
    if ($count !== 1) throw new RuntimeException('Patch replacement failed.');
}
foreach (['FILESGALLERY_ACL_INIT_V1', 'FILESGALLERY_ACL_ADMIN_V1', 'FILESGALLERY_ACL_FILTER_V1', 'FILESGALLERY_ACL_MENU_V1', 'FILESGALLERY_ACL_PREVIEW_V1', 'FILESGALLERY_ACL_ADMIN_ASSET_V1'] as $marker) {
    if (substr_count($source, $marker) !== 1) throw new RuntimeException('Patched marker count is invalid.');
}
if (file_put_contents($argv[2], $source) === false) throw new RuntimeException('Cannot write patched runtime source.');
