#!/usr/bin/env bash
# =============================================================================
# rsa-app-update — sync docs/app/ from GitHub and update deployed-version.
#
# Triggered by the CI ping-deploy job via the /_deploy/ webhook.
# The CI build job pushes .deb files and writes update.json directly via SSH,
# so this script only needs to sync the web app files.
#
# Always updates from main branch (not tags) so hotfixes to docs/app/
# are deployed immediately without needing a new version tag.
#
# Prerequisites (done once during server setup):
#   sudo cp bin/server-update-webapp.sh /usr/local/bin/rsa-app-update
#   sudo chmod +x /usr/local/bin/rsa-app-update
# =============================================================================
set -euo pipefail

DEPLOY_DIR="/var/www/rs-app/public_html"
VERSION_FILE="/var/www/rs-app/.deployed-version"
REPO="https://github.com/richardkentgates/rich-statistics.git"
LOG_TAG="rsa-app-update"

log() { logger -t "${LOG_TAG}" "$*" || true; echo "$(date -u +%FT%TZ)  $*"; }

# ── Always update from main branch ──────────────────────────────────────
LATEST="main"

CURRENT=$(cat "${VERSION_FILE}" 2>/dev/null || echo "none")

log "Updating from ${CURRENT} to latest main …"

# ── Sparse-clone docs/app/ from main branch ──────────────────────────────
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

# ── Sync to the deploy directory ──────────────────────────────────────────
# Root-level files: sync with --delete so stale files are removed.
# Exclude versioned snapshot dirs, dist dir, and _deploy webhook handler.
rsync -a --delete \
    --exclude='[0-9]*.[0-9]*.[0-9]*/' \
    --exclude='dist/' \
    --exclude='apt/' \
    --exclude='_deploy/' \
    "${TMPDIR}/repo/docs/app/" "${DEPLOY_DIR}/"

# Versioned snapshot subdirectories: additive-only — never delete old versions
# so WP sites running older plugin versions can still load their matching app.
rsync -a "${TMPDIR}/repo/docs/app/" "${DEPLOY_DIR}/"

# ── Record the deployed version ──────────────────────────────────────────
echo "${LATEST}" > "${VERSION_FILE}"

# ── Prune old versioned snapshots (keep last 12) ────────────────────────
# Matches CI behavior in build-release.yml — older snapshots are removed
# so the server disk doesn't grow unbounded. Sites running older plugin
# versions will fall back to the latest bundled snapshot.
cd "${DEPLOY_DIR}"
if [ -d "v" ]; then
    python3 -c "
import json, shutil, pathlib, sys
v_dir = pathlib.Path('v')
all_v = sorted(
    [d.name for d in v_dir.iterdir() if d.is_dir()],
    key=lambda x: list(map(int, x.split('.')))
)
keep = set(all_v[-12:])
for d in v_dir.iterdir():
    if d.is_dir() and d.name not in keep:
        shutil.rmtree(d)
        print(f'pruned {d}', file=sys.stderr)
" 2>&1 | while read -r line; do log "$line"; done
fi

log "Successfully deployed ${LATEST}."
