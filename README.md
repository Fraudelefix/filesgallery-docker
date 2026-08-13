# Files Gallery local — Synology DS920+ / DSM 7

Cette stack construit Files Gallery à partir de son `index.php` officiel, pas d'une image applicative tierce. Elle adopte le modèle *filesystem-first* : le NAS reste la source de vérité et `/media` est monté en lecture seule, hors du `DocumentRoot`.

## Décision d'architecture

```text
Navigateur → reverse proxy HTTPS → Apache :80 (master root)
                                      └─ workers PHP www-data = PUID:PGID
                                               ├─ /media  (NAS, ro)
                                               └─ /config (NAS, rw)
```

`php:8.3-apache` est le meilleur compromis ici : un seul conteneur, peu de RAM et Apache sait démarrer root puis abaisser les workers. L'entrypoint remappe `www-data` vers `PUID:PGID` (par défaut `1030:100`) avant ce démarrage. PHP possède alors l'UID propriétaire du dossier Synology et peut lister un dossier `drwx--x--x 1030:100`; le master Apache conserve les privilèges nécessaires au port 80. Cela résout le problème sans le dangereux `user: "1030:100"`, qui empêche Apache de s'initialiser.

Les groupes supplémentaires ne suffisent pas lorsque la lecture du répertoire n'est accordée qu'au propriétaire. Nginx + PHP-FPM ajoute un conteneur sans résoudre cette identité. `gosu` est inutile : Apache effectue déjà l'abaissement de privilèges correctement.

## Organisation et persistance

```text
/var/www/html/index.php            application dans l'image
/var/www/html/_filesconfig.php     bootstrap dans l'image
/media                             /volume1/homes/Victor/Numerisation:ro
/config                            /volume2/docker/filesgallery/config
  ├─ config/config.php             réglages persistants
  ├─ users/<nom>/config.php        utilisateurs persistants
  └─ cache/                        miniatures et cache
```

`storage_path` est forcément placé dans `_filesconfig.php` : Files Gallery résout ce chemin avant de lire `config.php`. Le mettre seulement dans la configuration principale ne fonctionne pas. `/config` et `/media` sont hors de la racine Web, donc Apache ne peut pas les exposer par URL.

## Source, version et maintenance

La stratégie retenue est de versionner localement le fichier officiel (option A). Téléchargez la version épinglée dans `app/README.md`, calculez sa somme SHA-256 et notez-la dans `app/VERSION`. Le Dockerfile ne télécharge rien : les builds sont reproductibles. N'utilisez jamais `latest`.

Pour mettre à jour : remplacez `app/index.php` par la nouvelle version officielle, changez le tag dans `app/VERSION` et `compose.yaml`, rebâtissez, puis conservez l'ancienne image (par exemple `filesgallery-local:0.13.0`) jusqu'à validation. `/config` survit à la recréation. Pour passer à PHP 8.4, remplacez explicitement l'image de base, rebuild et faites les mêmes tests. ImageMagick/FFmpeg sont actualisés lors du rebuild de la base.

## Dépendances et performances

GD, EXIF, mbstring et ZipArchive sont installés. ImageMagick + Imagick génèrent les aperçus TIFF/HEIC/PSD/DNG lorsqu'un délégué Debian le supporte; FFmpeg génère les miniatures vidéo. Ghostscript est optionnel (PDF/PS) et augmente légèrement la surface d'attaque : supprimez-le si inutile. JPEG/PNG/GIF/WebP sont couverts; TIFF est le flux attendu : original lu → miniature dans `/config/cache` → navigateur. HEIC, PSD et DNG doivent être validés sur des fichiers réels, car ils dépendent des délégués compilés par Debian.

Le point de départ est `memory_limit=512M`, 120 s. Augmentez à 768M uniquement si un TIFF réel échoue. Commencez sans limite Docker stricte, puis activez `mem_limit: 1g` et `cpus: 2.0` après observation. L'iGPU du DS920+ n'est pas utile aux conversions TIFF ImageMagick CPU.

## Sécurité

* `:ro` est la dernière barrière : même un bug applicatif ne peut écrire les originaux.
* `load_files_proxy_php=true` oblige les médias à transiter par Files Gallery et sa session; une URL Apache directe vers `/media` est impossible.
* Toute écriture, ZIP serveur, modification et tâches sont désactivés; téléchargement individuel seulement.
* `allow_symlinks=false`, `no-new-privileges`, fichiers cachés exclus et headers HTTP simples sont activés.
* Remplacez le mot de passe modèle avant le premier lancement. HSTS doit être configuré au reverse proxy HTTPS, jamais sur le port HTTP 8083.
* ImageMagick/FFmpeg analysent du contenu non fiable : maintenir l'image reconstruite et ne pas exposer directement 8083 à Internet.

## Utilisateurs et restrictions

Les règles sont appliquées côté serveur par Files Gallery, mais elles ne remplacent pas des ACL NAS pour une isolation forte. Pour des utilisateurs non fiables, préférez des bind mounts/sous-arbres ou ACL NAS séparés. Les utilisateurs héritent de la configuration globale; créez-les dans l'interface du compte administrateur, puis contrôlez `/config/users/<nom>/config.php`.

Paul — seulement `Famille` :

```php
<?php
return [
    'password' => 'MOT-DE-PASSE-ROBUSTE',
    'dirs_include' => '/^Famille(\\/|$)/',
    'allow_settings' => false,
];
```

Parents — tout sauf `Privé` :

```php
<?php
return [
    'password' => 'MOT-DE-PASSE-ROBUSTE',
    'dirs_exclude' => '/^Privé(\\/|$)/u',
    'allow_settings' => false,
];
```

Ce sont des regex PHP sur les chemins relatifs à `/media`; testez systématiquement les deeplinks. Un changement de mot de passe depuis l'interface permet à Files Gallery de le chiffrer dans le fichier.

## Déploiement Container Manager / Portainer

1. Copiez ce projet vers `/volume2/docker/filesgallery-src` et créez `/volume2/docker/filesgallery/config`.
2. Téléchargez `app/index.php` comme indiqué dans `app/README.md`. Remplacez `CHANGE-ME-BEFORE-FIRST-START` dans `docker/config.php` avant le premier build.
3. Dans Container Manager, créez un projet à partir de `compose.yaml` (ou Portainer → Stacks). Le contexte de build doit inclure `Dockerfile`, `app/` et `docker/`.
4. Déployez et ouvrez `http://IP_DU_NAS:8083/`. Connectez-vous avec `victor-admin`, créez les comptes, puis utilisez le reverse proxy DSM HTTPS pour un accès externe.
5. Après l'initialisation, modifiez `/volume2/docker/filesgallery/config/config.php`, pas le modèle `docker/config.php`; redémarrez après modification.

Sauvegardez le répertoire `/volume2/docker/filesgallery/config` et ce projet (dont `app/index.php`). `cache/` est recréable, mais `config/` et `users/` ne le sont pas. Un rollback consiste à sélectionner le tag d'image précédent et recréer le conteneur sans toucher au volume.

## Tests de validation

```sh
docker compose ps
docker logs filesgallery
curl -I http://127.0.0.1:8083/
docker exec filesgallery id www-data
docker exec filesgallery sh -c 'su -s /bin/sh www-data -c "ls -la /media"'
docker exec filesgallery sh -c 'touch /media/__must_fail__' # doit échouer
docker exec filesgallery php -m | grep -E 'exif|gd|imagick|mbstring|zip'
docker exec filesgallery sh -c 'convert -version; identify /media/chemin/test.tif'
docker exec filesgallery sh -c 'ffmpeg -hide_banner -version'
docker exec filesgallery sh -c 'test -f /config/config/config.php && echo persistent-config-ok'
curl -I http://127.0.0.1:8083/_filesconfig.php # doit renvoyer 403
curl -I http://127.0.0.1:8083/media/            # doit renvoyer 404
```

Connectez-vous enfin comme Paul et essayez directement un deeplink vers `Vacances` ou `Privé`; il doit être refusé/invisible. Testez un TIFF, un HEIC et une vidéo réels, puis vérifiez qu'une miniature apparaît dans `/config/cache` sans modification de l'original.

## Références officielles

La documentation officielle confirme que `storage_path` externe requiert `_filesconfig.php`, que les règles `dirs_include`/`dirs_exclude` sont évaluées côté application et que les utilisateurs héritent de la configuration globale : [storage](https://www.files.gallery/docs/storage/), [configuration](https://www.files.gallery/docs/config/), [utilisateurs](https://www.files.gallery/docs/users/), [sécurité](https://www.files.gallery/docs/security/).
