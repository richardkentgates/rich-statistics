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
| Deploy mechanism | ⚠️ Undocumented (cron or manual) | ⚠️ Undocumented (cron or manual) | ⚠️ Undocumented (cron or manual) |
| Deploy daemon (systemd) | ❌ Not installed | ❌ Not installed | ❌ Not installed |
| Web root ownership | `richardkentgates:www-data` | `richardkentgates:www-data` | `richardkentgates:www-data` |
| APT repository | ✅ Present | ✅ Present | ✅ Present |
| vhost `/apt/` alias | ✅ Present | ✅ Present (SSL only) | ✅ Present |
| `dist/update.json` | ✅ Present (sig: populated by CI) | ✅ Present (sig: populated by CI) | ✅ Present (sig: populated by CI) |
| `v/` version snapshots | ✅ Channel-subdir format (12 versions) | ✅ Channel-subdir format | ✅ Channel-subdir format |
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
| Plugin Code | ✅ Good | 0 | 0 | 0 | 5 |
| CI/CD | ✅ Good | 0 | 0 | 0 | 3 |
| Server Infra | ⚠️ Needs work | 0 | 0 | 0 | 2 |
| PWA | ✅ Good | 0 | 0 | 0 | 3 |
| Desktop App | ✅ Good | 0 | 0 | 0 | 2 |
| Documentation | ✅ Good | 0 | 0 | 0 | 6 |
| Database | ✅ Good | 0 | 0 | 0 | 5 |
| Tests | ⚠️ Needs work | 0 | 1 | 0 | 7 |
| **TOTAL** | | **0** | **1** | **0** | **27** |

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
| C7 | PWA | Root `sw.js` cache name stale (`rsa-2-4-19`) | ✅ Fixed — bumped to `rsa-2-4-20` |
| M2 | CI/CD | Chart.js SRI hash verification disabled | ✅ Fixed — enforced via `docs/app/chart.sri` |
| M25 | Server | Dev/test webhooks don't validate Content-Type | ✅ Fixed — matches production behavior |
| P2.1 | Server | Install systemd deploy daemon | ⏳ Created, not installed |
| P2.2 | PWA | Backfill missing version snapshots | ⏳ 5 versions missing |
| P2.3 | Server | Clean up old Windows binary names | ⏳ Old `Rich Statistics_*.exe` files in prod `dist/` |
| P2.4 | CI/CD | Post-deploy smoke tests | ⏳ Not implemented |
| P2.5 | CI/CD | `build-release.yml` tag/main divergence | ⏳ Desktop builds on tag, snapshots committed to main |
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
| **Production** | `104.197.231.120` | `/var/www/rs-app/public_html/` | v2.4.16 (`main`) | ✅ 6 entries | ✅ Present |
| **Dev** | `104.197.231.120` | `/var/www/rs-app-dev/` | v2.4.1 (`develop`) | ✅ 4 entries | ⚠️ Verify present |
| **Test (PWA)** | `104.197.231.120` | `/var/www/rs-app-test/` | v2.4.1 (`test`) | ✅ 4 entries | ⚠️ Verify present |
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
- `released` — stable tags (`v2.4.20`)
- `beta` — beta tags (`v2.4.20-beta.1`) and test branch builds

**Manual usage:**
```bash
php bin/deploy-freemius.php <file_name> <version> <release_mode> [sandbox]
# Example:
php bin/deploy-freemius.php rich-statistics-2.4.20.zip 2.4.20 released
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

### Working But Needs Attention (Yellow)

| # | Issue | Severity | Details |
|---|-------|----------|---------|
| 1 | Deploy mechanism is a "ghost" | 🔶 MEDIUM | Systemd daemon created but **not installed**. Old cron scripts exist but no system cron entries found. Deploys work but mechanism is undocumented and unmonitored. No `journalctl` logs. |
| 2 | Version parity gaps | 🔶 MEDIUM | 5 versions missing from `docs/app/v/`: `2.4.20`, `2.4.22`, `2.4.23`, `2.4.25`, `2.4.26`. Desktop app users on missing versions get incompatible fallback snapshots. |
| 3 | Windows binary naming inconsistency | 🔶 LOW | Prod `dist/` has both `Rich Statistics_2.4.1_x64-setup.exe` (old Tauri default) and `rich-statistics-windows.exe` (new CI naming). `update.json` points to new name; old files are orphaned clutter. |
| 4 | No post-deploy verification | 🔶 MEDIUM | After webhook ping, CI considers deploy "done." No smoke test confirms the server is actually serving the new version. A webhook handler failure goes unnoticed. |
| 5 | Test branch Freemius pollution | 🔶 LOW | Every `promote-test` → `build-test` run uploads to Freemius as `beta`. Acceptable for now — `beta` release_mode is designed for this. |

### At Risk (Red)

| # | Issue | Severity | Details |
|---|-------|----------|---------|
| 6 | `build-release.yml` tag vs. main divergence | 🔴 HIGH | `release` job commits PWA snapshots to `main`. `build-desktop` (needs: release) runs on the **tag ref**, which never contains those snapshots. It recreates them locally via `stamp-version`. If the recreation logic ever diverges from the commit logic, the desktop bundle and web-served PWA could be different files for the same version. |
| 7 | No automated recovery for failed releases | 🔴 MEDIUM | If `build-release.yml` fails mid-run (e.g., Freemius upload succeeds but desktop build fails), there's no automatic rollback or retry. Partial releases — GitHub Release exists but binaries missing, or vice versa. |

### Recommendations (Priority Order)

| Priority | Action | Impact |
|----------|--------|--------|
| **P1** | Install systemd daemon on all 3 servers and remove old cron scripts | Eliminates hidden deploy mechanism, adds `journalctl` logging |
| **P1** | Backfill missing PWA snapshots (2.4.20, 2.4.22, 2.4.23, 2.4.25, 2.4.26) | Fixes version parity for desktop app users |
| **P2** | Add post-deploy smoke test to all 3 build workflows | Catches deploy failures immediately |
| **P2** | Clean up old Windows binary names on prod server | Reduces confusion, saves disk |
| **P2** | Make `build-desktop` in `build-release.yml` use the main branch commit with snapshots | Prevents tag/main divergence |
| **P3** | Add a "health check" job to each build that verifies the app server responds | Observability |
| **P3** | Document the disaster recovery procedure for failed releases | Operational readiness |
