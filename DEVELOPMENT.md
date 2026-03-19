# Rich Statistics — Developer & Operations Reference

This document is the single authoritative reference for working in this repository:
development workflow, release process, CI/CD pipeline, infrastructure, and the
external services that connect everything together.

See also: [ARCHITECTURE.md](ARCHITECTURE.md) (plugin internals, DB schema, request lifecycle)
and [CONTRIBUTING.md](CONTRIBUTING.md) (local setup, coding standards, PR process).

---

## Table of Contents

1. [What This Repo Produces](#1-what-this-repo-produces)
2. [Repository Map](#2-repository-map)
3. [What Ships in the Distribution ZIP](#3-what-ships-in-the-distribution-zip)
4. [External Services & Topology](#4-external-services--topology)
5. [GitHub Secrets](#5-github-secrets)
6. [Release Process](#6-release-process)
7. [CI / CD Pipeline](#7-ci--cd-pipeline)
8. [App Server Infrastructure](#8-app-server-infrastructure)
9. [Freemius Premium Integration](#9-freemius-premium-integration)
10. [Webapp & Desktop App](#10-webapp--desktop-app)
11. [WordPress.org Distribution](#11-wordpressorg-distribution)
12. [Design Notes](#12-design-notes)

---

## 1. What This Repo Produces

This single repository produces **three deliverables**:

| Deliverable | What it is | Where it goes |
|---|---|---|
| **WordPress plugin ZIP** | The installable plugin (`rich-statistics-x.y.z.zip`) | GitHub Release → uploaded to Freemius for premium users; WordPress.org for free users |
| **PWA / companion app** | Installable web app (vanilla JS) served from `docs/app/` | Hosted at `https://rs-app.richardkentgates.com/app/` |
| **Linux desktop app** | Tauri-wrapped `.deb` for amd64 and arm64 | Served from `https://rs-app.richardkentgates.com/desktop/` |

---

## 2. Repository Map

```
rich-statistics/
│
├── rich-statistics.php   Main plugin file — constants, autoload, Freemius init
├── includes/             All plugin PHP classes (see ARCHITECTURE.md)
├── assets/               JS and CSS loaded in WordPress admin + frontend
├── templates/            PHP view partials rendered by RSA_Admin
├── cli/                  WP-CLI command class
├── languages/            .pot file for translators
│
├── webapp/               Tauri source for the desktop app — this folder is
│                         packaged by the Tauri CI build into .deb files.
│                         The JS/HTML/CSS here mirrors docs/app/ and is kept
│                         in sync manually.
│
├── vendor/               Composer dependencies
│   ├── freemius/         Freemius SDK — COMMITTED (not .gitignored).
│   │                     Ships inside the plugin ZIP. See §9.
│   └── (everything else) PHPUnit, Brain Monkey, etc. — dev-only, excluded from ZIP.
│
├── docs/                 GitHub Pages site — NOT shipped in the plugin ZIP
│   ├── index.html        Project landing page (rs-app.richardkentgates.com root)
│   ├── app/              Current live PWA — the app server pulls this on deploy
│   │   └── {version}/    Versioned snapshot copied automatically on each tag
│   └── wiki/             Plugin documentation (wiki.html pages)
│
├── bin/                  Operational scripts — NOT shipped in the plugin ZIP
│   ├── setup-app-server.sh       Provisions a fresh Debian 12 app server from scratch
│   ├── server-webhook.php        Source for the _deploy/index.php on the app server
│   ├── server-update-webapp.sh   Source for /usr/local/bin/rsa-app-update on server
│   └── install-wp-tests.sh       Sets up the WordPress integration test environment
│
├── tests/                PHPUnit unit + integration tests
├── .github/workflows/    CI/CD: tests.yml, build-release.yml
├── .distignore           Controls what is excluded by `wp dist-zip` (WP.org deploy)
├── ARCHITECTURE.md       Plugin internals and design decisions
├── CONTRIBUTING.md       Local dev setup and PR process
└── DEVELOPMENT.md        This file
```

---

## 3. What Ships in the Distribution ZIP

The plugin ZIP (built by CI or `wp dist-zip`) ships these and nothing else:

```
rich-statistics/
├── rich-statistics.php
├── includes/
├── assets/
├── templates/
├── cli/
├── languages/
├── vendor/freemius/   ← SDK committed and intentionally included
└── vendor/autoload.php + vendor/composer/
```

**Excluded from the ZIP** (enforced by `.distignore`):
`/.git`, `/.github`, `/bin`, `/build`, `/docs`, `/tests`, `/webapp`,
`composer.json`, `composer.lock`, `phpunit.xml.dist`, `CONTRIBUTING.md`,
`SECURITY.md`, `README.md`, `*.sh`, and the rest of `vendor/` (dev dependencies).

> **Key point:** `vendor/freemius/` is committed and included in the ZIP. All other
> `vendor/` packages are dev-only (PHPUnit, Brain Monkey, Mockery) and are excluded.

---

## 4. External Services & Topology

```
GitHub (source + CI)
  │
  ├── push v*.*.* tag
  │     │
  │     ├── tests.yml ─────────────────────────────── pass/fail status check
  │     │
  │     └── build-release.yml
  │           ├── Build plugin ZIP → GitHub Release artifact
  │           ├── Build .deb × 2 (amd64 + arm64, Tauri) → SSH to app server
  │           ├── Commit versioned docs/app/{version}/ snapshot → main branch
  │           └── POST /_deploy/ webhook → app server updates docs/app/
  │
  ├── WordPress.org SVN (plugin distribution — free tier)
  │     └── deploy-wporg.yml triggers on tag → 10up/action-wordpress-plugin-deploy
  │         (workflow not yet added — see §11)
  │
  └── Freemius dashboard (premium licensing + auto-updates)
        └── Developer manually uploads plugin ZIP after each release

App server: rs-app.richardkentgates.com  (104.197.231.120)
  ├── /app/              → serves the live PWA (pulled from docs/app/ by webhook)
  ├── /desktop/          → serves .deb files + update.json (pushed by CI via SSH)
  └── /_deploy/          → webhook endpoint (PHP, validates X-Deploy-Token header)
```

---

## 5. GitHub Secrets

All three secrets must be set in the GitHub repository settings before a release will fully succeed.

| Secret | Purpose | How to get the value |
|---|---|---|
| `APP_SERVER_SSH_KEY` | ED25519 private key used by CI to SSH into the app server to upload `.deb` files and `update.json` | Generated by `bin/setup-app-server.sh` (printed at end of script), or manually: `ssh-keygen -t ed25519 -C "rich-statistics-ci"` then add public key to server's `~/.ssh/authorized_keys` |
| `DEPLOY_WEBHOOK_TOKEN` | Bearer token in the `X-Deploy-Token` header when CI pings the `/_deploy/` webhook | Generated by `bin/setup-app-server.sh` (printed at end), or `openssl rand -hex 32`; same value stored in `/etc/rsa-webhook-token` on the server |
| `TAURI_SIGNING_PRIVATE_KEY` | Minisign private key used to sign `.deb` files so the desktop app's auto-updater can verify them | Generated once: `tauri signer generate`; the matching public key is embedded in `webapp/tauri.conf.json` |

---

## 6. Release Process

Releases follow a strict flow. CI handles everything after the tag is pushed.

```
develop  ──[all features / fixes merged here]──►
           │
           └─ release/1.x.x  (stabilisation branch — optional for larger releases)
                 │
                 ▼
               main  ──[merge via PR or fast-forward]──►
                 │
                 └─ Tag: v1.x.x  ──► triggers build-release.yml
```

### Step-by-step

1. **Ensure develop is passing** — `tests.yml` must be green.

2. **Update version numbers** — change `RSA_VERSION` constant and `Version:` header in
   `rich-statistics.php`, and the `Stable tag:` in `readme.txt`.

3. **Update CHANGELOG.md** — move the `[Unreleased]` block to a dated `[1.x.x] — YYYY-MM-DD`
   entry.

4. **Merge to main**
   ```bash
   git checkout main && git merge --no-ff develop
   ```

5. **Create an annotated tag on main**
   ```bash
   git tag -a v1.x.x -m "Release v1.x.x"
   git push origin main --tags
   ```

6. **CI takes over** (`build-release.yml`):
   - Builds and uploads the plugin ZIP as a GitHub Release artifact.
   - Builds `.deb` files for amd64 and arm64 via Tauri; uploads to app server via SSH.
   - Commits a versioned `docs/app/{version}/` snapshot to `main`.
   - Pings the webhook — app server pulls latest `docs/app/` and goes live.

7. **Upload to Freemius** — download the ZIP from the GitHub Release and upload it at
   `https://dashboard.freemius.com → Plugin → Versions → Add New Version`.
   Freemius delivers the update to premium users automatically.

8. **WordPress.org** — the `deploy-wporg.yml` workflow (once added — see §11) will push
   to the SVN repository automatically when a version tag is detected.

---

## 7. CI / CD Pipeline

### `tests.yml` — runs on every push/PR to `main` or `develop`

| Job | Matrix | What it does |
|---|---|---|
| `unit` | PHP 8.1, 8.2, 8.3 | Runs `tests/unit/` — no WordPress install needed, fast |
| `integration` | PHP 8.1/8.2 × WP latest/6.4 | Installs WordPress test suite, runs `tests/integration/` |
| `lint` | PHP 8.2 | `composer phpcs` — checks WordPress Coding Standards |

### `build-release.yml` — runs only on `v*.*.*` tags (or `workflow_dispatch`)

| Job | Needs | What it does |
|---|---|---|
| `build` | — | Verifies PHP syntax; creates plugin ZIP; uploads as Release artifact; commits versioned `docs/app/{version}/` snapshot to `main` |
| `build-desktop` | `build` | Matrix: amd64 (ubuntu) + arm64 (ubuntu-24.04-arm). Builds Tauri `.deb`. Uploads to app server: `SCP` → `/var/www/rs-app/desktop/`; writes `update.json` via SSH |
| `ping-deploy` | `build-desktop` | `POST /_deploy/` with `X-Deploy-Token` header → triggers `rsa-app-update` on server |

> **Note:** `build-desktop` uses `APP_SERVER_SSH_KEY` for SCP + SSH.
> `ping-deploy` uses `DEPLOY_WEBHOOK_TOKEN`. `build` uses neither.

---

## 8. App Server Infrastructure

**Server:** Debian 12 (bookworm), Google Cloud, `rs-app.richardkentgates.com` (`104.197.231.120`)
**Web server:** Apache 2.4 + PHP 8.2 (`libapache2-mod-php8.2`)
**SSL:** Let's Encrypt via `certbot --apache`, auto-renews via systemd timer
**System user:** `richardkentgates` (also the web-root owner)

### Deploy mechanism

```
CI (ping-deploy job)
  │  POST https://rs-app.richardkentgates.com/_deploy/
  │  Header: X-Deploy-Token: <DEPLOY_WEBHOOK_TOKEN>
  ▼
_deploy/index.php  (from bin/server-webhook.php)
  │  Reads token from /etc/rsa-webhook-token (root:www-data 640)
  │  Compares against X-Deploy-Token header
  │  On match: nohup sudo /usr/local/bin/rsa-app-update &
  ▼
/usr/local/bin/rsa-app-update  (from bin/server-update-webapp.sh)
  │  git sparse-clone: fetches only docs/app/ from the latest tag
  │  rsync to /var/www/rs-app/
  │  Preserves: desktop/, _deploy/, versioned dirs
```

**Sudoers rule:** `www-data ALL=(ALL) NOPASSWD: /usr/local/bin/rsa-app-update`
(stored in `/etc/sudoers.d/rsa-app-update`, mode 440)

### Recovery

If the server needs to be rebuilt from scratch:

```bash
git clone https://github.com/richardkentgates/rich-statistics.git
cd rich-statistics
sudo bash bin/setup-app-server.sh \
  --domain rs-app.richardkentgates.com \
  --email  your@email.com \
  --user   richardkentgates
```

The script prints the new `DEPLOY_WEBHOOK_TOKEN` and `APP_SERVER_SSH_KEY` values
at the end — update both secrets in the GitHub repository settings.

Full recovery documentation: [docs/wiki/app-server-setup.html](docs/wiki/app-server-setup.html)
(rendered at `https://rs-app.richardkentgates.com/wiki/app-server-setup.html`)

---

## 9. Freemius Premium Integration

### SDK location

`vendor/freemius/` is **committed to git** and ships inside the plugin ZIP. This is
intentional and contrary to Freemius's default scaffold, which downloads the SDK at
deploy time. Here the SDK is committed so:
- No network calls during CI
- No surprise version drift
- The ZIP is self-contained

### Configuration (in `rich-statistics.php`)

```php
$rs_fs = fs_dynamic_init([
    'id'         => '25954',          // Freemius product ID
    'slug'       => 'rich-statistics',
    'public_key' => 'pk_ebd3048f311ce1adcbdb6246fc1e5',  // public, safe to commit
    'is_premium' => true,
    ...
]);
```

The `public_key` (`pk_…`) identifies the plugin on the Freemius network and is
**intentionally public** — it is embedded in the distributed plugin files and
visible to all users. It is not an auth credential. The `secret_key` (`sk_…`)
is never stored in the plugin code; it lives only in the Freemius dashboard.

### Premium gating pattern

Every premium feature class checks at its entry point:
```php
if ( ! rs_fs()->can_use_premium_code__premium_only() ) {
    return;
}
```

In development (no `vendor/freemius/` SDK), the `rs_fs()` stub at the top of
`rich-statistics.php` returns a no-op object so all premium checks silently
return false and free-tier tests run without any Freemius dependency.

---

## 10. Webapp & Desktop App

### How they relate

```
webapp/                   ← Tauri source (wraps the PWA in a native GTK window)
  app.html / app.js …       Kept in sync with docs/app/ manually

docs/app/                 ← The live web app (served at /app/)
  index.html, app.js …     Updated by CI: webhook pulls latest on each release
  {version}/              ← Versioned snapshots (copied by CI on each tag)
    1.4.2/                  Tauri uses these to serve the correct app version
    ...                     to the installed desktop app
```

### Desktop auto-updates

The desktop app checks `https://rs-app.richardkentgates.com/desktop/update.json`
for new versions. The CI `build-desktop` job writes this file via SSH after
building each `.deb`. Updates are signed with `TAURI_SIGNING_PRIVATE_KEY`;
the matching public key is in `webapp/tauri.conf.json` (or `src-tauri/tauri.conf.json`).

### Authentication

The app authenticates to the WordPress REST API using **Application Passwords**
(WP core feature, no extra plugin needed). Users generate an app password in their
WordPress profile and enter it in the app's settings screen.

---

## 11. WordPress.org Distribution

The plugin will be distributed on WordPress.org for the free tier. The standard
deploy mechanism is a GitHub Actions workflow using `10up/action-wordpress-plugin-deploy`.

**This workflow (`deploy-wporg.yml`) has not yet been added to the repository.**

When added, it will:
1. Trigger on `v*.*.*` tags (same as `build-release.yml`)
2. Use `.distignore` to exclude dev-only files from the SVN commit
3. Require two repository secrets: `SVN_USERNAME` and `SVN_PASSWORD`

Until then, releases to WordPress.org must be done manually via the WP.org plugin dashboard.

---

## 12. Design Notes

These decisions can surprise contributors who expect a more typical WordPress plugin setup.

**No JavaScript build step.** All JS is plain ES5-compatible, no transpiler or bundler.
This matches the WordPress jQuery environment and keeps the dev setup minimal — no Node,
no npm, no webpack. The trade-off is slightly verbose code and no module imports.

**Chart.js is in `vendor/chart.min.js`.** Composer is for PHP only. Chart.js is
committed directly rather than fetched via npm because it is a pure runtime dependency
with no need for a build step. It ships in the plugin ZIP.

**`vendor/` in git.** `vendor/freemius/` is always committed. Dev-only packages
(PHPUnit, Brain Monkey, Mockery) are also committed so CI does not need to run
`composer install` to access the SDK, and so the ZIP can be built without composer.

**Templates are plain PHP partials.** No templating engine (Twig, Blade, etc.).
Output is escaped inline at the `echo` / `esc_*` call. This keeps the plugin
dependency-free and the template code readable to PHP developers without framework knowledge.

**Each database table uses `$wpdb->prefix`** (e.g. `wp_rsa_events`). In multisite each
subsite has its own table set because `$wpdb->prefix` is already subsite-scoped when
called inside a `switch_to_blog()` context. There is no cross-subsite data sharing.

**Bot detection is two-layer and non-blocking.** Requests are never blocked at the
server; requests with bot_score ≥ threshold are silently discarded. This avoids false
positives causing broken tracking for legitimate users and prevents an attacker from
probing which signals trigger blocking.
