#!/usr/bin/env bash
set -Eeuo pipefail

ONEPLOY_REPOSITORY="${ONEPLOY_REPOSITORY:-ShubhamTuts/OnePloy}"
ONEPLOY_SOURCE_REF="${ONEPLOY_SOURCE_REF:-main}"
ONEPLOY_VERSION="${ONEPLOY_VERSION:-${1:-latest}}"
ONEPLOY_DATA_DIR="${ONEPLOY_DATA_DIR:-/data/oneploy}"
ONEPLOY_SOURCE_DIR="${ONEPLOY_DATA_DIR}/source"
RAW_BASE="${ONEPLOY_RAW_BASE:-https://raw.githubusercontent.com/${ONEPLOY_REPOSITORY}/${ONEPLOY_SOURCE_REF}}"
DATE="$(date +%Y%m%d-%H%M%S)"
LOG_FILE="${ONEPLOY_SOURCE_DIR}/installation-${DATE}.log"

log() { printf '[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*"; }
fail() { log "ERROR: $*"; exit 1; }

if [[ "${EUID}" -ne 0 ]]; then
  fail "Run the OnePloy installer as root or with sudo."
fi

if [[ ! -r /etc/os-release ]]; then
  fail "Cannot determine operating system."
fi

# shellcheck disable=SC1091
source /etc/os-release
OS_ID="${ID:-unknown}"
OS_VERSION="${VERSION_ID:-unknown}"
case "${OS_ID}" in
  ubuntu|debian) ;;
  *) fail "OnePloy V1 installer currently supports Ubuntu and Debian. Detected ${OS_ID} ${OS_VERSION}." ;;
esac

mkdir -p "${ONEPLOY_SOURCE_DIR}"
exec > >(tee -a "${LOG_FILE}") 2>&1

log "OnePloy installation ${DATE}"
log "Repository: ${ONEPLOY_REPOSITORY}"
log "Source ref: ${ONEPLOY_SOURCE_REF}"
log "Requested version: ${ONEPLOY_VERSION}"

apt-get update -y
DEBIAN_FRONTEND=noninteractive apt-get install -y ca-certificates curl git jq openssl openssh-client iproute2

TOTAL_MEM_MB="$(awk '/MemTotal/ {printf "%d", $2/1024}' /proc/meminfo)"
AVAILABLE_GB="$(df -BG / | awk 'NR==2 {gsub("G", "", $4); print $4}')"
CPU_COUNT="$(nproc)"
log "Preflight: ${CPU_COUNT} CPU, ${TOTAL_MEM_MB} MB RAM, ${AVAILABLE_GB} GB free disk"

if (( TOTAL_MEM_MB < 1900 )); then
  log "WARNING: less than 2 GB RAM detected. 4 vCPU / 8 GB RAM is recommended for a commercial control plane."
fi
if (( AVAILABLE_GB < 20 )); then
  fail "At least 20 GB free disk is required."
fi

if ! command -v docker >/dev/null 2>&1; then
  log "Installing Docker Engine"
  curl -fsSL https://get.docker.com | sh
fi

systemctl enable --now docker >/dev/null 2>&1 || true
docker info >/dev/null 2>&1 || fail "Docker is installed but the daemon is not reachable."
docker compose version >/dev/null 2>&1 || fail "Docker Compose v2 is required."

PUBLIC_IPV4="${ONEPLOY_PUBLIC_IPV4:-}"
PUBLIC_IPV6="${ONEPLOY_PUBLIC_IPV6:-}"
PRIVATE_IP="${ONEPLOY_PRIVATE_IP:-}"

if [[ -z "${PUBLIC_IPV4}" ]]; then
  PUBLIC_IPV4="$(curl -4fsS --max-time 4 https://api.ipify.org 2>/dev/null || true)"
fi
if [[ -z "${PUBLIC_IPV4}" ]]; then
  PUBLIC_IPV4="$(curl -4fsS --max-time 4 https://ifconfig.co/ip 2>/dev/null | tr -d '[:space:]' || true)"
fi
if [[ -z "${PUBLIC_IPV6}" ]]; then
  PUBLIC_IPV6="$(curl -6fsS --max-time 4 https://api64.ipify.org 2>/dev/null || true)"
fi
if [[ -z "${PRIVATE_IP}" ]]; then
  PRIVATE_IP="$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{for (i=1;i<=NF;i++) if ($i=="src") {print $(i+1); exit}}' || true)"
fi
if [[ -z "${PRIVATE_IP}" ]]; then
  PRIVATE_IP="$(hostname -I 2>/dev/null | awk '{print $1}' || true)"
fi

log "Detected public IPv4: ${PUBLIC_IPV4:-not detected}"
log "Detected public IPv6: ${PUBLIC_IPV6:-not detected}"
log "Detected private IP: ${PRIVATE_IP:-not detected}"

mkdir -p \
  "${ONEPLOY_DATA_DIR}/ssh/keys" \
  "${ONEPLOY_DATA_DIR}/ssh/mux" \
  "${ONEPLOY_DATA_DIR}/applications" \
  "${ONEPLOY_DATA_DIR}/databases" \
  "${ONEPLOY_DATA_DIR}/services" \
  "${ONEPLOY_DATA_DIR}/backups" \
  "${ONEPLOY_DATA_DIR}/images" \
  "${ONEPLOY_DATA_DIR}/proxy/dynamic"

chown -R 9999:root "${ONEPLOY_DATA_DIR}"
chmod -R 700 "${ONEPLOY_DATA_DIR}"

SSH_KEY="${ONEPLOY_DATA_DIR}/ssh/keys/id.root@host.docker.internal"
if [[ ! -f "${SSH_KEY}" ]]; then
  ssh-keygen -t ed25519 -a 64 -N '' -C 'root@oneploy-local' -f "${SSH_KEY}"
fi
mkdir -p /root/.ssh
chmod 700 /root/.ssh
touch /root/.ssh/authorized_keys
chmod 600 /root/.ssh/authorized_keys
PUB_KEY="$(cat "${SSH_KEY}.pub")"
grep -qxF "${PUB_KEY}" /root/.ssh/authorized_keys || printf '%s\n' "${PUB_KEY}" >> /root/.ssh/authorized_keys

log "Downloading OnePloy production configuration"
curl -fsSL "${RAW_BASE}/docker-compose.yml" -o "${ONEPLOY_SOURCE_DIR}/docker-compose.yml"
curl -fsSL "${RAW_BASE}/docker-compose.prod.yml" -o "${ONEPLOY_SOURCE_DIR}/docker-compose.prod.yml"
curl -fsSL "${RAW_BASE}/.env.production" -o "${ONEPLOY_SOURCE_DIR}/.env.production"
curl -fsSL "${RAW_BASE}/scripts/oneploy-upgrade.sh" -o "${ONEPLOY_SOURCE_DIR}/oneploy-upgrade.sh"
chmod 700 "${ONEPLOY_SOURCE_DIR}/oneploy-upgrade.sh"

ENV_FILE="${ONEPLOY_SOURCE_DIR}/.env"
if [[ ! -f "${ENV_FILE}" ]]; then
  cp "${ONEPLOY_SOURCE_DIR}/.env.production" "${ENV_FILE}"
fi

set_env() {
  local key="$1" value="$2"
  if grep -q "^${key}=" "${ENV_FILE}"; then
    sed -i "s|^${key}=.*|${key}=${value}|" "${ENV_FILE}"
  else
    printf '%s=%s\n' "${key}" "${value}" >> "${ENV_FILE}"
  fi
}

set_if_empty() {
  local key="$1" value="$2"
  if ! grep -q "^${key}=." "${ENV_FILE}"; then
    set_env "${key}" "${value}"
  fi
}

set_env APP_NAME OnePloy
set_env APP_ENV production
set_env ONEPLOY_VERSION "${ONEPLOY_VERSION}"
set_env ONEPLOY_SOURCE_REF "${ONEPLOY_SOURCE_REF}"
set_env ONEPLOY_PUBLIC_IPV4 "${PUBLIC_IPV4}"
set_env ONEPLOY_PUBLIC_IPV6 "${PUBLIC_IPV6}"
set_env ONEPLOY_PRIVATE_IP "${PRIVATE_IP}"
set_env BASE_CONFIG_PATH "${ONEPLOY_DATA_DIR}"
set_env DB_DATABASE "${DB_DATABASE:-oneploy}"
set_env DB_USERNAME "${DB_USERNAME:-oneploy}"

set_if_empty APP_ID "$(openssl rand -hex 16)"
set_if_empty APP_KEY "base64:$(openssl rand -base64 32 | tr -d '\n')"
set_if_empty DB_PASSWORD "$(openssl rand -hex 32)"
set_if_empty REDIS_PASSWORD "$(openssl rand -hex 32)"
set_if_empty PUSHER_APP_ID "$(openssl rand -hex 16)"
set_if_empty PUSHER_APP_KEY "$(openssl rand -hex 32)"
set_if_empty PUSHER_APP_SECRET "$(openssl rand -hex 32)"

if [[ -n "${ROOT_USERNAME:-}" ]]; then set_env ROOT_USERNAME "${ROOT_USERNAME}"; fi
if [[ -n "${ROOT_USER_EMAIL:-}" ]]; then set_env ROOT_USER_EMAIL "${ROOT_USER_EMAIL}"; fi
if [[ -n "${ROOT_USER_PASSWORD:-}" ]]; then set_env ROOT_USER_PASSWORD "${ROOT_USER_PASSWORD}"; fi
if [[ -n "${ONEPLOY_SUPPORT_EMAIL:-}" ]]; then set_env ONEPLOY_SUPPORT_EMAIL "${ONEPLOY_SUPPORT_EMAIL}"; fi

# Temporary compatibility network for inherited internal code. It is local-only
# and is not a Coolify service or external dependency.
if ! docker network inspect coolify >/dev/null 2>&1; then
  docker network create --attachable coolify >/dev/null
fi

if [[ -n "${GHCR_TOKEN:-}" ]]; then
  printf '%s' "${GHCR_TOKEN}" | docker login ghcr.io -u "${GHCR_USERNAME:-${USER:-oneploy}}" --password-stdin
fi

COMPOSE=(
  docker compose
  --env-file "${ENV_FILE}"
  -f "${ONEPLOY_SOURCE_DIR}/docker-compose.yml"
  -f "${ONEPLOY_SOURCE_DIR}/docker-compose.prod.yml"
)

log "Pulling OnePloy images"
"${COMPOSE[@]}" pull

log "Starting OnePloy"
"${COMPOSE[@]}" up -d --remove-orphans --wait --wait-timeout 180

HEALTH_URL="http://127.0.0.1:${APP_PORT:-8000}/api/health"
for _ in $(seq 1 60); do
  if curl -fsS "${HEALTH_URL}" >/dev/null 2>&1; then
    break
  fi
  sleep 2
done
curl -fsS "${HEALTH_URL}" >/dev/null 2>&1 || fail "OnePloy containers started but the health endpoint did not become ready. Check ${LOG_FILE}."

LOGIN_HOST="${PUBLIC_IPV4:-${PRIVATE_IP:-localhost}}"
cat <<EOF

============================================================
OnePloy installation complete
============================================================
Version:        ${ONEPLOY_VERSION}
Public IPv4:    ${PUBLIC_IPV4:-not detected}
Public IPv6:    ${PUBLIC_IPV6:-not detected}
Private IP:     ${PRIVATE_IP:-not detected}
Local URL:      http://${LOGIN_HOST}:${APP_PORT:-8000}
Data directory: ${ONEPLOY_DATA_DIR}
Log:            ${LOG_FILE}

Next:
1. Point app.oneploy.dev to the detected public IPv4.
2. Open the local URL and complete the initial administrator setup.
3. Set the instance URL to https://app.oneploy.dev and enable the platform proxy/TLS.
4. After HTTPS works, restrict direct public access to port 8000.
============================================================
EOF
