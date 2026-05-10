# Rich Statistics — Roadmap

This document captures the audit findings, infrastructure roadmap, and planned improvements based on a comprehensive audit of the server, CI/CD pipeline, and codebase.

---

## 1. Audit Findings (May 2026)

### A. `desktop/` naming convention

The production server uses `/var/www/rs-app/public_html/desktop/` for downloadable binaries (`.deb`, `.exe`, `update.json`). The dev and test environments use `/dist/` instead. The `desktop/` name is a consumer-centric convention inappropriate for a Linux server.

| Ref | Location | Current | Required |
|-----|----------|---------|----------|
| A1 | Production server | `public_html/desktop/` | `dist/` (or agreed standard) |
| A2 | Dev server | root-level `dist/` | Align with prod standard |
| A3 | Test server | root-level `dist/` | Align with prod standard |
| A4 | CI `build-release.yml` | References `public_html/desktop/` | Update path |
| A5 | `rsa-apt-repo-update` | References `DESKTOP_DIR` | Update path |
| A6 | `rsa-update-windows` | References `/desktop/` in URLs | Update path |
| A7 | Test `dist/update.json` | URLs point to `rs-app.richardkentgates.com/desktop/` | Fix host + path |

### B. Branch-based endpoints (dev/test/prod)

All three subdomains resolve to `<PWA_SERVER_IP>`:
- `rs-app.richardkentgates.com` → `/var/www/rs-app/public_html/` (prod, SSL ✅)
- `rs-dev.richardkentgates.com` → `/var/www/rs-app-dev/` (dev, SSL ✅)
- `rs-test.richardkentgates.com` → `/var/www/rs-app-test/` (test, SSL ❌)

Apache configs exist for all three with LetsEncrypt SSL for prod and dev. Test SSL vhost is broken (see B5).

| Ref | Issue | Detail |
|-----|-------|--------|
| B1 | CI `deploy-web-dev` hits production webhook | Hits `rs-app.richardkentgates.com/_deploy/` — not the dev endpoint |
| B2 | CI `deploy-web-test` hits production webhook | Same, uses `DEPLOY_WEBHOOK_TOKEN` instead of `DEPLOY_WEBHOOK_TOKEN_TEST` |
| B3 | `rsa-app-update-dev` clones `main` branch | Should clone `develop` to deploy dev branch code |
| B4 | `rsa-app-update-test` clones `main` branch | Should clone the branch under test |
| B5 | Test SSL vhost has wrong `ServerName` | `rs-app-test-le-ssl.conf` has `ServerName rs-dev.richardkentgates.com` — should be `rs-test.richardkentgates.com` |
| B6 | No SSL cert for `rs-test.richardkentgates.com` | `/etc/letsencrypt/live/rs-test.richardkentgates.com/` doesn't exist. Test SSL vhost borrows dev's cert |
| B7 | Test Apache configs have no Alias for `/_deploy/` | Webhook PHP scripts exist at `/var/www/rs-app-test/_deploy*/` but are not routable via HTTP |
| B8 | Dev `dist/` is empty | No desktop binaries deployed to dev environment |
| B9 | `RSA_APP_URL` hardcoded to production | Both `main` and `develop` branches point to `rs-app.richardkentgates.com` |
| B10 | `config.js` identical on all servers | No `env` flag when served standalone (outside WordPress plugin context) |
| B11 | CI `build-desktop-dev` only uploads artifacts | Never pushes binaries to the dev server |

### C. Documentation / gating corrections

| Ref | File | Current text | Fix |
|-----|------|-------------|-----|
| C1 | `class-rest-api.php:3` | `[PREMIUM] REST API` | `REST API` — serves both free and premium features |
| C2 | `class-rest-api.php:10` | `@fs_premium_only` | Remove — class is not premium-only; endpoints are individually gated |
| C3 | `class-rest-api.php:13` | `All read endpoints require 'manage_options'` | `rsa_manage_statistics` capability |
| C4 | AGENTS.md | REST API section accurate | No action needed |

---

## 2. Version Compatibility: Plugin ↔ PWA ↔ Desktop

### Current Design

The PWA and desktop app must remain compatible with the WordPress plugin version they connect to. Since users update the plugin and app independently, version drift causes breakage.

**Current mechanism:**
- Version snapshot directories (e.g., `docs/app/2.2.7/`, `docs/app/2.0.0/`) contain frozen copies of the PWA for each plugin version.
- These are deployed to the server at root-level URLs: `https://rs-app.richardkentgates.com/2.2.7/`.
- The PWA (`checkPluginVersion()` in `app.js`) fetches `/wp-json/rsa/v1/info` to get the plugin version.
- In the browser, it clears SW caches and reloads when the plugin version changes.
- In Tauri, it navigates to the matching versioned folder (`/<version>/`), falling back to the latest bundled version.
- `versions.json` lists all available snapshots.

**Problems:**
1. 21+ version directories at the web root pollute the DocumentRoot.
2. No URL prefix namespace (`/2.2.7/` instead of `/v/2.2.7/`).
3. No semver range compatibility — exact match only, which breaks when patch versions differ.
4. No API-side version negotiation — the plugin doesn't declare which PWA version it needs.
5. No standardized compatibility metadata between plugin, PWA, and desktop app.

### Proposed Standardized Solution

**Phase 1: Namespace version snapshots**

Move version directories under a `/v/` prefix:
- `https://rs-app.richardkentgates.com/v/2.2.7/` instead of `https://rs-app.richardkentgates.com/2.2.7/`
- Update Apache `LocationMatch` cache rules for the new prefix.
- Update `getVersionedAppBase()` regex in `app.js`.
- Update `tauriNavigateToVersion()` in `app.js`.
- Add a 301 redirect from old paths for backwards compatibility.

**Phase 2: Semver compatibility API**

Add `app_version` field to the `/info` endpoint:
```json
{
  "version": "2.2.7",
  "app_version": "2.2.0",
  "min_app_version": "2.1.0",
  "app_url": "https://rs-app.richardkentgates.com/"
}
```

This lets the PWA resolve compatibility without exact version matching:
- `app_version` = the recommended PWA version for this plugin.
- `min_app_version` = the minimum PWA version this plugin works with.
- The PWA compares against available snapshots and picks the best match.

**Phase 3: Plugin-declared compatibility**

The plugin sets `RSA_CONFIG.appVersion` and `RSA_CONFIG.minAppVersion` in its injected config script (`class-admin.php:90-97`), matching the API response. This lets the PWA know the required version range before it even makes its first API call.

**Phase 4: Tauri bundle pruning**

Bundle only the latest N snapshots (e.g., latest 3 minor versions) in the desktop `.deb` to reduce bundle size. The Tauri app uses the compatibility API to determine if an update is needed rather than checking against all bundled versions.

---

## 3. Infrastructure Roadmap

### Priority: Fix the broken test environment

1. **Obtain SSL cert for `rs-test.richardkentgates.com`** via LetsEncrypt (`certbot --apache -d rs-test.richardkentgates.com`).
2. **Fix `rs-app-test-le-ssl.conf`**: Correct `ServerName` from `rs-dev.richardkentgates.com` to `rs-test.richardkentgates.com`.
3. **Add Apache Alias for `/_deploy/`** in test vhost configs to expose the webhook scripts.
4. **Add `Alias /apt/`** in test vhost configs (test APT repo exists but isn't served).

### Priority: Standardize directory naming

1. Rename `public_html/desktop/` to `public_html/dist/` on production server.
2. Update `rsa-apt-repo-update` to use `DIST_DIR` instead of `DESKTOP_DIR`.
3. Update `rsa-update-windows` paths and URLs.
4. Update CI `build-release.yml` push paths.
5. Update test `dist/update.json` URLs to point to the correct server.

### Priority: Route CI deployments to correct environments

1. CI `deploy-web-dev`: Send to `https://rs-dev.richardkentgates.com/_deploy/`.
2. CI `deploy-web-test`: Send to `https://rs-test.richardkentgates.com/_deploy/`.
3. CI `deploy-web-prod`: Keep at `https://rs-app.richardkentgates.com/_deploy/`.
4. Use separate GitHub secrets (`DEPLOY_WEBHOOK_TOKEN_DEV`, `_TEST`, `_PROD`).
5. `rsa-app-update-dev`: Clone `develop` branch, not `main`.
6. `build-desktop-dev`: Push binaries to dev server's `dist/`.

### Later: Environment-aware plugin

1. Make `RSA_APP_URL` configurable per environment (dev/test/prod).
2. `config.js` on dev/test servers should set `env` flag for standalone PWA usage.
3. Plugin injects `appUrl` into `RSA_CONFIG` based on environment detection.

---

## 4. CI/CD Pipeline Roadmap

| Ref | Workflow | Job | Current behavior | Target behavior |
|-----|----------|-----|-----------------|-----------------|
| CI1 | `build-dev.yml` | `deploy-web-dev` | Hits prod webhook, token `DEPLOY_WEBHOOK_TOKEN` | Hit `rs-dev.richardkentgates.com/_deploy/`, token `DEPLOY_WEBHOOK_TOKEN_DEV` |
| CI2 | `build-dev.yml` | `deploy-web-test` | Hits prod webhook, token `DEPLOY_WEBHOOK_TOKEN` | Hit `rs-test.richardkentgates.com/_deploy/`, token `DEPLOY_WEBHOOK_TOKEN_TEST` |
| CI3 | `build-dev.yml` | `build-desktop-dev` | Uploads to GH artifacts only | Push binaries to dev server |
| CI4 | `build-release.yml` | `build-desktop-linux` | Pushes to `public_html/desktop/` | Push to `public_html/dist/` |
| CI5 | `build-release.yml` | `ping-deploy` | Hits `rs-app.richardkentgates.com/_deploy/` | No change (prod is correct) |

---

## 5. Documentation Plan

| Ref | File | Task |
|-----|------|------|
| D1 | `includes/class-rest-api.php:3` | Change `[PREMIUM] REST API` to `REST API` |
| D2 | `includes/class-rest-api.php:10` | Remove `@fs_premium_only` |
| D3 | `includes/class-rest-api.php:13` | Update `manage_options` to `rsa_manage_statistics` |
| D4 | `AGENTS.md` | Add reference to ROADMAP.md; update External Services section with all 3 subdomains |
