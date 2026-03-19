#!/usr/bin/env bash
# build.sh — Rich Statistics plugin build script
#
# Creates a distributable ZIP suitable for uploading to:
#   Freemius Dashboard → Your Plugin → Versions → Add New Version
#
# Usage:
#   bash build.sh [version]
#
# Examples:
#   bash build.sh          # uses version from rich-statistics.php
#   bash build.sh 1.0.1    # overrides version

set -euo pipefail

# -----------------------------------------------------------------------
# Helpers
# -----------------------------------------------------------------------
BOLD=$'\033[1m'
GREEN=$'\033[32m'
YELLOW=$'\033[33m'
RED=$'\033[31m'
RESET=$'\033[0m'

info()    { echo "${BOLD}${GREEN}[build]${RESET} $*"; }
warn()    { echo "${BOLD}${YELLOW}[warn] ${RESET} $*"; }
error()   { echo "${BOLD}${RED}[error]${RESET} $*" >&2; exit 1; }

# -----------------------------------------------------------------------
# Requirements check
# -----------------------------------------------------------------------
for cmd in php zip; do
    command -v "$cmd" &>/dev/null || error "Required command not found: $cmd"
done

# -----------------------------------------------------------------------
# Version
# -----------------------------------------------------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

if [ -n "${1:-}" ]; then
    VERSION="$1"
else
    VERSION=$(grep -oP "define\s*\(\s*['\"]RSA_VERSION['\"]\s*,\s*['\"]?\K[0-9][0-9.]*" rich-statistics.php | head -1)
fi

info "Building Rich Statistics v${VERSION}"
ZIP_NAME="rich-statistics-${VERSION}.zip"
BUILD_DIR="build"
STAGE_DIR="${BUILD_DIR}/stage/rich-statistics"

# -----------------------------------------------------------------------
# Chart.js vendor
# -----------------------------------------------------------------------
if [ ! -f "vendor/chart.min.js" ]; then
    info "Downloading Chart.js 4.4.2..."
    mkdir -p vendor
    curl -fsSL \
        "https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js" \
        -o vendor/chart.min.js
    info "Chart.js ready."
else
    info "vendor/chart.min.js already present."
fi

# -----------------------------------------------------------------------
# PHP syntax check
# -----------------------------------------------------------------------
info "Checking PHP syntax..."
find . -name "*.php" \
    -not -path "./build/*" \
    -not -path "./vendor/freemius/languages/*" \
    | while read -r file; do
        php -l "$file" > /dev/null || error "Syntax error in $file"
    done
info "Syntax OK."

# -----------------------------------------------------------------------
# Stage build
# -----------------------------------------------------------------------
info "Staging files..."
rm -rf "$STAGE_DIR"
mkdir -p "$STAGE_DIR"

# Directories to include in the ZIP
INCLUDE_DIRS=(
    assets
    cli
    includes
    templates
    vendor
    webapp
)

# Root files to include
INCLUDE_FILES=(
    rich-statistics.php
    uninstall.php
    readme.txt
    LICENSE
    CHANGELOG.md
)

# Copy directories
for dir in "${INCLUDE_DIRS[@]}"; do
    if [ -d "$dir" ]; then
        cp -r "$dir" "$STAGE_DIR/${dir}"
    else
        warn "Directory not found, skipping: $dir"
    fi
done

# Copy root files
for file in "${INCLUDE_FILES[@]}"; do
    if [ -f "$file" ]; then
        cp "$file" "$STAGE_DIR/${file}"
    else
        warn "File not found, skipping: $file"
    fi
done

# Create empty languages directory
mkdir -p "$STAGE_DIR/languages"

# -----------------------------------------------------------------------
# Remove development artefacts from staged copy
# -----------------------------------------------------------------------
find "$STAGE_DIR" \( \
    -name "*.DS_Store" \
    -o -name "Thumbs.db" \
    -o -name "*.map" \
    -o -name "*.orig" \
    -o -name ".gitkeep" \
\) -delete

# Remove Freemius dev tools from the bundled SDK
rm -rf "$STAGE_DIR/vendor/freemius/languages" || true

# Remove vendor README (not user-facing)
rm -f "$STAGE_DIR/vendor/README.md"

# -----------------------------------------------------------------------
# Create ZIP
# -----------------------------------------------------------------------
info "Creating ${ZIP_NAME}..."
rm -f "${BUILD_DIR}/${ZIP_NAME}"

cd "${BUILD_DIR}/stage"
zip -qr "${SCRIPT_DIR}/${BUILD_DIR}/${ZIP_NAME}" rich-statistics/
cd "$SCRIPT_DIR"

ZIP_SIZE=$(du -sh "${BUILD_DIR}/${ZIP_NAME}" | cut -f1)
info "Done: ${BUILD_DIR}/${ZIP_NAME} (${ZIP_SIZE})"

# -----------------------------------------------------------------------
# Publish versioned app snapshot to docs/app/{version}/
# -----------------------------------------------------------------------
# Each version gets its own immutable folder on the GitHub Pages site
# (statistics.richardkentgates.com/app/1.x.x/).  The service worker
# caches it forever and app.js redirects there automatically on plugin
# update — no cache clearing, no sign-out.
APP_SRC="docs/app"
APP_VERSIONED="docs/app/${VERSION}"

if [ -d "$APP_VERSIONED" ]; then
    warn "Versioned app folder already exists: ${APP_VERSIONED} — skipping."
else
    info "Publishing versioned app snapshot: ${APP_VERSIONED}/"
    mkdir -p "$APP_VERSIONED"
    for f in index.html app.js app.css config.js sw.js manifest.json chart.min.js; do
        [ -f "${APP_SRC}/${f}" ] && cp "${APP_SRC}/${f}" "${APP_VERSIONED}/${f}"
    done
    [ -d "${APP_SRC}/icons" ] && cp -r "${APP_SRC}/icons" "${APP_VERSIONED}/icons"
    info "Versioned snapshot ready: ${APP_VERSIONED}/"
    info "Commit and push docs/ to publish to GitHub Pages."
fi
