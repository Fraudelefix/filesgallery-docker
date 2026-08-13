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
ADMIN_USER="admin"
ADMIN_DIR="/config/users/$ADMIN_USER"
ADMIN_CONFIG="$ADMIN_DIR/config.php"

: "${FILES_GALLERY_ADMIN_PASSWORD:?Set FILES_GALLERY_ADMIN_PASSWORD}"
umask 0077
ADMIN_HASH="$(php -r 'echo password_hash(getenv("FILES_GALLERY_ADMIN_PASSWORD"), PASSWORD_DEFAULT);')"
export ADMIN_HASH

if [ ! -e /config/config/config.php ]; then
  DISABLED_HASH="$(php -r 'echo password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);')"
  export DISABLED_HASH
  php -r '$t=file_get_contents($argv[1]); file_put_contents($argv[2], str_replace("__FILES_GALLERY_DISABLED_PASSWORD_HASH__", getenv("DISABLED_HASH"), $t));' /usr/local/share/filesgallery/config.php /config/config/config.php
fi

if [ ! -e "$ADMIN_CONFIG" ]; then
  mkdir -p "$ADMIN_DIR"
  php -r '$t=file_get_contents($argv[1]); file_put_contents($argv[2], str_replace("__FILES_GALLERY_ADMIN_PASSWORD_HASH__", getenv("ADMIN_HASH"), $t));' /usr/local/share/filesgallery/admin-config.php "$ADMIN_CONFIG"
else
  # Synchronise uniquement la valeur password. Les ACL, allow_settings et tous
  # les réglages ajoutés depuis le WebUI restent inchangés.
  php -r '$p=$argv[1]; $s=file_get_contents($p); $re="~(^\\s*\\x27password\\x27\\s*=>\\s*)\\x27[^\\x27]*\\x27~m"; $n=preg_replace_callback($re, function($m){ return $m[1].var_export(getenv("ADMIN_HASH"), true); }, $s, 1, $count); if($count !== 1){ fwrite(STDERR, "Admin config has no replaceable password entry\\n"); exit(67); } file_put_contents($p, $n);' "$ADMIN_CONFIG"
fi

# Un chown récursif de milliers de miniatures à chaque démarrage est évité.
# Il est relancé uniquement si l'identité configurée change.
OWNER_STATE="/config/.filesgallery-owner"
OWNER="$(id -u www-data):$(id -g www-data)"
if [ ! -f "$OWNER_STATE" ] || [ "$(cat "$OWNER_STATE")" != "$OWNER" ]; then
  chown -R www-data:"$(id -g www-data)" /config
  printf '%s\n' "$OWNER" > "$OWNER_STATE"
  chown www-data:"$(id -g www-data)" "$OWNER_STATE"
fi
exec "$@"
