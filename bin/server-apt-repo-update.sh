#!/usr/bin/env bash
# =============================================================================
# rsa-apt-repo-update — Add a new .deb to the APT repository and regenerate
# signed package metadata.
#
# Called by the CI build-desktop job via SSH immediately after each .deb is
# uploaded to /var/www/rs-app/desktop/.
#
# Usage:
#   sudo /usr/local/bin/rsa-apt-repo-update <arch> <version>
# e.g.:
#   sudo /usr/local/bin/rsa-apt-repo-update amd64 1.4.8
#   sudo /usr/local/bin/rsa-apt-repo-update arm64 1.4.8
#
# What this does:
#   1. Copies the uploaded .deb from /var/www/rs-app/desktop/ into the pool
#      with the standard versioned name  (rich-statistics_<ver>_<arch>.deb)
#   2. Regenerates the Packages / Packages.gz file for every architecture
#   3. Regenerates the Release file via apt-ftparchive
#   4. Signs Release → InRelease (--clearsign) and Release.gpg (detached)
#
# Prerequisites (already done by setup-apt-repo.sh):
#   apt-get install dpkg-dev apt-utils gnupg
#   sudo cp bin/server-apt-repo-update.sh /usr/local/bin/rsa-apt-repo-update
#   sudo chmod +x /usr/local/bin/rsa-apt-repo-update
# =============================================================================
set -euo pipefail

APT_DIR="/var/www/rs-app/apt"
DESKTOP_DIR="/var/www/rs-app/desktop"
KEY_UID="Rich Statistics APT Signing Key <apt@rs-app.richardkentgates.com>"
LOG_TAG="rsa-apt-repo-update"

log() { logger -t "${LOG_TAG}" "$*" || true; echo "$(date -u +%FT%TZ)  $*"; }

# ── Argument validation ────────────────────────────────────────────────────────
if [ $# -lt 2 ]; then
    echo "Usage: rsa-apt-repo-update <arch> <version>" >&2
    echo "  e.g.: rsa-apt-repo-update amd64 1.4.8" >&2
    exit 1
fi

ARCH="${1}"
VERSION="${2}"

# Strip leading 'v' if present (CI passes either "1.4.8" or "v1.4.8")
VERSION="${VERSION#v}"

if [[ ! "${ARCH}" =~ ^(amd64|arm64)$ ]]; then
    log "ERROR: Unsupported arch '${ARCH}'. Expected amd64 or arm64."
    exit 1
fi

if [[ ! "${VERSION}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    log "ERROR: Invalid version '${VERSION}'. Expected semver e.g. 1.4.8."
    exit 1
fi

# ── Copy .deb into the pool ────────────────────────────────────────────────────
SRC="${DESKTOP_DIR}/rich-statistics-linux-${ARCH}.deb"
POOL_DEB="${APT_DIR}/pool/rich-statistics_${VERSION}_${ARCH}.deb"

if [ ! -f "${SRC}" ]; then
    log "ERROR: Source .deb not found at ${SRC}"
    exit 1
fi

log "Copying ${SRC} → ${POOL_DEB}"
cp "${SRC}" "${POOL_DEB}"
chown www-data:www-data "${POOL_DEB}"

# ── Regenerate Packages files for all architectures ───────────────────────────
# Always rebuild all arches so a single Release file remains consistent.
log "Regenerating Packages files…"
for A in amd64 arm64; do
    BINARY_DIR="${APT_DIR}/dists/stable/main/binary-${A}"
    mkdir -p "${BINARY_DIR}"

    # dpkg-scanpackages --arch filters to only debs matching that arch.
    # Run from APT_DIR so Filename: paths are relative to the repo root.
    ( cd "${APT_DIR}" && \
        dpkg-scanpackages --arch "${A}" pool/ 2>/dev/null \
            > "${BINARY_DIR}/Packages"
    ) || true   # non-zero exit is OK when no debs exist for that arch yet

    gzip -9 -k -f "${BINARY_DIR}/Packages"
    chown www-data:www-data "${BINARY_DIR}/Packages" "${BINARY_DIR}/Packages.gz"
done

# ── Regenerate Release file ────────────────────────────────────────────────────
log "Regenerating Release file…"
( cd "${APT_DIR}" && \
    apt-ftparchive release -c apt-ftparchive.conf dists/stable/ \
        > dists/stable/Release
)
chown www-data:www-data "${APT_DIR}/dists/stable/Release"

# ── Sign the Release file ──────────────────────────────────────────────────────
log "Signing Release → InRelease and Release.gpg…"

gpg --batch --yes \
    --default-key "${KEY_UID}" \
    --clearsign \
    -o "${APT_DIR}/dists/stable/InRelease" \
    "${APT_DIR}/dists/stable/Release"

gpg --batch --yes \
    --default-key "${KEY_UID}" \
    -ab \
    -o "${APT_DIR}/dists/stable/Release.gpg" \
    "${APT_DIR}/dists/stable/Release"

chown www-data:www-data \
    "${APT_DIR}/dists/stable/InRelease" \
    "${APT_DIR}/dists/stable/Release.gpg"

log "APT repo updated — rich-statistics ${VERSION} (${ARCH}) is now available."
