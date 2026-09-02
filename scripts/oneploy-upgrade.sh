#!/usr/bin/env bash
set -Eeuo pipefail

ONEPLOY_DATA_DIR="${ONEPLOY_DATA_DIR:-/data/oneploy}"
SOURCE_DIR="${ONEPLOY_DATA_DIR}/source"
ENV_FILE="${SOURCE_DIR}/.env"
TARGET_VERSION="${1:-}"
DATE="$(date +%Y%m%d-%H%M%S)"
LOG_FILE="${SOURCE_DIR}/upgrade-${DATE}.log"
STATUS_FILE="${SOURCE_DIR}/.upgrade-status"
BACKUP_DIR="${ONEPLOY_DATA_DIR}/backups/control-plane/${DATE}"

log() { printf '[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" | tee -a "${LOG_FILE}"; }
fail() { log "ERROR: $*"; printf 'error|%s|%s\n' "$*" "$(date -Iseconds)" > "${STATUS_FILE}"; exit 1; }
status() { printf '%s|%s|%s\n' "$1" "$2" "$(date -Iseconds)" > "${STATUS_FILE}"; }

[[ "${EUID}" -eq 0 ]] || fail "Run the OnePloy updater as root."
[[ -f "${ENV_FILE}" ]] || fail "OnePloy environment file not found at ${ENV_FILE}."

mkdir -p "${BACKUP_DIR}"
touch "${LOG_FILE}"

get_env() {
  local key="$1"
  grep -E "^${key}=" "${ENV_FILE}" | tail -n1 | cut -d= -f2- || true
}
set_env() {
  local key="$1" value="$2"
  if grep -q "^${key}=" "${ENV_FILE}"; then
    sed -i "s|^${key}=.*|${key}=${value}|" "${ENV_FILE}"
  else
    printf '%s=%s\n' "${key}" "${value}" >> "${ENV_FILE}"
  fi
}

CURRENT_VERSION="$(get_env ONEPLOY_VERSION)"
CURRENT_VERSION="${CURRENT_VERSION:-latest}"
MANIFEST_URL="$(get_env ONEPLOY_RELEASE_MANIFEST_URL)"
MANIFEST_URL="${MANIFEST_URL:-https://raw.githubusercontent.com/ShubhamTuts/OnePloy/main/releases/stable.json}"
SOURCE_REF="$(get_env ONEPLOY_SOURCE_REF)"
SOURCE_REF="${SOURCE_REF:-main}"
RAW_BASE="https://raw.githubusercontent.com/ShubhamTuts/OnePloy/${SOURCE_REF}"

if [[ -z "${TARGET_VERSION}" ]]; then
  log "Resolving stable version from ${MANIFEST_URL}"
  MANIFEST="$(curl -fsSL "${MANIFEST_URL}")" || fail "Could not download OnePloy release manifest."
  READY="$(printf '%s' "${MANIFEST}" | jq -r '.ready // false')"
  [[ "${READY}" == "true" ]] || fail "The stable OnePloy release channel is not marked ready. Pass an explicit tested version if you are performing a controlled upgrade."
  TARGET_VERSION="$(printf '%s' "${MANIFEST}" | jq -r '.version // empty')"
fi

[[ -n "${TARGET_VERSION}" ]] || fail "No target version was provided or resolved."
TARGET_VERSION="${TARGET_VERSION#v}"

log "OnePloy upgrade: ${CURRENT_VERSION} -> ${TARGET_VERSION}"
status preflight "Preparing upgrade"

cp "${ENV_FILE}" "${BACKUP_DIR}/.env"
cp "${SOURCE_DIR}/docker-compose.yml" "${BACKUP_DIR}/docker-compose.yml" 2>/dev/null || true
cp "${SOURCE_DIR}/docker-compose.prod.yml" "${BACKUP_DIR}/docker-compose.prod.yml" 2>/dev/null || true

DB_USER="$(get_env DB_USERNAME)"; DB_USER="${DB_USER:-oneploy}"
DB_NAME="$(get_env DB_DATABASE)"; DB_NAME="${DB_NAME:-oneploy}"
DB_PASSWORD="$(get_env DB_PASSWORD)"

status backup "Backing up control-plane database"
if docker ps --format '{{.Names}}' | grep -qx 'coolify-db'; then
  log "Creating PostgreSQL backup"
  docker exec -e "PGPASSWORD=${DB_PASSWORD}" coolify-db \
    pg_dump -U "${DB_USER}" -d "${DB_NAME}" -Fc > "${BACKUP_DIR}/database.dump" \
    || fail "Database backup failed; upgrade aborted."
else
  fail "Control-plane PostgreSQL container is not running."
fi

status config "Refreshing OnePloy release configuration"
curl -fsSL "${RAW_BASE}/docker-compose.yml" -o "${SOURCE_DIR}/docker-compose.yml.new" || fail "Could not download base compose file."
curl -fsSL "${RAW_BASE}/docker-compose.prod.yml" -o "${SOURCE_DIR}/docker-compose.prod.yml.new" || fail "Could not download production compose file."
mv "${SOURCE_DIR}/docker-compose.yml.new" "${SOURCE_DIR}/docker-compose.yml"
mv "${SOURCE_DIR}/docker-compose.prod.yml.new" "${SOURCE_DIR}/docker-compose.prod.yml"

set_env ONEPLOY_VERSION "${TARGET_VERSION}"

COMPOSE=(
  docker compose
  --env-file "${ENV_FILE}"
  -f "${SOURCE_DIR}/docker-compose.yml"
  -f "${SOURCE_DIR}/docker-compose.prod.yml"
)

status pull "Pulling OnePloy ${TARGET_VERSION}"
if ! "${COMPOSE[@]}" pull 2>&1 | tee -a "${LOG_FILE}"; then
  cp "${BACKUP_DIR}/.env" "${ENV_FILE}"
  fail "Image pull failed. Environment restored; running containers were not replaced."
fi

status restart "Starting OnePloy ${TARGET_VERSION}"
if ! "${COMPOSE[@]}" up -d --remove-orphans --wait --wait-timeout 180 2>&1 | tee -a "${LOG_FILE}"; then
  log "New containers did not become healthy. Attempting configuration rollback."
  cp "${BACKUP_DIR}/.env" "${ENV_FILE}"
  cp "${BACKUP_DIR}/docker-compose.yml" "${SOURCE_DIR}/docker-compose.yml" 2>/dev/null || true
  cp "${BACKUP_DIR}/docker-compose.prod.yml" "${SOURCE_DIR}/docker-compose.prod.yml" 2>/dev/null || true
  "${COMPOSE[@]}" up -d --remove-orphans --wait --wait-timeout 180 2>&1 | tee -a "${LOG_FILE}" || true
  fail "Upgrade failed and rollback was attempted. Review ${LOG_FILE}."
fi

APP_PORT="$(get_env APP_PORT)"; APP_PORT="${APP_PORT:-8000}"
status verify "Verifying OnePloy health"
for _ in $(seq 1 60); do
  if curl -fsS "http://127.0.0.1:${APP_PORT}/api/health" >/dev/null 2>&1; then
    status complete "Upgrade complete"
    log "OnePloy ${TARGET_VERSION} is healthy. Backup: ${BACKUP_DIR}"
    exit 0
  fi
  sleep 2
done

log "Health verification failed. Attempting rollback to ${CURRENT_VERSION}."
cp "${BACKUP_DIR}/.env" "${ENV_FILE}"
cp "${BACKUP_DIR}/docker-compose.yml" "${SOURCE_DIR}/docker-compose.yml" 2>/dev/null || true
cp "${BACKUP_DIR}/docker-compose.prod.yml" "${SOURCE_DIR}/docker-compose.prod.yml" 2>/dev/null || true
"${COMPOSE[@]}" pull 2>&1 | tee -a "${LOG_FILE}" || true
"${COMPOSE[@]}" up -d --remove-orphans --wait --wait-timeout 180 2>&1 | tee -a "${LOG_FILE}" || true
fail "Target version failed health verification. Rollback attempted; database backup is ${BACKUP_DIR}/database.dump."
