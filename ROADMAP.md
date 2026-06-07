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
| B9 | `RSA_APP_URL` hardcoded to production | ✅ Resolved | Dynamic via `rsa_detect_app_url()` |
| B10 | `config.js` has no `env` flag | ✅ Resolved | Auto-detects from hostname; `config-dev.js`, `config-test.js` available |
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
| Deploy mechanism | ✅ Systemd daemon + webhook | ✅ Systemd daemon + webhook | ✅ Systemd daemon + webhook |
| Deploy daemon (systemd) | ✅ Active (`rsa-deploy-daemon@prod`) | ✅ Active (`rsa-deploy-daemon@dev`) | ✅ Active (`rsa-deploy-daemon@test`) |
| Web root ownership | `richardkentgates:www-data` | `richardkentgates:www-data` | `richardkentgates:www-data` |
| APT repository | ✅ Present | ✅ Present | ✅ Present |
| vhost `/apt/` alias | ✅ Present | ✅ Present (SSL only) | ✅ Present |
| `dist/update.json` | ✅ Present (sig: populated by CI) | ✅ Present (sig: populated by CI) | ✅ Present (sig: populated by CI) |
| `v/` version snapshots | ✅ Channel-subdir format (17 versions) | ✅ Channel-subdir format (17 versions) | ✅ Channel-subdir format (17 versions) |
| `versions.json` + `versions-beta.json` | ✅ Present | ✅ Present | ✅ Present |
| Git branch (updater) | `main` | `develop` | `test` |
| Desktop CI pushes | ✅ `build-release.yml` | ✅ `build-develop.yml` | ✅ `build-test.yml` |

---

## 4. CI/CD Pipeline Status

| Ref | Workflow | Job | Status | Notes |
|-----|----------|-----|--------|-------|
| CI1 | `build-develop.yml` | `deploy-web` | ✅ Resolved | Sends to `dev.richstatistics.com/_deploy/` |
| CI2 | `build-test.yml` | `deploy-web` | ✅ Resolved | Sends to `test.richstatistics.com/_deploy/` |
| CI3 | `build-develop.yml` | `build-desktop` | ✅ Resolved | Pushes signed binaries to dev server `dist/` |
| CI4 | `build-release.yml` | `build-desktop-linux` | ✅ Resolved | Pushes signed `.deb` + `.sig`, updates APT repo |
| CI5 | `build-release.yml` | `ping-deploy` | ✅ Resolved | Deterministic webhook call to production |
| CI6 | `build-release.yml` | `upload-freemius` | ✅ Resolved | Uses `bin/deploy-freemius.php` (Freemius PHP SDK) |
| CI7 | `build-test.yml` | `build-desktop` | ✅ Done | Pushes signed binaries to test server `dist/` |
| CI8 | `build-release.yml` | Prune old snapshots | ✅ Done | Keeps latest 12 versioned PWA snapshots |

---

## 5. Post-Audit Findings (May 2026 — Verified)

| Ref | Finding | Environment | Status |
|-----|---------|-------------|--------|
| F1 | Dev APT repo claimed missing but present | Dev | ✅ Verified present |
| F2 | Dev `update.json` claimed missing but present | Dev | ✅ Verified present |
| F3 | Test `update.json` claimed stale but current | Test | ✅ Verified current |
| F4 | Dev `v/` dirs claimed incomplete but complete | Dev | ✅ Verified complete |
| F5 | Test `v/` dirs had pre-2.0 relics | Test | ✅ Cleaned |
| F6 | `RSA_APP_URL` hardcoded | All | ✅ Resolved — dynamic detection |
| F7 | Web root ownership mismatch | All | ✅ Fixed to `richardkentgates:www-data` |
| F8 | Prod `_deploy/` at wrong path | Prod | ✅ Fixed — vhost alias correct |

---

## 6. Comprehensive Platform Audit — Verified Status (May 2026)

Full audit completed across 8 areas. See `TODO.md` for the complete action item list.

### Summary

| Area | Status | Critical | High | Medium | Low |
|------|--------|----------|------|--------|-----|
| Plugin Code | ✅ Good | 0 | 0 | 0 | 0 |
| CI/CD | ✅ Good | 0 | 0 | 0 | 0 |
| Server Infra | ✅ Good | 0 | 0 | 0 | 0 |
| PWA | ✅ Good | 0 | 0 | 0 | 0 |
| Desktop App | ✅ Good | 0 | 0 | 0 | 0 |
| Documentation | ✅ Good | 0 | 0 | 0 | 0 |
| Database | ✅ Good | 0 | 0 | 0 | 0 |
| Tests | ✅ Good | 0 | 0 | 0 | 0 |
| **TOTAL** | | **0** | **0** | **0** | **0** |

### Phase 1: Critical (ship with next release)
| Ref | Area | Finding | Status |
|-----|------|---------|--------|
| BC-3 | CI/CD | Beta tag hardcoded to `.beta.1` — no increment | ✅ Fixed — auto-increments `.beta.N` |
| BC-4 | PWA | Fallback URL hardcoded to `/stable/` for beta users | ✅ Verified correct — stable fallback is intentional |
| C1 | CI/CD | `promote.yml` beta step missing `GH_TOKEN` | ✅ Fixed — env var added |
| C2 | Plugin | Premium renderers missing capability check | ✅ Fixed — all 5 methods now gated |
| H1 | Plugin | `remove_filter` closure identity bug | ✅ Fixed — stored closure reference |
| H2 | Plugin | MySQL 8.0 window functions fatal on 5.7 | ✅ Fixed — version guard added |
| H3 | CI/CD | `build-release.yml` snapshot push no `set -e` | ✅ Fixed — `set -euo pipefail` added |
| H4 | CI/CD | `build-release.yml` lacks concurrency | ✅ Fixed — group per ref |
| H5 | CI/CD | Webhook curls no retry | ✅ Fixed — 3-attempt loop on all envs |
| H6 | CI/CD | APT repo update race | ✅ Fixed — gated to linux-amd64 only |
| H7 | CI/CD | `product-suffix` JSON escaping | ✅ Fixed — Python `json.dumps()` |
| H8 | CI/CD | `build-test.yml` duplicate builds on promote | ✅ Fixed — `workflow_dispatch` only |
| H10 | PWA | Missing `sw-init.js` in old snapshots | ✅ Fixed — backfilled v2.4.9–v2.4.19 |
| H12 | Server | Cron-based deploy mechanism | ✅ Daemon created — install pending |

### Phase 2: High Priority (pre-commercial)
| Ref | Area | Finding | Status |
|-----|------|---------|--------|
| BC-1 | Server | Snapshot format mismatch on production server (flat → channel subdirs) | ✅ Fixed — all 42 prod + 23 dev + 23 test versions migrated |
| BC-2 | Server | `versions-beta.json` missing from dev/test servers | ✅ Fixed — regenerated on dev (23) and test (23) |
| BC-8 | Server | Server accumulates snapshots with no pruning | ✅ Fixed — prunes to last 12 versions |
| BC-12 | CI/CD | `setup-webhook.yml` always deploys production webhook | ✅ Fixed — deploys env-appropriate webhook |
| C7 | PWA | Root `sw.js` cache name stale (`rsa-2-4-24`) | ✅ Fixed — bumped to `rsa-2-4-26` |
| M2 | CI/CD | Chart.js SRI hash verification disabled | ✅ Fixed — enforced via `docs/app/chart.sri` |
| M25 | Server | Dev/test webhooks don't validate Content-Type | ✅ Fixed — matches production behavior |
| P2.1 | Server | Install systemd deploy daemon | ✅ Installed — active on prod, dev, test. Old cron removed. |
| P2.2 | PWA | Backfill missing version snapshots | ✅ Backfilled 2.4.22, 2.4.23, 2.4.25, 2.4.26 (17 total) |
| P2.3 | Server | Clean up old Windows binary names | ✅ Removed old `Rich Statistics_*.exe` from prod `dist/` |
| P2.4 | CI/CD | Post-deploy smoke tests | ✅ Added to build-develop, build-test, build-release |
| P2.5 | CI/CD | `build-release.yml` tag/main divergence | ✅ Fixed — `checkout-ref: main` in job-build-desktop |
| P2.2 | Tests | E2E test pipeline | ✅ 55 tests passing |

### Phase 3: Medium Priority
| Ref | Area | Finding | Status |
|-----|------|---------|--------|
| P4.2 | Distribution | WordPress.org SVN submission | ✅ Assets ready — banners + screenshots need real images |

### Phase 4: Low Priority (36 items)
See `TODO.md` §4 for full list.

---

## 7. Remaining Work (Legacy)

### P1: Environment-aware plugin ✅
1. **Make `RSA_APP_URL` configurable** — ✅ `rsa_detect_app_url()` in `rich-statistics.php`
2. **Add `env` flag to `config.js`** — ✅ `config.js` auto-detects from hostname

### P2: CI / Quality
1. Add PHPCS check to CI workflows — ✅ Added to all 4 workflows
2. Add E2E test pipeline — ✅ 55 tests covering welcome screen, add site flow, navigation, view switching, disconnect
3. Add upgrade/migration test coverage — ✅ 9 migration tests + 10 env detection tests

### P3: Signatures ✅
1. **Run CI build to generate signed `update.json`** — ✅ Auto-resolved on each tag push

### P4: WordPress.org
1. Create `readme.txt` and plugin assets — ✅ `readme.txt` with full 2.x changelog
2. SVN submission — ⏳ `bin/deploy-wporg.sh` ready; requires screenshots in `wporg-assets/`

### P5: Monitoring / Operations
1. Uptime monitoring — ✅ Handled by external system
2. Error tracking — 📝 Documented in §8.2
3. Rollback procedure — ✅ Documented in §8.3
4. Database backup strategy — ✅ Documented in §8.4

---

## 8. Operations Guide

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

#### Failed / Partial Release Recovery

A release can fail partway through, leaving some artifacts published and others missing. Common scenarios and recovery steps:

**Scenario A: GitHub Release created, but Freemius upload failed**
1. The ZIP artifact is still available in the GitHub Release assets
2. Download it manually and run the Freemius deploy script:
   ```bash
   php bin/deploy-freemius.php rich-statistics-<version>.zip <version> released
   ```
3. Verify on the Freemius dashboard that the version appears

**Scenario B: Freemius upload succeeded, but desktop build failed**
1. The release is functionally complete for web users
2. Desktop users continue to receive the previous version via `update.json`
3. Re-dispatch the Build Release workflow with the same tag:
   ```bash
   gh workflow run "Build Release" --ref "v<version>"
   ```
   The `resolve-version` job will find the version already exists on Freemius and skip re-upload, proceeding directly to desktop build and PWA deploy.

**Scenario C: PWA snapshots committed to main, but prod deploy webhook failed**
1. The snapshots are in `main` but not on the production server
2. Manually trigger the deploy webhook:
   ```bash
   curl -X POST -H "X-Deploy-Token: $(cat /etc/rsa-webhook-token)" \
     https://app.richstatistics.com/_deploy/
   ```
3. Verify with the smoke test:
   ```bash
   curl -fsS -o /dev/null -w "%{http_code}" https://app.richstatistics.com/
   ```

**Scenario D: Desktop binaries pushed, but APT repo update failed**
1. Binaries exist on the server in `dist/`
2. Manually update the APT repo from any Linux build:
   ```bash
   ssh app-server "sudo /usr/local/bin/rsa-apt-repo-update amd64 <version>"
   ```
3. Verify: `apt update && apt show rich-statistics`

**Prevention:**
- All failure scenarios are mitigated by the retry loops (3 attempts, 10s backoff) added to webhook curls, SCP uploads, and APT/repo updates
- `build-release.yml` has `concurrency` control to prevent overlapping release jobs
- The `set -euo pipefail` flag ensures shell steps fail fast rather than silently continuing

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
| **Production** | `104.197.231.120` | `/var/www/rs-app/public_html/` | v2.4.26 (`main`) | ✅ 17 entries | ✅ Present |
| **Dev** | `104.197.231.120` | `/var/www/rs-app-dev/` | v2.4.26 (`develop`) | ✅ 17 entries | ✅ Present |
| **Test (PWA)** | `104.197.231.120` | `/var/www/rs-app-test/` | v2.4.26 (`test`) | ✅ 17 entries | ✅ Present |
| **Test (Plugin)** | `34.56.56.233` | `/srv/www/wordpress` | WordPress integration tests | N/A | N/A |

All 3 PWA environments run on the same server (`104.197.231.120`), sharing the same wildcard SSL cert.

### 9.3 Snapshot Format Analysis

**Two different formats exist:**

| Method | Location | Format Created | Status |
|--------|----------|---------------|--------|
| `build.sh` | Local dev build script | `v/{version}/{stable,beta}/<files>` | ✅ Active |
| `build-release.yml` | CI release workflow | `v/{version}/{stable,beta}/<files>` | ✅ Active |
| `job-build-desktop.yml` | CI desktop build | `v/{version}/{stable,beta}/<files>` | ✅ Active |

**On production server (39 version directories):**
- 38 versions are **flat** (`v/2.4.1/app.js` — old format)
- Only `v/2.4.16/` has `stable/` + `beta/` subdirectories (first CI-built version)
- **This is a critical compatibility break**: the root `app.js` navigates to `/v/{version}/{channel}/index.html` — all versions before 2.4.16 will 404 in new desktop builds
- **Fix**: Run server migration script (see §9.7)

**In repo `docs/app/v/` (12 versions):**
- All versions have correct `stable/` + `beta/` subdirectories ✅

### 9.4 Gaps Discovered During Audit

| # | Severity | Gap | Layer | Status |
|---|----------|-----|-------|--------|
| BC-1 | **CRITICAL** | Snapshot format mismatch: old flat vs new channel subdirs on server | Server | ✅ Migrated via SSH — all 42 prod + 23 dev + 23 test versions in channel-subdir format |
| BC-2 | **CRITICAL** | `versions-beta.json` missing from dev/test environments | Server | ✅ Regenerated on dev (23) and test (23) via SSH |
| BC-3 | **HIGH** | Beta tag in `promote.yml` hardcoded to `.beta.1` — no increment | CI/CD | ✅ Auto-increments `.beta.N` suffix in `promote.yml` |
| BC-4 | **HIGH** | `tauriNavigateToVersion` fallback hardcoded to `/stable/` | PWA | ✅ Intentional — stable fallback is correct behavior |
| BC-8 | **MEDIUM** | Server accumulates 39+ snapshots — CI only keeps 12 | Server | ✅ `server-update-webapp.sh` prunes to last 12 versions |
| BC-12 | **LOW** | `setup-webhook.yml` always deploys production webhook handler | CI/CD | ✅ Environment-aware webhook deployment added |

### 9.5 Freemius ZIP Upload via GitHub Actions

**Implemented:** Uses official Freemius PHP SDK via `bin/deploy-freemius.php`.

**How it works:**
1. `GET plugins/{id}/tags.json` — checks if the version already exists on Freemius
2. If not found → `POST plugins/{id}/tags.json` with ZIP file — uploads the plugin
3. `PUT plugins/{id}/tags/{tag_id}.json` — sets `release_mode` to the specified value

Supported `release_mode` values:
- `released` — stable tags (`v2.4.26`)
- `beta` — beta tags (`v2.4.26-beta.1`) and test branch builds

**Manual usage:**
```bash
php bin/deploy-freemius.php <file_name> <version> <release_mode> [sandbox]
# Example:
php bin/deploy-freemius.php rich-statistics-2.4.26.zip 2.4.26 released
```

### 9.6 Promotion Workflow Enforcement

| Step | From → To | Workflow | Trigger | Status |
|------|-----------|----------|---------|--------|
| 1 | `develop → test` | `promote-test.yml` | Manual dispatch on develop | ✅ In place |
| 2 | `test → main` (stable) | `promote.yml` | Manual dispatch on test | ✅ In place |
| 2b | Tag `test` (beta) | `promote.yml` | Manual dispatch on test | ✅ In place |

Both workflows use `gh pr create` + `gh pr merge --squash`, respecting GitHub branch protection.

**Gap:** Beta tag always `.1` — need increment logic for re-cuts (BC-3).

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

Current `update.json` has signatures populated by CI pipeline.

**Verified:**
- `TAURI_SIGNING_PRIVATE_KEY` and `TAURI_KEY_PASSWORD` secrets are correctly set in GitHub
- `tauri build` step correctly generates `.sig` files
- `gen-update-json.py` matches `.sig` files to their binaries correctly
- Platform key mapping fixed: `"linux-arm64": "linux-aarch64"`
- `pub_date` now uses dynamic timestamp: `datetime.now(timezone.utc).strftime(...)`

---

## 10. Big-Picture Pipeline Assessment (May 2026)

### Overall Grade: B+
Production-ready with manageable technical debt. The pipeline works end-to-end and produces all required artifacts. Remaining gaps are in observability, server mechanism transparency, and version parity completeness.

### What's Solid (Green)

| Area | Status | Details |
|------|--------|---------|
| Branch flow | ✅ Strong | `develop → test → main` enforced via promote workflows. No direct commits to protected branches. |
| CI reliability | ✅ Good | Concurrency controls, retry loops, `set -euo pipefail`, reusable sub-workflows. YAML syntax validated. |
| Test coverage | ✅ Good | Unit (74 tests), integration (PHP 8.1–8.4 × WP latest/6.4), E2E (55 Playwright tests) all run on develop. |
| Code quality gates | ✅ Good | PHPCS in `job-build-zip`, PHP syntax check, Chart.js SRI hash verification. |
| Freemius integration | ✅ Working | SDK-based upload in `build-test.yml` (beta) and `build-release.yml` (stable). Version deduplication handled. |
| Desktop distribution | ✅ Working | Linux amd64/arm64 `.deb` + Windows `.exe` on all 3 servers. APT repo + `update.json` for auto-updates. |
| Security posture | ✅ Recently hardened | Capability checks fixed, `remove_filter` identity fixed, MySQL version guard added, sanitized headers, nonce verification. |
| Webhook deploys | ✅ Functional | All 3 environments receive PWA deploys. Dev server binaries are current (May 24). |

### Resolved Since Last Audit

| # | Issue | Resolution |
|---|-------|------------|
| 1 | Deploy mechanism undocumented | ✅ Systemd daemon installed on all 3 servers; old cron removed. `journalctl -u rsa-deploy-daemon@{prod,dev,test}` for logs. |
| 2 | Version parity gaps | ✅ Backfilled 2.4.22, 2.4.23, 2.4.25, 2.4.26. 17 versions now present from 2.4.9 → 2.4.26. (2.4.20 was never released as a tag.) |
| 3 | Windows binary naming inconsistency | ✅ Old `Rich Statistics_*.exe` files removed from prod `dist/`. Only standardized `rich-statistics-windows.exe` remains. |
| 4 | No post-deploy verification | ✅ Smoke test added to `build-develop.yml`, `build-test.yml`, and `build-release.yml` — verifies HTTP 200 after webhook ping. |
| 6 | `build-release.yml` tag vs. main divergence | ✅ `job-build-desktop.yml` accepts `checkout-ref: main`; desktop build uses exact snapshots committed by release job. |
| 7 | Partial release recovery undocumented | ✅ Disaster recovery procedures documented in §8.3 (scenarios A–D). |
| — | Health check workflow | ✅ `.github/workflows/health-check.yml` runs weekly + manual dispatch. Checks PWA, update.json, APT, webhook, deployed version. |

### Working But Needs Attention (Yellow)

| # | Issue | Severity | Details |
|---|-------|----------|---------|
| 5 | Test branch Freemius pollution | 🔶 LOW | Every `promote-test` → `build-test` run uploads to Freemius as `beta`. Acceptable by design — `beta` release_mode is intended for this. |

### Recommendations (Priority Order)

| Priority | Action | Impact |
|----------|--------|--------|
| **P3** | Replace placeholder screenshots in `wporg-assets/` with real images | Unblocks WordPress.org SVN submission (`bin/deploy-wporg.sh` ready) |

---

## 11. Remove In-Plugin PWA Serving (Planned Task)

> **Status:** Plan corrected — corrections applied 2026-06-06. Awaiting user approval before implementation.
> **Last updated:** 2026-06-06

### 11.1 Goal

Remove the WordPress-plugin-embedded PWA serving mechanism (`/rs-app/` rewrite + file serving) while preserving the external app server (`app.richstatistics.com`), the Tauri desktop app, and all WordPress admin dashboard interfaces.

### 11.2 Corrected Architecture Understanding

The plugin currently has **two entirely separate mechanisms** that happen to read from the same source directory (`docs/app/`):

**Mechanism A — In-Plugin PWA (TO BE REMOVED):**
- `includes/class-admin.php` registers an Apache rewrite rule `rs-app/?$` and a query var `rsa_app`
- On activation it flushes rewrite rules
- When `?rsa_app=1` is set, `serve_app()` reads `docs/app/index.html` from disk and serves it as a standalone page inside the WordPress site
- `serve_manifest()` serves `docs/app/manifest.json`
- This is what makes `https://yoursite.com/rs-app/` load the PWA from within the plugin

**Mechanism B — External App Server (PRESERVED):**
- The external server (`app.richstatistics.com`, `dev.`, `test.`) runs `bin/server-update-webapp.sh`
- That script does a `git sparse-checkout` of `docs/app/` directly from the **GitHub repository** (not from any WordPress site)
- The files are served at the external subdomain
- CI workflows (`build-release.yml`, `build-develop.yml`, `build-test.yml`) trigger this via webhook

**Mechanism C — Desktop App Bundling (PRESERVED):**
- `src-tauri/tauri.conf.json` has `frontendDist` pointing to `../docs/app`
- Tauri bundles those files into the `.deb`/`.exe` at CI build time
- The desktop app never reads PWA files from a WordPress site

`docs/app/` must remain in the repository because it is the **single source of truth** for Mechanism B and Mechanism C. Only Mechanism A is being removed.

### 11.3 What Must Be Removed

**PHP (WordPress plugin):**

| File | Method / Code | Reason |
|------|---------------|--------|
| `includes/class-admin.php` | `register_app_rewrite()` | Registers `rs-app/?$` rewrite rule + `rsa_app` query var |
| `includes/class-admin.php` | `serve_app()` | Serves `docs/app/index.html` when `?rsa_app=1` |
| `includes/class-admin.php` | `serve_manifest()` | Serves `docs/app/manifest.json` |
| `includes/class-admin.php` | `add_app_query_var()` | Adds `rsa_app`/`rsa_manifest` query vars |
| `includes/class-admin.php` | Init hooks for rewrite/serving (lines 47-51) | Registers above handlers on `init`, `query_vars`, `template_redirect` |
| `includes/class-pwa-download.php` | `handle_download()` + `stream_zip()` + AJAX hook | ZIP download for manual PWA hosting; obsolete |
| `includes/class-pwa-download.php` | Docblock references to ZIP download | References to `rsa_download_pwa` and generic ZIP |
| `rich-statistics.php` | Activation hook anonymous function (lines 172-178) | Calls `register_app_rewrite()` + `flush_rewrite_rules()` — entire hook removed |
| `uninstall.php` | Missing rewrite flush (add `flush_rewrite_rules()`) | Stale `rs-app` rules persist in `wp_options` without explicit flush |

> `RSA_Pwa_Download` is **NOT** removed. The OTP site-pairing handler (`handle_generate_otp`) is still required. Only the ZIP download method and its AJAX hook are removed.

**JavaScript (PWA — authoritative copies only, not versioned snapshots):**

| File | Dead code | Reason |
|------|-----------|--------|
| `docs/app/config.js` | `/wp-content/` autoSiteUrl detection block (lines 17-23) | Only triggered when PWA was served in-plugin; never fires on external server |
| `docs/app/config.js` | Comment about "served from within the plugin directory" (lines 3-7) | In-plugin context no longer exists |
| `docs/app/app.js` | Auto-registration block (lines 122-147) | `autoSiteUrl + autoNonce` were only set by `serve_app()`; dead code |
| `docs/app/app.js` | `nonceAuth` variable and its use in init conditional (line 58) | `nonce` was only set by `serve_app()`; dead code |
| `docs/app/app.js` | `RSA_CONFIG.isPremium` + `RSA_CONFIG.upgradeUrl` loading (lines 53-56) | Only set by `serve_app()` injection; dead code |
| `docs/app/app.js` | `getAuthHeaders()` nonce branch (lines 367-371) | `nonce + autoUrl` never set; always falls through to Basic auth |
| `docs/app/app.js` | 403 nonce-retry path (lines 402-419) | `nonce + autoUrl` never set; dead code |
| `docs/app/app.js` | Add-site prefill from `autoSiteUrl` (lines 790-797) | `autoSiteUrl` never set; dead code |
| `docs/app/app.js` | Comment referencing `/rs-app/` and `serve_app()` (lines 122-125) | In-plugin context no longer exists |
| `docs/app/v/2.4.26/stable/app.js` | Same dead code as `docs/app/app.js` | Must match |
| `docs/app/v/2.4.26/beta/app.js` | Same dead code as `docs/app/app.js` | Must match |
| `docs/app/v/2.4.26/stable/config.js` | Same dead code as `docs/app/config.js` | Must match |
| `docs/app/v/2.4.26/stable/config-dev.js` | Same `/wp-content/` detection block | Must match |
| `docs/app/v/2.4.26/stable/config-test.js` | Same `/wp-content/` detection block | Must match |
| `docs/app/v/2.4.26/beta/config.js` | Same dead code as `docs/app/config.js` | Must match |
| `docs/app/v/2.4.26/beta/config-dev.js` | Same `/wp-content/` detection block | Must match |
| `docs/app/v/2.4.26/beta/config-test.js` | Same `/wp-content/` detection block | Must match |

**Versioned snapshots `docs/app/v/2.4.9/` through `docs/app/v/2.4.25/`:** Do NOT modify. These correspond to already-released plugin versions and are historical artifacts.

**Documentation and metadata:**

| File | What to update |
|------|---------------|
| `ARCHITECTURE.md` | Update `class-pwa-download.php` description — remove "Serves PWA ZIP download" |
| `.github/copilot-instructions.md` | Update `class-pwa-download.php` description |
| `includes/class-pwa-download.php` | Update file-level docblock — remove ZIP download references |
| `languages/rich-statistics.pot` | Regenerate after all PHP changes |

### 11.4 What Must Be Preserved

| Component | Why |
|-----------|-----|
| `docs/app/` directory | Source for external app server deploy AND desktop app bundling |
| All CI workflows (`build-*.yml`) | Already deploy to external servers correctly; no changes needed |
| `bin/server-update-webapp.sh` | External server deploy script; untouched |
| `bin/server-webhook.php` | Webhook handler for CI; untouched |
| All 17 admin templates (`templates/admin/*.php`) | Native WordPress dashboard; completely separate from PWA |
| REST API (`rsa/v1`) | Used by external PWA/desktop app |
| OTP pairing / App Code flow | Used to connect external app to WordPress via REST API |
| Desktop app download instructions (`templates/admin/install.php`) | Links to external URLs |
| Tauri desktop app (`src-tauri/`) | Bundles `docs/app/` from repo at build time |

### 11.5 Step-by-Step Implementation Plan

**Phase A — PHP (WordPress plugin)**

1. **Edit `includes/class-admin.php`**
   - Remove `register_app_rewrite()` entirely
   - Remove `serve_app()` entirely
   - Remove `serve_manifest()` entirely
   - Remove `add_app_query_var()` entirely
   - Remove the `add_action( 'init', ... )`, `add_filter( 'query_vars', ... )`, and `add_action( 'template_redirect', ... )` hooks for PWA serving (lines 47-51)

2. **Edit `includes/class-pwa-download.php`**
   - Remove `handle_download()` method
   - Remove `stream_zip()` method
   - Remove `add_action( 'wp_ajax_rsa_download_pwa', ... )` from `init()`
   - Update file-level docblock — remove ZIP download references (lines 4-8, 25)
   - **Do NOT remove `handle_generate_otp()`, `generate_otp()`, or `wp_ajax_rsa_generate_otp`** — OTP site-pairing is preserved
   - **Do NOT delete the class file**

3. **Edit `rich-statistics.php`**
   - Remove the activation hook anonymous function (lines 172-178) that calls `RSA_Admin::register_app_rewrite()` and `flush_rewrite_rules()`
   - **Do NOT remove** `RSA_Pwa_Download::init()` (line 246) — OTP handler is still needed

4. **Edit `uninstall.php`**
   - Add `flush_rewrite_rules()` call to remove stale `rs-app` rewrite rules from `wp_options`

5. **Delete `tests/unit/PwaDownloadTest.php`**
   - Tests `handle_download`, `stream_zip`, and class init — 3 of 10 tests reference removed methods; the file name is a misnomer after ZIP download removal. Delete entirely.

6. **Update `includes/class-pwa-download.php` docblock**
   - Remove references to `rsa_download_pwa` and "generic app ZIP" from file header

7. **Update documentation files**
   - `ARCHITECTURE.md` — Update `class-pwa-download.php` description
   - `.github/copilot-instructions.md` — Update `class-pwa-download.php` description

8. **Regenerate `languages/rich-statistics.pot`**
   - Run `composer make-pot` or equivalent after all PHP changes

9. **Run full test suite**
   - `composer test` (unit + integration)
   - `composer phpcs`
   - Verify zero failures

**Phase B — JavaScript (PWA — authoritative copies only)**

10. **Edit `docs/app/config.js`**
    - Remove the `/wp-content/` detection block (lines 17-23) — `autoSiteUrl` is never set when served externally
    - Update file comment (lines 3-7) — remove "When served from within the plugin directory" language

11. **Edit `docs/app/app.js`**
    - Remove `nonceAuth` variable and its use in the init conditional (line 58) — nonce is never set from external
    - Remove `RSA_CONFIG.isPremium` and `RSA_CONFIG.upgradeUrl` loading (lines 53-56) — only set by `serve_app()`
    - Remove the entire auto-registration block (lines 122-147) — `autoSiteUrl + autoNonce` never set externally
    - Simplify `getAuthHeaders()` (lines 366-376) — remove the nonce branch; always use Application Password Basic auth
    - Remove the 403 nonce-retry path in `apiGet()` (lines 402-419) — nonce refresh is never triggered
    - Remove `autoSiteUrl` prefill in `showAddSiteOverlay()` (lines 790-797) — `autoSiteUrl` never set
    - Update all comments referencing `/rs-app/`, `serve_app()`, or in-plugin context

12. **Copy to versioned snapshots** (latest version only)
    - `docs/app/v/2.4.26/stable/app.js` and `beta/app.js` — same changes as step 11
    - `docs/app/v/2.4.26/stable/config.js`, `config-dev.js`, `config-test.js` and `beta/` equivalents — same changes as step 10

**Phase C — Housekeeping**

13. **Update `CHANGELOG.md`**
    - Add entry under `[Unreleased]` noting the removal of in-plugin PWA serving and all dead code paths

14. **Commit and push to `develop`**
    - Follow branch structure: feature branch → develop → test → main

### 11.6 Test Impact

| Test file | Expected change |
|-----------|-----------------|
| `tests/unit/PwaDownloadTest.php` | **Delete entirely.** Tests `handle_download`, `stream_zip`, and class intspect — file name is a misnomer after ZIP download removal |
| `tests/integration/AdminTest.php` | No changes needed — verified to contain no assertions on rewrite rules or `serve_app` |
| `tests/integration/RestApiExtraTest.php` | No changes needed — uses `rs-app` only as a string value in test data |
| E2E tests (`tests/e2e/`) | No changes needed — E2E tests run against the external PWA server, not in-plugin serving |
| All other tests | No impact |

### 11.7 Risk Assessment

- **Low risk.** The in-plugin PWA serving is a standalone feature with no dependencies from other plugin components. Removing it does not affect tracking, analytics, REST API, admin dashboard, external app server, or desktop app.
- **No database migration needed.** No schema changes.
- **No user-facing admin pages affected.** Only the `/rs-app/` frontend URL disappears.
- **Plugin ZIP size benefit.** `docs/app/` was already excluded from the ZIP by `.distignore`, so ZIP size is unchanged. However, a small reduction from removing `handle_download()` and `stream_zip()`.

### 11.8 Server & GitHub Infrastructure Audit (2026-06-06)

Verified by inspecting the live server (`104.197.231.120`), GitHub repo (`richardkentgates/rich-statistics`), and all CI workflows.

#### App Server (all 3 environments)

| Environment | DocumentRoot | Deploy branch | SSL cert | Status |
|-------------|-------------|---------------|----------|--------|
| Production | `/var/www/rs-app/public_html/` | `main` | Let's Encrypt (SAN: `app.richstatistics.com`) | Live, serving PWA |
| Dev | `/var/www/rs-app-dev/` | `develop` | Shared cert | Live, serving PWA |
| Test | `/var/www/rs-app-test/` | `test` | Shared cert | Live, serving PWA |

**Key findings:**

- **No `/rs-app/` rewrite rules on the app server.** The Apache vhosts serve static files only — no PHP, no WordPress, no rewrite rules. The `rs-app` RewriteEngine rules in `setup-app-server.sh` are for HTTP→HTTPS redirect only. Confirmed: app server infrastructure is completely independent of the WordPress `/rs-app/` route.
- **Deploy mechanism verified:** `rsa-deploy-daemon@prod|dev|test` systemd services monitor `.deploy-trigger` files. When CI pings `/_deploy/`, the webhook writes the trigger, the daemon runs `rsa-app-update` which does `git sparse-checkout set docs/app` from the correct branch. No changes needed.
- **All 3 health check PWA root checks pass.** Webhook endpoint checks fail (pre-existing, unrelated to this change).
- **Stale flat-format snapshots** (`2.0.x` through `2.2.7`) still exist on the production server disk. These predate the channel-subdirectory format and are not managed by the CI prune. Separate cleanup task (not part of this removal).
- **Desktop builds served from `/dist/`**: `rich-statistics-linux-amd64.deb`, `linux-arm64.deb`, `windows.exe`, and `update.json` all present on production server.

#### GitHub Infrastructure

- **30 tags** (v2.2.7 through v2.4.26), **12 PWA version directories** on GitHub (`docs/app/v/2.4.14/` through `v/2.4.26/`).
- **4 branches:** `main`, `develop`, `test`, `fix/merge-main-into-test`.
- **11 CI workflows** (build-develop, build-test, build-release, job-build-zip, job-build-desktop, promote, promote-test, tests, e2e-tests, health-check, setup-webhook). All verified to NOT reference in-plugin PWA serving.
- **`job-build-zip.yml`** excludes `*/docs/*` from the plugin ZIP (line 84). This means `docs/app/` is NOT included in the distributed ZIP. The `serve_app()` method reads from `RSA_DIR . 'docs/app/index.html'` which only works in the dev repo — feature was already broken for distributed plugins.
- **`build-release.yml`** creates PWA snapshots from `docs/app/`, commits them to `main`, and triggers deploy webhook. All external-server flow. No changes needed.
- **`job-build-desktop.yml`** copies `docs/app/` into Tauri build. No changes needed.
- **`health-check.yml`** checks PWA root, update.json, APT repo, webhook, and deployed version on all 3 environments. No changes needed.

#### PWA `config.js` — `autoSiteUrl` Detection

The `autoSiteUrl` detection in `config.js` (lines 17-23) checks for `/wp-content/` in the script's `src` attribute:

```js
var idx = s.src.indexOf( '/wp-content/' );
if ( idx !== -1 ) {
    window.RSA_CONFIG.autoSiteUrl = s.src.substring( 0, idx );
}
```

**This code path becomes dead code** when in-plugin serving is removed — the PWA is only served from `app.richstatistics.com` where there is no `/wp-content/` in paths. It is harmless (evaluates to `-1` and skips), but the comment references "served from within the plugin directory" which should be updated.

**Affected files (authoritative copies only — `docs/app/` and `docs/app/v/2.4.26/`):**
- `docs/app/config.js` — Update comment about in-plugin context
- `docs/app/v/2.4.26/stable/config.js` — Same update
- `docs/app/v/2.4.26/beta/config.js` — Same update
- `docs/app/v/2.4.26/stable/config-dev.js` — Same update
- `docs/app/v/2.4.26/beta/config-dev.js` — Same update
- `docs/app/v/2.4.26/stable/config-test.js` — Same update
- `docs/app/v/2.4.26/beta/config-test.js` — Same update

**All older versioned snapshots (`docs/app/v/2.4.9/` through `docs/app/v/2.4.25/`):** Do NOT modify. They correspond to already-released plugin versions and are historical.

#### PWA `app.js` — `autoSiteUrl` + Nonce Auth Paths

Three code paths in `app.js` use `autoSiteUrl` and `nonce`, both of which were set by `serve_app()`:

1. **Auto-registration block (lines 122-147)** — When `autoSiteUrl + autoNonce` are both set, auto-registers the current WordPress site with empty credentials (nonce-based auth). After removal, both will always be empty/undefined, so this block is skipped entirely. **No behavioral change** — the `if ( autoUrl && autoNonce )` guard makes it a no-op.

2. **`getAuthHeaders()` function (lines 366-376)** — Uses `nonce + autoUrl` for same-origin auth. After removal, this always falls through to Application Password Basic auth. **This is the correct behavior** for the external app.

3. **403 retry with nonce refresh (lines 401-419)** — On 403, fetches fresh nonce from `autoUrl + '/wp-json/'`. After removal, this path is never reached (no `autoUrl` set). **Harmless no-op.**

4. **Add-site URL prefill (lines 790-797)** — Uses `autoSiteUrl` to prefill the site URL input. After removal, this won't prefill. **No code change needed** — `RSA_CONFIG.autoSiteUrl` is already guarded by `if ( autoUrl && urlField )`.

**Affected files (authoritative copies only):**
- `docs/app/app.js` — Update comment at lines 122-125
- `docs/app/v/2.4.26/stable/app.js` — Same update
- `docs/app/v/2.4.26/beta/app.js` — Same update

#### Stale Rewrite Rules — Upgrade Path Concern

After removing `register_app_rewrite()`, existing WordPress installations will still have the `rs-app` rewrite rules persisted in their `wp_options` table. These stale rules are harmless (they'll 404 since `serve_app()` is removed) but should be flushed.

**Solution:** Add `flush_rewrite_rules()` to the `RSA_DB::activate()` method or add a one-time upgrade hook. Since the plugin already has `register_activation_hook()` for `RSA_DB::activate()`, we can add the flush there. However, upgrading via plugin update does NOT trigger activation hooks.

The most reliable approach: add a version-based one-time flush in `rsa_init()` using a stored option. Or simpler — since stale rewrite rules cause no errors (just 404s), they'll be cleaned up naturally when any other plugin or WordPress action flushes rewrite rules. **Document this as a known minor issue and address it in a follow-up if needed.**

#### `uninstall.php` — Missing Rewrite Flush

`uninstall.php` does not call `flush_rewrite_rules()`. When the plugin is uninstalled, the `rs-app` rewrite rules will remain in the database. **Add `flush_rewrite_rules()` to `uninstall.php`** to ensure clean removal.

---

## 12. Consent Banner Feature

> **Status:** Planning — not yet implemented.
> **Last updated:** 2026-06-06

### 12.1 Goal

Add an optional visitor consent banner. Two admin checkboxes control behavior. When the banner is shown, all metrics default to ON. Visitor can turn categories OFF.

### 12.2 Design

**Two independent admin checkboxes:**

| Checkbox | Purpose | Default |
|----------|---------|---------|
| Show Banner | Shows the banner to visitors when checked | Off |
| Auto-Consent | When checked, tracking starts immediately. When unchecked, tracking waits for visitor choice. | Off |

**Four combinations:**

| Show Banner | Auto-Consent | Behavior |
|-------------|-------------|----------|
| Unchecked | Unchecked | No banner. Track everything. (Current default) |
| Unchecked | Checked | No banner. Track everything. localStorage receipt written on first interaction. |
| Checked | Unchecked | Banner shown. No tracking until visitor makes a choice. |
| Checked | Checked | Banner shown. Tracking starts immediately. Visitor can turn off categories. |

**All metrics default ON.** Visitor turns categories OFF, not on.

**Per-metric categories:**

| Category | Controls | Free/Premium |
|-----------|---------|-------------|
| Analytics | Pageviews, sessions, viewport, time on page | Free |
| Campaigns | UTM tracking (source, medium, campaign) | Free |
| Click Tracking | Element clicks, viewport coordinates, heatmap | Premium |
| Commerce | WooCommerce purchase events | Premium |

**Banner styling (fully customizable):**

Stored as JSON in `wp_options`:

```json
{
  "borderRadius": 8,
  "fontColor": "#1a1a2e",
  "backgroundColor": "#ffffff",
  "borderColor": "#e0e0e0",
  "borderWidth": 1,
  "shadowX": 0,
  "shadowY": 4,
  "shadowBlur": 12,
  "shadowSpread": 0,
  "shadowColor": "#000000",
  "shadowAlpha": 0.15
}
```

`shadowAlpha` stored separately for a dedicated opacity slider. Combined with `shadowColor` into `rgba()` at render time.

**Banner UX:**
- Minimal text, small footprint
- Collapse button: physically repositions the banner out of the way (off-screen or to a corner so page content remains clickable)
- Return button: physically brings the banner back to its original position
- Collapse/return state persists across page loads via localStorage

### 12.3 Settings Storage

New `wp_options` keys (added to `RSA_DB::seed_defaults()`):

| Option key | Type | Default |
|-----------|------|---------|
| `rsa_consent_banner` | int (0/1) | `0` |
| `rsa_consent_auto` | int (0/1) | `0` |
| `rsa_consent_styles` | JSON | Style config object (see 12.2) |

Also add these keys to `RSA_Admin::save_settings()` `$fields` array with `absint` sanitizer, and to `RSA_DB::drop_site_tables()` option deletion list in `class-db.php`.

### 12.4 Files to Create

| File | Purpose |
|------|---------|
| `includes/class-consent-banner.php` | New class: `RSA_Consent_Banner`. Hooks `wp_enqueue_scripts` to inject banner HTML and `<style>` block. Injects consent config into `window.RSA`. Exits early if `rsa_consent_banner` is `0`. |
| `assets/css/consent-banner.css` | Base layout styles (position: fixed, z-index, responsive). Colors/shadows/borders via CSS custom properties set server-side. |

### 12.5 Files to Modify

| File | Change |
|------|--------|
| `includes/class-db.php` | Add `rsa_consent_*` defaults to `seed_defaults()`. Add consent options to `drop_site_tables()` uninstall cleanup list. |
| `includes/class-admin.php` | Add `rsa_consent_banner` and `rsa_consent_auto` to `save_settings()` `$fields` array with `absint` sanitizer. Hook `RSA_Consent_Banner::init()`. |
| `includes/class-tracker.php` | Add `consentBanner` and `consentAuto` to `wp_localize_script` data (`window.RSA`). |
| `assets/js/tracker.js` | Check `window.RSA.consentBanner` and `window.RSA.consentAuto` before sending. Per-category gate applies to both `sendBeacon` and jQuery sync AJAX fallback paths. If `localStorage` is blocked, fall back to `sessionStorage` (session-only consent) then in-memory state (page-load-only consent). |
| `templates/admin/preferences.php` | Add "Consent Banner" section: Show Banner checkbox, Auto-Consent checkbox, style controls. |
| `includes/class-privacy-disclosure.php` | Update legal claims based on consent mode. |
| `uninstall.php` | No changes needed — `RSA_DB::maybe_remove_data()` handles option cleanup via `drop_site_tables()`. |
| `languages/rich-statistics.pot` | Regenerate after all PHP changes. |
| `CHANGELOG.md` | Document under `[Unreleased]`. |

### 12.6 Consent Flow

```
Visitor loads page
  ↓
tracker.js checks window.RSA.consentBanner and window.RSA.consentAuto
  ├── consentBanner=0 → track everything, no banner (current default)
  ├── consentBanner=1, consentAuto=1 → banner rendered, track immediately, visitor can toggle categories
  └── consentBanner=1, consentAuto=0 → banner rendered, no tracking until visitor makes a choice
        Visitor accepts → localStorage: all categories true
        Visitor rejects → localStorage: all categories false
        Visitor customizes → per-category toggles (all ON), save writes to localStorage
        └── Collapse button → repositions banner out of the way
        └── Return button → brings banner back to original position
        └── Collapse/return state persists across page loads
  ↓
On each beacon, tracker.js checks localStorage categories
  ├── Pageview → check analytics
  ├── UTM → check campaigns
  ├── Click → check clicks
  └── Commerce → check commerce
```

### 12.6.1 localStorage Blocked Fallback

If `localStorage` is unavailable (private browsing, corporate policy, browser extension):
- Fall back to `sessionStorage` → consent persists for the tab session only, lost on close
- If `sessionStorage` is also blocked, use in-memory state → consent lasts for the page load only, resets on navigation
- The banner still renders and functions normally in all cases — only persistence scope changes

### 12.6.2 Tracker Send Path

`tracker.js` sends data via two paths (existing code):
1. `navigator.sendBeacon()` — preferred, fire-and-forget
2. jQuery `$.ajax({ async: false })` — sync fallback when sendBeacon is unavailable

**Both paths must apply the same consent gating.** The consent check happens before the send decision, so both paths are blocked equally when a category is declined.

### 12.7 What Must Be Preserved

- Default behavior (`rsa_consent_banner=0`): track everything, no banner
- DNT/GPC check in `tracker.js` — exits before consent logic
- Existing `window.RSA` config structure — consent keys are additive
- All existing REST endpoints — no new endpoints needed

### 12.8 Risk Assessment

- Low risk. Default is off — no change until site owner enables it.
- No database migration. All settings are `wp_options`.
- No schema changes. Consent enforced client-side.
- Backwards-compatible. If `window.RSA.consentBanner` is undefined, tracking proceeds as before.

### 12.9 Implementation Order

**Phase A — Backend (PHP)**
1. `includes/class-db.php` — Add defaults to `seed_defaults()`, add to `drop_site_tables()` list
2. `includes/class-consent-banner.php` — New class
3. `includes/class-admin.php` — Add to `save_settings()` `$fields` array, hook `RSA_Consent_Banner::init()`
4. `templates/admin/preferences.php` — Consent Banner section
5. `assets/css/consent-banner.css` — Base styles
6. `includes/class-tracker.php` — Consent config in `wp_localize_script`
7. `includes/class-privacy-disclosure.php` — Update legal claims
8. `uninstall.php` — No changes needed (handled by `drop_site_tables()`)

**Phase B — Frontend (tracker.js)**
9. `assets/js/tracker.js` — Consent check + per-category gating

**Phase C — Housekeeping**
10. `languages/rich-statistics.pot` — Regenerate
11. `CHANGELOG.md` — Document
12. `composer phpcs` + `composer test`

### 12.10 Test Plan

| Test | Verify |
|------|--------|
| Default (off) | No banner, all tracking, no localStorage |
| Auto-consent | No banner, all tracking, localStorage receipt on interaction |
| Banner (no auto) | Banner shown, no tracking until visitor makes a choice |
| Banner + auto | Banner shown, tracking immediate, visitor can toggle categories |
| Category gating | Reject "Clicks" → click beacons dropped; accept "Analytics" → pageviews send |
| Banner persistence | Banner renders when Show Banner is checked |
| Collapse button | Clicking collapse repositions banner out of the way; page content underneath is clickable |
| Return button | Clicking return brings banner back to original position |
| Collapse state persists | On next page load, collapsed state is remembered via localStorage |
| localStorage blocked | With localStorage blocked, consent falls back to sessionStorage; choices persist for tab session only |
| AJAX fallback | jQuery sync AJAX path applies same consent gating as sendBeacon path |
| DNT/GPC | `doNotTrack=1` or `globalPrivacyControl=true` exits tracker before consent logic |
| Privacy disclosure | Shortcode reflects actual consent mode |
| Settings save | New options save correctly via `save_settings()` with proper sanitization |
| Uninstall cleanup | Consent options are deleted when plugin is uninstalled |

---

## 13. Comprehensive Test Coverage Audit (June 2026)

Systematic audit of all source files against test suite identified 13 major gaps across P1–P4 priority. **11 of 13 gaps now covered** (84 new tests, 201 assertions). Full details in `TODO.md` §6.

### Coverage Summary

| Area | Files | Has Tests | Coverage Quality | Gaps |
|------|-------|-----------|------------------|------|
| REST API | `class-rest-api.php` | ✅ 160+ | Shape + auth + CORS + AI gating | App Password auth (covered by RestAuthTest), CORS preflight, origin validation |
| Analytics | `class-analytics.php` | ✅ 55+ | Key/shape + filters + sorts + fill_date_gaps + timezone | `get_path_flow()` requires MySQL 8.0+ window functions |
| Tracker | `class-tracker.php` | ✅ 30+ | Unit: sanitize, bot signals; Integration: rate limiting, session upsert | Session upsert edge cases |
| DB | `class-db.php` | ✅ 45+ | Schema, prune, aggregate, uninstall, on_new_blog | Network-wide activate (multisite env not available) |
| Admin | `class-admin.php` | ✅ 35+ | Capabilities, roles, template rendering, XSS, settings save | Network dashboard aggregation |
| WooCommerce | `class-woocommerce.php` | ✅ 20+ | insert_event, funnel counts | REST ingest pipeline (covered by MetricPipelineTest) |
| Email | `class-email.php` | ✅ 23+ | Scheduling, return values, HTML content, recipients | MIME multipart structure (header validated) |
| Consent Banner | `class-consent-banner.php` | ✅ 27 | CSS injection, options, gating, uninstall, privacy disclosure | — |
| Heatmap | `class-heatmap.php` | ✅ 7 | Coordinate bucketing, aggregation, REST shape, NULL exclusion | — |
| Security | — | ✅ 14 | SQLi, XSS, path traversal, CSRF, session spoofing, bot score | — |
| Templates | `templates/admin/*.php` (16 files) | ✅ 15 | Output capture, premium gating, XSS escaping, permissions | Network views (multisite) |
| Uninstall | `uninstall.php` | ✅ 4 | Single-site table drops, option deletion, missing-table edge case | Multisite uninstall (env limitation) |
| E2E | `tests/e2e/*.js` (4 files, 55 tests) | ✅ 55 | Shell, add site, nav, views | Consent, WooCommerce, AI chat, export, offline |

### Priority Matrix

| Priority | Count | Status | Risk if Untested |
|----------|-------|--------|------------------|
| **P1** | 3 gaps | ✅ **Complete** | GDPR non-compliance, security vulnerabilities, broken admin UX |
| **P2** | 3 gaps | ✅ **Complete** | Premium features broken, abuse vectors, data left on uninstall |
| **P3** | 3 gaps | ✅ **Complete** | Wrong analytics, broken emails, unauthorized API access |
| **P4** | 4 gaps | 2 ✅ / 2 ⏳ | Enterprise issues, AI gating bypass, user-facing regressions, broken releases |

**Bugs found and fixed during test writing:**
1. `class-analytics.php:1087` — `get_heatmap()` `GROUP BY` used raw columns instead of computed `ROUND(x_pct/2)*2` buckets
2. `class-rest-api.php:719` — `ai_tool()` declared `WP_REST_Response` return type but returned `WP_Error` for invalid tools
