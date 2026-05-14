# Rich Statistics — App Server Architecture

This document describes the production application server infrastructure for disaster recovery and replication. It covers all three environments (production, dev, test) running on the same physical server.

---

## 1. Server Overview

| Attribute | Value |
|-----------|-------|
| **Hostname** | `rs-app` |
| **IP** | `<PWA_SERVER_IP>` |
| **OS** | Debian 12 (bookworm) |
| **Provider** | Google Cloud (GCP) |
| **SSH user** | `richardkentgates` |
| **Web server** | Apache 2.4 with mod_php 8.2 |
| **SSL** | Let's Encrypt via certbot (systemd timer) |
| **Firewall** | UFW + iptables (fail2ban chains) |

### Environments (same server, separate docroots)

| Env | Subdomain | Docroot | Branch |
|-----|-----------|---------|--------|
| **Production** | `app.richstatistics.com` | `/var/www/rs-app/public_html` | `main` |
| **Dev** | `dev.richstatistics.com` | `/var/www/rs-app-dev` | `develop` |
| **Test** | `test.richstatistics.com` | `/var/www/rs-app-test` | `test` |

---

## 2. Provisioning

A fresh server can be provisioned by running the setup script from the repo:

```bash
git clone https://github.com/richardkentgates/rich-statistics.git
cd rich-statistics
sudo bash bin/setup-app-server.sh \
  --domain app.richstatistics.com \
  --email admin@example.com \
  --user richardkentgates
```

The script prints the new `DEPLOY_WEBHOOK_TOKEN` and `APP_SERVER_SSH_KEY` at the end. These must be added as GitHub repository secrets.

### Manual setup steps (if script is unavailable):

```bash
# 1. Install system packages
apt update && apt install -y apache2 mariadb-server php8.2 \
  php8.2-mysql php8.2-curl libapache2-mod-php8.2 \
  certbot python3-certbot-apache ufw fail2ban \
  modsecurity-crs

# 2. Enable Apache modules
a2enmod rewrite ssl headers deflate security2 evasive

# 3. Configure firewall
ufw allow 22/tcp && ufw allow 80/tcp && ufw allow 443/tcp && ufw enable

# 4. Set up Let's Encrypt
certbot --apache -d app.richstatistics.com

# 5. Create directory structure (see §3)

# 6. Copy deploy scripts from repo (see §4)
```

---

## 3. Directory Layout

```
/var/www/
├── rs-app/                          ← Production root
│   ├── .deployed-version            ← Current deployed branch (content: "main")
│   ├── public_html/                 ← PWA web root (Apache DocumentRoot)
│   │   ├── index.html              ← Live PWA shell
│   │   ├── app.js / app.css        ← PWA assets
│   │   ├── config.js               ← Runtime configuration
│   │   ├── config-dev.js           ← Dev override (optional)
│   │   ├── config-test.js          ← Test override (optional)
│   │   ├── index-dev.html          ← Dev entry point (optional)
│   │   ├── index-test.html         ← Test entry point (optional)
│   │   ├── sw.js / sw-init.js      ← Service worker
│   │   ├── manifest.json           ← PWA manifest
│   │   ├── chart.min.js            ← Chart.js (bundled)
│   │   ├── icons/                  ← PWA icons (192px, 512px, 64px)
│   │   ├── dist/                   ← Desktop binaries + update.json
│   │   │   ├── rich-statistics-linux-amd64.deb
│   │   │   ├── rich-statistics-linux-arm64.deb
│   │   │   ├── rich-statistics-windows.exe
│   │   │   └── update.json
│   │   ├── v/                      ← Versioned PWA snapshots
│   │   │   ├── 2.0.0/
│   │   │   ├── 2.0.1/
│   │   │   └── ...
│   │   └── versions.json           ← List of all published versions
│   ├── _deploy/                    ← Webhook handler
│   │   └── index.php               ← Token verification + async update trigger
│   └── apt/                        ← APT repository
│       ├── public.gpg              ← ASCII-armored public key
│       ├── pool/main/              ← .deb package pool
│       │   ├── amd64/
│       │   └── arm64/
│       └── dists/stable/           ← APT metadata (Release, InRelease, Packages)
│
├── rs-app-dev/                     ← Dev environment (same structure)
├── rs-app-test/                    ← Test environment (same structure)
```

---

## 4. Deploy Scripts

Located at `/usr/local/bin/` on the server, sources in the repo under `bin/`.

| Script | Source | Purpose |
|--------|--------|---------|
| `rsa-app-update` | `bin/server-update-webapp.sh` | Syncs `docs/app/` from GitHub main branch → production web root |
| `rsa-app-update-dev` | `bin/server-update-webapp-dev.sh` | Same for dev environment (clones develop branch) |
| `rsa-app-update-test` | `bin/server-update-webapp-test.sh` | Same for test environment (clones test branch) |
| `rsa-apt-repo-update` | `bin/server-apt-repo-update.sh` | Copies .deb into APT pool, regenerates Packages/Release/InRelease |
| `rsa-gen-update-json` | `bin/gen-update-json.py` | Regenerates `update.json` with platform signatures |

All update scripts use the same pattern:
1. `git clone --depth 1 --filter=blob:none --sparse --branch <branch> <repo> /tmp/rsa-extract-*`
2. `git sparse-checkout set docs/app`
3. `rsync -a --delete` root files (excludes versioned dirs, dist/, _deploy/)
4. `rsync -a` additive for versioned dirs
5. Write `.deployed-version` with branch name

### Sudoers

```bash
www-data ALL=(ALL) NOPASSWD: /usr/local/bin/rsa-app-update
www-data ALL=(ALL) NOPASSWD: /usr/local/bin/rsa-app-update-dev
www-data ALL=(ALL) NOPASSWD: /usr/local/bin/rsa-app-update-test
```

Stored in `/etc/sudoers.d/rsa-app-update*` (mode 440).

---

## 5. Apache Virtual Hosts

Six vhost files in `/etc/apache2/sites-enabled/`:

| File | Env | SSL |
|------|-----|-----|
| `rs-app.conf` | Production | No (redirects to SSL) |
| `rs-app-le-ssl.conf` | Production | Yes |
| `rs-dev.conf` | Dev | No |
| `rs-dev-le-ssl.conf` | Dev | Yes |
| `rs-app-test.conf` | Test | No |
| `rs-app-test-le-ssl.conf` | Test | Yes |

### Key vhost directives (SSL variant, production):

```apache
<IfModule mod_ssl.c>
<VirtualHost *:443>
    ServerName app.richstatistics.com
    DocumentRoot /var/www/rs-app/public_html

    Alias /apt/ /var/www/rs-app/apt/
    Alias /_deploy/ /var/www/rs-app/_deploy/

    ErrorLog ${APACHE_LOG_DIR}/rs-app-error.log
    CustomLog ${APACHE_LOG_DIR}/rs-app-access.log combined

    # Security headers
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-Content-Type-Options "nosniff"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"

    # Let's Encrypt
    Include /etc/letsencrypt/options-ssl-apache.conf
    SSLCertificateFile /etc/letsencrypt/live/app.richstatistics.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/app.richstatistics.com/privkey.pem
</VirtualHost>
</IfModule>
```

### Notable aliases:
- `/_deploy/` → `/var/www/rs-app/_deploy/` (outside DocumentRoot)
- `/apt/` → `/var/www/rs-app/apt/` (outside DocumentRoot)

For test environment, there are additional aliases:
- `/_deploy-dev/` → `/var/www/rs-app-test/_deploy-dev/`
- `/_deploy-test/` → `/var/www/rs-app-test/_deploy-test/`

---

## 6. Webhook Deploy Flow

```
GitHub CI (ping-deploy job)
  │  POST https://app.richstatistics.com/_deploy/
  │  Header: X-Deploy-Token: <token>
  ▼
_deploy/index.php  (from bin/server-webhook.php)
  │  Reads token from /etc/rsa-webhook-token (root:www-data 640)
  │  Constant-time compare against X-Deploy-Token header
  │  On match: nohup sudo /usr/local/bin/rsa-app-update &
  ▼
rsa-app-update
  │  git sparse-clone docs/app/ from main branch
  │  rsync to /var/www/rs-app/public_html/
  │  Preserves: dist/, _deploy/, versioned dirs (v/)
  ▼
.deployed-version ← "main"
```

### Token files (one per environment):

```
/etc/rsa-webhook-token       ← Production (referenced by deploy/index.php)
/etc/rsa-webhook-token-dev   ← Dev
/etc/rsa-webhook-token-test  ← Test
```

All tokens are 64-char hex strings generated by `openssl rand -hex 32`. Ownership: `root:www-data 640`.

### CI push flow (desktop binaries):

```
GitHub CI (build-desktop job)
  │  tauri build → .deb / .exe + .sig files
  │  SCP to /var/www/rs-app/public_html/dist/
  │  SSH sudo /usr/local/bin/rsa-apt-repo-update <arch> <version>
  │  SSH sudo /usr/local/bin/rsa-gen-update-json <version> <host> <dist-path>
  ▼
Server dist/ directory updated
APT repository regenerated
update.json regenerated with platform signatures
```

---

## 7. APT Repository

### URL: `https://app.richstatistics.com/apt stable main`

### Signing key:
- **UID:** `Rich Statistics APT Signing Key <apt@app.richstatistics.com>`
- **Location:** root's GPG keyring on server (generated by `bin/setup-apt-repo.sh`)
- **Public key:** `https://<host>/apt/public.gpg`

### Directory structure:

```
/var/www/rs-app/apt/
├── public.gpg                  ← ASCII-armored public key
├── pool/
│   ├── rich-statistics_<version>_amd64.deb
│   └── rich-statistics_<version>_arm64.deb
└── dists/stable/
    ├── main/
    │   ├── binary-amd64/       ← Packages, Packages.gz
    │   └── binary-arm64/       ← Packages, Packages.gz
    ├── Release
    ├── InRelease               ← GPG-signed by root's keyring
    └── Release.gpg
```

Dev and test have their own APT repos at `/var/www/rs-app-dev/apt/` and `/var/www/rs-app-test/apt/` with their own `rsa-apt-repo-update-dev`/`test` scripts.

---

## 8. Security Configuration

### 8.1 Firewall (UFW + iptables)

Default policy: **DROP** inbound, **ALLOW** outbound.

Open ports:
| Port | Purpose |
|------|---------|
| 22/tcp | SSH |
| 80/tcp | HTTP (redirects to HTTPS) |
| 443/tcp | HTTPS |

iptables includes fail2ban chains that dynamically ban offending IPs:
- `f2b-apache-auth` — HTTP auth failures
- `f2b-sshd` — SSH brute force

### 8.2 fail2ban

Active jails (in `/etc/fail2ban/jail.d/sshd-hard.conf`):

| Jail | Log source | Ban action |
|------|-----------|------------|
| `sshd` | auth.log | SSH brute force (max 3 retries, 10 min ban) |

Additional jails (`apache-auth`, `apache-badbots`, `apache-noscript`, `apache-overflows`) can be added for production hardening.

### 8.3 ModSecurity

**Not currently installed.** Planned for production hardening. When added, configuration will use the OWASP Core Rule Set (CRS) with WordPress-specific exclusion rules.

### 8.4 SSH Hardening

Configuration in `/etc/ssh/sshd_config`:
```
PermitRootLogin no
PasswordAuthentication no
```

Only SSH key authentication is permitted. The CI uses an ED25519 deploy key added to `~/.ssh/authorized_keys` on the server.

### 8.5 mod_evasive

Enabled to mitigate DoS attacks. Configured in `/etc/apache2/mods-enabled/evasive.conf`.

### 8.6 Linux Malware Detect (maldet)

**Not currently installed.** Recommended for production hardening:

```bash
cd /tmp
wget https://www.rfxn.com/downloads/maldetect-current.tar.gz
tar xzf maldetect-current.tar.gz
cd maldetect-*
sudo ./install.sh
```

---

## 9. SSL Certificates

All three subdomains have valid Let's Encrypt certificates obtained via certbot:

```bash
certbot --apache -d app.richstatistics.com
certbot --apache -d dev.richstatistics.com
certbot --apache -d test.richstatistics.com
```

Auto-renewal via systemd timer:
```
certbot.timer — runs twice daily (auto-renewal)
```

Certificate locations:
```
/etc/letsencrypt/live/app.richstatistics.com/
/etc/letsencrypt/live/dev.richstatistics.com/
/etc/letsencrypt/live/test.richstatistics.com/
```

---

## 10. Disaster Recovery

### Full server rebuild:

```bash
# 1. Provision new Debian 12 VPS
# 2. Run setup script
sudo bash bin/setup-app-server.sh \
  --domain app.richstatistics.com \
  --email admin@example.com \
  --user richardkentgates

# 3. Install maldet
cd /tmp && wget https://www.rfxn.com/downloads/maldetect-current.tar.gz && ...

# 4. Set up webhook tokens
sudo sh -c 'openssl rand -hex 32 > /etc/rsa-webhook-token'
sudo chmod 640 /etc/rsa-webhook-token
sudo chown root:www-data /etc/rsa-webhook-token
# Repeat for -dev and -test variants

# 5. Update GitHub secrets with the new tokens

# 6. Trigger initial deploy from CI (ping-deploy workflow or SSH the update script)
sudo /usr/local/bin/rsa-app-update

# 7. Verify
curl -I https://app.richstatistics.com/
```

### Partial recovery:

| Scenario | Action |
|----------|--------|
| Corrupted `public_html/` | `sudo /usr/local/bin/rsa-app-update` re-syncs from GitHub |
| Lost APT repo metadata | `sudo /usr/local/bin/rsa-apt-repo-update amd64 <version>` |
| Lost webhook token | Regenerate token file, update GitHub secret |
| Expired SSL cert | `sudo certbot renew` or re-run certbot command |
| Failed CI deploy | Check `.deployed-version` — re-run webhook or update script manually |

### Backup:

| Data | Location | Backup method |
|------|----------|--------------|
| PWA web app | `/var/www/rs-app/public_html/` | Recoverable from GitHub |
| Desktop binaries | `/var/www/rs-app/public_html/dist/` | Recoverable from CI artifacts |
| APT repo | `/var/www/rs-app/apt/` | Rebuild with `rsa-apt-repo-update` |
| GPG signing key | root's keyring | **Must export** — critical for APT signing |
| Apache vhost configs | `/etc/apache2/sites-enabled/` | In repo as `bin/setup-app-server.sh` |

**Critical:** The APT signing key must be backed up separately:
```bash
sudo gpg --export-secret-keys --armor 7528670109B7907492528C2F7F1EA217D64A5134 > /backup/rsa-apt-signing-key.asc
```

---

## 11. Monitored Endpoints

| URL | Check |
|-----|-------|
| `https://app.richstatistics.com/` | Returns 200 |
| `https://dev.richstatistics.com/` | Returns 200 |
| `https://test.richstatistics.com/` | Returns 200 |
| `https://<env>/dist/update.json` | Valid JSON with version + signatures |
| `https://<env>/apt/` | Returns directory listing or 403 (not 404) |
| `https://<env>/_deploy/` | Returns 401 on GET (not 404 — endpoint exists) |

---

## 12. CI/CD Integration

| Environment | CI Workflow | Trigger | What it pushes |
|-------------|------------|---------|---------------|
| Production | `build-release.yml` | Tag `v*.*.*` on main | ZIP, PWA snapshot, .deb, .exe, update.json |
| Dev | `build-develop.yml` | Push to develop | ZIP, PWA via webhook, .deb, .exe |
| Test | `build-test.yml` | Push to test | ZIP, PWA via webhook, .deb, .exe |

Desktop binaries are pushed via SCP. PWA files are synced via webhook → `rsa-app-update*`.
