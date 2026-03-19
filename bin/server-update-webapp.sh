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
REPO="https://github.com/richardkentgates/rich-statistics.git"
API_URL="https://api.github.com/repos/richardkentgates/rich-statistics/tags"
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

# ── Sparse-clone just docs/app/ at the exact tag ─────────────────────────────
# Public repo — no credentials needed.
TMPDIR="$(mktemp -d /tmp/rsa-extract-XXXXXX)"
cleanup() { rm -rf "${TMPDIR}"; }
trap cleanup EXIT

git clone \
    --depth 1 \
    --filter=blob:none \
    --sparse \
    --branch "${LATEST}" \
    -c advice.detachedHead=false \
    "${REPO}" \
    "${TMPDIR}/repo" \
    --quiet

git -C "${TMPDIR}/repo" sparse-checkout set docs/app

if [ ! -d "${TMPDIR}/repo/docs/app" ]; then
    log "ERROR: docs/app/ not found in repo at tag ${LATEST} — aborting."
    exit 1
fi

# ── Sync to the deploy directory ─────────────────────────────────────────────
# Root-level files (app.js, app.css, index.html, etc.): sync with --delete so
# stale files are removed. Exclude versioned snapshot dirs and the _deploy
# webhook handler so they are never wiped.
rsync -a --delete \
    --exclude='[0-9]*.[0-9]*.[0-9]*/' \
    --exclude='_deploy/' \
    "${TMPDIR}/repo/docs/app/" "${DEPLOY_DIR}/"

# Versioned snapshot subdirectories: additive-only — never delete old versions
# so WP sites running older plugin versions can still load their matching app.
rsync -a "${TMPDIR}/repo/docs/app/" "${DEPLOY_DIR}/"

# ── Record the deployed version ──────────────────────────────────────────────
echo "${LATEST}" > "${VERSION_FILE}"

log "Successfully deployed ${LATEST}."
