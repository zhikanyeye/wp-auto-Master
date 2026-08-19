#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUTPUT_PATH="${1:-${ROOT_DIR}/boke-wpai-automation.zip}"
STAGE_DIR="$(mktemp -d "${TMPDIR:-/tmp}/bokeauto-package.XXXXXX")"
PACKAGE_DIR="${STAGE_DIR}/bokeauto"
STAGE_ZIP="${STAGE_DIR}/boke-wpai-automation.zip"

mkdir -p "${PACKAGE_DIR}"

# Keep the distributable limited to runtime files. Project documentation,
# tests, CI configuration, and workspace metadata stay outside the package.
cp -a \
  "${ROOT_DIR}/includes" \
  "${ROOT_DIR}/admin" \
  "${ROOT_DIR}/assets" \
  "${ROOT_DIR}/bokeauto.php" \
  "${ROOT_DIR}/uninstall.php" \
  "${ROOT_DIR}/readme.txt" \
  "${ROOT_DIR}/LICENSE" \
  "${PACKAGE_DIR}/"

mkdir -p "$(dirname "${OUTPUT_PATH}")"
( cd "${STAGE_DIR}" && zip -qr "${STAGE_ZIP}" bokeauto )
mv -f "${STAGE_ZIP}" "${OUTPUT_PATH}"

echo "Created ${OUTPUT_PATH}"
unzip -l "${OUTPUT_PATH}"
