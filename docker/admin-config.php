<?php
// Modèle du seul compte d'administration. L'entrypoint remplace les marqueurs
// par le nom validé et un hash password_hash() au premier démarrage.
return [
    'password' => '__FILES_GALLERY_ADMIN_PASSWORD_HASH__',
    'allow_settings' => true,
];
