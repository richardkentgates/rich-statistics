#!/usr/bin/env bash
# =============================================================================
# rsa-app-update — pull latest release from GitHub and sync to web root
#
# Triggered by bin/server-webhook.php (via sudo from www-data).
# No credentials needed — the repository is public.
#
# Prerequisites (done once during server setup — see server-webhook.php):
#   sudo cp bin/server-update-webapp.sh /usr/local/bin/rsa-app-update
#   sudo chmod +x /usr/local/bin/rsa-app-update
# =============================================================================
set -euo pipefail

DEPLOY_DIR="/var/www/rs-app"
VERSION_FILE="${DEPLOY_DIR}/.deployed-version"
REPO="richardkentgates/rich-statistics"
API_URL="https://api.github.com/repos/${REPO}/releases/latest"
LOG_TAG="rsa-app-update"

log() { logger -t "${LOG_TAG}" "$*" || true; echo "$(date -u +%FT%TZ)  $*"; }

# ── Fetch latest release tag from the public GitHub API ──────────────────────
LATEST=$(curl -sf "${API_URL}" \
    | python3 -c "import sys,json; print(json.load(sys.stdin)['tag_name'])")

if [ -z "${LATEST}" ]; then
    log "ERROR: Could not determine latest release tag — skipping."
    exit 1
fi

CURRENT=$(cat "${VERSION_FILE}" 2>/dev/null || echo "none")

if [ "${LATEST}" = "${CURRENT}" ]; then
    log "Already at ${LATEST} — nothing to do."
    exit 0
fi

log "Updating from ${CURRENT} to ${LATEST} …"

# ── Download source tarball (public, no authentication required) ──────────────
TARBALL="$(mktemp /tmp/rsa-XXXXXX.tar.gz)"
TMPDIR="$(mktemp -d /tmp/rsa-extract-XXXXXX)"
# GitHub strips the leading 'v' from the tag in the archive directory name.
DIR_NAME="rich-statistics-${LATEST#v}"

cleanup() { rm -rf "${TMPDIR}" "${TARBALL}"; }
trap cleanup EXIT

curl -sfL \
    "https://github.com/${REPO}/archive/refs/tags/${LATEST}.tar.gz" \
    -o "${TARBALL}"

# ── Extract only docs/app/ ────────────────────────────────────────────────────
tar -xzf "${TARBALL}" -C "${TMPDIR}" "${DIR_NAME}/docs/app/"

# ── Sync to the deploy directory ─────────────────────────────────────────────
rsync -a --delete "${TMPDIR}/${DIR_NAME}/docs/app/" "${DEPLOY_DIR}/"

# ── Record the deployed version ──────────────────────────────────────────────
echo "${LATEST}" > "${VERSION_FILE}"

log "Successfully deployed ${LATEST}."
