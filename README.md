# Files Gallery Docker

This repository is an independent Docker packaging project that makes [Files Gallery](https://www.files.gallery/) easy to deploy. Files Gallery itself is developed by mjau-mjau and is not maintained here. No modifications are made to the original Files Gallery source code.

The Docker image does not contain the Files Gallery application. At container startup it downloads the original, unmodified index.php directly from a pinned upstream Git commit, verifies its version and SHA-256, then starts Apache.

## Upstream Files Gallery

> Files is a single-file PHP app that can be dropped into any folder on server, instantly creating a gallery of files and folders. It supports all file types and allows you to preview images, video, audio, documents and text files.

> — [Files Gallery upstream project](https://github.com/mjau-mjau/files.photo.gallery)

- GitHub: https://github.com/mjau-mjau/files.photo.gallery
- Website: https://www.files.gallery/

## Features

- Apache with PHP 8.3, ImageMagick/Imagick, FFmpeg, TIFF and HEIC preview support.
- Synology-friendly PUID / PGID mapping: Apache stays root while PHP workers use the mapped www-data account.
- Read-only /media mount and persistent /config storage.
- Fixed admin account; its password is supplied through FILES_GALLERY_ADMIN_PASSWORD.
- PHP extension and ImageMagick JPEG/TIFF smoke checks during image builds.
- Common NAS and system files hidden from Files Gallery by default.

## How this image works

1. The image contains Apache, PHP, and the required runtime dependencies—not the Files Gallery application.
2. At startup, the entrypoint reads its pinned version metadata and downloads the official Files Gallery 0.15.3 index.php over HTTPS.
3. The file is checked against its SHA-256 and its declared Files Gallery version before an atomic install to /var/www/html/index.php; the installed file is checked again afterwards.
4. A normal restart reuses the already verified file. A recreated container downloads and verifies it again.
5. /media is the read-only media source; /config stores Files Gallery configuration and cache.

If a download, checksum, version, or existing-file verification fails, the container exits without starting Apache.

## Docker Compose

Use a dated image tag in production. The latest successful tag at the time of this documentation is 2026.08.13-4.

~~~yaml
services:
  filesgallery:
    image: ghcr.io/fraudelefix/filesgallery-docker:2026.08.13-4
    container_name: filesgallery
    hostname: filesgallery
    restart: unless-stopped

    ports:
      - "8083:80"

    environment:
      PUID: "1030"
      PGID: "100"
      TZ: "Europe/Paris"
      FILES_GALLERY_ADMIN_PASSWORD: "change-me"

    volumes:
      - "/etc/localtime:/etc/localtime:ro"
      - "/volume2/docker/filesgallery/config:/config"
      - "/volume1/homes/Victor/Numerisation:/media:ro"

    security_opt:
      - no-new-privileges:true
~~~

Set PUID and PGID to the NAS user that owns the media, choose a strong admin password, and adapt both NAS paths. /media deliberately remains read-only: this image does not delete or modify original media.

## Configuration

/config is persistent and remains owned by root:root (0755). Files Gallery's writable directories are /config/config, /config/cache, and /config/users; they are owned by the remapped www-data account (0700).

The admin username is always admin. FILES_GALLERY_ADMIN_PASSWORD is the password source of truth: at every start, only the admin password hash is synchronised. ACLs and other per-user settings are preserved. Plain-text passwords are never stored in /config.

## Default exclusions

Files Gallery hides these entries by default:

- Directories: hidden directories beginning with ., @eaDir, __MACOSX, and $RECYCLE.BIN.
- Files: files beginning with ., Thumbs.db, desktop.ini, and Office temporary files beginning with ~$.

This is only Files Gallery display/access filtering. Nothing in /media is deleted or changed.

## Updating and versioning

Git tags use YYYY.MM.DD or YYYY.MM.DD-N and publish matching GHCR image tags. latest is also updated for a successful tagged publication, but production deployments should use a dated tag. To roll back, switch the image tag in Compose and recreate the container; keep /config intact.

The pinned upstream dependency is defined in [app/VERSION](app/VERSION). It records the Files Gallery version, upstream commit, raw GitHub URL, and SHA-256. The upstream index.php is intentionally not stored in this repository or baked into the image.

## Credits

Files Gallery is developed by [mjau-mjau](https://github.com/mjau-mjau). This repository is an independent Docker packaging project and is not affiliated with or endorsed by the upstream author.

- https://github.com/mjau-mjau/files.photo.gallery
- https://www.files.gallery/
