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
# Use the tags API — any pushed tag is visible immediately,
# unlike releases/latest which only counts formally published GitHub Releases.
API_URL="https://api.github.com/repos/${REPO}/tags"
LOG_TAG="rsa-app-update"

log() { logger -t "${LOG_TAG}" "$*" || true; echo "$(date -u +%FT%TZ)  $*"; }

# ── Fetch latest tag from the public GitHub API ───────────────────────────────
LATEST=$(curl -sf "${API_URL}" \
    | python3 -c "import sys,json; tags=json.load(sys.stdin); print(tags[0]['name']) if tags else print('')")

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
tar -xzf "${TARBALL}" -C "${TMPDIR}" "${DIR_NAME}/docs/app/" 2>/dev/null || {
    log "ERROR: docs/app/ not found in tarball for ${LATEST} — aborting."
    exit 1
}

# ── Sync to the deploy directory ─────────────────────────────────────────────
rsync -a --delete "${TMPDIR}/${DIR_NAME}/docs/app/" "${DEPLOY_DIR}/"

# ── Record the deployed version ──────────────────────────────────────────────
echo "${LATEST}" > "${VERSION_FILE}"

log "Successfully deployed ${LATEST}."
