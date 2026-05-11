#!/usr/bin/env bash
# =============================================================================
# deploy-wporg.sh — Submit Rich Statistics to WordPress.org plugin directory.
#
# Prerequisites:
#   1. A WordPress.org account with committer access to the plugin.
#   2. SVN installed (apt install subversion).
#   3. Actual screenshots (PNG, 1200x900 min) placed in ./wporg-assets/
#
# Usage:
#   bash bin/deploy-wporg.sh <svn-username>
#
# Steps this script performs:
#   1. Checks out the WordPress.org SVN repo
#   2. Syncs trunk/ from the current branch
#   3. Copies readme.txt
#   4. Updates assets/ (banners, screenshots, icons)
#   5. Tags the release
#   6. Commits and prompts for confirmation
# =============================================================================
set -euo pipefail

BOLD=$'\033[1m'
GREEN=$'\033[32m'
YELLOW=$'\033[33m'
RED=$'\033[31m'
RESET=$'\033[0m'

info()  { echo "${BOLD}${GREEN}[deploy]${RESET} $*"; }
warn()  { echo "${BOLD}${YELLOW}[warn]  ${RESET} $*"; }
error() { echo "${BOLD}${RED}[error]${RESET} $*" >&2; exit 1; }

# ── Config ──────────────────────────────────────────────────────────────────
PLUGIN_SLUG="rich-statistics"
SVN_USER="${1:-}"
SVN_URL="https://plugins.svn.wordpress.org/${PLUGIN_SLUG}"
VERSION=$(grep -oP "define\s*\(\s*['\"]RSA_VERSION['\"]\s*,\s*['\"]?\K[0-9][0-9.]*" rich-statistics.php | head -1)

if [ -z "$SVN_USER" ]; then
    echo "Usage: $0 <svn-username>"
    echo "  <svn-username>  Your WordPress.org username (must have committer access)"
    exit 1
fi

info "Deploying Rich Statistics v${VERSION} to WordPress.org"
info "SVN URL: ${SVN_URL}"

# ── Checkout SVN repo ──────────────────────────────────────────────────────
TMPDIR="$(mktemp -d /tmp/wporg-XXXXXX)"
cleanup() { rm -rf "${TMPDIR}"; }
trap cleanup EXIT

info "Checking out SVN repo (trunk + assets only)..."
svn co --username="${SVN_USER}" "${SVN_URL}/trunk" "${TMPDIR}/trunk" --depth=empty
svn co --username="${SVN_USER}" "${SVN_URL}/assets" "${TMPDIR}/assets" --depth=infinity

# ── Sync trunk ──────────────────────────────────────────────────────────────
info "Syncing trunk/ with current branch..."
rsync -a --delete \
    --exclude='*.git*' \
    --exclude='.github/' \
    --exclude='tests/' \
    --exclude='bin/' \
    --exclude='build/' \
    --exclude='docs/' \
    --exclude='node_modules/' \
    --exclude='.gitignore' \
    --exclude='.gitattributes' \
    --exclude='composer.json' \
    --exclude='composer.lock' \
    --exclude='phpunit.xml.dist' \
    --exclude='phpcs.xml.dist' \
    --exclude='*.sh' \
    --exclude='DEVELOPMENT.md' \
    --exclude='ARCHITECTURE.md' \
    --exclude='AGENTS.md' \
    --exclude='ROADMAP.md' \
    --exclude='CHANGELOG.md' \
    --exclude='CONTRIBUTING.md' \
    --exclude='SECURITY.md' \
    --exclude='README.md' \
    ./ "${TMPDIR}/trunk/"

# Always copy readme.txt
cp readme.txt "${TMPDIR}/trunk/"

# ── Assets ──────────────────────────────────────────────────────────────────
if [ -d "wporg-assets" ]; then
    info "Copying WordPress.org assets from wporg-assets/..."
    rsync -a --delete wporg-assets/ "${TMPDIR}/assets/"
else
    warn "No wporg-assets/ directory found."
    warn ""
    warn "Create wporg-assets/ with these files before deploying:"
    warn ""
    warn "  banner-772x250.png    — Plugin page banner (772x250)"
    warn "  banner-1544x500.png   — Retina banner (1544x500)"
    warn "  icon-256x256.png      — Plugin icon (256x256)"
    warn "  icon-128x128.png      — Plugin icon (128x128)"
    warn "  screenshot-1.png      — Overview dashboard"
    warn "  screenshot-2.png      — Audience page"
    warn "  screenshot-3.png      — Heatmap (Premium)"
    warn "  screenshot-4.png      — Click Tracking (Premium)"
    warn "  screenshot-5.png      — PWA Web App (Premium)"
    warn ""
fi

# ── Tag release ─────────────────────────────────────────────────────────────
if [ ! -d "${TMPDIR}/tags/${VERSION}" ]; then
    info "Creating tag ${VERSION}..."
    svn cp --username="${SVN_USER}" \
        "${SVN_URL}/trunk" \
        "${SVN_URL}/tags/${VERSION}" \
        -m "Tagging ${VERSION}" \
        --non-interactive \
        --trust-server-cert-failures=unknown-ca 2>/dev/null || \
    svn cp "${TMPDIR}/trunk" "${TMPDIR}/tags/${VERSION}"
else
    warn "Tag ${VERSION} already exists — skipping."
fi

# ── Show diff and commit ────────────────────────────────────────────────────
cd "${TMPDIR}"
echo ""
info "=== SVN Status ==="
svn status

echo ""
info "=== SVN Diff (trunk) ==="
svn diff trunk/ 2>/dev/null | head -80

echo ""
read -p "${BOLD}${YELLOW}Review the changes above. Commit? [y/N]${RESET} " -r
if [[ $REPLY =~ ^[Yy]$ ]]; then
    svn commit --username="${SVN_USER}" \
        -m "Release ${VERSION}" \
        --non-interactive
    info "Deployed! The plugin will appear at:"
    info "  https://wordpress.org/plugins/${PLUGIN_SLUG}/"
else
    warn "Commit cancelled. Changes are in ${TMPDIR} if you want to commit manually."
    warn "Run: cd ${TMPDIR} && svn commit --username=${SVN_USER} -m 'Release ${VERSION}'"
fi
