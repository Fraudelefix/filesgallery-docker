# Revue et stack Files Gallery — Synology DS920+ / DSM 7

Cette image exécute le fichier officiel Files Gallery **0.15.3** sur un unique
conteneur Apache/PHP. `/media` reste la source de vérité et est monté en lecture
seule; `/config` contient toutes les données applicatives persistantes.

## 1. Résumé exécutif

L'architecture Apache mono-conteneur est adaptée au DS920+ et a été conservée.
La revue a identifié trois corrections importantes, désormais appliquées :

1. **CRITIQUE — Files Gallery 0.13.0 ne connaissait pas plusieurs options
   configurées**, notamment `root_lock`, ImageMagick pour les images, et les
   noms actuels des options. L'application est mise à niveau vers la version
   officielle 0.15.3, dont le code implémente ces options.
2. **CRITIQUE — `allow_settings=true` dans la configuration globale était
   hérité par chaque nouvel utilisateur.** Tous les utilisateurs pouvaient donc
   administrer les comptes et les réglages. L'administrateur est maintenant un
   utilisateur distinct, seul à recevoir `allow_settings=true`; le profil par
   défaut obligatoire est inutilisable et verrouille l'accès anonyme.
3. **IMPORTANT — le mot de passe modèle était public dans Git.** La source de
   vérité est désormais `FILES_GALLERY_ADMIN_PASSWORD` dans `compose.yaml`;
   seul son hash `password_hash()` est persisté dans `/config`.

La révision corrige aussi la version réelle de Files Gallery, vérifie son
SHA-256 pendant le build, épingle Imagick, retire `mod_rewrite` inutilisé et
évite le `chown -R /config` à chaque redémarrage.

## 2. Architecture actuelle

```text
Navigateur ─ HTTPS/reverse proxy ─ Apache :80 (master root)
                                       │
                                       └─ mod_php / workers www-data = 1030:100
                                             ├─ /media  ← NAS :ro
                                             └─ /config ← NAS :rw
```

`php:8.3.33-apache-bookworm` est une image officielle qui utilise Apache avec
`mod_php` et MPM prefork. Apache est initialement lancé par root, écoute :80,
puis lance les workers sous l'utilisateur défini par `APACHE_RUN_USER`
(`www-data`). L'entrypoint change l'identité Unix de **www-data seulement**
vers `PUID:PGID`; il ne faut surtout pas définir `user: "1030:100"` dans
Compose. PHP s'exécute donc bien en `1030:100`, tandis que le master reste
capable d'ouvrir le port et les logs.

Avec la permission Synology décrite (`drwx--x--x 1030:100`), cette identité
propriétaire donne à PHP les droits de lecture et de liste requis. Le bind mount
`:ro` interdit les modifications, même à cet UID.

## 3. Ce qui est correct

* `/media` est hors de `/var/www/html`: Apache ne peut pas le servir par URL.
* `storage_path=/config` dans `_filesconfig.php` est le bon emplacement : Files
  Gallery doit le résoudre **avant** de charger `/config/config/config.php`.
* La structure effective est bien `/config/config`, `/config/cache` et
  `/config/users/<nom>/config.php`; elle persiste à la recréation.
* `load_files_proxy_php=true`, combiné à une racine hors DocumentRoot et à une
  authentification obligatoire, protège les fichiers via le contrôle de session.
* `allow_symlinks=false`, les opérations d'écriture désactivées et le volume
  lecture seule sont cohérents avec une galerie de consultation.
* FFmpeg sert aux miniatures vidéo; Files Gallery ne transcode pas une vidéo.
  Monter `/dev/dri` ou utiliser l'iGPU ne procure donc aucun gain utile ici.

## 4. Risques et statut

| Niveau | Sujet | Décision |
| --- | --- | --- |
| CRITIQUE | Options 0.15 dans une application 0.13 | Corrigé : Files Gallery 0.15.3 officiel. |
| CRITIQUE | Héritage de `allow_settings=true` | Corrigé : admin distinct, défaut à `false`. |
| IMPORTANT | Secret initial public | Corrigé : lu depuis Compose, hashé dans `/config`. |
| IMPORTANT | `load_images_max_filesize=0` | Corrigé à 32 MiB : 0 ne signifie pas illimité. |
| IMPORTANT | `ffmpeg_path` 0.13 ignoré | Corrigé par mise à niveau 0.15.3. |
| MINEUR | `chown -R` à chaque démarrage | Corrigé : seulement après un changement d'identité. |
| OPTIONNEL | Ghostscript | Conservé pour PDF/PS; retirer si ces aperçus ne sont jamais nécessaires. |

## 5. Vérification Files Gallery officielle

La version 0.15.3 embarquée déclare `root_lock` comme chemin nullable et
contrôle que tout `root` utilisateur reste à l'intérieur. Définir
`'root_lock' => '/media'` dans `_filesconfig.php` est donc exact : le réglage
est verrouillé avant les réglages éditables. `root` y est aussi défini pour que
le GUI ne puisse pas déplacer la galerie hors de `/media`; sa répétition dans
la configuration persistante a été supprimée.

`dirs_include` et `dirs_exclude` sont évalués par PHP sur le chemin relatif à
la racine, dans les actions JSON comme dans la navigation. Le code valide aussi
le chemin réel contre la racine pour les requêtes contenant `..`. Ils constituent
une restriction applicative serveur, pas une ACL Unix : pour une isolation entre
utilisateurs hostiles, il faut des volumes/instances ou ACL NAS séparés.

Exemples dans `/config/users` :

```php
// Paul : seulement /media/Famille et ses descendants
return [
  'password' => '$2y$...hashé...',
  'dirs_include' => '/^Famille(\\/|$)/u',
  'allow_settings' => false,
];
```

```php
// Parents : toute la racine sauf /media/Privé
return [
  'password' => '$2y$...hashé...',
  'dirs_exclude' => '/^Privé(\\/|$)/u',
  'allow_settings' => false,
];
```

L'administrateur est toujours le compte fixe `admin`, seul compte avec
`allow_settings=true`; il peut créer les comptes depuis le GUI. Vérifier que
chaque compte créé possède explicitement `allow_settings => false`.

`load_images_max_filesize=0` ne veut pas dire « aucune limite » : dans le code
0 interdit de renvoyer un original lorsque le format n'est pas redimensionnable
ou lorsque la réduction est inutile. `33554432` (32 MiB) permet ces originaux
raisonnablement gros; les TIFF/HEIC pris en charge passent normalement par
ImageMagick et le cache plutôt que par l'original.

## 6. Formats, PHP et Apache

GD fournit JPEG/PNG/GIF/WebP; EXIF, mbstring et ZipArchive sont utiles. La
version 0.15.3 utilise ImageMagick/Imagick pour les formats configurés non
nativement web (`heif, heic, tiff, tif, psd, dng`) et écrit les miniatures dans
`/config/cache/images`. L'original ne sera jamais réécrit, et le montage ro le
garantit indépendamment de l'application.

ImageMagick sous Bookworm doit être vérifié dans l'image réelle : TIFF est
normalement présent; HEIC nécessite le délégué libheif (installé); PSD et DNG
dépendent des délégués distribués par Debian et ne sont pas garantis sans test.
Ghostscript est principalement utile aux miniatures PDF/PS. Il augmente la
surface de parsing : conserver la politique Debian par défaut et ne l'assouplir
que si les aperçus PDF sont explicitement nécessaires.

PHP 8.3 est compatible avec Files Gallery 0.15.3. PHP 8.4 ne doit être envisagé
qu'après validation d'Imagick et de tous les formats, pas comme mise à jour
automatique. 512 MiB et 120 secondes constituent un point de départ réaliste
pour le DS920+; augmenter seulement après échec reproductible d'un TIFF.

Apache seul reste préférable à nginx+FPM : aucun besoin fonctionnel ne justifie
un second conteneur. `mod_rewrite` n'était pas utilisé et a été retiré. Les
en-têtes actuels (`nosniff`, `same-origin`, `SAMEORIGIN`) ne cassent pas les
assets Files Gallery. Ne pas ajouter une CSP stricte sans test : les assets sont
par défaut chargés depuis JSDelivr.

## 7. Analyse fichier par fichier

| Fichier | Revue |
| --- | --- |
| `app/index.php` | Source officielle 0.15.3, version et SHA-256 consignés dans `app/VERSION`. C'est indispensable : la 0.13.0 ne mettait pas en œuvre plusieurs options déjà configurées. |
| `docker/_filesconfig.php` | Correct et volontairement immuable : verrouille `storage_path`, `root` et `root_lock` avant la configuration administrable. |
| `docker/config.php` | Configuration globale persistante initiale. Écriture média désactivée; aucun mot de passe clair; `allow_settings=false`. |
| `docker/admin-config.php` | Modèle du seul utilisateur administrateur, avec `allow_settings=true`. |
| `docker/entrypoint.sh` | Remappe `www-data`, initialise les deux configs, synchronise uniquement le hash du mot de passe admin depuis Compose et reprend la propriété de `/config` uniquement si l'identité change. |
| `docker/php-filesgallery.ini` | 512 MiB/120 s pour les aperçus, upload limité parce qu'inutilisé, sessions dans `/tmp`. |
| `docker/apache-filesgallery.conf` | Pas d'index de répertoire, pas de `.htaccess`, bootstrap `_filesconfig.php` explicitement refusé; `/config` et `/media` sont hors DocumentRoot. |
| `Dockerfile` | PHP exact, extensions/fournisseurs ImageMagick, Imagick exact, vérification SHA-256 de l'application; `mod_rewrite` supprimé. |
| `compose.yaml` | Les `PUID/PGID` sont effectivement consommés; le mot de passe admin est la source de vérité; `no-new-privileges` reste compatible. |

## 8. Dockerfile et entrypoint

Le Dockerfile installe les bibliothèques runtime et les en-têtes de compilation,
compile GD, Imagick 3.8.1, puis supprime les paquets `-dev`. `apt` conserve les
bibliothèques runtime requises par ImageMagick et Imagick, car elles sont des
dépendances du paquet ImageMagick ou détectées par le paquet installé. C'est à
valider par `ldd`/tests de format après build. Le tag PHP précis stabilise la
version PHP; pour une reproductibilité binaire absolue, remplacer `PHP_IMAGE`
par un digest amd64 vérifié, au prix de ne plus recevoir les correctifs de base
tant que ce digest n'est pas mis à jour explicitement.

L'entrypoint est idempotent : groupe existant par GID accepté, UID existant
différent refusé explicitement, et répertoire `/config` repris seulement si
`PUID:PGID` change. `getent`, `groupadd`, `usermod`, `install` et `php` sont
présents dans la base Debian/PHP. Les sessions utilisent `/tmp`; aucune session
ne doit être sauvegardée.

`no-new-privileges:true` est compatible : il interdit à un processus d'acquérir
de nouveaux privilèges à l'exécution (setuid, file capabilities), mais ne retire
pas les privilèges déjà détenus par PID 1 root. `groupadd`, `usermod` et `chown`
fonctionnent donc avant Apache. Ne pas ajouter `cap_drop: ALL` ni `read_only`
dans cette variante : l'entrypoint et Apache nécessitent des capacités et des
écritures runtime. Un durcissement supplémentaire demanderait une architecture
non-root préparée à la construction, qui complique précisément le problème UID.

## 9. Déploiement

1. Copiez le projet dans `/volume2/docker/filesgallery-src`; créez
   `/volume2/docker/filesgallery/config`.
2. Dans `compose.yaml`, remplacez `FILES_GALLERY_ADMIN_PASSWORD: "changeme"`
   par un mot de passe long et unique avant toute exposition réseau. Cette
   valeur est la source de vérité : elle est lue à chaque démarrage, hashée avec
   `password_hash()` et le mot de passe en clair n'est jamais écrit dans
   `/config`.
3. Déployez `compose.yaml` dans Container Manager ou Portainer. Le premier
   lancement génère deux hashes : un compte par défaut désactivé et le vrai
   compte administrateur fixe `/config/users/admin/config.php`.
4. Accédez à `http://IP_DU_NAS:8083/`, connectez-vous, créez les utilisateurs
   puis placez le service derrière le reverse proxy DSM HTTPS. N'exposez pas
   8083 directement sur Internet.

## 10. Tests de validation

```sh
docker compose ps
docker logs filesgallery
docker exec filesgallery id www-data
docker exec filesgallery ps -eo user:16,pid,comm,args
docker exec filesgallery ls -ld /media /config
docker exec filesgallery sh -c 'su -s /bin/sh www-data -c "ls -la /media"'
docker exec filesgallery php -r 'var_dump(is_dir("/media"), is_readable("/media"), realpath("/media"));'
docker exec filesgallery sh -c 'touch /media/__must_fail__' # doit échouer
docker exec filesgallery php -m | grep -E 'exif|gd|imagick|mbstring|zip'
docker exec filesgallery sh -c 'convert -version; convert -list format | grep -Ei "TIFF|HEIC|HEIF|PSD|DNG"'
docker exec filesgallery sh -c 'identify /media/chemin/test.tif'
docker exec filesgallery ffmpeg -version
curl -i http://127.0.0.1:8083/
curl -i http://127.0.0.1:8083/_filesconfig.php # 403 attendu
curl -i http://127.0.0.1:8083/config/           # 404 attendu
curl -i http://127.0.0.1:8083/media/            # 404 attendu
```

### Test de synchronisation du mot de passe admin

Avant le changement, relever une empreinte des réglages admin hors mot de
passe :

```sh
docker exec filesgallery php -r '$c=include "/config/users/admin/config.php"; unset($c["password"]); echo hash("sha256", serialize($c)), PHP_EOL;'
```

Remplacer dans `compose.yaml` `FILES_GALLERY_ADMIN_PASSWORD: "changeme"` par
la nouvelle valeur, puis recréer le service (`docker compose up -d
--force-recreate`, ou **Redeploy** dans Portainer/Container Manager). Vérifier
le nouveau mot de passe et relever à nouveau l'empreinte :

```sh
docker exec filesgallery php -r '$c=include "/config/users/admin/config.php"; var_dump(password_verify("NOUVEAU_MOT_DE_PASSE", $c["password"])); unset($c["password"]); echo hash("sha256", serialize($c)), PHP_EOL;'
```

`bool(true)` est attendu et les deux empreintes doivent être identiques : seule
la clé `password` a été modifiée. Les sessions existantes sont invalidées au
redémarrage, car Files Gallery les lie au hash de mot de passe.

Connectez-vous ensuite comme Paul et utilisez un deeplink vers `Vacances` puis
`Privé`; contrôlez l'absence de fichiers retournés. Connectez-vous comme Parents
et vérifiez que seul `Privé` est refusé. Enfin, ouvrez TIFF, HEIC et vidéo réels
et confirmez la création de fichiers sous `/config/cache/images` sans variation
de date ou hash de l'original.

## 11. Mise à jour, rollback et backup

Pour Files Gallery, télécharger explicitement la nouvelle version dans
`app/index.php`, mettre à jour `app/VERSION`, `FILES_GALLERY_VERSION` dans
Dockerfile et `image:` dans Compose, puis rebâtir. Le build vérifie le SHA-256.
Conserver les tags précédents (`filesgallery-local:0.15.3`) jusqu'à validation.
Pour PHP/Debian/ImageMagick/FFmpeg, modifier `PHP_IMAGE` ou reconstruire après
avoir consulté les changelogs, exécuter la checklist, puis seulement supprimer
l'image précédente.

Sauvegarder impérativement `/volume2/docker/filesgallery/config` :
`config/config.php`, `users/`, assets/personnalisations éventuels et cache. Le
cache est recréable, les configs utilisateurs ne le sont pas. Sauvegarder aussi
ce dépôt, incluant Dockerfile, Compose, `docker/*`, `app/index.php` et
`app/VERSION`; ils définissent exactement l'application reconstruisible.

## Références

* [Configuration Files Gallery](https://www.files.gallery/docs/config/)
* [Stockage Files Gallery](https://www.files.gallery/docs/storage/)
* [Utilisateurs Files Gallery](https://www.files.gallery/docs/users/)
* [Sécurité Files Gallery](https://www.files.gallery/docs/security/)
* [Image PHP officielle](https://hub.docker.com/_/php/)
