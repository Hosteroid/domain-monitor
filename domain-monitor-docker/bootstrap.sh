#!/usr/bin/env bash
set -euo pipefail

### --- CONFIG ---
GIT_URL="${GIT_URL:-https://github.com/Hosteroid/domain-monitor.git}"
APP_ENV_DEFAULT="${APP_ENV:-production}"

# Always treat the CURRENT directory as project root
PROJECT_ROOT="$(pwd)"
APP_DIR="$PROJECT_ROOT/app"
DOCKER_ENV_FILE="$PROJECT_ROOT/.env.docker"
ENV_TMPL="$APP_DIR/env.example.txt"
ENV_FILE="$APP_DIR/.env"

# Numeric IDs for php:apache (Debian) www-data
WWW_UID=33
WWW_GID=33
ROOT_UID=0

dc() {
  if command -v docker &>/dev/null && docker compose version &>/dev/null; then
    docker compose "$@"
  elif command -v docker-compose &>/dev/null; then
    docker-compose "$@"
  else
    echo "Error: docker compose not found." >&2
    exit 1
  fi
}

fail() { echo "Error: $*" >&2; exit 1; }
upsert_kv() {
  local file="$1" key="$2" val="$3"
  if grep -qE "^${key}=" "$file" 2>/dev/null; then
    sed -i "s#^${key}=.*#${key}=${val}#g" "$file"
  else
    printf "%s=%s\n" "$key" "$val" >> "$file"
  fi
}

echo "==> Domain Monitor bootstrap"

[[ -f "$PROJECT_ROOT/docker-compose.yml" ]] || fail "Run this from the domain-monitor-docker folder."

# Ensure .env.docker exists (or copy example then exit)
if [[ ! -f "$DOCKER_ENV_FILE" ]]; then
  if [[ -f "$PROJECT_ROOT/env.docker.example" ]]; then
    echo "==> .env.docker not found. Creating from example..."
    cp "$PROJECT_ROOT/env.docker.example" "$DOCKER_ENV_FILE"
    echo "   Edit $DOCKER_ENV_FILE with real passwords, then re-run."
    exit 1
  else
    fail ".env.docker not found (and no example to copy)."
  fi
fi

# Load Docker env
set -a
# shellcheck disable=SC1090
. "$DOCKER_ENV_FILE"
set +a

: "${DB_DATABASE:?Missing DB_DATABASE in .env.docker}"
: "${DB_USERNAME:?Missing DB_USERNAME in .env.docker}"
: "${DB_PASSWORD:?Missing DB_PASSWORD in .env.docker}"
: "${DB_ROOT_PASSWORD:?Missing DB_ROOT_PASSWORD in .env.docker}"
TZ="${TZ:-UTC}"

# Clone repo if needed
if [[ ! -d "$APP_DIR" || -z "$(ls -A "$APP_DIR" 2>/dev/null || true)" ]]; then
  echo "==> Cloning $GIT_URL into app/"
  git clone "$GIT_URL" "$APP_DIR"
else
  echo "==> app/ exists; skipping clone"
fi

# Create/merge app .env
if [[ -f "$ENV_FILE" ]]; then
  cp -a "$ENV_FILE" "$ENV_FILE.bak.$(date +%s)"
  echo "   - Existing .env backed up."
elif [[ -f "$ENV_TMPL" ]]; then
  cp "$ENV_TMPL" "$ENV_FILE"
  echo "   - Created .env from env.example.txt"
else
  touch "$ENV_FILE"
  echo "   - Created empty .env (env.example.txt not found)"
fi

# Write DB + env for container network
upsert_kv "$ENV_FILE" "DB_HOST" "db"
upsert_kv "$ENV_FILE" "DB_PORT" "3306"
upsert_kv "$ENV_FILE" "DB_DATABASE" "$DB_DATABASE"
upsert_kv "$ENV_FILE" "DB_USERNAME" "$DB_USERNAME"
upsert_kv "$ENV_FILE" "DB_PASSWORD" "$DB_PASSWORD"
upsert_kv "$ENV_FILE" "APP_ENV" "${APP_ENV_DEFAULT}"

# --- PERMISSIONS using numeric IDs (works across host/container) ---
echo "==> Applying permissions with numeric IDs (root:${WWW_GID} for code; ${WWW_UID}:${WWW_GID} for writable dirs)"
mkdir -p "$APP_DIR/logs"

# Code owned by root, group = www-data (33)
chown -R ${ROOT_UID}:${WWW_GID} "$APP_DIR" || true

# Dirs 755, files 644
find "$APP_DIR" -type d -print0 | xargs -0 chmod 755
find "$APP_DIR" -type f -print0 | xargs -0 chmod 644

# Writable dirs for the app at runtime
for d in logs storage cache tmp runtime; do
  if [ -d "$APP_DIR/$d" ]; then
    echo "   - Making $d writable by ${WWW_UID}:${WWW_GID}"
    chown -R ${WWW_UID}:${WWW_GID} "$APP_DIR/$d"
    chmod -R 775 "$APP_DIR/$d"
  fi
done

# Allow installer to create .installed at project root
chmod 775 "$APP_DIR"

# .env must be writable by www-data (installer needs to write encryption key)
if [ -f "$APP_DIR/.env" ]; then
  echo "   - Making .env writable by ${WWW_UID}:${WWW_GID} for installation"
  chown ${WWW_UID}:${WWW_GID} "$APP_DIR/.env"
  chmod 660 "$APP_DIR/.env"
fi

# Install vendors via Composer container
echo "==> Installing Composer vendors (composer:2) ..."
docker run --rm -v "$APP_DIR":/app -w /app composer:2 install --no-interaction --prefer-dist

# Bring stack up (build/rebuild)
echo "==> Starting stack ..."
dc up -d --build

echo "==> Done."
echo "    App URL:     http://<SERVER_IP>:8080"
echo "    phpMyAdmin:  http://<SERVER_IP>:8081 (Server: domain-monitor-mariadb)"
echo "    Note: On the host, UID 33 may display as 'tape'; inside the container it's www-data. This is normal."
