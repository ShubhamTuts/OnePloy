#!/usr/bin/env bash
# OnePloy production installer.
# Builds OnePloy from this repository (not CoolLabs images) and starts the control plane.
#
# Ubuntu 22.04 / 24.04 example:
#   curl -fsSL https://raw.githubusercontent.com/ShubhamTuts/OnePloy/main/scripts/oneploy-install.sh | sudo bash -s -- --fqdn app.oneploy.dev --email admin@oneploy.dev
#
# Or from a cloned checkout:
#   sudo FQDN=app.oneploy.dev EMAIL=admin@oneploy.dev bash scripts/oneploy-install.sh

set -euo pipefail

ONEPLOY_REPO="${ONEPLOY_REPO:-https://github.com/ShubhamTuts/OnePloy.git}"
ONEPLOY_BRANCH="${ONEPLOY_BRANCH:-main}"
ONEPLOY_DIR="${ONEPLOY_DIR:-/opt/oneploy}"
DATA_DIR="/data/coolify"
SOURCE_DIR="${DATA_DIR}/source"
ENV_FILE="${SOURCE_DIR}/.env"
DATE="$(date +%Y%m%d-%H%M%S)"
FQDN="${FQDN:-}"
EMAIL="${EMAIL:-}"
APP_PORT="${APP_PORT:-8000}"
SKIP_HELPER="${SKIP_HELPER:-false}"
ROOT_USERNAME="${ROOT_USERNAME:-}"
ROOT_USER_EMAIL="${ROOT_USER_EMAIL:-}"
ROOT_USER_PASSWORD="${ROOT_USER_PASSWORD:-}"

on_error() {
    echo "OnePloy installation stopped at line ${1}. Review the message above before retrying."
}
trap 'on_error ${LINENO}' ERR

usage() {
    cat <<'EOF'
OnePloy installer

Options:
  --fqdn HOST          Public hostname (example: app.oneploy.dev). DNS A/AAAA must already point here for SSL.
  --email ADDRESS      Let's Encrypt / admin contact email
  --repo URL           Git repository to clone (default: https://github.com/ShubhamTuts/OnePloy.git)
  --branch NAME        Git branch (default: main)
  --port N             Dashboard HTTP port before SSL (default: 8000)
  --skip-helper        Skip building the deploy helper image (not recommended)
  -h, --help           Show this help

Environment:
  ROOT_USERNAME / ROOT_USER_EMAIL / ROOT_USER_PASSWORD   Optional first admin user
  SKIP_HELPER=true                                       Same as --skip-helper
EOF
}

while [ $# -gt 0 ]; do
    case "$1" in
        --fqdn) FQDN="${2:-}"; shift 2 ;;
        --email) EMAIL="${2:-}"; shift 2 ;;
        --repo) ONEPLOY_REPO="${2:-}"; shift 2 ;;
        --branch) ONEPLOY_BRANCH="${2:-}"; shift 2 ;;
        --port) APP_PORT="${2:-}"; shift 2 ;;
        --skip-helper) SKIP_HELPER=true; shift ;;
        -h|--help) usage; exit 0 ;;
        *) echo "Unknown argument: $1"; usage; exit 1 ;;
    esac
done

if [ "$(id -u)" -ne 0 ]; then
    echo "Run this installer as root (sudo)."
    exit 1
fi

FQDN="${FQDN#http://}"
FQDN="${FQDN#https://}"
FQDN="${FQDN%%/*}"
if [ -n "$FQDN" ] && ! printf '%s' "$FQDN" | grep -Eq '^([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,63}$'; then
    echo "Invalid --fqdn value: $FQDN"
    exit 1
fi
if [ -n "$EMAIL" ] && ! printf '%s' "$EMAIL" | grep -Eq '^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$'; then
    echo "Invalid --email value: $EMAIL"
    exit 1
fi
if ! printf '%s' "$APP_PORT" | grep -Eq '^[0-9]+$' || [ "$APP_PORT" -lt 1 ] || [ "$APP_PORT" -gt 65535 ]; then
    echo "Invalid --port value: $APP_PORT"
    exit 1
fi

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"; }
section() {
    echo ""
    echo "============================================================"
    echo " $*"
    echo "============================================================"
}

detect_public_ip() {
    curl -4fsS --max-time 8 https://ifconfig.io 2>/dev/null || curl -4fsS --max-time 8 https://api.ipify.org 2>/dev/null || true
}

detect_private_ip() {
    ip -4 route get 1.1.1.1 2>/dev/null | awk '{for (i=1;i<=NF;i++) if ($i=="src") print $(i+1)}' | head -n1
}

if [ -z "$FQDN" ] && [ -n "${1:-}" ]; then
    FQDN="$1"
fi

section "OnePloy installation"
echo "Repository: ${ONEPLOY_REPO} (${ONEPLOY_BRANCH})"
echo "Install dir: ${ONEPLOY_DIR}"
echo "Data dir:    ${DATA_DIR} (legacy path kept for engine compatibility)"
echo "FQDN:        ${FQDN:-not set — HTTP on :${APP_PORT} only}"
echo "Email:       ${EMAIL:-not set}"

OS_ID="$(. /etc/os-release; echo "${ID:-unknown}")"
OS_VERSION="$(. /etc/os-release; echo "${VERSION_ID:-unknown}")"
OS_CODENAME="$(. /etc/os-release; echo "${VERSION_CODENAME:-}")"
ARCH="$(uname -m)"
MEMORY_GIB="$(awk '/MemTotal/{printf "%d",$2/1024/1024}' /proc/meminfo)"
DISK_GIB="$(df -Pk / | awk 'NR==2{printf "%d",$4/1024/1024}')"
log "OS=${OS_ID} ${OS_VERSION} arch=${ARCH} cpu=$(nproc) ram=${MEMORY_GIB}GiB disk=${DISK_GIB}GiB free"

if [ "$OS_ID" != "ubuntu" ] || { [ "$OS_VERSION" != "22.04" ] && [ "$OS_VERSION" != "24.04" ]; }; then
    echo "Supported operating systems: Ubuntu 22.04 LTS and Ubuntu 24.04 LTS."
    exit 1
fi
if [ "$ARCH" != "x86_64" ]; then
    echo "This source-build release is gated to x86_64 until ARM release acceptance passes."
    exit 1
fi
if [ "$MEMORY_GIB" -lt 4 ] || [ "$DISK_GIB" -lt 30 ]; then
    echo "Insufficient capacity. OnePloy requires at least 4 GiB RAM and 30 GiB free disk for installation."
    exit 1
fi
if [ "$MEMORY_GIB" -lt 8 ]; then
    log "Warning: 8 GiB RAM is recommended for the first local image build."
fi

section "1/8 Packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y curl wget git jq openssl ca-certificates gnupg lsb-release

section "2/8 Docker Engine"
if ! command -v docker >/dev/null 2>&1; then
    install -m 0755 -d /etc/apt/keyrings
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
    chmod a+r /etc/apt/keyrings/docker.asc
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu ${OS_CODENAME} stable" \
        >/etc/apt/sources.list.d/docker.list
    apt-get update -y
    apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
fi
systemctl enable --now docker
docker version >/dev/null
if ! docker compose version >/dev/null 2>&1; then
    apt-get install -y docker-compose-plugin
fi

mkdir -p /etc/docker
if [ ! -f /etc/docker/daemon.json ]; then
    cat >/etc/docker/daemon.json <<'EOL'
{
  "log-driver": "json-file",
  "log-opts": {
    "max-size": "10m",
    "max-file": "3"
  }
}
EOL
    systemctl restart docker
fi

section "3/8 Directories and SSH"
mkdir -p "${DATA_DIR}"/{source,ssh,applications,databases,backups,services,proxy,sentinel,images}
mkdir -p "${DATA_DIR}/ssh/keys" "${DATA_DIR}/ssh/mux" "${DATA_DIR}/proxy/dynamic"
chown -R 9999:root "${DATA_DIR}" || true
chmod -R 700 "${DATA_DIR}"

CURRENT_USER="${SUDO_USER:-root}"
CURRENT_HOME="$(getent passwd "$CURRENT_USER" | cut -d: -f6)"
CURRENT_GROUP="$(id -gn "$CURRENT_USER")"
if [ -z "$CURRENT_HOME" ]; then
    echo "Could not resolve the home directory for $CURRENT_USER."
    exit 1
fi
AUTHORIZED_KEYS="${CURRENT_HOME}/.ssh/authorized_keys"
install -d -m 700 -o "$CURRENT_USER" -g "$CURRENT_GROUP" "${CURRENT_HOME}/.ssh"
touch "$AUTHORIZED_KEYS"
chown "$CURRENT_USER:$CURRENT_GROUP" "$AUTHORIZED_KEYS"
chmod 600 "$AUTHORIZED_KEYS"

KEY_FILE="${DATA_DIR}/ssh/keys/id.${CURRENT_USER}@host.docker.internal"
if [ ! -f "$KEY_FILE" ]; then
    ssh-keygen -t ed25519 -a 100 -f "$KEY_FILE" -q -N "" -C oneploy
    chown 9999 "$KEY_FILE"
    sed -i "/oneploy\|coolify/d" "$AUTHORIZED_KEYS" || true
    cat "${KEY_FILE}.pub" >> "$AUTHORIZED_KEYS"
    rm -f "${KEY_FILE}.pub"
fi

section "4/8 Source"
if [ -d "${ONEPLOY_DIR}/.git" ]; then
    git -C "${ONEPLOY_DIR}" fetch --depth 1 origin "${ONEPLOY_BRANCH}"
    git -C "${ONEPLOY_DIR}" checkout "${ONEPLOY_BRANCH}"
    git -C "${ONEPLOY_DIR}" reset --hard "origin/${ONEPLOY_BRANCH}"
else
    if [ -e "${ONEPLOY_DIR}" ] && [ -n "$(find "${ONEPLOY_DIR}" -mindepth 1 -maxdepth 1 -print -quit 2>/dev/null)" ]; then
        echo "${ONEPLOY_DIR} exists but is not a Git checkout. Move it aside and retry."
        exit 1
    fi
    git clone --depth 1 --branch "${ONEPLOY_BRANCH}" "${ONEPLOY_REPO}" "${ONEPLOY_DIR}"
fi

cp -f "${ONEPLOY_DIR}/docker-compose.yml" "${SOURCE_DIR}/docker-compose.yml"
cp -f "${ONEPLOY_DIR}/docker-compose.oneploy.yml" "${SOURCE_DIR}/docker-compose.oneploy.yml"
cp -f "${ONEPLOY_DIR}/scripts/oneploy-upgrade.sh" "${SOURCE_DIR}/oneploy-upgrade.sh"
chmod +x "${SOURCE_DIR}/oneploy-upgrade.sh"

if [ -f "${ONEPLOY_DIR}/.env.production" ]; then
    if [ -f "$ENV_FILE" ]; then
        cp "$ENV_FILE" "${ENV_FILE}-${DATE}"
        awk -F '=' '!seen[$1]++' "$ENV_FILE" "${ONEPLOY_DIR}/.env.production" > "${ENV_FILE}.tmp" && mv "${ENV_FILE}.tmp" "$ENV_FILE"
    else
        cp "${ONEPLOY_DIR}/.env.production" "$ENV_FILE"
    fi
else
    touch "$ENV_FILE"
fi

set_env() {
    local key="$1"
    local value="$2"
    if grep -q "^${key}=$" "$ENV_FILE" || ! grep -q "^${key}=" "$ENV_FILE"; then
        if grep -q "^${key}=" "$ENV_FILE"; then
            sed -i "s|^${key}=.*|${key}=${value}|" "$ENV_FILE"
        else
            printf '%s=%s\n' "$key" "$value" >>"$ENV_FILE"
        fi
    fi
}

force_env() {
    local key="$1"
    local value="$2"
    if grep -q "^${key}=" "$ENV_FILE"; then
        sed -i "s|^${key}=.*|${key}=${value}|" "$ENV_FILE"
    else
        printf '%s=%s\n' "$key" "$value" >>"$ENV_FILE"
    fi
}

rand_hex() { openssl rand -hex "$1"; }
rand_b64() { openssl rand -base64 32 | tr -d '\n'; }

set_env APP_ID "$(rand_hex 16)"
set_env APP_KEY "base64:$(openssl rand -base64 32 | tr -d '\n')"
set_env DB_USERNAME "coolify"
set_env DB_PASSWORD "$(rand_b64)"
set_env REDIS_PASSWORD "$(rand_b64)"
set_env PUSHER_APP_ID "$(rand_hex 16)"
set_env PUSHER_APP_KEY "$(rand_hex 16)"
set_env PUSHER_APP_SECRET "$(rand_hex 16)"
force_env APP_PORT "$APP_PORT"
force_env APP_NAME "OnePloy"
force_env APP_ENV "production"
force_env SELF_HOSTED "true"
force_env AUTOUPDATE "false"
force_env HELPER_IMAGE "oneploy/helper"
force_env REALTIME_IMAGE "oneploy/realtime"
force_env ONEPLOY_OWN_RELEASES "true"
force_env ONEPLOY_PLATFORM "true"
force_env ONEPLOY_APP_IMAGE "oneploy/app:local"
force_env ONEPLOY_REALTIME_IMAGE "oneploy/realtime:local"
if [ -n "$FQDN" ]; then
    force_env APP_BIND_ADDRESS "127.0.0.1"
else
    force_env APP_BIND_ADDRESS "0.0.0.0"
fi

if [ -n "$FQDN" ]; then
    force_env APP_URL "https://${FQDN}"
    force_env APP_FQDN "$FQDN"
else
    set_env APP_URL "http://$(detect_private_ip):${APP_PORT}"
fi
if [ -n "$EMAIL" ]; then
    force_env ONEPLOY_SUPPORT_EMAIL "$EMAIL"
    force_env LETSENCRYPT_EMAIL "$EMAIL"
fi
if [ -n "$ROOT_USERNAME" ]; then force_env ROOT_USERNAME "$ROOT_USERNAME"; fi
if [ -n "$ROOT_USER_EMAIL" ]; then force_env ROOT_USER_EMAIL "$ROOT_USER_EMAIL"; fi
if [ -n "$ROOT_USER_PASSWORD" ]; then force_env ROOT_USER_PASSWORD "$ROOT_USER_PASSWORD"; fi

chown -R 9999:root "${DATA_DIR}" || true
chmod 700 "$ENV_FILE"

section "5/8 Build images (this can take 15–40 minutes)"
docker network inspect coolify >/dev/null 2>&1 || docker network create coolify
cd "${ONEPLOY_DIR}"
docker build -t oneploy/app:local -f docker/production/Dockerfile .
docker build -t oneploy/realtime:local -f docker/coolify-realtime/Dockerfile .
if [ "$SKIP_HELPER" != "true" ]; then
    docker build -t oneploy/helper:1.0.16 -f docker/coolify-helper/Dockerfile .
    docker tag oneploy/helper:1.0.16 oneploy/helper:latest
else
    log "Skipping helper image build. Git deployments will fail until oneploy/helper:1.0.16 exists."
fi

section "6/8 Start control plane"
cd "${SOURCE_DIR}"
docker compose --env-file "$ENV_FILE" -f docker-compose.yml -f docker-compose.oneploy.yml config --quiet
docker compose --env-file "$ENV_FILE" -f docker-compose.yml -f docker-compose.oneploy.yml up -d --remove-orphans

section "7/8 Health"
HEALTH="unknown"
for i in $(seq 1 90); do
    HEALTH="$(docker inspect --format='{{.State.Health.Status}}' coolify 2>/dev/null || echo unknown)"
    if [ "$HEALTH" = "healthy" ]; then
        break
    fi
    sleep 2
done
if [ "$HEALTH" != "healthy" ]; then
    echo "OnePloy container is not healthy yet (status: ${HEALTH}). Check: docker logs coolify"
    docker logs --tail 80 coolify || true
    exit 1
fi

section "8/8 Instance bootstrap"
BOOTSTRAP_ARGS=()
if [ -n "$FQDN" ]; then BOOTSTRAP_ARGS+=(--fqdn "https://${FQDN}"); fi
if [ -n "$EMAIL" ]; then BOOTSTRAP_ARGS+=(--email "$EMAIL"); fi
docker exec coolify php artisan oneploy:bootstrap "${BOOTSTRAP_ARGS[@]}"

PUBLIC_IP="$(detect_public_ip)"
PRIVATE_IP="$(detect_private_ip)"

cat <<EOF

============================================================
 OnePloy is running
============================================================
Local recovery:  http://127.0.0.1:${APP_PORT}
EOF

if [ -n "$FQDN" ]; then
    if [ "$(docker inspect --format='{{.State.Running}}' coolify-proxy 2>/dev/null || echo false)" != "true" ]; then
        echo "The HTTPS proxy did not start. Check: docker logs coolify-proxy"
        exit 1
    fi

    log "Waiting for HTTPS certificate and public health check"
    HTTPS_READY=false
    for i in $(seq 1 60); do
        if curl -fsS --max-time 10 "https://${FQDN}/api/health" >/dev/null 2>&1; then
            HTTPS_READY=true
            break
        fi
        sleep 5
    done
    if [ "$HTTPS_READY" != "true" ]; then
        echo "The control plane is healthy, but HTTPS validation failed for https://${FQDN}."
        echo "Confirm DNS points to ${PUBLIC_IP:-this VPS} and ports 80/443 are open, then rerun the installer."
        docker logs --tail 80 coolify-proxy || true
        exit 1
    fi

    cat <<EOF
HTTPS URL:       https://${FQDN}

DNS: point ${FQDN} A (and AAAA if used) to ${PUBLIC_IP:-YOUR_VPS_IP}
Firewall: allow your SSH port, 80, and 443. Port ${APP_PORT} is bound to localhost.
SSL: active and verified through Traefik / Let's Encrypt.
EOF
else
    cat <<EOF
HTTP dashboard:  http://${PUBLIC_IP:-${PRIVATE_IP}}:${APP_PORT}
Firewall: allow your SSH port and ${APP_PORT}. Add --fqdn for automatic HTTPS.
EOF
fi

cat <<EOF

First login: open the dashboard and create the root user unless ROOT_USER_* was provided.
Upgrade later:
  sudo bash ${SOURCE_DIR}/oneploy-upgrade.sh

Keep a backup of ${ENV_FILE} off this server.

Logs: docker logs -f coolify
============================================================
EOF
