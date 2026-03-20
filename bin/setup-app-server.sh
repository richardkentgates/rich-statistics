#!/usr/bin/env bash
# =============================================================================
# bin/setup-app-server.sh
#
# Provisions a new rs-app.richardkentgates.com server from scratch on
# Debian 12 (bookworm).  Run as root (or via sudo) on a freshly created VPS.
#
# What it installs / configures
# ─────────────────────────────
#   • Apache 2.4 + PHP 8.2 (mod_php)
#   • mod_rewrite, mod_headers, mod_ssl
#   • Certbot (Let's Encrypt SSL, apache authenticator)
#   • git, rsync, curl, python3, fail2ban, ufw
#   • /var/www/rs-app/  web root + all subdirectories
#   • /_deploy/index.php  webhook handler (from bin/server-webhook.php)
#   • /usr/local/bin/rsa-app-update  update script (from bin/server-update-webapp.sh)
#   • /etc/rsa-webhook-token  (random 32-byte hex, root:www-data 640)
#   • /etc/sudoers.d/rsa-app-update  (lets www-data call the update script)
#   • Apache virtual-host configs for port 80 + 443
#   • Let's Encrypt TLS certificate (certbot --apache)
#   • ED25519 SSH keypair for GitHub Actions CI
#   • fail2ban sshd jail
#   • ufw: allow 22/tcp 80/tcp 443/tcp
#
# Usage
# ─────
#   sudo bash setup-app-server.sh [options]
#
# Options
#   --domain   FQDN to serve (default: rs-app.richardkentgates.com)
#   --email    Let's Encrypt registration address (required for cert)
#   --user     OS user who owns /var/www/rs-app and receives SSH access
#              (default: richardkentgates)
#   --skip-ssl Skip certbot — use this when DNS is not yet pointed to the
#              server.  You can run  sudo certbot --apache -d <domain>  later.
#   --skip-deploy  Skip the initial rsa-app-update run.
#   --help     Show this message and exit.
#
# After the script finishes it prints the values you need to set in GitHub:
#   Repository → Settings → Secrets and variables → Actions
#       APP_SERVER_SSH_KEY    ← new CI private key (printed to stdout)
#       DEPLOY_WEBHOOK_TOKEN  ← contents of /etc/rsa-webhook-token
#
# Prerequisites
# ─────────────
#   1. A fresh Debian 12 VPS (tested on Google Cloud e2-micro).
#   2. The A record for --domain must point to this server's IP BEFORE you run
#      with SSL enabled (certbot will fail otherwise).
#   3. Run this script from the root of the rich-statistics git repo:
#         git clone https://github.com/richardkentgates/rich-statistics.git
#         cd rich-statistics
#         sudo bash bin/setup-app-server.sh --email you@example.com
# =============================================================================

set -euo pipefail

# ─── colour helpers ──────────────────────────────────────────────────────────
BOLD=$'\033[1m'; GREEN=$'\033[32m'; YELLOW=$'\033[33m'; RED=$'\033[31m'; RESET=$'\033[0m'
info()  { echo "${BOLD}${GREEN}[setup]${RESET} $*"; }
warn()  { echo "${BOLD}${YELLOW}[warn] ${RESET} $*"; }
die()   { echo "${BOLD}${RED}[error]${RESET} $*" >&2; exit 1; }
step()  { echo ""; echo "${BOLD}── $* ──${RESET}"; }

# ─── defaults ────────────────────────────────────────────────────────────────
DOMAIN="rs-app.richardkentgates.com"
ADMIN_EMAIL=""
SERVER_USER="richardkentgates"
SKIP_SSL=0
SKIP_DEPLOY=0
WEB_ROOT="/var/www/rs-app"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(dirname "${SCRIPT_DIR}")"

# ─── argument parsing ────────────────────────────────────────────────────────
while [[ $# -gt 0 ]]; do
    case "$1" in
        --domain)      DOMAIN="$2";      shift 2 ;;
        --email)       ADMIN_EMAIL="$2"; shift 2 ;;
        --user)        SERVER_USER="$2"; shift 2 ;;
        --skip-ssl)    SKIP_SSL=1;       shift   ;;
        --skip-deploy) SKIP_DEPLOY=1;    shift   ;;
        --help|-h)
            sed -n '/^# Usage/,/^# Prerequisites/{ /^# Prereq/q; s/^# \{0,1\}//; p }' "$0"
            exit 0
            ;;
        *) die "Unknown option: $1  (run with --help)" ;;
    esac
done

# ─── checks ──────────────────────────────────────────────────────────────────
[[ $EUID -eq 0 ]] || die "Run as root: sudo bash $0"

[[ -f "${REPO_ROOT}/bin/server-webhook.php" ]] \
    || die "Run this from the rich-statistics repo root (bin/server-webhook.php not found)"

[[ -f "${REPO_ROOT}/bin/server-update-webapp.sh" ]] \
    || die "bin/server-update-webapp.sh not found"

if [[ $SKIP_SSL -eq 0 && -z "${ADMIN_EMAIL}" ]]; then
    die "--email is required for SSL.  Use --skip-ssl to defer certificate setup."
fi

# ─── 1. packages ─────────────────────────────────────────────────────────────
step "Installing packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y \
    apache2 \
    php8.2 \
    libapache2-mod-php8.2 \
    php8.2-cli \
    certbot \
    python3-certbot-apache \
    git \
    rsync \
    curl \
    python3 \
    fail2ban \
    ufw \
    sudo
info "Packages installed."

# ─── 2. Apache modules ───────────────────────────────────────────────────────
step "Enabling Apache modules"
a2enmod rewrite headers ssl php8.2
info "Modules: rewrite, headers, ssl, php8.2 — enabled."

# ─── 3. System user ──────────────────────────────────────────────────────────
step "Ensuring OS user: ${SERVER_USER}"
if ! id "${SERVER_USER}" &>/dev/null; then
    adduser --disabled-password --gecos "" "${SERVER_USER}"
    info "Created user ${SERVER_USER}."
else
    info "User ${SERVER_USER} already exists."
fi
# Add to www-data group so the user can read PHP-written files if needed
usermod -aG www-data "${SERVER_USER}" || true

# ─── 4. Web root ─────────────────────────────────────────────────────────────
step "Setting up web root: ${WEB_ROOT}"
mkdir -p "${WEB_ROOT}/_deploy" "${WEB_ROOT}/desktop"
chown -R "${SERVER_USER}:${SERVER_USER}" "${WEB_ROOT}"
find "${WEB_ROOT}" -type d -exec chmod 755 {} +
find "${WEB_ROOT}" -type f -exec chmod 644 {} + 2>/dev/null || true
info "Web root ready."

# ─── 5. Webhook handler ──────────────────────────────────────────────────────
step "Installing webhook handler"
cp "${REPO_ROOT}/bin/server-webhook.php" "${WEB_ROOT}/_deploy/index.php"
# Apache (www-data) needs to read this file; 644 + world-rx on parent dir is fine
chmod 644 "${WEB_ROOT}/_deploy/index.php"
chown "${SERVER_USER}:${SERVER_USER}" "${WEB_ROOT}/_deploy/index.php"
info "Webhook: ${WEB_ROOT}/_deploy/index.php"

# ─── 6. Webhook token ────────────────────────────────────────────────────────
step "Generating deploy token"
TOKEN_FILE="/etc/rsa-webhook-token"
if [[ -f "${TOKEN_FILE}" ]]; then
    warn "${TOKEN_FILE} already exists — keeping existing token."
else
    openssl rand -hex 32 > "${TOKEN_FILE}"
    info "Token generated: ${TOKEN_FILE}"
fi
chmod 640 "${TOKEN_FILE}"
chown root:www-data "${TOKEN_FILE}"

# ─── 7. Update script ────────────────────────────────────────────────────────
step "Installing app-update script"
cp "${REPO_ROOT}/bin/server-update-webapp.sh" /usr/local/bin/rsa-app-update
chmod +x /usr/local/bin/rsa-app-update
info "Script: /usr/local/bin/rsa-app-update"

# ─── 7b. APT repository update script ───────────────────────────────────────
step "Installing apt-repo-update script"
cp "${REPO_ROOT}/bin/server-apt-repo-update.sh" /usr/local/bin/rsa-apt-repo-update
chmod +x /usr/local/bin/rsa-apt-repo-update
info "Script: /usr/local/bin/rsa-apt-repo-update"

# ─── 8. Sudoers — let www-data call the update scripts ──────────────────────
step "Configuring sudoers"
SUDOERS_FILE="/etc/sudoers.d/rsa-app-update"
cat > "${SUDOERS_FILE}" <<'SUDOERS'
www-data ALL=(ALL) NOPASSWD: /usr/local/bin/rsa-app-update
richardkentgates ALL=(ALL) NOPASSWD: /usr/local/bin/rsa-apt-repo-update
SUDOERS
chmod 440 "${SUDOERS_FILE}"
visudo -c -f "${SUDOERS_FILE}" || die "sudoers file failed validation — check manually."
info "Sudoers: ${SUDOERS_FILE}"

# ─── 9. Apache site config ───────────────────────────────────────────────────
step "Writing Apache site config"
SITE_CONF="/etc/apache2/sites-available/rs-app.conf"
cat > "${SITE_CONF}" <<APACHECONF
<VirtualHost *:80>
    ServerName ${DOMAIN}
    DocumentRoot ${WEB_ROOT}

    <Directory ${WEB_ROOT}>
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted
    </Directory>

    <LocationMatch "^/[0-9]+\.[0-9]+\.[0-9]+/">
        Header set Cache-Control "public, max-age=31536000, immutable"
        Header set Access-Control-Allow-Origin "*"
    </LocationMatch>

    <Location "/">
        Header set Access-Control-Allow-Origin "*"
    </Location>

    ErrorLog  \${APACHE_LOG_DIR}/rs-app-error.log
    CustomLog \${APACHE_LOG_DIR}/rs-app-access.log combined

    RewriteEngine on
    RewriteCond %{SERVER_NAME} =${DOMAIN}
    RewriteRule ^ https://%{SERVER_NAME}%{REQUEST_URI} [END,NE,R=permanent]
</VirtualHost>
APACHECONF

a2ensite rs-app.conf
# Disable the default site if it would conflict
a2dissite 000-default.conf 2>/dev/null || true
systemctl reload apache2
info "Apache site enabled: rs-app.conf"

# ─── 10. TLS certificate ─────────────────────────────────────────────────────
if [[ $SKIP_SSL -eq 0 ]]; then
    step "Obtaining Let's Encrypt certificate"
    certbot --apache \
        -d "${DOMAIN}" \
        --non-interactive \
        --agree-tos \
        --email "${ADMIN_EMAIL}" \
        --redirect
    info "Certificate issued and auto-renewal configured."
    systemctl reload apache2
else
    warn "Skipping SSL (--skip-ssl).  Run when DNS is ready:"
    warn "  sudo certbot --apache -d ${DOMAIN} --email <you@example.com> --redirect"
fi

# ─── 11. fail2ban ────────────────────────────────────────────────────────────
step "Configuring fail2ban (sshd jail)"
cat > /etc/fail2ban/jail.d/sshd-hard.conf <<'F2B'
[sshd]
enabled  = true
port     = ssh
maxretry = 5
bantime  = 1h
findtime = 10m
F2B
systemctl enable fail2ban
systemctl restart fail2ban
info "fail2ban sshd jail active."

# ─── 12. ufw firewall ────────────────────────────────────────────────────────
step "Configuring ufw"
ufw --force reset
ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp comment 'SSH'
ufw allow 80/tcp comment 'HTTP'
ufw allow 443/tcp comment 'HTTPS'
ufw --force enable
info "ufw: 22/80/443 open."

# ─── 13. CI SSH keypair ──────────────────────────────────────────────────────
step "Generating CI SSH keypair"
CI_KEY_FILE="/home/${SERVER_USER}/.ssh/ci_key_rsa_app"
mkdir -p "/home/${SERVER_USER}/.ssh"
chmod 700 "/home/${SERVER_USER}/.ssh"
chown "${SERVER_USER}:${SERVER_USER}" "/home/${SERVER_USER}/.ssh"

if [[ -f "${CI_KEY_FILE}" ]]; then
    warn "CI key already exists at ${CI_KEY_FILE} — skipping generation."
else
    ssh-keygen -t ed25519 -C "rich-statistics-ci-$(date +%Y%m%d)" \
        -f "${CI_KEY_FILE}" -N ""
    info "CI keypair created: ${CI_KEY_FILE}"
fi

# Authorize the CI key on this server
AUTH_KEYS="/home/${SERVER_USER}/.ssh/authorized_keys"
CI_PUBKEY=$(cat "${CI_KEY_FILE}.pub")
if ! grep -qF "${CI_PUBKEY}" "${AUTH_KEYS}" 2>/dev/null; then
    echo "${CI_PUBKEY}" >> "${AUTH_KEYS}"
    chmod 600 "${AUTH_KEYS}"
    chown "${SERVER_USER}:${SERVER_USER}" "${AUTH_KEYS}"
    info "CI public key added to authorized_keys."
else
    info "CI public key already in authorized_keys."
fi
chown "${SERVER_USER}:${SERVER_USER}" "${CI_KEY_FILE}" "${CI_KEY_FILE}.pub"

# ─── 14. Initial deploy ──────────────────────────────────────────────────────
if [[ $SKIP_DEPLOY -eq 0 ]]; then
    step "Running initial deploy (rsa-app-update)"
    # Run as the server user so file ownership is correct
    if su -c "/usr/local/bin/rsa-app-update" "${SERVER_USER}" 2>&1; then
        info "Initial deploy complete."
    else
        warn "Initial deploy failed — the server is up, but app files may need a manual deploy."
        warn "Re-run:  sudo -u ${SERVER_USER} /usr/local/bin/rsa-app-update"
    fi
else
    warn "Skipping initial deploy (--skip-deploy)."
    warn "Re-run:  sudo -u ${SERVER_USER} /usr/local/bin/rsa-app-update"
fi

# ─── 15. Summary ─────────────────────────────────────────────────────────────
echo ""
echo "${BOLD}${GREEN}════════════════════════════════════════════════════════${RESET}"
echo "${BOLD}${GREEN} Setup complete — update these GitHub secrets:${RESET}"
echo "${BOLD}${GREEN}════════════════════════════════════════════════════════${RESET}"
echo ""
echo "${BOLD}Repository → Settings → Secrets and variables → Actions${RESET}"
echo ""
echo "${BOLD}── DEPLOY_WEBHOOK_TOKEN ──${RESET}"
echo "(contents of ${TOKEN_FILE})"
echo ""
cat "${TOKEN_FILE}"
echo ""
echo ""
echo "${BOLD}── APP_SERVER_SSH_KEY ──${RESET}"
echo "(ED25519 private key for ${SERVER_USER}@${DOMAIN})"
echo ""
cat "${CI_KEY_FILE}"
echo ""
echo "${BOLD}${GREEN}════════════════════════════════════════════════════════${RESET}"
echo ""
echo "Server user:  ${SERVER_USER}@${DOMAIN}"
echo "Web root:     ${WEB_ROOT}"
echo "Webhook URL:  https://${DOMAIN}/_deploy/"
echo "Apache logs:  /var/log/apache2/rs-app-*.log"
echo "Deploy log:   /var/log/rsa-deploy.log"
echo ""
echo "To verify the server is serving the app:"
echo "  curl -I https://${DOMAIN}/"
echo ""
echo "To manually trigger an app update:"
echo "  sudo -u ${SERVER_USER} /usr/local/bin/rsa-app-update"
echo ""
