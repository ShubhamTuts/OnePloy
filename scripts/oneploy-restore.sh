#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
RESTORE_ROOT="/data/coolify"
ENV_FILE="${ONEPLOY_ENV_FILE:-${RESTORE_ROOT}/source/.env}"
ARCHIVE=""
CONFIRMED=false

while [ "$#" -gt 0 ]; do
    case "$1" in
        --archive) ARCHIVE="${2:-}"; shift 2 ;;
        --confirm-restore) CONFIRMED=true; shift ;;
        *) echo "Unknown option: $1"; exit 1 ;;
    esac
done

if [ "$(id -u)" -ne 0 ]; then
    echo "Run this restore as root."
    exit 1
fi
if [ "$CONFIRMED" != "true" ] || [ -z "$ARCHIVE" ]; then
    echo "Usage: $0 --archive /absolute/path/backup.tar.gz.enc --confirm-restore"
    exit 1
fi
if [ "$(realpath -m "$RESTORE_ROOT")" != "/data/coolify" ]; then
    echo "refusing unsafe restore target: ${RESTORE_ROOT}"
    exit 1
fi
if [ ! -f "$ENV_FILE" ]; then
    echo "Install a clean OnePloy control plane before restoring."
    exit 1
fi

read_env() {
    local key="$1"
    awk -F= -v key="$key" '$1 == key {sub(/^[^=]*=/, ""); sub(/\r$/, ""); print; exit}' "$ENV_FILE"
}

CURRENT_DB_USERNAME="$(read_env DB_USERNAME)"
CURRENT_DB_PASSWORD="$(read_env DB_PASSWORD)"
CURRENT_DB_DATABASE="$(read_env DB_DATABASE)"
CURRENT_REDIS_PASSWORD="$(read_env REDIS_PASSWORD)"
APP_PORT="$(read_env APP_PORT)"
CURRENT_DB_USERNAME="${CURRENT_DB_USERNAME:-coolify}"
CURRENT_DB_DATABASE="${CURRENT_DB_DATABASE:-coolify}"
APP_PORT="${APP_PORT:-8000}"
PASSPHRASE_FILE="${ONEPLOY_BACKUP_PASSPHRASE_FILE:-$(read_env ONEPLOY_BACKUP_PASSPHRASE_FILE)}"

"${SCRIPT_DIR}/oneploy-backup-verify.sh" "$ARCHIVE"
"${SCRIPT_DIR}/oneploy-backup.sh"

RESTORE_DIR="$(mktemp -d)"
trap 'rm -rf -- "$RESTORE_DIR"' EXIT
openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 \
    -in "$ARCHIVE" -out "${RESTORE_DIR}/backup.tar.gz" -pass "file:${PASSPHRASE_FILE}"
tar -xzf "${RESTORE_DIR}/backup.tar.gz" -C "$RESTORE_DIR"

if tar -tzf "${RESTORE_DIR}/control-plane-files.tar.gz" | grep -Eq '(^/|(^|/)\.\.(/|$))'; then
    echo "refusing unsafe restore target from archive paths"
    exit 1
fi
mkdir -p "${RESTORE_DIR}/files"
tar -xzf "${RESTORE_DIR}/control-plane-files.tar.gz" -C "${RESTORE_DIR}/files"

cd "${RESTORE_ROOT}/source"
docker compose --env-file "$ENV_FILE" -f docker-compose.yml -f docker-compose.oneploy.yml stop coolify powerdns

docker cp "${RESTORE_DIR}/control-plane.dump" coolify-db:/tmp/oneploy-restore.dump
docker exec coolify-db dropdb -U "$CURRENT_DB_USERNAME" --if-exists "$CURRENT_DB_DATABASE"
docker exec coolify-db createdb -U "$CURRENT_DB_USERNAME" "$CURRENT_DB_DATABASE"
docker exec coolify-db pg_restore --clean --if-exists --no-owner --no-privileges \
    -U "$CURRENT_DB_USERNAME" -d "$CURRENT_DB_DATABASE" /tmp/oneploy-restore.dump
docker exec coolify-db rm -f /tmp/oneploy-restore.dump

install -d -m 700 "${RESTORE_ROOT}/ssh" "${RESTORE_ROOT}/proxy"
cp -a "${RESTORE_DIR}/files/ssh/." "${RESTORE_ROOT}/ssh/"
cp -a "${RESTORE_DIR}/files/proxy/." "${RESTORE_ROOT}/proxy/"
install -m 600 "${RESTORE_DIR}/environment.env" "$ENV_FILE"

set_env() {
    local key="$1"
    local value="$2"
    local escaped_value
    escaped_value="$(printf '%s' "$value" | sed 's/[\\&|]/\\&/g')"
    if grep -q "^${key}=" "$ENV_FILE"; then
        sed -i "s|^${key}=.*|${key}=${escaped_value}|" "$ENV_FILE"
    else
        printf '%s=%s\n' "$key" "$value" >> "$ENV_FILE"
    fi
}
set_env DB_USERNAME "$CURRENT_DB_USERNAME"
set_env DB_PASSWORD "$CURRENT_DB_PASSWORD"
set_env DB_DATABASE "$CURRENT_DB_DATABASE"
set_env REDIS_PASSWORD "$CURRENT_REDIS_PASSWORD"

docker run --rm --network none \
    --entrypoint sh \
    --volume oneploy-powerdns:/volume \
    --volume "${RESTORE_DIR}:/backup:ro" \
    oneploy/helper:latest \
    -c 'find /volume -mindepth 1 -maxdepth 1 -exec rm -rf -- {} + && tar -xzf /backup/powerdns-volume.tar.gz -C /volume'

docker compose --env-file "$ENV_FILE" -f docker-compose.yml -f docker-compose.oneploy.yml up -d --remove-orphans
for attempt in $(seq 1 90); do
    if curl -fsS --max-time 5 "http://127.0.0.1:${APP_PORT}/api/health" >/dev/null 2>&1; then
        echo "OnePloy restore completed and the control plane is healthy."
        exit 0
    fi
    sleep 2
done

echo "Restore finished, but the api/health check did not recover. Inspect docker logs coolify."
exit 1
