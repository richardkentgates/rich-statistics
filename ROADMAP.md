# Rich Statistics — Roadmap

This document captures audit findings, infrastructure decisions, and the verified status of all environments, CI/CD, and documentation.

---

## 1. Audit Findings (May 2026) — Resolution Status

### A. `desktop/` → `dist/` naming convention

| Ref | Location | Status | Notes |
|-----|----------|--------|-------|
| A1 | Production server `public_html/desktop/` | ✅ Resolved | Renamed to `public_html/dist/` |
| A2 | Dev server root-level `dist/` | ✅ Resolved | Consistent with prod |
| A3 | Test server root-level `dist/` | ✅ Resolved | Consistent with prod |
| A4 | CI `build-release.yml` push paths | ✅ Resolved | Uses `dist/` |
| A5 | `rsa-apt-repo-update` DESKTOP_DIR | ✅ Resolved | Uses `DIST_DIR` |
| A6 | `rsa-update-windows` URL paths | ✅ Resolved | Uses `/dist/` |
| A7 | Test `update.json` URLs | ✅ Resolved | Points to `rs-test.richardkentgates.com/dist/` |

### B. Branch-based endpoints (dev/test/prod)

| Ref | Issue | Status | Notes |
|-----|-------|--------|-------|
| B1 | CI `deploy-web-dev` hit prod webhook | ✅ Resolved | Now hits `rs-dev.richardkentgates.com/_deploy/` with `DEPLOY_WEBHOOK_TOKEN_DEV` |
| B2 | CI `deploy-web-test` hit prod webhook | ✅ Resolved | Now hits `rs-test.richardkentgates.com/_deploy/` with `DEPLOY_WEBHOOK_TOKEN_TEST` |
| B3 | `rsa-app-update-dev` cloned `main` | ✅ Resolved | Now clones `develop` branch |
| B4 | `rsa-app-update-test` cloned `main` | ✅ Resolved | Now clones `test` branch |
| B5 | Test SSL vhost wrong `ServerName` | ✅ Resolved | Corrected to `rs-test.richardkentgates.com` |
| B6 | No SSL cert for test | ✅ Resolved | LetsEncrypt cert obtained for `rs-test.richardkentgates.com` |
| B7 | Test vhost missing `/_deploy/` Alias | ✅ Resolved | Added to both HTTP and SSL vhosts |
| B8 | Dev `dist/` empty | ✅ Resolved | Desktop binaries now pushed by CI |
| B9 | `RSA_APP_URL` hardcoded to production | ❌ **Open** | Both `main` and `develop` still point to `rs-app.richardkentgates.com` |
| B10 | `config.js` has no `env` flag | ❌ **Open** | No environment awareness when served standalone |
| B11 | CI `build-desktop-dev` artifacts only | ✅ Resolved | Now pushes binaries to dev server |

### C. Documentation / gating corrections

| Ref | File | Status | Notes |
|-----|------|--------|-------|
| C1 | `class-rest-api.php:3` docblock | ✅ Resolved | `[PREMIUM]` removed |
| C2 | `class-rest-api.php:10` `@fs_premium_only` | ✅ Resolved | Removed |
| C3 | `class-rest-api.php:13` `manage_options` | ✅ Resolved | Changed to `rsa_manage_statistics` |
| C4 | AGENTS.md | ✅ Resolved | Updated with branch/server info |

---

## 2. Version Compatibility: Plugin ↔ PWA ↔ Desktop (COMPLETED)

All four phases are implemented:

| Phase | Description | Status |
|-------|-------------|--------|
| **Phase 1** | Namespace version snapshots under `/v/` prefix; update Apache rules, app.js paths, CI snapshot creation, server symlinks | ✅ Done |
| **Phase 2** | Add `app_version` + `min_app_version` fields to `GET /wp-json/rsa/v1/info` | ✅ Done |
| **Phase 3** | Plugin injects `RSA_CONFIG.appVersion` + `RSA_CONFIG.minAppVersion` into admin shell | ✅ Done |
| **Phase 4** | Prune old version snapshots from Tauri bundles (keep latest 3) | ✅ Done |

---

## 3. Infrastructure Status

### All three environments are operational

| Resource | Production | Dev | Test |
|----------|-----------|-----|------|
| Subdomain | `rs-app.richardkentgates.com` | `rs-dev.richardkentgates.com` | `rs-test.richardkentgates.com` |
| Server path | `/var/www/rs-app/public_html/` | `/var/www/rs-app-dev/` | `/var/www/rs-app-test/` |
| SSL | ✅ Valid LetsEncrypt | ✅ Valid LetsEncrypt | ✅ Valid LetsEncrypt |
| PWA web app | ✅ Served | ✅ Served | ✅ Served |
| `/_deploy/` webhook | ✅ Present | ✅ Present | ✅ Present |
| Desktop binaries in `dist/` | ✅ Present | ✅ Present | ✅ Present |
| APT repository | ✅ Present | ❌ **Missing** | ✅ Present |
| vhost `/apt/` alias | ✅ Present | ❌ **Missing** | ✅ Present |
| `dist/update.json` | ✅ Present (version 2.2.7) | ❌ **Missing** | ❌ **Stale** (shows 2.1.0) |
| `v/` version snapshots | ✅ Complete (2.0.0–2.2.7) | ❌ **Incomplete** (missing 2.1.2+) | ❌ **Outdated** (pre-2.0 relics, missing 2.1.1+) |
| Git branch (updater) | `main` | `develop` | `test` |
| Desktop CI pushes | ✅ `build-release.yml` | ✅ `build-develop.yml` | ✅ `build-test.yml` |

### Open infrastructure issues

| Priority | Issue | Detail |
|----------|-------|--------|
| P1 | Dev APT repo missing | No `/var/www/rs-app-dev/apt/` directory, no vhost `/apt/` alias, no `rsa-apt-repo-update-dev` script |
| P1 | Dev `dist/update.json` missing | Desktop binaries are pushed but no Tauri updater metadata file exists at `/var/www/rs-app-dev/dist/update.json` |
| P2 | Test `dist/update.json` stale | Shows version `2.1.0` but binaries on disk are `2.2.7` |
| P2 | Dev/test `v/` version directories incomplete | Dev missing 2.1.2–2.2.7; test has pre-2.0 artifacts and missing 2.1.1–2.2.7 |
| P3 | `RSA_APP_URL` hardcoded to production | Plugin points PWA link to prod on all environments |

---

## 4. CI/CD Pipeline Status

| Ref | Workflow | Job | Status | Notes |
|-----|----------|-----|--------|-------|
| CI1 | `build-develop.yml` | `deploy-web` | ✅ Resolved | Sends to `rs-dev.richardkentgates.com/_deploy/` with `DEPLOY_WEBHOOK_TOKEN_DEV` |
| CI2 | `build-test.yml` | `deploy-web` | ✅ Resolved | Sends to `rs-test.richardkentgates.com/_deploy/` with `DEPLOY_WEBHOOK_TOKEN_TEST` |
| CI3 | `build-develop.yml` | `build-desktop` | ✅ Resolved | Pushes Linux + Windows (signed) binaries + `.sig` to dev server `dist/`, regenerates `update.json` |
| CI4 | `build-release.yml` | `build-desktop-linux` | ✅ Resolved | Pushes signed `.deb` + `.sig` to `public_html/dist/`, updates APT repo |
| CI5 | `build-release.yml` | `ping-deploy` | ✅ Resolved | Deterministic webhook call to production `/_deploy/` |
| — | `build-release.yml` | `build-desktop-windows` | ✅ Resolved | Pushes signed `.exe` + `.sig` to `public_html/dist/`, regenerates `update.json` |
| — | `build-test.yml` | `build-desktop` | ✅ Done | Pushes signed binaries + `.sig` to test server `dist/`, regenerates `update.json` |
| — | `build-release.yml` | Prune old snapshots | ✅ Done | Keeps latest 3 versioned PWA snapshots in `docs/app/` |

### CI gaps

| Gap | Detail |
|-----|--------|
| PHPCS in CI | Coding standards not checked automatically on push |
| E2E tests | No browser-based testing for PWA or admin interface |
| Upgrade/migration tests | DB migrations between versions not tested in CI |

---

## 5. Post-Audit Findings (May 2026 — Verified)

These are discrepancies discovered during verification of the initial audit fixes:

| Ref | Finding | Environment | Detail |
|-----|---------|-------------|--------|
| F1 | **Dev APT repo missing entirely** | Dev | No `/var/www/rs-app-dev/apt/` directory, no vhost alias, no `rsa-apt-repo-update-dev` script. Desktop binaries pushed but no APT distribution |
| F2 | **Dev `dist/update.json` missing** | Dev | Desktop `.deb` and `.exe` binaries exist but no `update.json` for Tauri updater — desktop apps won't auto-update |
| F3 | **Test `update.json` version stale** | Test | Shows `"version": "2.1.0"` but latest binaries on disk are `2.2.7`. CI pushes binaries but doesn't update the version metadata |
| F4 | **Dev `v/` version dirs incomplete** | Dev | Only has 2.0.0–2.1.1. Missing 2.1.2 through 2.2.7. Will cause version mismatch errors for users running newer plugin versions |
| F5 | **Test `v/` version dirs outdated** | Test | Has pre-2.0 versions (1.3.0–1.4.8) that shouldn't be there. Missing 2.1.1 through 2.2.7 |
| F6 | **`RSA_APP_URL` hardcoded** | All | Plugin always points "Open App" button to production `rs-app.richardkentgates.com` regardless of environment |

---

## 6. Remaining Work (Prioritized)

### P0: Fix infrastructure gaps

1. **Create dev APT repo**: Set up `/var/www/rs-app-dev/apt/` directory structure, add vhost `/apt/` alias, create `rsa-apt-repo-update-dev` script, add APT update step to dev CI
2. **Create dev `dist/update.json`**: Generate Tauri updater metadata for dev desktop binaries
3. **Fix test `dist/update.json` version**: Update to reflect actual deployed version

### P1: Populate version directories

1. **Sync dev/test `v/` directories**: Copy version snapshots from prod to dev and test so all environments have the complete set (2.0.0–2.2.7)

### P2: Environment-aware plugin

1. **Make `RSA_APP_URL` configurable**: Plugin should detect environment and use the correct PWA URL
2. **Add `env` flag to `config.js`**: Deploy environment-specific config on dev/test subdomains

### P3: CI / Quality

1. Add PHPCS check to CI workflows
2. Add E2E test pipeline
3. Add upgrade/migration test coverage

### P4: WordPress.org

1. Create `readme.txt` and plugin assets
2. SVN submission to WordPress.org plugin directory

### P5: Monitoring / Operations

1. Uptime monitoring for all three subdomains
2. Error tracking for production
3. Documented rollback procedure
4. Database backup strategy

---

## 7. Documentation Plan

| Ref | File | Task | Status |
|-----|------|------|--------|
| D1 | `includes/class-rest-api.php:3` | Change `[PREMIUM] REST API` to `REST API` | ✅ Done |
| D2 | `includes/class-rest-api.php:10` | Remove `@fs_premium_only` | ✅ Done |
| D3 | `includes/class-rest-api.php:13` | Update `manage_options` to `rsa_manage_statistics` | ✅ Done |
| D4 | AGENTS.md | Add reference to ROADMAP.md; update External Services section with all 3 subdomains | ✅ Done |
| D5 | README.md | Add Release Tracks table, dev/test install instructions, server endpoint tables | ✅ Done |
| D6 | CONTRIBUTING.md | Add Branch Structure section, release process, environment endpoints | ✅ Done |
| D7 | GitHub Wiki | Create with dev/test installation documentation | ❌ **Open** |
