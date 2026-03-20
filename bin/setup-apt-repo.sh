#!/usr/bin/env bash
# =============================================================================
# setup-apt-repo.sh — one-time APT repository initialisation for the app server.
#
# Run once as root on the app server after initial server provisioning:
#   sudo bash bin/setup-apt-repo.sh
#
# What this does:
#   1. Installs dpkg-dev, apt-utils, gnupg (required to build the repo)
#   2. Creates the APT repo directory structure under /var/www/rs-app/apt/
#   3. Generates a dedicated GPG signing key (no passphrase — used unattended)
#   4. Exports the public key to /var/www/rs-app/apt/public.gpg
#   5. Writes a reusable apt-ftparchive config for Release generation
#   6. Copies server-apt-repo-update.sh to /usr/local/bin/rsa-apt-repo-update
#
# After this runs, users can add the repo with:
#   curl -fsSL https://rs-app.richardkentgates.com/apt/public.gpg \
#     | sudo gpg --dearmor -o /usr/share/keyrings/rich-statistics.gpg
#   echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/rich-statistics.gpg] \
#     https://rs-app.richardkentgates.com/apt stable main" \
#     | sudo tee /etc/apt/sources.list.d/rich-statistics.list
#   sudo apt update && sudo apt install rich-statistics
# =============================================================================
set -euo pipefail

APT_DIR="/var/www/rs-app/apt"
KEY_UID="Rich Statistics APT Signing Key <apt@rs-app.richardkentgates.com>"
LOG_TAG="setup-apt-repo"

log() { echo "[$(date -u +%FT%TZ)]  $*"; }

# ── Sanity checks ──────────────────────────────────────────────────────────────
if [ "$(id -u)" -ne 0 ]; then
    echo "ERROR: run as root." >&2
    exit 1
fi

# ── System dependencies ────────────────────────────────────────────────────────
log "Installing dpkg-dev, apt-utils, gnupg…"
apt-get install -y dpkg-dev apt-utils gnupg

# ── Directory structure ────────────────────────────────────────────────────────
log "Creating repository directory tree under ${APT_DIR}…"
mkdir -p \
    "${APT_DIR}/pool" \
    "${APT_DIR}/dists/stable/main/binary-amd64" \
    "${APT_DIR}/dists/stable/main/binary-arm64"

# Ensure the web server can read everything
chown -R www-data:www-data "${APT_DIR}"
chmod -R 755 "${APT_DIR}"

# ── GPG key ────────────────────────────────────────────────────────────────────
# Check for an existing key so re-runs are idempotent.
EXISTING_KEY=$(gpg --list-keys --with-colons "${KEY_UID}" 2>/dev/null \
    | awk -F: '/^pub/{print $5; exit}' || true)

if [ -n "${EXISTING_KEY}" ]; then
    log "GPG key already exists (fingerprint prefix: ${EXISTING_KEY}) — skipping key generation."
else
    log "Generating GPG signing key…"
    KEYBATCH=$(mktemp /tmp/gpg-batch-XXXXXX)
    cat > "${KEYBATCH}" <<EOF
%no-protection
Key-Type: RSA
Key-Length: 4096
Subkey-Type: RSA
Subkey-Length: 4096
Name-Real: Rich Statistics APT Signing Key
Name-Email: apt@rs-app.richardkentgates.com
Expire-Date: 0
%commit
EOF
    gpg --batch --gen-key "${KEYBATCH}"
    rm -f "${KEYBATCH}"
fi

# ── Export public key ──────────────────────────────────────────────────────────
log "Exporting public key to ${APT_DIR}/public.gpg…"
gpg --armor --export "${KEY_UID}" > "${APT_DIR}/public.gpg"
chown www-data:www-data "${APT_DIR}/public.gpg"
chmod 644 "${APT_DIR}/public.gpg"
log "Public key fingerprint:"
gpg --fingerprint "${KEY_UID}"

# ── apt-ftparchive config ──────────────────────────────────────────────────────
log "Writing apt-ftparchive Release config…"
cat > "${APT_DIR}/apt-ftparchive.conf" <<'EOF'
APT::FTPArchive::Release {
  Origin      "Rich Statistics";
  Label       "Rich Statistics";
  Suite       "stable";
  Codename    "stable";
  Architectures "amd64 arm64";
  Components  "main";
  Description "Rich Statistics Linux Desktop App - https://rs-app.richardkentgates.com";
};
EOF

# ── Install update script ──────────────────────────────────────────────────────
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [ -f "${SCRIPT_DIR}/server-apt-repo-update.sh" ]; then
    log "Installing rsa-apt-repo-update to /usr/local/bin…"
    cp "${SCRIPT_DIR}/server-apt-repo-update.sh" /usr/local/bin/rsa-apt-repo-update
    chmod +x /usr/local/bin/rsa-apt-repo-update
else
    log "Warning: server-apt-repo-update.sh not found next to this script — install it manually."
fi

# ── Create empty initial Packages files (apt needs them to exist) ──────────────
for ARCH in amd64 arm64; do
    PKGS="${APT_DIR}/dists/stable/main/binary-${ARCH}/Packages"
    if [ ! -f "${PKGS}" ]; then
        touch "${PKGS}"
        gzip -9 -k "${PKGS}"
    fi
done

# ── Initial Release + InRelease ────────────────────────────────────────────────
log "Generating initial Release and InRelease files…"
apt-ftparchive release -c "${APT_DIR}/apt-ftparchive.conf" \
    "${APT_DIR}/dists/stable/" > "${APT_DIR}/dists/stable/Release"

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

chown -R www-data:www-data "${APT_DIR}/dists"

log ""
log "✓  APT repository initialised at ${APT_DIR}"
log ""
log "Users install the app with:"
log "  curl -fsSL https://rs-app.richardkentgates.com/apt/public.gpg \\"
log "    | sudo gpg --dearmor -o /usr/share/keyrings/rich-statistics.gpg"
log '  echo "deb [arch=\$(dpkg --print-architecture) signed-by=/usr/share/keyrings/rich-statistics.gpg] \\'
log "    https://rs-app.richardkentgates.com/apt stable main\" \\"
log "    | sudo tee /etc/apt/sources.list.d/rich-statistics.list"
log "  sudo apt update && sudo apt install rich-statistics"
