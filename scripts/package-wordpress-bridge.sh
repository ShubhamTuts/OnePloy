#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPOSITORY_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
PLUGIN_DIR="${REPOSITORY_DIR}/wordpress-bridge"
ADMIN_GUIDE="${REPOSITORY_DIR}/docs/oneploy/WORDPRESS-BRIDGE.md"
OUTPUT_DIR="${1:-${REPOSITORY_DIR}/dist}"
VERSION="$(sed -n 's/^ \* Version: \([0-9][0-9.]*\)$/\1/p' "${PLUGIN_DIR}/oneploy-bridge.php" | head -n 1)"

if [ -z "${VERSION}" ]; then
    echo "Unable to determine the OnePloy Bridge version." >&2
    exit 1
fi

if ! command -v zip >/dev/null 2>&1; then
    echo "The zip command is required to package OnePloy Bridge." >&2
    exit 1
fi

mkdir -p "${OUTPUT_DIR}"
OUTPUT_DIR="$(cd "${OUTPUT_DIR}" && pwd)"
ARCHIVE="${OUTPUT_DIR}/oneploy-bridge-${VERSION}.zip"
ARCHIVE_NAME="$(basename "${ARCHIVE}")"
TEMP_DIR="$(mktemp -d)"
trap 'rm -rf -- "${TEMP_DIR}"' EXIT

mkdir -p "${TEMP_DIR}/oneploy-bridge"
cp -R "${PLUGIN_DIR}/oneploy-bridge.php" "${PLUGIN_DIR}/readme.txt" "${PLUGIN_DIR}/uninstall.php" "${PLUGIN_DIR}/assets" "${TEMP_DIR}/oneploy-bridge/"
cp "${ADMIN_GUIDE}" "${TEMP_DIR}/oneploy-bridge/ADMIN-GUIDE.md"
(cd "${TEMP_DIR}" && zip -qr "${ARCHIVE}" oneploy-bridge)

(cd "${OUTPUT_DIR}" && sha256sum "${ARCHIVE_NAME}" > "${ARCHIVE_NAME}.sha256")
echo "Created ${ARCHIVE}"
echo "Checksum ${ARCHIVE}.sha256"
