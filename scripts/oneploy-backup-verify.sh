#!/usr/bin/env bash

set -euo pipefail

ARCHIVE="${1:-}"
ENV_FILE="${ONEPLOY_ENV_FILE:-/data/coolify/source/.env}"

if [ -z "$ARCHIVE" ] || [ ! -f "$ARCHIVE" ]; then
    echo "Usage: $0 /absolute/path/oneploy-control-plane-*.tar.gz.enc"
    exit 1
fi

read_env() {
    local key="$1"
    awk -F= -v key="$key" '$1 == key {sub(/^[^=]*=/, ""); sub(/\r$/, ""); print; exit}' "$ENV_FILE"
}

PASSPHRASE_FILE="${ONEPLOY_BACKUP_PASSPHRASE_FILE:-$(read_env ONEPLOY_BACKUP_PASSPHRASE_FILE)}"
if [ -z "$PASSPHRASE_FILE" ] || [ ! -f "$PASSPHRASE_FILE" ]; then
    echo "The backup passphrase file is missing."
    exit 1
fi
if [ ! -f "${ARCHIVE}.sha256" ]; then
    echo "Missing checksum file: ${ARCHIVE}.sha256"
    exit 1
fi

ARCHIVE_DIR="$(cd "$(dirname "$ARCHIVE")" && pwd -P)"
ARCHIVE_NAME="$(basename "$ARCHIVE")"
(
    cd "$ARCHIVE_DIR"
    sha256sum -c "${ARCHIVE_NAME}.sha256"
)

VERIFY_DIR="$(mktemp -d)"
trap 'rm -rf -- "$VERIFY_DIR"' EXIT
openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 \
    -in "$ARCHIVE" -out "${VERIFY_DIR}/backup.tar.gz" -pass "file:${PASSPHRASE_FILE}"
tar -tzf "${VERIFY_DIR}/backup.tar.gz" >/dev/null
tar -xzf "${VERIFY_DIR}/backup.tar.gz" -C "$VERIFY_DIR"

for required_file in control-plane.dump environment.env control-plane-files.tar.gz powerdns-volume.tar.gz manifest; do
    if [ ! -s "${VERIFY_DIR}/${required_file}" ]; then
        echo "Backup is incomplete: missing ${required_file}"
        exit 1
    fi
done

docker run --rm --network none \
    --volume "${VERIFY_DIR}:/backup:ro" \
    postgres:15-alpine \
    pg_restore --list /backup/control-plane.dump >/dev/null

echo "Backup checksum, encryption, archive, and PostgreSQL dump verified: ${ARCHIVE}"
