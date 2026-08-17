# Files Gallery Docker

This repository is an independent Docker packaging project that makes [Files Gallery](https://www.files.gallery/) easy to deploy. Files Gallery itself is developed by mjau-mjau and is not maintained here. This repository does not redistribute a modified upstream `index.php`.

The Docker image does not contain the Files Gallery application. At container startup it downloads the pristine `index.php` directly from a pinned upstream Git commit and verifies its version and SHA-256. It retains that pristine copy separately, then deterministically generates the runtime `index.php` with the independent ACL integration before starting Apache.

## Upstream Files Gallery

> Files is a single-file PHP app that can be dropped into any folder on server, instantly creating a gallery of files and folders. It supports all file types and allows you to preview images, video, audio, documents and text files.

- GitHub: https://github.com/mjau-mjau/files.photo.gallery
- Website: https://www.files.gallery/

- Licence type : F1-XXXX-XXXX-XXXX-XXXX-XXXX-XXXX

## Features

- Apache with PHP 8.3, ImageMagick/Imagick, FFmpeg, TIFF and HEIC preview support.
- Synology-friendly PUID / PGID mapping: Apache stays root while PHP workers use the mapped www-data account.
- Read-only /media mount and persistent /config storage.
- Fixed admin account; its password is supplied through FILES_GALLERY_ADMIN_PASSWORD.
- PHP extension and ImageMagick JPEG/TIFF smoke checks during image builds.
- Common NAS and system files hidden from Files Gallery by default.
- Admin-only, allow-only folder ACL editor for existing Files Gallery users.

## How this image works

1. The image contains Apache, PHP, and the required runtime dependencies.
2. The image does not include the Files Gallery application. At startup, the entrypoint reads its pinned version metadata and downloads the pristine official Files Gallery 0.15.3 index.php over HTTPS.
3. The pristine file is checked against its SHA-256 and its declared Files Gallery version, retained separately for verification, then deterministically patched with the independent ACL integration into the runtime /var/www/html/index.php.
4. A normal restart reuses the already verified file. A recreated container downloads and verifies it again.
5. /media is the read-only media source; /config stores Files Gallery configuration and cache.

If a download, checksum, version, or existing-file verification fails, the container exits without starting Apache.

## Docker Compose

~~~yaml
services:
  filesgallery:
    image: ghcr.io/fraudelefix/filesgallery-docker:latest
    container_name: filesgallery
    hostname: filesgallery
    restart: unless-stopped

    ports:
      - "8080:80" 

    environment:
      PUID: "1000" # Change me
      PGID: "1000" # Change me
      TZ: "Europe/Paris"
      FILES_GALLERY_ADMIN_PASSWORD: "changeme"

    volumes:
      - "/etc/localtime:/etc/localtime:ro"
      - "/path/to/filesgallery/config:/config"
      - "/path/to/media:/media:ro"

    security_opt:
      - no-new-privileges:true
~~~

Set PUID and PGID to the NAS user that owns the media, choose a strong admin password, and adapt both NAS paths. /media deliberately remains read-only: this image does not delete or modify original media.

## Configuration

/config is persistent and remains owned by root:root (0755). Files Gallery's writable directories are /config/config, /config/cache, and /config/users; they are owned by the remapped www-data account (0700).

The admin username is always admin. FILES_GALLERY_ADMIN_PASSWORD is the password source of truth: at every start, only the admin password hash is synchronised. ACLs and other per-user settings are preserved. Plain-text passwords are never stored in /config.

Files Gallery diagnostics/tests are disabled globally and enabled by default for the built-in admin account only.

New users created from Settings/User Manager inherit the default NAS/system exclusion regexes. Those users can edit their own regexes afterwards, for example to add a private-folder exclusion.

### Per-user folder ACLs

After signing in as the built-in `admin` user, open `http://<host>/?action=acl_admin`. The page lists existing valid users (not `admin` itself), lazy-loads the actual `/media` directory tree, and edits only their read-visibility ACLs. It is independent of the Files Gallery premium user manager.

A checked folder grants recursive read. Its unselected ancestors display as traversal-only; those ancestors are computed for navigation and are never written to disk. ACLs are allow-only: selecting `Family` makes every child readable, so to hide `Family/Private`, remove `Family` and select only the wanted branch such as `Family/Shared`. This does not grant upload, deletion, rename, move, or any other media write capability.

The page uses the existing session CSRF token and writes atomically. The canonical allow-list is stored as UTF-8 PHP in `/config/users/<username>/acl.php`; missing, empty, or malformed ACLs grant no normal-user access. For recovery/debugging, the supported file format is:

~~~php
<?php
return [
    'allow' => [
        'Family/Shared',
        'Videos',
    ],
];
~~~

## Default exclusions

Files Gallery hides these entries by default:

- Directories: hidden directories beginning with ., @eaDir, __MACOSX, and $RECYCLE.BIN.
- Files: files beginning with ., Thumbs.db, desktop.ini, and Office temporary files beginning with ~$.

This is only Files Gallery display/access filtering. Nothing in /media is deleted or changed.

## Updating and versioning

Git tags use YYYY.MM.DD or YYYY.MM.DD-N and publish matching GHCR image tags. latest is also updated for a successful tagged publication, but production deployments should use a dated tag. To roll back, switch the image tag in Compose and recreate the container; keep /config intact.

The pinned upstream dependency is defined in [app/VERSION](app/VERSION). It records the Files Gallery version, upstream commit, raw GitHub URL, and SHA-256. The pristine upstream index.php is intentionally not stored in this repository or baked into the image; the runtime copy is generated only after verification.
