# Rich Statistics — Roadmap

This document captures audit findings, infrastructure decisions, and the verified status of all environments, CI/CD, and documentation.

---

## 1. Audit Findings (May 2026 — Initial) — Resolution Status

### A. `desktop/` → `dist/` naming convention

| Ref | Location | Status | Notes |
|-----|----------|--------|-------|
| A1 | Production server `public_html/desktop/` | ✅ Resolved | Renamed to `public_html/dist/` |
| A2 | Dev server root-level `dist/` | ✅ Resolved | Consistent with prod |
| A3 | Test server root-level `dist/` | ✅ Resolved | Consistent with prod |
| A4 | CI `build-release.yml` push paths | ✅ Resolved | Uses `dist/` |
| A5 | `rsa-apt-repo-update` DESKTOP_DIR | ✅ Resolved | Uses `DIST_DIR` |
| A6 | `rsa-update-windows` URL paths | ✅ Resolved | Uses `/dist/` |
| A7 | Test `update.json` URLs | ✅ Resolved | Points to `test.richstatistics.com/dist/` |

### B. Branch-based endpoints (dev/test/prod)

| Ref | Issue | Status | Notes |
|-----|-------|--------|-------|
| B1 | CI `deploy-web-dev` hit prod webhook | ✅ Resolved | Now hits `dev.richstatistics.com/_deploy/` with `DEPLOY_WEBHOOK_TOKEN_DEV` |
| B2 | CI `deploy-web-test` hit prod webhook | ✅ Resolved | Now hits `test.richstatistics.com/_deploy/` with `DEPLOY_WEBHOOK_TOKEN_TEST` |
| B3 | `rsa-app-update-dev` cloned `main` | ✅ Resolved | Now clones `develop` branch |
| B4 | `rsa-app-update-test` cloned `main` | ✅ Resolved | Now clones `test` branch |
| B5 | Test SSL vhost wrong `ServerName` | ✅ Resolved | Corrected to `test.richstatistics.com` |
| B6 | No SSL cert for test | ✅ Resolved | LetsEncrypt cert obtained for `test.richstatistics.com` |
| B7 | Test vhost missing `/_deploy/` Alias | ✅ Resolved | Added to both HTTP and SSL vhosts |
| B8 | Dev `dist/` empty | ✅ Resolved | Desktop binaries now pushed by CI |
| B9 | `RSA_APP_URL` hardcoded to production | ✅ Resolved | Dynamic via `rsa_detect_app_url()` — see commit df82c7c |
| B10 | `config.js` has no `env` flag | ✅ Resolved | `config.js` auto-detects from hostname; `config-dev.js`, `config-test.js`, `index-dev.html` available |
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
| Subdomain | `app.richstatistics.com` | `dev.richstatistics.com` | `test.richstatistics.com` |
| Server path | `/var/www/rs-app/public_html/` | `/var/www/rs-app-dev/` | `/var/www/rs-app-test/` |
| SSL | ✅ Valid LetsEncrypt | ✅ Valid LetsEncrypt | ✅ Valid LetsEncrypt |
| PWA web app | ✅ Served | ✅ Served | ✅ Served |
| `/_deploy/` webhook | ✅ Present | ✅ Present | ✅ Present |
| Desktop binaries in `dist/` | ✅ Present | ✅ Present | ✅ Present |
| Web root ownership | `richardkentgates:www-data` | `richardkentgates:www-data` | `richardkentgates:www-data` |
| APT repository | ✅ Present | ✅ Present | ✅ Present |
| vhost `/apt/` alias | ✅ Present | ✅ Present (SSL only) | ✅ Present |
| `dist/update.json` | ✅ Present (v2.3.0, sig: populated by CI) | ✅ Present (v2.3.0, sig: populated by CI) | ✅ Present (v2.3.0, sig: populated by CI) |
| `v/` version snapshots | ✅ Complete (2.3.0–2.4.1) | ✅ Complete (2.3.0–2.4.1) | ✅ Complete (2.3.0–2.4.1) |
| `versions.json` | ✅ Complete (3 entries) | ✅ Complete (3 entries) | ✅ Complete (3 entries) |
| Old root-level version dirs | ✅ Clean | ✅ Clean | ✅ Clean |
| Git branch (updater) | `main` | `develop` | `test` |
| Desktop CI pushes | ✅ `build-release.yml` | ✅ `build-develop.yml` | ✅ `build-test.yml` |

---

## 4. CI/CD Pipeline Status

| Ref | Workflow | Job | Status | Notes |
|-----|----------|-----|--------|-------|
| CI1 | `build-develop.yml` | `deploy-web` | ✅ Resolved | Sends to `dev.richstatistics.com/_deploy/` with `DEPLOY_WEBHOOK_TOKEN_DEV` |
| CI2 | `build-test.yml` | `deploy-web` | ✅ Resolved | Sends to `test.richstatistics.com/_deploy/` with `DEPLOY_WEBHOOK_TOKEN_TEST` |
| CI3 | `build-develop.yml` | `build-desktop` | ✅ Resolved | Pushes Linux + Windows (signed) binaries + `.sig` to dev server `dist/`, regenerates `update.json` |
| CI4 | `build-release.yml` | `build-desktop-linux` | ✅ Resolved | Pushes signed `.deb` + `.sig` to `public_html/dist/`, updates APT repo |
| CI5 | `build-release.yml` | `ping-deploy` | ✅ Resolved | Deterministic webhook call to production `/_deploy/` |
| — | `build-release.yml` | `build-desktop-windows` | ✅ Resolved | Pushes signed `.exe` + `.sig` to `public_html/dist/`, regenerates `update.json` |
| — | `build-test.yml` | `build-desktop` | ✅ Done | Pushes signed binaries + `.sig` to test server `dist/`, regenerates `update.json` |
| — | `build-release.yml` | Prune old snapshots | ✅ Done | Keeps latest 12 versioned PWA snapshots in `docs/app/` |

---

## 5. Post-Audit Findings (May 2026 — Verified)

| Ref | Finding | Environment | Detail |
|-----|---------|-------------|--------|
| F1 | **Dev APT repo claimed missing but present** | Dev | ROADMAP said missing but actually exists ✅ |
| F2 | **Dev `update.json` claimed missing but present** | Dev | ROADMAP said missing but exists with v2.2.7 ✅ |
| F3 | **Test `update.json` claimed stale but current** | Test | ROADMAP said v2.1.0 but actual was v2.2.7 ✅ |
| F4 | **Dev `v/` dirs claimed incomplete but complete** | Dev | ROADMAP said missing 2.1.2+ but all 19 versions present ✅ |
| F5 | **Test `v/` dirs had pre-2.0 relics** | Test | Old 1.3.0–1.4.8 root-level dirs cleaned ✅ |
| F6 | **`RSA_APP_URL` hardcoded** | All | Plugin always points "Open App" button to production regardless of environment |
| F7 | **Web root ownership mismatch** | All | Fixed to `richardkentgates:www-data` ✅ |
| F8 | **Prod `_deploy/` at wrong path** | Prod | Vhost alias correct. Ownership fixed ✅ |

---

## 6. Comprehensive Platform Audit (May 2026)

Full audit completed across 8 areas. See `TODO.md` for the complete action item list.

### Summary

| Area | Status | Critical | High | Medium | Low |
|------|--------|----------|------|--------|-----|
| Plugin Code | ✅ Good | 0 | 0 | 2 | 5 |
| CI/CD | ⚠️ Needs work | 2 | 4 | 5 | 5 |
| Server Infra | ⚠️ Needs work | 2 | 3 | 3 | 2 |
| PWA | ⚠️ Needs work | 1 | 3 | 1 | 3 |
| Desktop App | ⚠️ Needs work | 0 | 2 | 0 | 2 |
| Documentation | ❌ Poor | 2 | 9 | 7 | 7 |
| Database | ✅ Good | 0 | 2 | 3 | 5 |
| Tests | ⚠️ Needs work | 0 | 5 | 8 | 7 |
| **TOTAL** | | **7** | **28** | **29** | **36** |

### Phase 1: Critical (ship with next release)
| Ref | Area | Finding | Status |
|-----|------|---------|--------|
| C1 | PWA | `sw-init.js` missing from all 3 versioned snapshots | ⬜ Not started |
| C2 | Server | `bin/setup-apt-repo.sh` referenced but does not exist | ⬜ Not started |
| C3 | CI/CD | APT repo update scripts never deployed by CI | ⬜ Not started |
| C4 | Docs | CHANGELOG.md missing 21 of 42 git tags | ⬜ Not started |
| C5 | Server | `gen-update-json.py` uses wrong platform key (`linux-arm64` vs `linux-aarch64`) | ⬜ Not started |
| C6 | Server | `gen-update-json.py` has hardcoded stale `pub_date` | ⬜ Not started |
| C7 | PWA | All `sw.js` files have cache name `rsa-1-5-2` (stale since v1.5.2) | ⬜ Not started |

### Phase 2: High Priority
| Ref | Area | Finding | Status |
|-----|------|---------|--------|
| H1 | CI/CD | Hardcoded server IP `<PWA_SERVER_IP>` in 8+ places | ⬜ Not started |
| H2 | CI/CD | Hardcoded SSH username `<SSH_USER>@` in all deploy steps | ⬜ Not started |
| H3 | CI/CD | `ssh-keyscan` without fingerprint verification | ⬜ Not started |
| H4 | CI/CD | `setup-webhook.yml` uses `StrictHostKeyChecking=no` | ⬜ Not started |
| H5 | CI/CD | `build-release.yml` has dead `setup_webhook` input | ⬜ Not started |
| H6 | PWA | `RSA_APP_VERSION` (2.4.0) ≠ `RSA_VERSION` (2.4.1) | ⬜ Not started |
| H7 | PWA | `src-tauri/tauri.conf.json` version (2.4.0) ≠ plugin (2.4.1) | ⬜ Not started |
| H8 | PWA | `src-tauri/Cargo.toml` version (2.4.0) ≠ plugin (2.4.1) | ⬜ Not started |
| H9 | PWA | `connect-src *` in PWA CSP is overly permissive | ⬜ Not started |
| H10 | DB | `SCHEMA_VERSION` never checked — no migration framework | ⬜ Not started |
| H11 | DB | `aggregate_heatmap()` uses `DATE(created_at)` — prevents index usage | ⬜ Not started |
| H12–H16 | Tests | 5 major code paths with zero test coverage | ⬜ Not started |
| H17–H23 | Docs | 7 documentation contradictions and phantom references | ⬜ Not started |
| H24–H28 | Server/Tests | Webhook error handling, secret exposure, stale bootstrap version | ⬜ Not started |

### Phase 3: Medium Priority (29 items)
See `TODO.md` §3 for full list.

### Phase 4: Low Priority (36 items)
See `TODO.md` §4 for full list.

---

## 7. Remaining Work (Legacy — superseded by §6)

### P1: Environment-aware plugin ✅
1. **Make `RSA_APP_URL` configurable** — ✅ `rsa_detect_app_url()` in `rich-statistics.php`
2. **Add `env` flag to `config.js`** — ✅ `config.js` auto-detects from hostname

### P2: CI / Quality
1. Add PHPCS check to CI workflows — ✅ Added to all 4 workflows
2. Add E2E test pipeline — ⬜ Not started
3. Add upgrade/migration test coverage — ✅ 9 migration tests in DbTest.php + 10 env detection tests

### P3: Signatures ✅
1. **Run CI build to generate signed `update.json`** — Auto-resolved on next tag push

### P4: WordPress.org
1. Create `readme.txt` and plugin assets — ✅ `readme.txt` with full 2.x changelog
2. SVN submission — ⏳ `bin/deploy-wporg.sh` ready; requires screenshots in `wporg-assets/`

### P5: Monitoring / Operations
1. Uptime monitoring — ✅ Handled by external system
2. Error tracking — 📝 Documented in §8.2
3. Rollback procedure — ✅ Documented in §8.3
4. Database backup strategy — ✅ Documented in §8.4

### Documentation Plan (Legacy)
| Ref | File | Task | Status |
|-----|------|------|--------|
| D1 | `class-rest-api.php:3` | Change `[PREMIUM] REST API` to `REST API` | ✅ Done |
| D2 | `class-rest-api.php:10` | Remove `@fs_premium_only` | ✅ Done |
| D3 | `class-rest-api.php:13` | Update `manage_options` to `rsa_manage_statistics` | ✅ Done |
| D4 | AGENTS.md | Add reference to ROADMAP.md | ✅ Done |
| D5 | README.md | Add Release Tracks table, dev/test install instructions | ✅ Done |
| D6 | CONTRIBUTING.md | Add Branch Structure section | ✅ Done |
| D7 | GitHub Wiki | Create with dev/test installation documentation | ✅ Done |

---

## 8. Operations Guide

---

## 9. Beta Channel & Freemius Integration Audit (May 2026)

### 9.1 Multi-Layer Architecture

The beta channel flows through 5 software layers:

```
WordPress Settings (checkbox rsa_beta_channel)
  → wp_option 'rsa_beta_channel' (0|1)
    → /info endpoint: { channel: 'beta'|'stable' }
      → PWA app.js: state.channel = info.channel
        → Tauri: tauriNavigateToVersion(version, channel)
          → navigates to /v/{version}/{channel}/index.html
            → reads versions-beta.json or versions.json
```

- **Freemius sync**: `class-admin.php:958-969` calls `PUT /plugin-tags/beta-mode.json` on save — tells Freemius to serve beta plugin updates
- **CI snapshot creation**: `build-release.yml` creates both `stable/` and `beta/` subdirs per version
- **CI `versions.json` + `versions-beta.json`**: Both generated with identical version lists (channel differentiation is purely path-based)

### 9.2 Server Reality (Verified via SSH 2026-05-16)

| Env | Server | Web Root | Last Deployed | versions.json | versions-beta.json |
|-----|--------|----------|---------------|---------------|-------------------|
| **Production** | `104.197.231.120` | `/var/www/rs-app/public_html/` | v2.4.16 (`main`) | ✅ 6 entries | ✅ Present |
| **Dev** | `104.197.231.120` | `/var/www/rs-app-dev/` | v2.4.1 (`develop`) | ✅ 4 entries | ❌ **Missing** |
| **Test (PWA)** | `104.197.231.120` | `/var/www/rs-app-test/` | v2.4.1 (`test`) | ✅ 4 entries | ❌ **Missing** |
| **Test (Plugin)** | `34.56.56.233` | `/srv/www/wordpress` | WordPress integration tests | N/A | N/A |

All 3 PWA environments run on the same server (`104.197.231.120`), sharing the same wildcard SSL cert.

### 9.3 Snapshot Format Analysis

**Three different formats exist across the codebase:**

| Method | Location | Format Created | Status |
|--------|----------|---------------|--------|
| `build.sh` | Local dev build script | `v/{version}/<files>` (flat) | ❌ Stale |
| `build-release.yml` | CI release workflow | `v/{version}/{stable,beta}/<files>` | ✅ Active |
| `job-build-desktop.yml` | CI desktop build | `v/{version}/{stable,beta}/<files>` | ✅ Active |

**On production server (39 version directories):**
- 38 versions are **flat** (`v/2.4.1/app.js` — old format)
- Only `v/2.4.16/` has `stable/` + `beta/` subdirectories (first CI-built version)
- **This is a critical compatibility break**: the root `app.js` navigates to `/v/{version}/{channel}/index.html` — all versions before 2.4.16 will 404 in new desktop builds

### 9.4 Gaps Discovered During Audit

| # | Severity | Gap | Layer | Impact |
|---|----------|-----|-------|--------|
| BC-1 | **CRITICAL** | Snapshot format mismatch: old flat vs new channel subdirs | Snapshots | New Tauri builds 404 on all versions before 2.4.16 |
| BC-2 | **CRITICAL** | `versions-beta.json` missing from dev/test environments | Server | Beta routing unavailable on dev/test.richstatistics.com |
| BC-3 | **HIGH** | Beta tag in `promote.yml` hardcoded to `.beta.1` — no increment | CI/CD | 2nd beta release for same version fails (tag collision) |
| BC-4 | **HIGH** | `tauriNavigateToVersion` fallback hardcoded to `/stable/` | PWA | Beta users always redirected to stable on fallback |
| BC-5 | **HIGH** | `build.sh` creates flat snapshots — doesn't match CI format | Dev tooling | Local builds produce different structure than releases |
| BC-6 | **HIGH** | Apache vhost has no `LocationMatch` for immutable caching | Server | All PWA assets served without immutable Cache-Control |
| BC-7 | **HIGH** | `update.json` signatures are empty strings | Server | Tauri auto-updater won't validate binary signatures |
| BC-8 | **MEDIUM** | Server accumulates 39+ snapshots — CI only keeps 12 | Server | No server-side pruning; grows unbounded |
| BC-9 | **MEDIUM** | No snapshot for `2.4.2` or `2.4.3` in repo | Repo | `RSA_VERSION=2.4.3` but last snapshot is 2.4.1 |
| BC-10 | **MEDIUM** | Version constants drift: plugin header=2.4.1, RSA_VERSION=2.4.3 | Repo | Confusion; update checks may be inconsistent |
| BC-11 | **LOW** | No defensive regex guard on `channel` in app.js | PWA | Currently safe due to ternary chain, but fragile |
| BC-12 | **LOW** | `setup-webhook.yml` always deploys production webhook handler | CI/CD | Dev/test webhooks deployed via this workflow validate against production token file |

### 9.5 Freemius ZIP Upload via GitHub Actions (New Feature)

**Principle:** The Freemius upload happens inside the CI pipeline, not on the app server. No server-side scripts or Apache involvement — purely a `build-release.yml` step, the same pattern as SCP to the app server.

**Current state:** `build-release.yml` creates a GitHub Release with the plugin ZIP but then only documents a manual step:
```
### Upload to Freemius
Download the ZIP above and upload it at:
https://dashboard.freemius.com → Your Plugin → Versions → Add New Version
```

**Target state:** `build-release.yml` automatically uploads the plugin ZIP to Freemius as a new version, gated by channel (stable → regular release, beta → pre-release).

**Where it fits in the workflow:**

```
promote.yml (test→main + tag)
  └── tag push v*.*.* triggers build-release.yml
       ├── job-build-zip: creates rich-statistics-{ver}.zip
       │     ↓
       ├── NEW STEP: Upload ZIP to Freemius Developer API
       │     ↓
       ├── Create GitHub Release (existing)
       ├── Create PWA snapshots (existing)
       ├── job-build-desktop (existing)
       └── ping-deploy (existing)
```

**Freemius Developer API endpoint:**

```
POST https://api.freemius.com/v1/products/{product_id}/releases.json
Authorization: Bearer {developer_secret_key}
Content-Type: multipart/form-data
```

Fields:
| Field | Value | Notes |
|-------|-------|-------|
| `version` | `${{ steps.version.outputs.version }}` | e.g. `2.4.3` |
| `file` | `build/rich-statistics-{version}.zip` | The plugin ZIP artifact |
| `release_notes` | Changelog entry for this version | Extract from `CHANGELOG.md` |
| `is_beta` | `true` for beta channel tags | e.g. `v2.4.3-beta.1` |

**Implementation plan:**

1. **Add GitHub secret** — `FREEMIUS_SECRET_KEY` (obtained from Freemius Dashboard → Developer → API Keys)
2. **Add step to `build-release.yml`** after the `build-zip` job completes (or as a dependent job):
   ```yaml
   upload-freemius:
     name: Upload to Freemius
     runs-on: ubuntu-latest
     needs: build-zip
     steps:
       - uses: actions/download-artifact@v4
         with:
           name: rich-statistics-${{ github.ref_name }}
           path: build
       - name: Determine channel
         id: channel
         run: |
           if echo "${{ github.ref_name }}" | grep -q "beta"; then
             echo "is_beta=true" >> $GITHUB_OUTPUT
           else
             echo "is_beta=false" >> $GITHUB_OUTPUT
           fi
       - name: Upload to Freemius
         env:
           FREEMIUS_KEY: ${{ secrets.FREEMIUS_SECRET_KEY }}
           VERSION: ${{ steps.version.outputs.version }}
           IS_BETA: ${{ steps.channel.outputs.is_beta }}
         run: |
           curl -s -X POST "https://api.freemius.com/v1/products/25954/releases.json" \
             -H "Authorization: Bearer ${FREEMIUS_KEY}" \
             -F "version=${VERSION}" \
             -F "file=@build/rich-statistics-${VERSION}.zip" \
             -F "is_beta=${IS_BETA}"
   ```

3. **Beta channel handling:**
   - Tags like `v2.4.3-beta.1` → `is_beta=true` → Freemius marks as pre-release
   - Tags like `v2.4.3` → `is_beta=false` → Freemius marks as stable release
   - This mirrors how `build-release.yml` already routes beta tags to the `test` branch for PWA snapshots

4. **Notes:**
   - The Freemius API returns the new release ID on success — log it in CI output
   - On failure, the release still exists as a GitHub Release (Freemius upload failure is non-blocking)
   - Product ID is `25954` (matches the `WP_FS__PRODUCT_25954_MULTISITE` constant in `rich-statistics.php`)

### 9.6 Promotion Workflow Enforcement

| Step | From → To | Workflow | Trigger | Status |
|------|-----------|----------|---------|--------|
| 1 | `develop → test` | `promote-test.yml` | Manual dispatch on develop | ✅ In place |
| 2 | `test → main` (stable) | `promote.yml` | Manual dispatch on test | ✅ In place |
| 2b | Tag `test` (beta) | `promote.yml` | Manual dispatch on test | ✅ In place |

Both workflows use `gh pr create` + `gh pr merge --squash`, respecting GitHub branch protection.

**Gap:** Beta tag always `.1` — need increment logic for re-cuts.

### 9.7 Snapshot Migration Plan

To fix BC-1 (flat → channel subdirs), all existing flat snapshots need conversion:

```bash
for dir in /var/www/rs-app/public_html/v/*/; do
    version=$(basename "$dir")
    # Skip if already has subdirectories
    [ -d "$dir/stable" ] && continue
    # Migrate flat files into stable/
    mkdir -p "$dir/stable"
    for f in "$dir"/*; do
        [ -f "$f" ] && mv "$f" "$dir/stable/"
    done
    [ -d "$dir/icons" ] && mv "$dir/icons" "$dir/stable/"
    # Copy stable to beta (identical content)
    cp -r "$dir/stable" "$dir/beta"
done
```

This must be run on:
- Production: `/var/www/rs-app/public_html/v/`
- Dev: `/var/www/rs-app-dev/v/`
- Test (PWA): `/var/www/rs-app-test/v/`

Each will also need `versions-beta.json` generated from existing `versions.json`:

```bash
cp versions.json versions-beta.json
```

### 9.8 Apache Config Update

Current SSL vhost for production (`/etc/apache2/sites-available/app.richstatistics.com-le-ssl.conf`):

```apache
<IfModule mod_ssl.c>
<VirtualHost *:443>
    ServerName app.richstatistics.com
    DocumentRoot /var/www/rs-app/public_html
    # Missing: LocationMatch for immutable caching on versioned paths
```

**Needs added:**

```apache
    <LocationMatch "^/v/[0-9]+\.[0-9]+\.[0-9]+/">
        Header set Cache-Control "public, max-age=31536000, immutable"
        Header set Access-Control-Allow-Origin "*"
    </LocationMatch>
```

Same fix needed for dev/test SSL vhosts.

### 9.9 Update JSON Signature Fix

Current `update.json` has empty signatures — Tauri updater requires valid signatures for auto-updates.

**Root cause to investigate:**
- Verify `TAURI_SIGNING_PRIVATE_KEY` and `TAURI_KEY_PASSWORD` secrets are correctly set in GitHub
- Check that `tauri build` step is correctly generating `.sig` files
- Verify `gen-update-json.py` is matching `.sig` files to their binaries correctly
- Check `server-gen-update-json.sh` was deployed with the correct `--dir` parameter

### 8.1 Uptime Monitoring

Each environment subdomain should be monitored for HTTP 200 responses.

**Recommended setup (cron on app server, or external):**
```
# Check every 5 minutes via cron
*/5 * * * * curl -fsS -o /dev/null -w "%{http_code}" https://app.richstatistics.com/ | grep -q 200 || logger -t rsa-monitor "PROD DOWN"
*/5 * * * * curl -fsS -o /dev/null -w "%{http_code}" https://dev.richstatistics.com/ | grep -q 200 || logger -t rsa-monitor "DEV DOWN"
*/5 * * * * curl -fsS -o /dev/null -w "%{http_code}" https://test.richstatistics.com/ | grep -q 200 || logger -t rsa-monitor "TEST DOWN"
```

**Endpoints to monitor:**
| Environment | URL | What to check |
|-------------|-----|---------------|
| Production | `https://app.richstatistics.com/` | PWA root returns 200 |
| Dev | `https://dev.richstatistics.com/` | PWA root returns 200 |
| Test | `https://test.richstatistics.com/` | PWA root returns 200 |
| All | `https://<host>/dist/update.json` | Tauri update manifest accessible |
| All | `https://<host>/apt/` | APT repo directory listing |
| All | `https://<host>/_deploy/` | Webhook endpoint (returns 405 on GET, not 404) |

### 8.2 Error Tracking

No error tracking service (Sentry, Bugsnag, etc.) is currently configured.

**Recommended for production:**
1. Enable WordPress `WP_DEBUG_LOG` on production server for plugin-level errors
2. Add a cron that tails and alerts on `wp-content/debug.log` growth
3. Consider Sentry PHP SDK (`sentry/sdk`) for structured error reporting — gate behind `RSA_APP_ENV === 'production'`

**Server-level errors (Apache):**
```
# Monitor for 5xx spikes
tail -f /var/log/apache2/rs-app_error.log | grep -c '" 5[0-9][0-9] '
```

### 8.3 Rollback Procedure

#### WordPress Plugin Rollback
```bash
# 1. Reinstall previous version from Freemius or GitHub Releases
wp plugin install https://github.com/richardkentgates/rich-statistics/releases/download/v<previous>/rich-statistics-<previous>.zip --force

# 2. Database: no automatic rollback — schema is additive-only via dbDelta()
# 3. Clear caches
wp cache flush
```

#### Desktop App Rollback
```bash
# Linux (APT)
sudo apt install rich-statistics=<previous-version>

# Linux (direct .deb)
sudo dpkg -i rich-statistics-linux-amd64-<previous>.deb

# Windows — download previous .exe from https://app.richstatistics.com/dist/
```

#### App Server Rollback
```bash
# 1. Revert docs/app/ to previous commit
cd /var/www/rs-app/public_html
sudo git checkout <previous-hash> -- docs/app/
# 2. Flush CDN/cache if applicable
# 3. Verify: curl -I https://app.richstatistics.com/
```

#### CI Pipeline Rollback
```bash
# 1. Revert the offending merge commit on the relevant branch
git revert <merge-commit-hash>
git push origin <branch>
# 2. For releases: delete the tag, re-tag the previous commit, force-push
git tag -d v<bad-release>
git tag v<previous-release> <previous-hash>
git push origin --tags --force
```

### 8.4 Database Backup Strategy

#### Current State
- Database backup is **not** configured as part of the plugin or CI
- WordPress sites using this plugin rely on their host's backup solution

#### Recommended Strategy

**For the WordPress database (containing analytics tables):**
```bash
# Daily cron — keep 7 days of backups
0 3 * * * mysqldump --single-transaction --routines --triggers \
  --databases wordpress_db \
  | gzip > /backups/wordpress/$(date +\%Y-\%m-\%d).sql.gz
find /backups/wordpress/ -mtime +7 -delete
```

**For the analytics tables specifically (lighter, more frequent):**
```bash
# Hourly analytics-only backup (smaller, faster)
0 * * * * mysqldump --single-transaction \
  wordpress_db wp_rsa_events wp_rsa_sessions wp_rsa_clicks wp_rsa_heatmap wp_rsa_wc_events \
  | gzip > /backups/analytics/$(date +\%Y-\%m-\%d_\%H).sql.gz
find /backups/analytics/ -mtime +3 -delete
```

**Restore:**
```bash
gunzip < /backups/wordpress/2026-05-11.sql.gz | mysql wordpress_db
```

> **Note:** The plugin's `rsa_remove_data_on_uninstall` option is off by default. If enabled and the plugin is deleted, all analytics tables are dropped — ensure backups exist before uninstalling.
