#!/usr/bin/env bash
# =============================================================================
# rsa-app-update — sync docs/app/ from GitHub and update deployed-version.
#
# Triggered by the CI ping-deploy job via the /_deploy/ webhook.
# The CI build job pushes .deb files and writes update.json directly via SSH,
# so this script only needs to sync the web app files.
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

# ── Sparse-clone just docs/app/ at the exact tag ─────────────────────────────
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
# Root-level files: sync with --delete so stale files are removed.
# Exclude versioned snapshot dirs, desktop dir, and _deploy webhook handler.
rsync -a --delete \
    --exclude='[0-9]*.[0-9]*.[0-9]*/' \
    --exclude='desktop/' \
    --exclude='_deploy/' \
    "${TMPDIR}/repo/docs/app/" "${DEPLOY_DIR}/"

# Versioned snapshot subdirectories: additive-only — never delete old versions.
rsync -a "${TMPDIR}/repo/docs/app/" "${DEPLOY_DIR}/"

# ── Record the deployed version ──────────────────────────────────────────────
echo "${LATEST}" > "${VERSION_FILE}"

log "Successfully deployed ${LATEST}."

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

# ── Download .deb packages and write Tauri update manifest ───────────────────
mkdir -p "${DESKTOP_DIR}"

DEB_AMD64="rich-statistics-linux-amd64.deb"
DEB_ARM64="rich-statistics-linux-arm64.deb"

log "Downloading desktop packages for ${LATEST} …"
curl -sfL "${RELEASES_URL}/${LATEST}/${DEB_AMD64}" -o "${DESKTOP_DIR}/${DEB_AMD64}" || \
    log "WARN: amd64 .deb not yet available at tag ${LATEST} — skipping."
curl -sfL "${RELEASES_URL}/${LATEST}/${DEB_ARM64}" -o "${DESKTOP_DIR}/${DEB_ARM64}" || \
    log "WARN: arm64 .deb not yet available at tag ${LATEST} — skipping."

# Download detached .sig files produced by `tauri build --sign`
curl -sfL "${RELEASES_URL}/${LATEST}/${DEB_AMD64}.sig" -o "${DESKTOP_DIR}/${DEB_AMD64}.sig" || true
curl -sfL "${RELEASES_URL}/${LATEST}/${DEB_ARM64}.sig" -o "${DESKTOP_DIR}/${DEB_ARM64}.sig" || true

SIG_AMD64=$(cat "${DESKTOP_DIR}/${DEB_AMD64}.sig" 2>/dev/null || echo "")
SIG_ARM64=$(cat "${DESKTOP_DIR}/${DEB_ARM64}.sig" 2>/dev/null || echo "")
PUB_DATE=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
BASE_URL="https://rs-app.richardkentgates.com/desktop"
VERSION="${LATEST#v}"

# Release notes (first 500 chars of GitHub release body, falls back gracefully)
NOTES=$(curl -sf "https://api.github.com/repos/richardkentgates/rich-statistics/releases/tags/${LATEST}" \
    | python3 -c "import sys,json; d=json.load(sys.stdin); b=d.get('body',''); print(b[:500])" 2>/dev/null || echo "")

python3 - <<PYEOF
import json, pathlib, textwrap

platforms = {}
sig_amd = """${SIG_AMD64}"""
sig_arm = """${SIG_ARM64}"""

if (pathlib.Path("${DESKTOP_DIR}/${DEB_AMD64}")).exists():
    platforms["linux-x86_64"] = {
        "url": "${BASE_URL}/${DEB_AMD64}",
        "signature": sig_amd.strip()
    }
if (pathlib.Path("${DESKTOP_DIR}/${DEB_ARM64}")).exists():
    platforms["linux-aarch64"] = {
        "url": "${BASE_URL}/${DEB_ARM64}",
        "signature": sig_arm.strip()
    }

manifest = {
    "version": "${VERSION}",
    "pub_date": "${PUB_DATE}",
    "notes": """${NOTES}""".strip(),
    "platforms": platforms
}
pathlib.Path("${DESKTOP_DIR}/update.json").write_text(json.dumps(manifest, indent=2) + "\n")
print("update.json written for v${VERSION} (platforms: " + ", ".join(platforms.keys()) + ")")
PYEOF

# ── Record the deployed version ──────────────────────────────────────────────
echo "${LATEST}" > "${VERSION_FILE}"

log "Successfully deployed ${LATEST}."
