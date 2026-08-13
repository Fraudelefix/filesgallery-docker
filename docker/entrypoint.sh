#!/bin/sh
set -eu
PUID="${PUID:-33}"
PGID="${PGID:-33}"
case "$PUID:$PGID" in *[!0-9:]*|:*|*:) echo "PUID and PGID must be numeric" >&2; exit 64;; esac

# Apache est lancé root, puis ses workers deviennent www-data. On remappe
# seulement www-data : Apache peut donc encore ouvrir le port 80.
if ! getent group "$PGID" >/dev/null; then groupadd --gid "$PGID" filesgallery; fi
if [ "$(id -u www-data)" != "$PUID" ]; then
  if getent passwd "$PUID" >/dev/null; then
    echo "PUID $PUID is already used in this image" >&2; exit 65
  fi
  usermod --uid "$PUID" --gid "$PGID" www-data
elif [ "$(id -g www-data)" != "$PGID" ]; then
  usermod --gid "$PGID" www-data
fi
mkdir -p /config/config /config/cache /config/users
if [ ! -e /config/config/config.php ]; then
  install -m 0640 -o www-data -g "$(id -g www-data)" /usr/local/share/filesgallery/config.php /config/config/config.php
fi
chown -R www-data:"$(id -g www-data)" /config
exec "$@"
