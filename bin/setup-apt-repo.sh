#!/usr/bin/env bash
# =============================================================================
# setup-apt-repo.sh — Initialize the APT repository directory structure,
# generate a GPG signing key, and create the apt-ftparchive configuration.
#
# Called by setup-app-server.sh during initial server provisioning.
# Also available as a standalone script for re-initializing the APT repo.
#
# Usage:
#   sudo bash bin/setup-apt-repo.sh [--domain <domain>] [--email <email>] [--user <user>]
#
# Defaults:
#   --domain  app.richstatistics.com
#   --email   admin@example.com
#   --user    $(whoami)
# =============================================================================
set -euo pipefail

# ── Defaults ───────────────────────────────────────────────────────────────────
DOMAIN="app.richstatistics.com"
EMAIL="admin@example.com"
USER="$(whoami)"

# ── Argument parsing ───────────────────────────────────────────────────────────
while [[ $# -gt 0 ]]; do
    case "$1" in
        --domain)  DOMAIN="$2";  shift 2 ;;
        --email)   EMAIL="$2";   shift 2 ;;
        --user)    USER="$2";    shift 2 ;;
        *) echo "Unknown option: $1" >&2; exit 1 ;;
    esac
done

APT_DIR="/var/www/${USER}/apt"
KEY_UID="Rich Statistics APT Signing Key <apt@${DOMAIN}>"
LOG_TAG="rsa-setup-apt-repo"

log() { logger -t "${LOG_TAG}" "$*" || true; echo "$(date -u +%FT%TZ)  $*"; }

# ── Install prerequisites ──────────────────────────────────────────────────────
log "Installing APT repository prerequisites…"
apt-get update
apt-get install -y dpkg-dev apt-utils gnupg

# ── Create directory structure ─────────────────────────────────────────────────
log "Creating APT directory structure at ${APT_DIR}…"
mkdir -p "${APT_DIR}/pool"
mkdir -p "${APT_DIR}/dists/stable/main/binary-amd64"
mkdir -p "${APT_DIR}/dists/stable/main/binary-arm64"

# ── Create apt-ftparchive.conf ────────────────────────────────────────────────
log "Creating apt-ftparchive.conf…"
cat > "${APT_DIR}/apt-ftparchive.conf" <<EOF
APT::FTPArchive::Release {
    Origin "Rich Statistics";
    Label "Rich Statistics";
    Suite "stable";
    Codename "stable";
    Architectures "amd64 arm64";
    Components "main";
    Description "Rich Statistics Desktop App APT Repository";
};
EOF

# ── Generate GPG signing key (if not already present) ─────────────────────────
log "Checking for existing GPG signing key…"
if gpg --list-keys "${KEY_UID}" >/dev/null 2>&1; then
    log "GPG signing key already exists: ${KEY_UID}"
else
    log "Generating new GPG signing key: ${KEY_UID}"
    gpg --batch --gen-key <<EOF
%no-protection
Key-Type: RSA
Key-Length: 4096
Subkey-Type: RSA
Subkey-Length: 4096
Name-Real: Rich Statistics APT Signing Key
Name-Email: apt@${DOMAIN}
Expire-Date: 0
%commit
EOF
    log "GPG key generated."
fi

# ── Export public key ─────────────────────────────────────────────────────────
log "Exporting public key to ${APT_DIR}/public.gpg…"
gpg --batch --yes --armor --export "${KEY_UID}" > "${APT_DIR}/public.gpg"

# ── Set permissions ────────────────────────────────────────────────────────────
log "Setting permissions…"
chown -R "${USER}:www-data" "${APT_DIR}"
chmod -R 755 "${APT_DIR}"
chmod 644 "${APT_DIR}/public.gpg" "${APT_DIR}/apt-ftparchive.conf"

# ── Print fingerprint ─────────────────────────────────────────────────────────
FINGERPRINT=$(gpg --list-keys --with-colons "${KEY_UID}" 2>/dev/null | grep '^fpr' | head -1 | cut -d: -f10)

log ""
log "============================================"
log "APT repository initialized at ${APT_DIR}"
log "============================================"
log "GPG Key UID:    ${KEY_UID}"
log "Fingerprint:    ${FINGERPRINT}"
log "Public key:     ${APT_DIR}/public.gpg"
log ""
log "To add this repo on a client:"
log "  curl -fsSL https://${DOMAIN}/apt/public.gpg \\"
log "      | sudo gpg --dearmor -o /usr/share/keyrings/rich-statistics.gpg"
log "  echo \"deb [arch=\$(dpkg --print-architecture) signed-by=/usr/share/keyrings/rich-statistics.gpg] \\"
log "      https://${DOMAIN}/apt stable main\" \\"
log "      | sudo tee /etc/apt/sources.list.d/rich-statistics.list"
log "  sudo apt update && sudo apt install rich-statistics"
log ""
log "IMPORTANT: Back up the GPG private key:"
log "  sudo gpg --export-secret-keys --armor ${FINGERPRINT} > /backup/rsa-apt-signing-key.asc"
log "============================================"
