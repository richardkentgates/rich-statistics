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
for cmd in php zip curl unzip; do
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
    VERSION=$(php -r "
        \$content = file_get_contents('rich-statistics.php');
        preg_match('/define\s*\(\s*['\''\"']RSA_VERSION['\''\"']\s*,\s*['\''\"']([^'\''\""]+)['\''\"']/i', \$content, \$m);
        echo \$m[1] ?? '0.0.0';
    ")
fi

info "Building Rich Statistics v${VERSION}"
ZIP_NAME="rich-statistics-${VERSION}.zip"
BUILD_DIR="build"
STAGE_DIR="${BUILD_DIR}/stage/rich-statistics"

# -----------------------------------------------------------------------
# Freemius SDK
# -----------------------------------------------------------------------
FREEMIUS_VERSION="2.7.4"

if [ ! -f "freemius/start.php" ]; then
    info "Downloading Freemius SDK ${FREEMIUS_VERSION}..."
    curl -fsSL \
        "https://github.com/Freemius/wordpress-sdk/archive/refs/tags/${FREEMIUS_VERSION}.zip" \
        -o /tmp/freemius-sdk.zip
    unzip -q /tmp/freemius-sdk.zip -d /tmp/
    mv "/tmp/wordpress-sdk-${FREEMIUS_VERSION}" freemius
    rm /tmp/freemius-sdk.zip
    info "Freemius SDK ready."
else
    info "Freemius SDK already present, skipping download."
fi

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
    -not -path "./freemius/languages/*" \
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
    freemius
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
rm -rf "$STAGE_DIR/freemius/languages"  || true

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
# Reminder
# -----------------------------------------------------------------------
echo ""
echo "${BOLD}Next steps:${RESET}"
echo "  1. Upload ${BUILD_DIR}/${ZIP_NAME} to Freemius:"
echo "     https://dashboard.freemius.com → Your Plugin → Versions → Add New Version"
echo ""
echo "  2. If this is your first upload, make sure you have filled in your"
echo "     Freemius product ID and public key in rich-statistics.php:"
echo "     Look for: 'id' => '0000'  and  'public_key' => 'pk_REPLACE...'"
echo ""
