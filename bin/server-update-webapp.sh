#!/usr/bin/env bash
# =============================================================================
# rsa-app-update — sync docs/app/ from GitHub and update deployed-version.
#
# Triggered by the CI ping-deploy job via the /_deploy/ webhook.
# The CI build job pushes .deb files and writes update.json directly via SSH,
# so this script only needs to sync the web app files.
#
# Clone from main branch (not the release tag) because the CI build job commits
# the versioned app snapshot to main AFTER creating the tag. Cloning the tag
# would miss those post-tag commits and deploy stale files.
#
# Prerequisites (done once during server setup):
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

# ── Sparse-clone docs/app/ from main branch ───────────────────────────────────
# Use main (not the tag) so we always get the versioned snapshot that the CI
# build job commits to main after the tag is created.
TMPDIR="$(mktemp -d /tmp/rsa-extract-XXXXXX)"
cleanup() { rm -rf "${TMPDIR}"; }
trap cleanup EXIT

git clone \
    --depth 1 \
    --filter=blob:none \
    --sparse \
    --branch main \
    "${REPO}" \
    "${TMPDIR}/repo" \
    --quiet

git -C "${TMPDIR}/repo" sparse-checkout set docs/app

if [ ! -d "${TMPDIR}/repo/docs/app" ]; then
    log "ERROR: docs/app/ not found in repo — aborting."
    exit 1
fi

# ── Sync to the deploy directory ─────────────────────────────────────────────
# Root-level files: sync with --delete so stale files are removed.
# Exclude versioned snapshot dirs, desktop dir, and _deploy webhook handler.
rsync -a --delete \
    --exclude='[0-9]*.[0-9]*.[0-9]*/' \
    --exclude='desktop/' \
    --exclude='_deploy/' \
    "${TMPDIR}/repo/docs/app/" "${DEPLOY_DIR}/"

# Versioned snapshot subdirectories: additive-only — never delete old versions
# so WP sites running older plugin versions can still load their matching app.
rsync -a "${TMPDIR}/repo/docs/app/" "${DEPLOY_DIR}/"

# ── Record the deployed version ──────────────────────────────────────────────
echo "${LATEST}" > "${VERSION_FILE}"

log "Successfully deployed ${LATEST}."
