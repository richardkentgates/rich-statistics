# Rich Statistics — Developer & Operations Reference

This document is the single authoritative reference for working in this repository:
development workflow, release process, CI/CD pipeline, infrastructure, and the
external services that connect everything together.

See also: [ARCHITECTURE.md](ARCHITECTURE.md) (plugin internals, DB schema, request lifecycle)
and [CONTRIBUTING.md](CONTRIBUTING.md) (local setup, coding standards, PR process).

---

## Table of Contents

1. [What This Repo Produces](#what-this-repo-produces)
2. [Repository Map](#repository-map)
3. [What Ships in the Distribution ZIP](#what-ships-in-the-distribution-zip)
4. [External Services & Topology](#external-services--topology)
5. [GitHub Secrets](#github-secrets)
6. [Release Process](#release-process)
7. [CI / CD Pipeline](#ci--cd-pipeline)
8. [App Server Infrastructure](#app-server-infrastructure)
9. [Freemius Premium Integration](#freemius-premium-integration)
10. [Webapp & Desktop App](#webapp--desktop-app)
11. [WordPress.org Distribution](#wordpressorg-distribution)
12. [Design Notes](#design-notes)

---

## 1. What This Repo Produces

This single repository produces **three deliverables**:

| Deliverable | What it is | Where it goes |
|---|---|---|
| **WordPress plugin ZIP** | The installable plugin (`rich-statistics-x.y.z.zip`) | GitHub Release → uploaded to Freemius for premium users; WordPress.org for free users |
| **PWA / companion app** | Installable web app (vanilla JS) served from `docs/app/` | Hosted at `https://app.richstatistics.com/` |
| **Linux desktop app** | Tauri-wrapped `.deb` for amd64 and arm64 | Served from `https://app.richstatistics.com/dist/` |

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
├── src-tauri/            Tauri 2 source for the desktop app — this folder is
│                         packaged by the Tauri CI build into .deb and .exe files.
│                         The frontend dist is docs/app/ (same as PWA).
│
├── vendor/               Composer dependencies
│   ├── freemius/         Freemius SDK — COMMITTED (not .gitignored).
│   │                     Ships inside the plugin ZIP. See §9.
│   └── (everything else) PHPUnit, Brain Monkey, etc. — dev-only, excluded from ZIP.
│
├── docs/                 PWA frontend — NOT shipped in the plugin ZIP
│   ├── app/              Current live PWA served from app server
│   │   ├── index.html    Root-level JS/CSS (live canonical copy)
│   │   ├── v/            Versioned snapshots (copied by CI on each tag)
│   │   └── config-dev.js / config-test.js / index-dev.html / index-test.html
│   └── versions.json     JSON array of all published semver strings
│
├── bin/                  Operational scripts — NOT shipped in the plugin ZIP
│   ├── setup-app-server.sh       Provisions a fresh Debian 12 app server from scratch
│   ├── server-webhook.php        Source for the _deploy/index.php on the app server
│   ├── server-update-webapp.sh   Source for /usr/local/bin/rsa-app-update on server
│   └── install-wp-tests.sh       Sets up the WordPress integration test environment
│
├── tests/                PHPUnit unit + integration tests
├── .github/workflows/    CI/CD: tests.yml, build-develop.yml, build-test.yml, build-release.yml
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
  │         (workflow pending — awaiting WP.org plugin submission approval, see §11)
  │
  └── Freemius dashboard (premium licensing + auto-updates)
        └── Developer manually uploads plugin ZIP after each release

App server: app.richstatistics.com  (104.197.231.120)
  ├── /                  → serves the live PWA (pulled from docs/app/ by webhook)
  ├── /dist/             → serves .deb files + update.json (pushed by CI via SSH)
  └── /_deploy/          → webhook endpoint (PHP, validates X-Deploy-Token header)
```

---

## 5. GitHub Secrets

All three secrets must be set in the GitHub repository settings before a release will fully succeed.

| Secret | Purpose | How to get the value |
|---|---|---|
| `APP_SERVER_SSH_KEY` | ED25519 private key used by CI to SSH into the app server to upload `.deb` files and `update.json` | Generated by `bin/setup-app-server.sh` (printed at end of script), or manually: `ssh-keygen -t ed25519 -C "rich-statistics-ci"` then add public key to server's `~/.ssh/authorized_keys` |
| `DEPLOY_WEBHOOK_TOKEN` | Bearer token in the `X-Deploy-Token` header when CI pings the `/_deploy/` webhook | Generated by `bin/setup-app-server.sh` (printed at end), or `openssl rand -hex 32`; same value stored in `/etc/rsa-webhook-token` on the server |
| `TAURI_SIGNING_PRIVATE_KEY` | Minisign private key used to sign `.deb` files so the desktop app's auto-updater can verify them | Generated once: `tauri signer generate`; the matching public key is embedded in `src-tauri/tauri.conf.json` |

---

## 6. Release Process

Releases follow a strict flow. CI handles everything after the tag is pushed.

```
feature/foo ──PR──→ develop ──push──→ auto-deploy: rs-dev
                        │
                   merge PR
                        ↓
                      test ──push──→ auto-deploy: rs-test
                        │
                   merge PR
                        ↓
                      main ──tag v*──→ build-release.yml → rs-app
```

### Step-by-step

1. **Ensure develop is passing** — `tests.yml` must be green.

2. **Update version numbers** — change `RSA_VERSION` constant and `Version:` header in
   `rich-statistics.php`, and the `Stable tag:` in `readme.txt`.

3. **Update CHANGELOG.md** — move the `[Unreleased]` block to a dated `[x.y.z] — YYYY-MM-DD`
   entry.

4. **Merge to test for QA**
   ```bash
   git checkout test && git merge --no-ff develop && git push origin test
   ```
   CI auto-deploys to `test.richstatistics.com`.

5. **After QA passes, merge to main and tag**
   ```bash
   git checkout main && git merge --no-ff test
   git tag -a v2.x.x -m "Release v2.x.x"
   git push origin main --tags
   ```

6. **CI takes over** (`build-release.yml`):
   - Builds and uploads the plugin ZIP as a GitHub Release artifact.
   - Builds `.deb` files for amd64 and arm64 via Tauri; uploads to app server via SSH.
   - Commits a versioned `docs/app/v/{version}/` snapshot to `main`.
   - Pings the webhook — app server pulls latest `docs/app/` and goes live.

7. **Upload to Freemius** — download the ZIP from the GitHub Release and upload it at
   `https://dashboard.freemius.com → Plugin → Versions → Add New Version`.
   Freemius delivers the update to premium users automatically.

8. **WordPress.org** — run `bash bin/deploy-wporg.sh <svn-username>` after the release.
   Requires screenshots in `wporg-assets/` (see ROADMAP §6 P4.2).

---

## 7. CI / CD Pipeline

All three build workflows share reusable sub-workflows for common tasks.

### Reusable workflows

| Workflow | Purpose |
|----------|---------|
| `job-build-zip.yml` | PHP syntax check, composer install, PHPCS, create plugin ZIP, upload artifact |
| `job-build-desktop.yml` | Tauri build for Linux amd64 + arm64 + Windows, push binaries to server, update APT repo + update.json |

### `tests.yml` — runs on every push/PR to `main`, `develop`, or `test`

| Job | Matrix | What it does |
|---|---|---|
| `unit` | PHP 8.1, 8.2, 8.3 | Unit tests (BrainMonkey, no WP install) |
| `integration` | PHP 8.1/8.2 × WP latest/6.4 | Full integration test suite with MySQL |
| `lint` | PHP 8.2 | PHP syntax check (`php -l` on all files) |
| `phpcs` | PHP 8.2 | WordPress Coding Standards check |

### `build-develop.yml` — push to `develop`

Calls `job-build-zip` (version: `dev.<#run>`) and `job-build-desktop` (pushes to `rs-dev`).

### `build-test.yml` — push to `test`

Calls `job-build-zip` (version: `test.<#run>`) and `job-build-desktop` (pushes to `rs-test`).

### `build-release.yml` — tag `v*.*.*` on `main`

| Job | What it does |
|---|---|
| `build` (inline) | PHP syntax + PHPCS, creates plugin ZIP, GitHub Release, versioned `docs/app/v/{version}/` snapshot, commits to main |
| `build-desktop` (reusable) | Calls `job-build-desktop` with `stamp-version: true`, pushes to production server |
| `ping-deploy` | `POST /_deploy/` → triggers `rsa-app-update` on production server |

### `setup-webhook.yml` — manual `workflow_dispatch`

Bootstraps the `/_deploy/` webhook handler and `rsa-app-update*` script on any environment. One-time per server setup.

---

## 8. App Server Infrastructure

**Server:** Debian 12 (bookworm), Google Cloud, `app.richstatistics.com` (`104.197.231.120`)
**Web server:** Apache 2.4 + PHP 8.2 (`libapache2-mod-php8.2`)
**SSL:** Let's Encrypt via `certbot --apache`, auto-renews via systemd timer
**System user:** `richardkentgates` (also the web-root owner)

### Deploy mechanism

```
CI (ping-deploy job)
  │  POST https://app.richstatistics.com/_deploy/
  │  Header: X-Deploy-Token: <DEPLOY_WEBHOOK_TOKEN>
  ▼
_deploy/index.php  (from bin/server-webhook.php)
  │  Reads token from /etc/rsa-webhook-token (root:www-data 640)
  │  Compares against X-Deploy-Token header
  │  On match: nohup sudo /usr/local/bin/rsa-app-update &
  ▼
/usr/local/bin/rsa-app-update  (from bin/server-update-webapp.sh)
  │  git sparse-clone: fetches only docs/app/ from the latest tag
  │  rsync to /var/www/rs-app/public_html/
  │  Preserves: dist/, _deploy/, versioned dirs
```

**Sudoers rule:** `www-data ALL=(ALL) NOPASSWD: /usr/local/bin/rsa-app-update`
(stored in `/etc/sudoers.d/rsa-app-update`, mode 440)

### Recovery

If the server needs to be rebuilt from scratch:

```bash
git clone https://github.com/richardkentgates/rich-statistics.git
cd rich-statistics
sudo bash bin/setup-app-server.sh \
  --domain app.richstatistics.com \
  --email  your@email.com \
  --user   richardkentgates
```

The script prints the new `DEPLOY_WEBHOOK_TOKEN` and `APP_SERVER_SSH_KEY` values
at the end — update both secrets in the GitHub repository settings.

Full recovery documentation: [docs/wiki/app-server-setup.html](docs/wiki/app-server-setup.html)
(rendered at `https://app.richstatistics.com/wiki/app-server-setup.html`)

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

The PWA lives in `docs/app/` and is served from `https://app.richstatistics.com/`.

`src-tauri/` is the Tauri 2 source folder (wraps `docs/app/` in a native window). 
The CI builds `.deb` files (amd64 + arm64) and `.exe` installer (Windows) and pushes them to the app server via SSH.

- **PWA**: vanilla JS, no build step — edit `docs/app/` directly.
- **Desktop app**: `src-tauri/` contains the Tauri 2 config and Rust glue.
  The CI installs Tauri, runs `tauri build`, and uploads the resulting binaries.
- **Auto-update**: The PWA detects new plugin versions via the `/info` REST endpoint.
  The desktop app uses Tauri's built-in updater (reads `update.json` from `/dist/`).
- **`update.json`** (on the app server):
  ```json
  {
    "version": "2.3.0",
    "pub_date": "2026-05-10T12:00:00Z",
    "notes": "",
    "platforms": {
      "linux-x86_64": {
        "url": "https://app.richstatistics.com/dist/rich-statistics-linux-amd64.deb",
        "signature": ""
      },
      "linux-aarch64": {
        "url": "https://app.richstatistics.com/dist/rich-statistics-linux-arm64.deb",
        "signature": ""
      },
      "windows-x86_64": {
        "url": "https://app.richstatistics.com/dist/rich-statistics-windows.exe",
        "signature": ""
      }
    }
  }
  ```

### Desktop auto-updates

The desktop app checks `https://app.richstatistics.com/dist/update.json`
for new versions. The CI `build-desktop` job writes this file via SSH after
building each `.deb`. Updates are signed with `TAURI_SIGNING_PRIVATE_KEY`;
the matching public key is in `src-tauri/tauri.conf.json`.

### Authentication

The app authenticates to the WordPress REST API using **Application Passwords**
(WP core feature, no extra plugin needed). Users generate an app password in their
WordPress profile and enter it in the app's settings screen.

---

## 11. WordPress.org Distribution

The plugin will be distributed on WordPress.org for the free tier. The standard
deploy mechanism is a GitHub Actions workflow using `10up/action-wordpress-plugin-deploy`.

> **Pending:** This workflow will be added once the plugin's WordPress.org submission
> is approved and SVN access is granted. The plugin has not yet been submitted.

Once approved, this workflow will:
1. Trigger on `v*.*.*` tags (same as `build-release.yml`)
2. Use `.distignore` to exclude dev-only files from the SVN commit
3. Require two repository secrets: `SVN_USERNAME` and `SVN_PASSWORD`

Until submission is approved, releases to WordPress.org must be done manually via
the WP.org plugin dashboard. The `.distignore` file is already in place and correct.

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
