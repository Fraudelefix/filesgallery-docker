#!/bin/sh
set -eu

PUID="${PUID:-33}"
PGID="${PGID:-33}"
case "$PUID:$PGID" in *[!0-9:]*|:*|*:) echo "PUID and PGID must be numeric" >&2; exit 64;; esac

METADATA_FILE="/usr/local/share/filesgallery/VERSION"
PRISTINE_INDEX_PATH="/usr/local/share/filesgallery/upstream-index.php"
INDEX_PATH="/var/www/html/index.php"

load_upstream_metadata() {
  if [ ! -r "$METADATA_FILE" ]; then
    echo "Files Gallery metadata is missing: $METADATA_FILE" >&2
    exit 66
  fi
  # shellcheck disable=SC1090
  . "$METADATA_FILE"
  case "${FILES_GALLERY_VERSION:-}:${FILES_GALLERY_UPSTREAM_COMMIT:-}:${FILES_GALLERY_SHA256:-}" in
    *[!0-9.a-f:]*|::*|*:) echo "Files Gallery metadata is invalid" >&2; exit 66;;
  esac
  expected_url="https://raw.githubusercontent.com/mjau-mjau/files.photo.gallery/${FILES_GALLERY_UPSTREAM_COMMIT}/index.php"
  if [ "${FILES_GALLERY_URL:-}" != "$expected_url" ]; then
    echo "Files Gallery metadata URL is not pinned to its upstream commit" >&2
    exit 66
  fi
  if ! printf '%s' "$FILES_GALLERY_SHA256" | grep -Eq '^[0-9a-f]{64}$'; then
    echo "Files Gallery metadata has an invalid SHA-256" >&2
    exit 66
  fi
}

verify_upstream_index() {
  candidate="$1"
  actual_sha256="$(sha256sum "$candidate" | awk '{print $1}')" || return 1
  if [ "$actual_sha256" != "$FILES_GALLERY_SHA256" ]; then
    echo "Files Gallery index.php checksum mismatch" >&2
    return 1
  fi
  if ! grep -F "Files Gallery $FILES_GALLERY_VERSION" "$candidate" >/dev/null; then
    echo "Files Gallery index.php does not identify version $FILES_GALLERY_VERSION" >&2
    return 1
  fi
}

install_pristine_index() {
  if [ -e "$PRISTINE_INDEX_PATH" ]; then
    if ! verify_upstream_index "$PRISTINE_INDEX_PATH"; then
      echo "Existing pristine Files Gallery index.php is invalid" >&2
      exit 67
    fi
    return
  fi

  temporary="$(mktemp /usr/local/share/filesgallery/.upstream-index.XXXXXX)"
  if ! curl --fail --location --silent --show-error --proto '=https' --tlsv1.2 \
      --connect-timeout 15 --retry 3 --retry-all-errors \
      --output "$temporary" "$FILES_GALLERY_URL"; then
    rm -f "$temporary"
    echo "Failed to download Files Gallery from the pinned upstream URL" >&2
    exit 67
  fi
  if ! verify_upstream_index "$temporary"; then
    rm -f "$temporary"
    echo "Downloaded Files Gallery index.php failed verification" >&2
    exit 67
  fi
  chown root:root "$temporary"
  chmod 0644 "$temporary"
  mv -f "$temporary" "$PRISTINE_INDEX_PATH"
  if ! verify_upstream_index "$PRISTINE_INDEX_PATH"; then
    echo "Installed pristine Files Gallery index.php failed final verification" >&2
    exit 67
  fi
}

install_patched_runtime_index() {
  temporary="$(mktemp /var/www/html/.filesgallery-runtime.XXXXXX)"
  if ! php -d display_errors=0 /usr/local/share/filesgallery/acl/patch.php "$PRISTINE_INDEX_PATH" "$temporary"; then
    rm -f "$temporary"
    echo "Files Gallery ACL patch failed" >&2
    exit 67
  fi
  if ! php -d display_errors=0 -l "$temporary" >/dev/null; then
    rm -f "$temporary"
    echo "Patched Files Gallery index.php is invalid PHP" >&2
    exit 67
  fi
  for marker in FILESGALLERY_ACL_INIT_V1 FILESGALLERY_ACL_ADMIN_V1 FILESGALLERY_ACL_FILTER_V1 FILESGALLERY_ACL_MENU_V1 FILESGALLERY_ACL_PREVIEW_V1 FILESGALLERY_ACL_ADMIN_ASSET_V1; do
    [ "$(grep -c "$marker" "$temporary")" = 1 ] || { rm -f "$temporary"; echo "Patched Files Gallery marker verification failed" >&2; exit 67; }
  done
  grep -F "Files Gallery $FILES_GALLERY_VERSION" "$temporary" >/dev/null || { rm -f "$temporary"; echo "Patched Files Gallery version verification failed" >&2; exit 67; }
  chown root:root "$temporary"
  chmod 0644 "$temporary"
  mv -f "$temporary" "$INDEX_PATH"
}

load_upstream_metadata
install_pristine_index
install_patched_runtime_index

# Apache master stays root to open port 80. Its workers subsequently run under
# www-data, whose UID/GID are remapped to the NAS media owner.
if ! getent group "$PGID" >/dev/null; then groupadd --gid "$PGID" filesgallery; fi
if [ "$(id -u www-data)" != "$PUID" ]; then
  if getent passwd "$PUID" >/dev/null; then
    echo "PUID $PUID is already used in this image" >&2; exit 65
  fi
  usermod --uid "$PUID" --gid "$PGID" www-data
elif [ "$(id -g www-data)" != "$PGID" ]; then
  usermod --gid "$PGID" www-data
fi

WWW_GROUP="$(id -g www-data)"
CONFIG_DIR="/config"
ADMIN_CONFIG="$CONFIG_DIR/users/admin/config.php"

valid_hash() {
  printf '%s' "$1" | grep -Eq '^\$2[aby]\$[0-9]{2}\$[./A-Za-z0-9]{53}$'
}

generate_hash_from_env() {
  errors="$(mktemp)"
  if ! hash="$(php -d display_errors=0 -r '$secret=getenv("FILES_GALLERY_ADMIN_PASSWORD"); if(!is_string($secret) || $secret === "") exit(1); $hash=password_hash($secret, PASSWORD_DEFAULT); if(!is_string($hash)) exit(1); echo $hash;' 2>"$errors")" || [ -s "$errors" ]; then
    echo "Failed to generate admin password hash" >&2
    cat "$errors" >&2
    rm -f "$errors"
    exit 68
  fi
  rm -f "$errors"
  valid_hash "$hash" || { echo "Generated admin password hash is invalid" >&2; exit 68; }
  printf '%s' "$hash"
}

generate_disabled_hash() {
  errors="$(mktemp)"
  if ! hash="$(php -d display_errors=0 -r '$hash=password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT); if(!is_string($hash)) exit(1); echo $hash;' 2>"$errors")" || [ -s "$errors" ]; then
    echo "Failed to generate disabled password hash" >&2
    cat "$errors" >&2
    rm -f "$errors"
    exit 69
  fi
  rm -f "$errors"
  valid_hash "$hash" || { echo "Generated disabled password hash is invalid" >&2; exit 69; }
  printf '%s' "$hash"
}

atomic_template_config() {
  target="$1" template="$2" marker="$3" hash="$4" directory="$(dirname "$target")"
  temporary="$(mktemp "$directory/.filesgallery-config.XXXXXX")"
  trap 'rm -f "$temporary"' HUP INT TERM EXIT
  CONFIG_HASH="$hash" php -d display_errors=0 -r '$source=file_get_contents($argv[1]); if($source === false) exit(1); $result=str_replace($argv[2], getenv("CONFIG_HASH"), $source, $count); if($count !== 1 || file_put_contents($argv[3], $result) === false) exit(1);' "$template" "$marker" "$temporary" || {
    echo "Failed to generate $target" >&2; exit 70;
  }
  php -d display_errors=0 -l "$temporary" >/dev/null || { echo "Generated $target is invalid PHP" >&2; exit 70; }
  chown www-data:"$WWW_GROUP" "$temporary"
  chmod 0600 "$temporary"
  mv -f "$temporary" "$target"
  trap - HUP INT TERM EXIT
}

atomic_update_admin_password() {
  target="$1" hash="$2" directory="$(dirname "$target")"
  temporary="$(mktemp "$directory/.filesgallery-config.XXXXXX")"
  trap 'rm -f "$temporary"' HUP INT TERM EXIT
  ADMIN_HASH="$hash" php -d display_errors=0 -r '$source=file_get_contents($argv[1]); if($source === false) exit(1); $pattern="~(^\\s*\\x27password\\x27\\s*=>\\s*)\\x27[^\\x27]*\\x27~m"; $result=preg_replace_callback($pattern, fn($m) => $m[1].var_export(getenv("ADMIN_HASH"), true), $source, 1, $count); if($count !== 1 || file_put_contents($argv[2], $result) === false) exit(1);' "$target" "$temporary" || {
    echo "Failed to update admin password" >&2; exit 71;
  }
  php -d display_errors=0 -l "$temporary" >/dev/null || { echo "Updated admin config is invalid PHP" >&2; exit 71; }
  chown www-data:"$WWW_GROUP" "$temporary"
  chmod 0600 "$temporary"
  mv -f "$temporary" "$target"
  trap - HUP INT TERM EXIT
}

mkdir -p "$CONFIG_DIR/config" "$CONFIG_DIR/cache" "$CONFIG_DIR/users"
chown root:root "$CONFIG_DIR"
chmod 0755 "$CONFIG_DIR"

# /config is Docker-owned; only Files Gallery's mutable children are owned by
# the remapped worker identity. A versioned marker limits recursive repairs.
OWNER="$(id -u www-data):$WWW_GROUP"
STATE_FILE="$CONFIG_DIR/.filesgallery-permissions-v2"
for directory in "$CONFIG_DIR/config" "$CONFIG_DIR/cache" "$CONFIG_DIR/users"; do
  chown www-data:"$WWW_GROUP" "$directory"
  chmod 0700 "$directory"
done
if [ ! -f "$STATE_FILE" ] || [ "$(cat "$STATE_FILE" 2>/dev/null || true)" != "v2:$OWNER" ]; then
  chown -R www-data:"$WWW_GROUP" "$CONFIG_DIR/config" "$CONFIG_DIR/cache" "$CONFIG_DIR/users"
  printf 'v2:%s\n' "$OWNER" > "$STATE_FILE"
  chown root:root "$STATE_FILE"
  chmod 0600 "$STATE_FILE"
fi

for directory in "$CONFIG_DIR/config" "$CONFIG_DIR/cache" "$CONFIG_DIR/users"; do
  if ! su -s /bin/sh www-data -c "test -w '$directory'"; then
    echo "www-data cannot write to $directory" >&2; exit 72
  fi
done
if [ -d /media ] && ! su -s /bin/sh www-data -c 'test -r /media && ls /media >/dev/null'; then
  echo "www-data cannot read /media" >&2; exit 73
fi

: "${FILES_GALLERY_ADMIN_PASSWORD:?Set FILES_GALLERY_ADMIN_PASSWORD}"
ADMIN_HASH="$(generate_hash_from_env)"

if [ -e "$CONFIG_DIR/config/config.php" ]; then
  php -d display_errors=0 -l "$CONFIG_DIR/config/config.php" >/dev/null || {
    echo "Existing $CONFIG_DIR/config/config.php is invalid PHP; refusing to overwrite it" >&2; exit 74;
  }
else
  DISABLED_HASH="$(generate_disabled_hash)"
  atomic_template_config "$CONFIG_DIR/config/config.php" /usr/local/share/filesgallery/config.php "__FILES_GALLERY_DISABLED_PASSWORD_HASH__" "$DISABLED_HASH"
fi

if [ -e "$ADMIN_CONFIG" ]; then
  php -d display_errors=0 -l "$ADMIN_CONFIG" >/dev/null || {
    echo "Existing $ADMIN_CONFIG is invalid PHP; refusing to overwrite it" >&2; exit 75;
  }
  atomic_update_admin_password "$ADMIN_CONFIG" "$ADMIN_HASH"
else
  mkdir -p "$(dirname "$ADMIN_CONFIG")"
  chown www-data:"$WWW_GROUP" "$(dirname "$ADMIN_CONFIG")"
  chmod 0700 "$(dirname "$ADMIN_CONFIG")"
  atomic_template_config "$ADMIN_CONFIG" /usr/local/share/filesgallery/admin-config.php "__FILES_GALLERY_ADMIN_PASSWORD_HASH__" "$ADMIN_HASH"
fi

exec "$@"
