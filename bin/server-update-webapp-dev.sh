#!/usr/bin/env bash
# =============================================================================
# rsa-app-update-dev — sync docs/app/ from GitHub develop branch to dev server.
#
# Installation:
#   sudo cp bin/server-update-webapp-dev.sh /usr/local/bin/rsa-app-update-dev
#   sudo chmod +x /usr/local/bin/rsa-app-update-dev
# =============================================================================
set -euo pipefail

DEPLOY_DIR="/var/www/rs-app-dev"
VERSION_FILE="/var/www/rs-app-dev/.deployed-version"
REPO="https://github.com/richardkentgates/rich-statistics.git"
LOG_TAG="rsa-app-update-dev"

log() { logger -t "${LOG_TAG}" "$*" || true; echo "$(date -u +%FT%TZ)  $*"; }

LATEST="develop"

CURRENT=$(cat "${VERSION_FILE}" 2>/dev/null || echo "none")

log "Updating from ${CURRENT} to latest ${LATEST} …"

TMPDIR="$(mktemp -d /tmp/rsa-extract-XXXXXX)"
cleanup() { rm -rf "${TMPDIR}"; }
trap cleanup EXIT

git clone \
    --depth 1 \
    --filter=blob:none \
    --sparse \
    --branch "${LATEST}" \
    "${REPO}" \
    "${TMPDIR}/repo" \
    --quiet

git -C "${TMPDIR}/repo" sparse-checkout set docs/app

if [ ! -d "${TMPDIR}/repo/docs/app" ]; then
    log "ERROR: docs/app/ not found in repo — aborting."
    exit 1
fi

rsync -a --delete \
    --exclude='[0-9]*.[0-9]*.[0-9]*/' \
    --exclude='dist/' \
    --exclude='_deploy/' \
    "${TMPDIR}/repo/docs/app/" "${DEPLOY_DIR}/"

rsync -a "${TMPDIR}/repo/docs/app/" "${DEPLOY_DIR}/"

echo "${LATEST}" > "${VERSION_FILE}"

log "Successfully deployed ${LATEST}."
