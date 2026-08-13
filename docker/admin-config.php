<?php
// Modèle du seul compte d'administration fixe "admin".
// L'entrypoint remplace le marqueur de mot de passe par un hash password_hash().
return [
    'password' => '__FILES_GALLERY_ADMIN_PASSWORD_HASH__',
    'allow_settings' => true,
];
