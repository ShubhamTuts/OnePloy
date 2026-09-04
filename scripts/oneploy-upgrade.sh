#!/usr/bin/env bash
# OnePloy-owned upgrade: pull git, rebuild images, migrate, restart. Never contacts CoolLabs.

set -euo pipefail

ONEPLOY_DIR="${ONEPLOY_DIR:-/opt/oneploy}"
ONEPLOY_BRANCH="${ONEPLOY_BRANCH:-main}"
SOURCE_DIR="/data/coolify/source"
ENV_FILE="${SOURCE_DIR}/.env"
DATE="$(date +%Y%m%d-%H%M%S)"
SKIP_BACKUP="${SKIP_BACKUP:-false}"

if [ "$(id -u)" -ne 0 ]; then
    echo "Run as root."
    exit 1
fi

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"; }

if [ ! -d "${ONEPLOY_DIR}/.git" ]; then
    echo "Missing ${ONEPLOY_DIR}. Re-run scripts/oneploy-install.sh."
    exit 1
fi

log "Backing up control-plane database"
if [ "$SKIP_BACKUP" != "true" ]; then
    if ! bash "${ONEPLOY_DIR}/scripts/oneploy-backup.sh"; then
        echo "Database backup failed. Upgrade aborted before changing source or containers."
        exit 1
    fi
fi

log "Updating source"
PREVIOUS_COMMIT="$(git -C "${ONEPLOY_DIR}" rev-parse HEAD)"
git -C "${ONEPLOY_DIR}" fetch --depth 1 origin "${ONEPLOY_BRANCH}"
git -C "${ONEPLOY_DIR}" checkout "${ONEPLOY_BRANCH}"
git -C "${ONEPLOY_DIR}" reset --hard "origin/${ONEPLOY_BRANCH}"

cp -f "${ONEPLOY_DIR}/docker-compose.yml" "${SOURCE_DIR}/docker-compose.yml"
cp -f "${ONEPLOY_DIR}/docker-compose.oneploy.yml" "${SOURCE_DIR}/docker-compose.oneploy.yml"
cp -f "${ONEPLOY_DIR}/docker-compose.dns-secondary.yml" "${SOURCE_DIR}/docker-compose.dns-secondary.yml"
for oneploy_script in oneploy-upgrade.sh oneploy-backup.sh oneploy-backup-verify.sh oneploy-restore.sh; do
    cp -f "${ONEPLOY_DIR}/scripts/${oneploy_script}" "${SOURCE_DIR}/${oneploy_script}"
    chmod +x "${SOURCE_DIR}/${oneploy_script}"
done

log "Rebuilding images"
cd "${ONEPLOY_DIR}"
docker build -t oneploy/app:local -f docker/production/Dockerfile .
docker build -t oneploy/realtime:local -f docker/coolify-realtime/Dockerfile .
docker build -t oneploy/helper:1.0.16 -f docker/coolify-helper/Dockerfile .
docker tag oneploy/helper:1.0.16 oneploy/helper:latest

log "Restarting stack"
cd "${SOURCE_DIR}"
docker compose --env-file "$ENV_FILE" -f docker-compose.yml -f docker-compose.oneploy.yml config --quiet
docker compose --env-file "$ENV_FILE" -f docker-compose.yml -f docker-compose.oneploy.yml up -d --remove-orphans

for i in $(seq 1 60); do
    HEALTH="$(docker inspect --format='{{.State.Health.Status}}' coolify 2>/dev/null || echo unknown)"
    [ "$HEALTH" = "healthy" ] && break
    sleep 2
done

if [ "${HEALTH:-unknown}" != "healthy" ]; then
    echo "Upgrade health check failed. Previous source commit: ${PREVIOUS_COMMIT}"
    echo "Inspect: docker logs --tail 120 coolify"
    docker logs --tail 120 coolify || true
    exit 1
fi

docker exec coolify php artisan oneploy:bootstrap
log "Upgrade complete. Health=healthy commit=$(git -C "${ONEPLOY_DIR}" rev-parse --short HEAD)"
