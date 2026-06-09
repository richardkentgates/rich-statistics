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

ZIP_ONLY=false
if [ "${1:-}" = "--zip-only" ]; then
    ZIP_ONLY=true
    VERSION="${2:-}"
    if [ -z "$VERSION" ]; then
        VERSION=$(grep -oP "define\s*\(\s*['\"]RSA_VERSION['\"]\s*,\s*['\"]?\K[0-9][0-9.]*" rich-statistics.php | head -1)
    fi
elif [ -n "${1:-}" ]; then
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
)

# Root files to include
INCLUDE_FILES=(
    rich-statistics.php
    uninstall.php
    readme.txt
    LICENSE
    CHANGELOG.md
    composer.json
);

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

# Remove development artefacts from staged copy
rm -f "$STAGE_DIR/phpunit.xml.dist"
rm -f "$STAGE_DIR/phpcs.xml.dist"
rm -f "$STAGE_DIR/.distignore"
rm -f "$STAGE_DIR/build.sh"

# Strip dev-only vendor packages — keep only Freemius SDK and autoloader
find "$STAGE_DIR/vendor" -mindepth 1 -maxdepth 1 -type d ! -name "freemius" ! -name "composer" -exec rm -rf {} + 2>/dev/null
# Remove any PHPUnit, BrainMonkey, Mockery, WPCS config files left in vendor root
find "$STAGE_DIR/vendor" -name "phpunit.xml*" -delete 2>/dev/null
find "$STAGE_DIR/vendor" -name "README.md" -delete 2>/dev/null

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
# Publish versioned app snapshot (skipped in --zip-only mode)
# -----------------------------------------------------------------------
if [ "$ZIP_ONLY" = false ]; then
    APP_SRC="docs/app"
    APP_VERSIONED="docs/app/v/${VERSION}"

    # Bump SW cache name to match release version
    CACHE_NAME="rsa-$(echo "${VERSION}" | tr '.' '-')"
    sed -i "s/: 'rsa-[0-9]\+-[0-9]\+-[0-9]\+'/: '${CACHE_NAME}'/" "${APP_SRC}/sw.js"
    info "Bumped SW cache name to ${CACHE_NAME}"

    # Create versioned snapshot directories (stable + beta)
    for CHANNEL in stable beta; do
        CHANNEL_DIR="${APP_VERSIONED}/${CHANNEL}"
        if [ -d "$CHANNEL_DIR" ]; then
            warn "Versioned app folder already exists: ${CHANNEL_DIR} — skipping."
            continue
        fi
        info "Publishing versioned app snapshot: ${CHANNEL_DIR}/"
        mkdir -p "$CHANNEL_DIR"
        for f in index.html app.js app.css config.js sw.js sw-init.js manifest.json chart.min.js; do
            [ -f "${APP_SRC}/${f}" ] && cp "${APP_SRC}/${f}" "${CHANNEL_DIR}/${f}"
        done
        for f in index-dev.html index-test.html config-dev.js config-test.js; do
            [ -f "${APP_SRC}/${f}" ] && cp "${APP_SRC}/${f}" "${CHANNEL_DIR}/${f}"
        done
        [ -d "${APP_SRC}/icons" ] && cp -r "${APP_SRC}/icons" "${CHANNEL_DIR}/icons"
    done

    # Regenerate versions.json + versions-beta.json from v/ directory
    python3 << 'PYEOF'
import json, pathlib

def vkey(x):
    parts = x.split('.')
    return tuple(int(p) if p.isdigit() else 999999 for p in parts)

v_dir = pathlib.Path('docs/app/v')
versions = sorted(
    [d.name for d in v_dir.iterdir() if d.is_dir()],
    key=vkey
)
pathlib.Path('docs/app/versions.json').write_text(json.dumps(versions))
pathlib.Path('docs/app/versions-beta.json').write_text(json.dumps(versions))
PYEOF
    info "Regenerated versions.json and versions-beta.json"

    # Prune old snapshot directories (keep last 12)
    python3 << 'PYEOF'
import json, shutil, pathlib

def vkey(x):
    parts = x.split('.')
    return tuple(int(p) if p.isdigit() else 999999 for p in parts)

v_dir = pathlib.Path('docs/app/v')
all_v = sorted(
    [d.name for d in v_dir.iterdir() if d.is_dir()],
    key=vkey
)
keep = set(all_v[-12:])
for d in v_dir.iterdir():
    if d.is_dir() and d.name not in keep:
        shutil.rmtree(d)
        print(f"pruned {d.name}")
remaining = sorted(
    [d.name for d in v_dir.iterdir() if d.is_dir()],
    key=vkey
)
pathlib.Path('docs/app/versions.json').write_text(json.dumps(remaining))
pathlib.Path('docs/app/versions-beta.json').write_text(json.dumps(remaining))
PYEOF
    info "Pruned snapshots to last 12 versions"
    info "Versioned snapshot ready: ${APP_VERSIONED}/{stable,beta}/"
    info "Commit and push docs/ to publish."
    info "Run: git add docs/ && git commit -m 'chore: add versioned PWA snapshot v${VERSION}'"
else
    info "Skipping PWA snapshot creation (--zip-only mode)"
fi
