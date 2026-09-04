#!/usr/bin/env bash

set -euo pipefail

DATA_DIR="${ONEPLOY_DATA_DIR:-/data/coolify}"
ENV_FILE="${ONEPLOY_ENV_FILE:-${DATA_DIR}/source/.env}"
LOCAL_BACKUP_DIR="${ONEPLOY_LOCAL_BACKUP_DIR:-${DATA_DIR}/backups/oneploy-control-plane}"
DATE="$(date -u +%Y%m%dT%H%M%SZ)"
ARCHIVE_NAME="oneploy-control-plane-${DATE}.tar.gz.enc"

if [ "$(id -u)" -ne 0 ]; then
    echo "Run this backup as root."
    exit 1
fi

read_env() {
    local key="$1"
    awk -F= -v key="$key" '$1 == key {sub(/^[^=]*=/, ""); sub(/\r$/, ""); print; exit}' "$ENV_FILE"
}

for required_command in docker findmnt openssl sha256sum tar realpath; do
    if ! command -v "$required_command" >/dev/null 2>&1; then
        echo "Missing required backup command: ${required_command}"
        exit 1
    fi
done

if [ ! -f "$ENV_FILE" ]; then
    echo "Missing OnePloy environment file: ${ENV_FILE}"
    exit 1
fi

PASSPHRASE_FILE="${ONEPLOY_BACKUP_PASSPHRASE_FILE:-$(read_env ONEPLOY_BACKUP_PASSPHRASE_FILE)}"
DESTINATION="${ONEPLOY_BACKUP_DESTINATION:-$(read_env ONEPLOY_BACKUP_DESTINATION)}"
REQUIRE_OFFSITE="${ONEPLOY_REQUIRE_OFFSITE_BACKUP:-$(read_env ONEPLOY_REQUIRE_OFFSITE_BACKUP)}"
REQUIRE_OFFSITE="${REQUIRE_OFFSITE:-true}"
RETENTION_DAYS="${ONEPLOY_BACKUP_RETENTION_DAYS:-$(read_env ONEPLOY_BACKUP_RETENTION_DAYS)}"
RETENTION_DAYS="${RETENTION_DAYS:-14}"
RETENTION_QUARANTINE_DAYS=7
BACKUP_HELPER_IMAGE="${ONEPLOY_BACKUP_HELPER_IMAGE:-oneploy/helper:latest}"

if ! [[ "$RETENTION_DAYS" =~ ^[0-9]+$ ]] || [ "$RETENTION_DAYS" -lt 1 ] || [ "$RETENTION_DAYS" -gt 3650 ]; then
    echo "ONEPLOY_BACKUP_RETENTION_DAYS must be between 1 and 3650."
    exit 1
fi

if [ -z "$PASSPHRASE_FILE" ] || [ ! -f "$PASSPHRASE_FILE" ]; then
    echo "ONEPLOY_BACKUP_PASSPHRASE_FILE must point to an existing root-only file."
    exit 1
fi
if [ -n "$(find "$PASSPHRASE_FILE" -prune -perm /077 -print)" ]; then
    echo "Backup passphrase file permissions must be 600 or stricter."
    exit 1
fi

if [ -n "$DESTINATION" ]; then
    if [[ "$DESTINATION" != /* ]]; then
        echo "refusing unsafe backup destination: use an absolute mounted off-host path"
        exit 1
    fi
    RESOLVED_DESTINATION="$(realpath -m "$DESTINATION")"
    case "$RESOLVED_DESTINATION" in
        /|/data|/data/coolify|/data/coolify/*|/opt|/opt/oneploy|/opt/oneploy/*)
            echo "refusing unsafe backup destination: ${RESOLVED_DESTINATION}"
            exit 1
            ;;
    esac
    install -d -m 700 "$RESOLVED_DESTINATION"
    DESTINATION_DEVICE="$(findmnt -n -o SOURCE --target "$RESOLVED_DESTINATION")"
    ROOT_DEVICE="$(findmnt -n -o SOURCE --target /)"
    if [ "$REQUIRE_OFFSITE" = "true" ] && [ "$DESTINATION_DEVICE" = "$ROOT_DEVICE" ]; then
        echo "refusing unsafe backup destination: required off-host storage is on the root filesystem"
        exit 1
    fi
elif [ "$REQUIRE_OFFSITE" = "true" ]; then
    echo "ONEPLOY_BACKUP_DESTINATION must be a mounted off-host path when ONEPLOY_REQUIRE_OFFSITE_BACKUP=true."
    exit 1
fi

install -d -m 700 "$LOCAL_BACKUP_DIR"

prune_local_backups() {
    find "$LOCAL_BACKUP_DIR" -maxdepth 1 -type f \
        \( -name 'oneploy-control-plane-*.tar.gz.enc' -o -name 'oneploy-control-plane-*.tar.gz.enc.sha256' \) \
        -mtime "+${RETENTION_DAYS}" -delete
}

quarantine_expired_offsite_backups() {
    local quarantine_dir="${RESOLVED_DESTINATION}/.oneploy-expired"
    local archive
    local archive_name

    install -d -m 700 "$quarantine_dir"
    while IFS= read -r -d '' archive; do
        archive_name="$(basename "$archive")"
        mv -n -- "$archive" "${quarantine_dir}/${archive_name}"
        if [ -f "${archive}.sha256" ]; then
            mv -n -- "${archive}.sha256" "${quarantine_dir}/${archive_name}.sha256"
        fi
    done < <(
        find "$RESOLVED_DESTINATION" -maxdepth 1 -type f \
            -name 'oneploy-control-plane-*.tar.gz.enc' \
            -mtime "+${RETENTION_DAYS}" -print0
    )

    find "$quarantine_dir" -maxdepth 1 -type f \
        \( -name 'oneploy-control-plane-*.tar.gz.enc' -o -name 'oneploy-control-plane-*.tar.gz.enc.sha256' \) \
        -mtime "+${RETENTION_QUARANTINE_DAYS}" -delete
}

STAGING_DIR="$(mktemp -d "${LOCAL_BACKUP_DIR}/.oneploy-backup.XXXXXX")"
trap 'rm -rf -- "$STAGING_DIR"' EXIT

DB_USERNAME="$(read_env DB_USERNAME)"
DB_DATABASE="$(read_env DB_DATABASE)"
DB_USERNAME="${DB_USERNAME:-coolify}"
DB_DATABASE="${DB_DATABASE:-coolify}"

docker exec coolify-db pg_dump -U "$DB_USERNAME" --format=custom --create "$DB_DATABASE" > "${STAGING_DIR}/control-plane.dump"
install -m 600 "$ENV_FILE" "${STAGING_DIR}/environment.env"
tar --create --gzip --file="${STAGING_DIR}/control-plane-files.tar.gz" \
    --directory="$DATA_DIR" source/.env ssh proxy

docker run --rm --network none \
    --entrypoint tar \
    --volume oneploy-powerdns:/volume:ro \
    --volume "${STAGING_DIR}:/backup" \
    "$BACKUP_HELPER_IMAGE" \
    -czf /backup/powerdns-volume.tar.gz -C /volume .

printf 'created_at=%s\ndatabase=%s\nsource_commit=%s\n' \
    "$DATE" \
    "$DB_DATABASE" \
    "$(git -C /opt/oneploy rev-parse HEAD 2>/dev/null || echo unknown)" \
    > "${STAGING_DIR}/manifest"

PLAIN_ARCHIVE="${LOCAL_BACKUP_DIR}/.${ARCHIVE_NAME%.enc}"
FINAL_ARCHIVE="${LOCAL_BACKUP_DIR}/${ARCHIVE_NAME}"
trap 'rm -rf -- "$STAGING_DIR"; rm -f -- "$PLAIN_ARCHIVE"' EXIT
tar --create --gzip --file="$PLAIN_ARCHIVE" --directory="$STAGING_DIR" \
    control-plane.dump environment.env control-plane-files.tar.gz powerdns-volume.tar.gz manifest
openssl enc -aes-256-cbc -pbkdf2 -iter 200000 -salt \
    -in "$PLAIN_ARCHIVE" -out "$FINAL_ARCHIVE" -pass "file:${PASSPHRASE_FILE}"
rm -f -- "$PLAIN_ARCHIVE"
chmod 600 "$FINAL_ARCHIVE"
(
    cd "$LOCAL_BACKUP_DIR"
    sha256sum "$ARCHIVE_NAME" > "${ARCHIVE_NAME}.sha256"
    chmod 600 "${ARCHIVE_NAME}.sha256"
)

if [ -n "$DESTINATION" ]; then
    install -m 600 "$FINAL_ARCHIVE" "${RESOLVED_DESTINATION}/${ARCHIVE_NAME}"
    install -m 600 "${FINAL_ARCHIVE}.sha256" "${RESOLVED_DESTINATION}/${ARCHIVE_NAME}.sha256"
    (
        cd "$RESOLVED_DESTINATION"
        sha256sum -c "${ARCHIVE_NAME}.sha256"
    )
    quarantine_expired_offsite_backups
    echo "Encrypted backup copied to ${RESOLVED_DESTINATION}/${ARCHIVE_NAME}"
else
    echo "Encrypted local backup created at ${FINAL_ARCHIVE}; off-host copying is disabled."
fi

prune_local_backups
