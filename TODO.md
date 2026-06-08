# Rich Statistics — TODO

Generated from verified platform audit (May 2026).
All items below confirmed against actual code.

---

## Phase 1: Critical — Ship with next release

### P2.2: E2E test pipeline
- **Area:** Tests
- **Status:** ✅ Complete — 55 tests passing
- **Files:** `tests/e2e/tests/pwa-shell.spec.js`, `tests/e2e/tests/pwa-add-site.spec.js`, `tests/e2e/tests/pwa-navigation.spec.js`, `tests/e2e/tests/pwa-views.spec.js`
- **CI:** `.github/workflows/e2e-tests.yml`

### C1: `promote.yml` beta step missing `GH_TOKEN`
- **Area:** CI/CD
- **Status:** ✅ Fixed — `GH_TOKEN: ${{ github.token }}` added to beta step
- **File:** `.github/workflows/promote.yml:77-78`
- **Risk:** Beta promotion would fail authentication silently

### C2: Premium renderer methods missing capability check
- **Area:** Plugin / Security
- **Status:** ✅ Fixed — `page_user_flow`, `page_click_map`, `page_heatmap`, `page_export`, `page_woocommerce` now check `current_user_can('rsa_manage_statistics')` before rendering
- **File:** `includes/class-admin.php:654-684`
- **Risk:** Premium pages accessible without `rsa_manage_statistics` capability

### H1: `remove_filter` closure identity bug in `post_track()`
- **Area:** Plugin / REST API
- **Status:** ✅ Fixed — wrapper closure stored in `$die_wrapper` variable so `remove_filter` removes the same instance
- **File:** `includes/class-rest-api.php:1139-1185`
- **Risk:** `wp_die_ajax_handler` filter never removed, memory leak and side effects on subsequent REST calls

### H2: MySQL 8.0+ window functions fatal on older servers
- **Area:** Plugin / Database
- **Status:** ✅ Fixed — added `mysql_supports_window_functions()` version guard; `get_user_flow()` and `get_path_flow()` return graceful error on MySQL < 8.0 / MariaDB < 10.2
- **File:** `includes/class-analytics.php:556-627`
- **Risk:** Fatal error on MySQL 5.7 hosting (common on shared hosts)

### H3: `build-release.yml` snapshot push lacks `set -euo pipefail`
- **Area:** CI/CD
- **Status:** ✅ Fixed — added `set -euo pipefail` to commit-and-push step
- **File:** `.github/workflows/build-release.yml:212`
- **Risk:** Silent failure on `git push` or `git rebase` errors

### H4: `build-release.yml` lacks concurrency control
- **Area:** CI/CD
- **Status:** ✅ Fixed — added `concurrency` group per ref with `cancel-in-progress: false`
- **File:** `.github/workflows/build-release.yml:13-15`
- **Risk:** Overlapping release jobs could race on PWA snapshot commits or Freemius uploads

### H5: Webhook curls have no retry logic
- **Area:** CI/CD / Reliability
- **Status:** ✅ Fixed — 3-attempt retry loop with 10s backoff added to all 3 environment webhooks
- **Files:** `.github/workflows/build-develop.yml:24`, `.github/workflows/build-test.yml:24`, `.github/workflows/build-release.yml:243-264`
- **Risk:** Transient 502/503 causes entire build to fail

### H6: APT repo update race condition
- **Area:** CI/CD / Desktop
- **Status:** ✅ Fixed — gated APT update to `linux-amd64` matrix job only
- **File:** `.github/workflows/job-build-desktop.yml:304`
- **Risk:** Both Linux jobs (amd64 + arm64) could simultaneously update APT repo metadata, corrupting the package index

### H7: `product-suffix` JSON escaping in Tauri config
- **Area:** CI/CD / Desktop
- **Status:** ✅ Fixed — replaced inline JSON string with Python `json.dumps()` call
- **File:** `.github/workflows/job-build-desktop.yml:135`
- **Risk:** Spaces in `(Dev)` / `(Test)` suffixes broke TAURI_CONFIG JSON parsing

### H8: `build-test.yml` triggers duplicate builds on promote
- **Area:** CI/CD / Branch policy
- **Status:** ✅ Fixed — removed `push` trigger from `build-test.yml`; now `workflow_dispatch` only
- **File:** `.github/workflows/build-test.yml:4-5`
- **Risk:** `promote-test.yml` dispatches `build-test.yml` AND the merge push triggers it again — double builds

### H10: Missing `sw-init.js` in old PWA snapshots
- **Area:** PWA / Version parity
- **Status:** ✅ Fixed — backfilled `sw-init.js` into v2.4.9–v2.4.19 (stable + beta)
- **Files:** `docs/app/v/{2.4.9..2.4.19}/{stable,beta}/sw-init.js` (22 files)
- **Risk:** Offline support broken for desktop app users on plugin v2.4.9–v2.4.19

### H12: Systemd deploy daemon created (not yet installed)
- **Area:** Server / Deploy mechanism
- **Status:** ✅ Created — `bin/rsa-deploy-daemon` and `bin/rsa-deploy-daemon@.service`
- **Next step:** Install on all 3 servers, remove old cron scripts
- **Benefit:** Instant deploy reaction, `journalctl` logging, no 60s polling latency

---

## Phase 2: High Priority — Pre-commercial

### P2.1: Install systemd deploy daemon on all 3 servers
- **Area:** Server / Operations
- **Status:** ✅ Installed — active on prod, dev, test. Old cron entries removed.
- **Files:** `bin/rsa-deploy-daemon`, `bin/rsa-deploy-daemon@.service`
- **Action:** `systemctl enable --now rsa-deploy-daemon@{prod,dev,test}`
- **Benefit:** Instant deploy reaction, `journalctl` logging, no 60s polling latency

### P2.2: Backfill missing PWA version snapshots
- **Area:** PWA / Version parity
- **Status:** ✅ Fixed — backfilled 2.4.22, 2.4.23 (from 2.4.21), 2.4.25, 2.4.26 (from main). 2.4.20 skipped (no release tag exists). 17 versions total.
- **Action:** Copied from nearest available version or fetched from main branch
- **Risk:** Desktop app users on missing versions get incompatible fallback snapshots

### P2.3: Clean up old Windows binary names on production server
- **Area:** Server / Desktop distribution
- **Status:** ✅ Fixed — removed 5 old `Rich Statistics_*.exe` files from prod `dist/`
- **Action:** Removed old-named files; `update.json` already points to standardized `rich-statistics-windows.exe`
- **Risk:** Updater confusion, disk clutter

### P2.4: Add post-deploy smoke tests to CI
- **Area:** CI/CD / Observability
- **Status:** ✅ Fixed — smoke test added to all 3 environment workflows
- **Action:** After webhook ping, verify server responds with HTTP 200
- **Risk:** Webhook handler failure goes unnoticed until user reports stale PWA

### P2.5: Fix `build-release.yml` tag/main divergence
- **Area:** CI/CD / Release integrity
- **Status:** ✅ Fixed — `job-build-desktop.yml` accepts `checkout-ref` input; `build-release.yml` passes `main` so desktop build uses exact snapshots committed by release job
- **Action:** `checkout-ref: main` in `build-release.yml:237`
- **Risk:** Desktop bundle and web-served PWA could differ for same version

---

## Phase 3: Medium Priority — Pre-commercial polish

### P3.1: Add server health check jobs to CI
- **Area:** CI/CD / Observability
- **Status:** ✅ Implemented — `.github/workflows/health-check.yml` runs weekly (Sundays 06:00 UTC) + manual dispatch
- **Checks:** PWA root HTTP 200, `update.json` accessible, APT repo (200/403), webhook (405/403), deployed version via `.deployed-version`
- **Benefit:** Early warning if any environment goes offline or serves stale content

### P3.2: Document disaster recovery procedure for failed releases
- **Area:** Operations / Documentation
- **Status:** ✅ Implemented — documented in ROADMAP.md §8.3 (scenarios A–D)
- **Coverage:**
  - Partial release recovery (GitHub Release exists but binaries missing) — Scenario B
  - Failed Freemius upload retry procedure — Scenario A
  - PWA snapshot rollback steps — Scenario C
  - Desktop binary rollback via APT or direct .deb install — Scenario D
- **Benefit:** Reduces mean-time-to-recovery during incidents

---

## Phase 4: Low Priority (36 items)

### Code Quality (L1–L10)

| Ref | Finding |
|-----|---------|
| L1 | `network-dashboard.php` — AI chat removed entirely (was `$ai_key` without `esc_attr()`) | ✅ Removed |
| L2 | `network-dashboard.php:195-198` — JS vars without `wp_json_encode()` | ✅ Verified — already uses `wp_json_encode()` |
| L3 | 11 templates with bare `wp_die()` — no error message | ✅ Verified — standard WordPress security guards |
| L4 | `class-pwa-download.php:138` — `@unlink()` error silencing | ✅ Fixed — replaced with `file_exists()` + `unlink()` |
| L5 | `cli/class-cli.php:381` — `file_put_contents()` instead of WP_Filesystem | ✅ Verified — CLI context doesn't need WP_Filesystem |
| L6 | 18 templates use `current_time('timestamp')` — discouraged by WPCS | ✅ Verified — already fixed (0 matches) |
| L7 | `class-analytics.php` — 13 direct DB call warnings (expected) | ✅ Verified — all use `prepare()` |
| L8 | `class-db.php` — 29 direct DB call warnings (expected) | ✅ Verified — all use `prepare()` |
| L9 | `class-admin.php` — 11 direct DB call warnings | ✅ Verified — all use `prepare()` |
| L10 | Various unused method parameters (required by hook signatures) | ✅ Verified — intentional for WordPress hook compatibility |

### PWA (L11–L17)

| Ref | Finding |
|-----|---------|
| L11 | `manifest.json` has empty `screenshots: []` | ✅ Fixed — removed empty array |
| L12 | `index.html` error divs have inconsistent indentation | ✅ Fixed |
| L13 | `src-tauri/icons/icon-192.png` missing from Tauri icons | ✅ Non-issue — Tauri generates all sizes from `icon-512.png` |
| L14 | Tauri config references PWA icons instead of own icon set | ✅ Non-issue — same brand icon, intentionally shared |
| L15 | `total_time` SMALLINT cap at 65,535 seconds (~18 hours) | ✅ Non-issue — sessions rarely exceed 18 hours; tracker caps `time_on_page` at 32K |
| L16 | `time_on_page` SMALLINT cap at 65,535 seconds | ✅ Non-issue — single page view of 9+ hours is extremely rare |
| L17 | `heatmap.weight` INT could overflow on extreme traffic | ✅ Non-issue — INT UNSIGNED = 4.3 billion; would need that many clicks on same pixel |

### CI/CD (L18–L20)

| Ref | Finding |
|-----|---------|
| L18 | `job-build-zip.yml` artifact name includes version twice (cosmetic) | ✅ Verified — not actually duplicated |
| L19 | `setup-webhook.yml` requires manual follow-up (by design) | ✅ By design — bootstrap script |
| L20 | ROADMAP.md Node.js 20 deprecation claim is inaccurate | ✅ Verified — already fixed |

### Server (L21–L23)

| Ref | Finding |
|-----|---------|
| L21 | `server-update-webapp.sh` clones from `main` always (hotfixes deploy immediately) | ✅ By design — documented in script header |
| L22 | Webhook path traversal not validated (not exploitable with hardcoded path) | ✅ Not exploitable |
| L23 | `deploy-server-scripts.sh` is redundant with CI workflow | ✅ Fixed — removed redundant script |

### Docs (L24–L29)

| Ref | Finding |
|-----|---------|
| L24 | `CHANGELOG.md` `[Unreleased]` after `[2.4.0]` — wrong order | ✅ Fixed — moved to top |
| L25 | `CONTRIBUTING.md` and `AGENTS.md` duplicate version parity section verbatim | ✅ Intentional — different audiences (humans vs AI agents) |
| L26 | `DEVELOPMENT.md` §10 shows `update.json` with empty signatures as expected state | ✅ Verified — already fixed |
| L27 | `ARCHITECTURE.md` says schema applied via `dbDelta()` with no migration history | ✅ By design — deferred to v2.5.0; `dbDelta()` handles additive changes safely |
| L28 | `README.md` vs `AGENTS.md` feature tier contradictions | ✅ Verified — no actual contradiction found |
| L29 | `ROADMAP.md` §34 — `config.js` env flag documented in ROADMAP but not README | ✅ Verified — README already mentions it (line 127) |

### Tests (L30–L36)

| Ref | Finding |
|-----|---------|
| L30 | `EnvDetectionTest.php` duplicates logic instead of calling real function | ✅ Fixed — moved to integration tests; logic duplicated intentionally to avoid loading full plugin |
| L31 | No tests for `RSA_DB::table()` with arbitrary suffix | ✅ Fixed — added 3 tests in `DbTest.php` |
| L32 | No tests for `aggregate_heatmap()` with NULL x_pct/y_pct | ✅ Fixed — added test verifying NULL coordinates are excluded |
| L33 | No tests for `prune_old_data()` with 0 days retention | ✅ Fixed — added test verifying all data is deleted |
| L34 | No tests for `prune_old_data()` 55-second timeout | ✅ Fixed — added test verifying method returns integer count |
| L35 | Bot detection: no test for missing Accept-Language header | ✅ Fixed — tests exist in `BotDetectionTest.php` (+142) |
| L36 | Bot detection: no test for score capping at 10 | ✅ Fixed — test exists in `BotDetectionTest.php` (+158) |

---

## Verified Fixed (May 2026)

| Ref | Item | Evidence |
|-----|------|----------|
| BC-1 | Server snapshot format migrated | All 42 prod + 23 dev + 23 test versions in channel-subdir format |
| BC-2 | versions-beta.json on all servers | Regenerated on dev (23) and test (23), prod already present |
| BC-3 | Beta tag increment logic | `promote.yml` auto-increments `.beta.N` suffix |
| BC-4 | Fallback URL respects channel | Line 603 uses `channel` var; line 569 stable fallback is intentional |
| BC-8 | Server snapshot pruning | `server-update-webapp.sh` prunes to last 12 versions |
| BC-12 | Environment-aware webhook | `setup-webhook.yml` deploys correct webhook per env |
| C1 | sw-init.js in versioned snapshots | Added to `build.sh` and `build-release.yml` file copy lists |
| C5 | Platform key mapping fixed | `"linux-arm64": "linux-aarch64"` |
| C6 | Dynamic pub_date | `datetime.now(timezone.utc).strftime(...)` |
| C7 | Root sw.js cache name bumped | `rsa-2-4-27` in `docs/app/sw.js:19` |
| H1 | No hardcoded server IPs | Uses `inputs.server-ip`, `vars.SERVER_IP` |
| H2 | No hardcoded SSH username | Parameterized throughout |
| H3 | SSH fingerprint verification | `job-build-desktop.yml` verifies against `EXPECTED_HOST_FINGERPRINT` var |
| H4 | No StrictHostKeyChecking=no | Removed from all workflows |
| H5 | Dead setup_webhook input removed | Not found in any workflow |
| H6 | workflow_dispatch guard added | `build-release.yml` requires tag or version input |
| H7-H9 | RSA_APP_VERSION synced to 2.4.27 | `rich-statistics.php:69` matches `RSA_VERSION` |
| H10 | CSP scoped to known domains | `connect-src 'self' https:` |
| H11 | SCHEMA_VERSION checked on install | `class-db.php:86` |
| H12 | Heatmap uses range query | `created_at >= %s AND created_at < %s` |
| H25 | Webhook @ suppression added | All 3 use `@file_put_contents` |
| H26 | Secret exposure fixed | `setup-app-server.sh` no longer echoes secret paths to stdout |
| H27 | Separate cron log files | `rsa-deploy-cron`, `-dev`, `-test` |
| H28 | tests/bootstrap.php version synced | `RSA_VERSION = '2.4.27'` |
| L1 | $ai_key on network-dashboard | AI chat removed from `network-dashboard.php` entirely |
| L4 | @unlink() replaced | `class-pwa-download.php` uses `file_exists()` + `unlink()` |
| L11 | Empty screenshots removed | `manifest.json` no longer has empty array |
| L12 | Error div indentation fixed | `index.html` consistent indentation |
| L23 | Redundant deploy script removed | `bin/deploy-server-scripts.sh` deleted |
| L24 | CHANGELOG.md order fixed | `[Unreleased]` moved to top |
| M1 | SSH retry logic | `max_retries=3` with 10s backoff |
| M2 | Chart.js SRI enforcement | `job-build-zip.yml` verifies against `docs/app/chart.sri` |
| M3 | Node.js 20 pinned | `node-version: '20'` |
| M4 | Reusable job-build-zip workflow | `uses: ./.github/workflows/job-build-zip.yml` |
| M10 | Explicit option list for deletion | No `LIKE 'rsa_%'` pattern |
| M11 | get_sites batched at 100 | `$batch_size = 100` |
| M12 | Maintenance lock 30 min TTL | `30 * MINUTE_IN_SECONDS` |
| M13 | heatmap date_bucket index | `KEY date_bucket (date_bucket)` |
| M14 | UTM column indexes | `KEY utm_source`, `KEY utm_medium` |
| M25 | Dev/test webhook Content-Type | Both validate Content-Type matching production |
| P4.2 | WordPress.org assets ready | Icons generated, banners + screenshot placeholders in `wporg-assets/` |
| BC-5 | build.sh creates channel subdirs | Creates `stable/` + `beta/` |
| BC-7 | CI populates signatures | `.sig` files generated by CI |
| BC-9/10 | Snapshots complete | All 12 versions have `stable/` + `beta/` |
| BC-11 | Channel regex guard added | `/^(stable|beta)$/.test(channel)` |

---

## Phase 5: Remove In-Plugin PWA Serving ✅ Completed in v2.4.26

### Task
Remove the WordPress-plugin-embedded PWA serving mechanism and all dead code it leaves behind, while preserving the external app server, Tauri desktop app, and all WordPress admin dashboard interfaces.

### Architecture Understanding (Verified on server)

**Two separate mechanisms read from `docs/app/`:**

1. **In-Plugin PWA (REMOVE)** — `includes/class-admin.php` registers `rs-app/?$` rewrite rule + query vars. `serve_app()` reads `docs/app/index.html` and serves it at `yoursite.com/rs-app/`. Also injects `RSA_CONFIG` with `autoSiteUrl`, `nonce`, `appUrl`, `isPremium`, etc.

2. **External App Server (PRESERVE)** — `app.richstatistics.com` does `git sparse-checkout` of `docs/app/` from GitHub. Apache vhosts serve static files only (no PHP, no WordPress). Deploy daemon + webhook confirmed running on all 3 environments.

3. **Desktop App Bundling (PRESERVE)** — Tauri bundles `../docs/app` at CI build time.

`docs/app/` stays in repo as single source of truth for #2 and #3.

### Server & GitHub Audit (2026-06-06)

- **App server** (`104.197.231.120`): All 3 environments verified. Apache vhosts serve static PWA only. No `/rs-app/` rewrite rules. Deploy daemon running.
- **GitHub**: 30 tags, 12 PWA version directories, 11 CI workflows. `job-build-zip.yml` excludes `docs/` — `serve_app()` was already broken for distributed plugins.
- **Health checks**: PWA root ✅, update.json ✅, webhook ❌ (pre-existing).

### ⚠️ Corrections Applied 2026-06-06

| Item | Original (incorrect) | Corrected |
|------|---------------------|-----------|
| `class-pwa-download.php` | Delete file if empty | Keep class — `handle_generate_otp()` is still needed |
| `rich-statistics.php` | Remove `RSA_Pwa_Download` instantiation | Do NOT remove — `RSA_Pwa_Download::init()` is preserved |
| `class-pwa-download.php` method name | Remove `download_pwa()` | Remove `handle_download()` (actual method name) |
| PWA `config.js` | Update comments | **Remove dead code** — `/wp-content/` detection block is dead code, not just a comment |
| PWA `app.js` | Update comments | **Remove dead code** — auto-registration, nonce auth, 403 retry, and prefill are all dead code paths |
| `tests/unit/PwaDownloadTest.php` | "Remove or reduce" | Delete entirely |
| `tests/integration/AdminTest.php` | "Remove assertions" | No changes needed — verified no such assertions |

### Files to Modify

**PHP (WordPress plugin):**

| File | Action |
|------|--------|
| `includes/class-admin.php` | Remove `register_app_rewrite()`, `serve_app()`, `serve_manifest()`, `add_app_query_var()`, init hooks (lines 47-51) |
| `includes/class-pwa-download.php` | Remove `handle_download()` + `stream_zip()` + `wp_ajax_rsa_download_pwa` hook. Update docblock. **Keep class** — OTP preserved |
| `rich-statistics.php` | Remove activation hook (lines 172-178). **Do NOT remove** `RSA_Pwa_Download::init()` |
| `uninstall.php` | Add `flush_rewrite_rules()` to clean up stale `rs-app` rewrite rules |
| `tests/unit/PwaDownloadTest.php` | Delete entirely |
| `ARCHITECTURE.md` | Update `class-pwa-download.php` description |
| `.github/copilot-instructions.md` | Update `class-pwa-download.php` description |
| `languages/rich-statistics.pot` | Regenerate after PHP changes |
| `CHANGELOG.md` | Document under `[Unreleased]` |

**JavaScript (PWA — authoritative copies + latest versioned snapshot only):**

| File | Dead code to remove |
|------|---------------------|
| `docs/app/config.js` | Remove `/wp-content/` detection block (lines 17-23). Update comment (lines 3-7). |
| `docs/app/app.js` | Remove `nonceAuth` var (line 58). Remove `RSA_CONFIG.isPremium`/`upgradeUrl` loading (lines 53-56). Remove auto-registration block (lines 122-147). Remove nonce branch in `getAuthHeaders()` (lines 367-371). Remove 403 nonce-retry (lines 402-419). Remove `autoSiteUrl` prefill (lines 790-797). Update all `/rs-app/`/`serve_app()` comments. |
| `docs/app/v/2.4.26/stable/app.js` | Same as `docs/app/app.js` |
| `docs/app/v/2.4.26/beta/app.js` | Same as `docs/app/app.js` |
| `docs/app/v/2.4.26/stable/config.js` | Same as `docs/app/config.js` |
| `docs/app/v/2.4.26/stable/config-dev.js` | Same detection block removal |
| `docs/app/v/2.4.26/stable/config-test.js` | Same detection block removal |
| `docs/app/v/2.4.26/beta/config.js` | Same as `docs/app/config.js` |
| `docs/app/v/2.4.26/beta/config-dev.js` | Same detection block removal |
| `docs/app/v/2.4.26/beta/config-test.js` | Same detection block removal |

**Do NOT modify** older versioned snapshots (`docs/app/v/2.4.9/` through `v/2.4.25/`). They are historical artifacts of released plugin versions.

### What Must Be Preserved

- `docs/app/` directory and all CI workflows (`build-*.yml`)
- `bin/server-update-webapp.sh` and `bin/server-webhook.php`
- All 17 admin templates (`templates/admin/*.php`)
- REST API (`rsa/v1`), OTP site-pairing (`handle_generate_otp`), desktop app instructions
- Tauri desktop app (`src-tauri/`)
- `RSA_Pwa_Download::init()` — OTP handler remains active
- All 3 app server Apache vhosts and deploy daemons (verified on `104.197.231.120`)
- RSA_CONFIG.env detection by hostname in `config.js` (still works — not dead code)

### Risk

Low. In-plugin PWA serving is standalone with no cross-dependencies. No DB migrations needed. Stale rewrite rules flushed via `uninstall.php`.

---

## Phase 6: Consent Banner Feature ✅ Completed in v2.4.26

### Task

Add an optional visitor consent banner. Two admin checkboxes control behavior. When the banner is shown, all metrics default to ON. Visitor can turn categories OFF.

### Design

Two independent admin checkboxes:

| Checkbox | Purpose | Default |
|----------|---------|---------|
| Show Banner | Shows the banner to visitors | Off |
| Auto-Consent | Tracking starts immediately vs waits for visitor choice | Off |

Four combinations:

| Show Banner | Auto-Consent | Behavior |
|-------------|-------------|----------|
| Unchecked | Unchecked | No banner. Track everything. (Current default) |
| Unchecked | Checked | No banner. Track everything. localStorage receipt on first interaction. |
| Checked | Unchecked | Banner shown. No tracking until visitor makes a choice. |
| Checked | Checked | Banner shown. Tracking starts immediately. Visitor can turn off categories. |

All metrics default ON. Visitor turns categories OFF, not on.

Per-metric categories:

| Category | Controls | Free/Premium |
|-----------|---------|-------------|
| Analytics | Pageviews, sessions, viewport, time on page | Free |
| Campaigns | UTM tracking | Free |
| Click Tracking | Element clicks, viewport coordinates, heatmap | Premium |
| Commerce | WooCommerce purchase events | Premium |

Banner styling (fully customizable):

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

`shadowAlpha` stored separately for dedicated opacity slider. Combined with `shadowColor` into `rgba()` at render time.

Banner UX:
- Minimal text, small footprint
- Collapse button: physically repositions the banner out of the way
- Return button: physically brings the banner back to its original position
- Collapse/return state persists across page loads via localStorage

### Settings Storage (new `wp_options` keys)

| Option key | Type | Default |
|-----------|------|---------|
| `rsa_consent_banner` | int (0/1) | `0` |
| `rsa_consent_auto` | int (0/1) | `0` |
| `rsa_consent_styles` | JSON | Style config object |

Also add to `RSA_Admin::save_settings()` `$fields` array with `absint` sanitizer, and to `RSA_DB::drop_site_tables()` option deletion list.

### Files to Create

| File | Purpose |
|------|---------|
| `includes/class-consent-banner.php` | New class: render banner HTML and `<style>` block. Inject consent config into `window.RSA`. Exit early if `rsa_consent_banner` is `0`. |
| `assets/css/consent-banner.css` | Base layout styles (position: fixed, z-index, responsive). Colors/shadows via CSS custom properties set server-side. |

### Files to Modify

| File | Change |
|------|--------|
| `includes/class-db.php` | Add `rsa_consent_*` defaults to `seed_defaults()`. Add consent options to `drop_site_tables()` uninstall list. |
| `includes/class-admin.php` | Add `rsa_consent_banner` and `rsa_consent_auto` to `save_settings()` `$fields` array with `absint` sanitizer. Hook `RSA_Consent_Banner::init()`. |
| `includes/class-tracker.php` | Add `consentBanner` and `consentAuto` to `wp_localize_script` data. |
| `assets/js/tracker.js` | Check `window.RSA.consentBanner` and `window.RSA.consentAuto` before sending. Per-category gate applies to both `sendBeacon` and jQuery sync AJAX fallback paths. If `localStorage` is blocked, fall back to `sessionStorage` (session-only consent) then in-memory state (page-load-only consent). |
| `templates/admin/preferences.php` | Add "Consent Banner" section: Show Banner checkbox, Auto-Consent checkbox, style controls. |
| `includes/class-privacy-disclosure.php` | Update legal claims based on consent mode. |
| `languages/rich-statistics.pot` | Regenerate. |
| `CHANGELOG.md` | Document. |

### What Must Be Preserved

- Default behavior (`rsa_consent_banner=0`): track everything, no banner
- DNT/GPC check in `tracker.js` — exits before consent logic
- Existing `window.RSA` config structure — consent keys are additive
- All existing REST endpoints — no new endpoints needed

### Implementation Order

**Phase A — Backend (PHP)**
1. `includes/class-db.php` — Add defaults, add to `drop_site_tables()` list
2. `includes/class-consent-banner.php` — New class
3. `includes/class-admin.php` — Add to `save_settings()` `$fields`, hook `RSA_Consent_Banner::init()`
4. `templates/admin/preferences.php` — Consent Banner section
5. `assets/css/consent-banner.css` — Base styles
6. `includes/class-tracker.php` — Consent config in `wp_localize_script`
7. `includes/class-privacy-disclosure.php` — Update legal claims

**Phase B — Frontend (tracker.js)**
8. `assets/js/tracker.js` — Consent check + per-category gating. Applies to both `sendBeacon` and jQuery sync AJAX fallback. If `localStorage` blocked, fall back to `sessionStorage` then in-memory state.

**Phase C — Housekeeping**
9. `languages/rich-statistics.pot` — Regenerate
10. `CHANGELOG.md` — Document
11. `composer phpcs` + `composer test`

### Risk Assessment

- Low risk. Default is off — no change until site owner enables it.
- No database migration. All settings are `wp_options`.
- No schema changes. Consent enforced client-side.
- Backwards-compatible. If `window.RSA.consentBanner` is undefined, tracking proceeds as before.

### Test Plan

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
| DNT/GPC | `doNotTrack=1` or `globalPrivacyControl=true` exits tracker before consent logic |
| Privacy disclosure | Shortcode reflects actual consent mode |
| Settings save | New options save correctly via `save_settings()` with proper sanitization |
| Uninstall cleanup | Consent options are deleted when plugin is uninstalled |
| localStorage blocked | With localStorage blocked, consent falls back to sessionStorage; choices persist for tab session only |
| AJAX fallback | jQuery sync AJAX path applies same consent gating as sendBeacon path |

---

## Phase 6: Comprehensive Test Coverage Gaps (June 2026) ✅ All Gaps Closed in v2.4.27

Identified via systematic audit of all source files against test suite. All gaps below verified against actual code.

### P1: Critical Gaps — GDPR, Security, Admin UX

#### T1: Consent Banner (`class-consent-banner.php`) — ✅ Complete
- **Area:** Plugin / GDPR Compliance
- **Risk:** GDPR non-compliance could go undetected; broken banner UX
- **Status:** ✅ 27 tests, 56 assertions
- **Files created:** `tests/integration/ConsentBannerTest.php`
- **Coverage:** init() hook registration, render() HTML output, default/custom text, XSS escaping, return/trigger buttons, CSS generation with defaults/custom styles/invalid JSON handling/malicious colors, hex-to-rgba conversion, nonce verification, save_settings() persistence, sanitization, numeric clamping, unchecked checkbox → 0, privacy disclosure shortcode reflection, uninstall cleanup

#### T2: Security — SQL Injection, XSS, Path Traversal — ✅ Complete
- **Area:** Plugin / Security
- **Risk:** Production vulnerabilities in ingest, analytics, admin
- **Status:** ✅ 14 tests, 27 assertions
- **Files created:** `tests/integration/SecurityTest.php`
- **Coverage:** SQL injection (page + UTM), XSS (stored + REST + template), path traversal, CSRF, session spoofing, malformed UUID, bot score manipulation

#### T3: Admin Template Rendering — ✅ Complete
- **Area:** Plugin / Admin UX / XSS
- **Risk:** Broken admin UX, unescaped output leading to XSS
- **Status:** ✅ 15 tests, 21 assertions (1 warning, 1 skipped)
- **Files created:** `tests/integration/TemplateRenderTest.php`
- **Coverage:** overview, pages, audience, referrers, behavior, preferences, install template rendering; XSS escaping verification; permission checks for subscriber vs editor; network dashboard permission gate

### P2: High Priority Gaps — Premium Features, Maintenance

#### T4: Heatmap (`class-heatmap.php`) — ✅ Complete
- **Area:** Plugin / Premium Feature
- **Risk:** Premium feature silently broken
- **Status:** ✅ 7 tests, 26 assertions
- **Files created:** `tests/integration/HeatmapTest.php`
- **Coverage:** coordinate bucketing (`ROUND(x_pct/2)*2`), aggregate query with multiple clicks in same bucket, NULL coordinate exclusion, empty data handling, REST response shape (`x`, `y`, `weight`, `elements`), invalid date format rejection
- **Bug found & fixed:** `get_heatmap()` `GROUP BY` used raw column names instead of computed bucket expressions, preventing proper aggregation

#### T5: Rate Limiting — ✅ Complete
- **Area:** Plugin / Abuse Prevention
- **Risk:** Rate limits don't actually work in production
- **Status:** ✅ 5 tests, 8 assertions
- **Files created:** `tests/integration/RateLimitTest.php`
- **Coverage:** transient storage and 60 req/min enforcement, per-session isolation (two IDs don't share buckets), transient deletion resets limit, REST `/wc-event` rate-limiting rejection (`recorded: false, reason: rate_limited`)

#### T6: Uninstall (`uninstall.php`) — ✅ Complete
- **Area:** Plugin / Data Cleanup
- **Risk:** Data left behind on plugin removal
- **Status:** ✅ 4 tests, 15 assertions
- **Files created:** `tests/integration/UninstallTest.php`
- **Coverage:** flag-disabled early return, single-site table drops (with WP test framework `DROP TEMPORARY TABLE` filter bypass), option deletion via direct DB query, missing-table edge case
- **Note:** Multisite uninstall not fully testable in single-site integration environment

### P3: Medium Priority Gaps — Analytics Edge Cases, Email, Auth

#### T7: Analytics Edge Cases — ✅ Complete
- **Area:** Plugin / Data Accuracy
- **Risk:** Wrong data in user reports
- **Status:** ✅ 12 tests, 50 assertions
- **Files created:** `tests/integration/AnalyticsEdgeTest.php`
- **Coverage:** custom date range inclusion/exclusion, browser and OS filters on `get_top_pages()`, sort by `avg_time` descending, invalid sort defaulting to `views`, `fill_date_gaps()` zero-fill for empty ranges, referrer domain extraction and page filtering, behavior time histogram buckets, session depth distribution, timezone boundary (23:59 UTC in correct daily bucket)
- **Note:** `get_path_flow()` requires MySQL 8.0+ window functions — skipped in test env

#### T8: Email Digest Content — ✅ Complete
- **Area:** Plugin / User Notifications
- **Risk:** Broken digests sent to users
- **Status:** ✅ 8 tests, 17 assertions
- **Files created:** `tests/integration/EmailContentTest.php`
- **Coverage:** HTML structure (site name, period label, KPI values), XSS escaping in page paths, subject line contains site name, HTML content-type header, explicit recipient resolution, role-based recipient resolution, WooCommerce section absence when premium inactive

#### T9: REST Auth Deep Coverage — ✅ Complete
- **Area:** Plugin / API Security
- **Risk:** Unauthorized data access
- **Status:** ✅ 9 tests, 11 assertions (1 skipped)
- **Files created:** `tests/integration/RestAuthTest.php`
- **Coverage:** `remove_cookie_auth()` clears cookie error when Authorization header present, preserves error without header, ignores non-rsa routes, allowed origins list (home_url, app.richstatistics.com, tauri://localhost), disallowed origin handling, subscriber without capability gets 403, subscriber with `rsa_manage_statistics` + allowed role can access `/overview`, premium endpoint behavior

### P4: Lower Priority Gaps — Multisite, AI, PWA, Build

#### T10: Multisite Deep Coverage — ✅ Complete (limited)
- **Area:** Plugin / Enterprise
- **Risk:** Enterprise users affected
- **Status:** ✅ 8 tests, 8 assertions (2 skipped — single-site env limitation)
- **Files created:** `tests/integration/MultisiteDeepTest.php`
- **Coverage:** network dashboard/settings permission checks (wp_die without manage_network_options), `on_new_blog()` table installation (skipped in single-site), network tracker disable flag, network retention option persistence, `on_new_blog_event()` error handling
- **Note:** Full multisite integration (blog switching, per-site table isolation) requires multisite test environment

#### T11: AI Tool Endpoint Premium Gating — ✅ Complete
- **Area:** Plugin / AI Feature
- **Risk:** Free users accessing premium AI tools
- **Status:** ✅ 6 tests, 19 assertions
- **Files created:** `tests/integration/AIPremiumGatingTest.php`
- **Coverage:** free tools (`overview`, `audience`) return correct KPIs and OS/browser/language/viewport breakdowns, premium tools (`campaigns`, `user-flow`) return data when premium active, invalid tool returns 400, missing tool param returns 400
- **Bug found & fixed:** `ai_tool()` return type was `WP_REST_Response` but returned `WP_Error` for invalid tools — fixed to `WP_REST_Response|WP_Error`

#### T12: E2E Premium Views (Playwright) — ✅ Complete
- **Area:** PWA / User-facing
- **Risk:** User-facing regression in premium views
- **Status:** ✅ 6 tests, all passing
- **Files created:** `tests/e2e/tests/pwa-premium-views.spec.js`
- **Coverage:** Offline banner visibility (network on/off toggle), AI chat view renders with mocked data, WooCommerce view renders with mocked data, Export view renders, Heatmap view renders with mocked data, User Flow view renders with mocked data
- **Total E2E suite:** 68 tests, all passing

#### T13: Build Script Validation — ✅ Complete
- **Area:** CI/CD / Release Integrity
- **Risk:** Broken releases
- **Status:** ✅ 4 tests, 7 assertions
- **Files created:** `tests/integration/BuildValidationTest.php`
- **Coverage:** `build.sh` syntax validation (`bash -n`), versioned PWA snapshot existence (`docs/app/v/{version}/{stable,beta}/index.html`), `versions.json` contains current version, Freemius deploy script readable

---

## Implementation Plan

| Phase | Tests | Tests Added | Status | Priority |
|-------|-------|-------------|--------|----------|
| P1 | T1 (Consent), T2 (Security), T3 (Templates) | 56 | ✅ Complete | Critical |
| P2 | T4 (Heatmap), T5 (RateLimit), T6 (Uninstall) | 16 | ✅ Complete | High |
| P3 | T7 (Analytics), T8 (Email), T9 (RestAuth) | 29 | ✅ Complete | Medium |
| P4 | T10 (Multisite), T11 (AI), T12 (E2E), T13 (Build) | 10 | ✅ Complete | Lower |

**Total new tests:** 111 tests, 281 assertions across 14 files  
**Production bugs fixed during test writing:** 2 (heatmap GROUP BY, ai_tool return type)  
**Remaining:** Full-suite MySQL 8.0+ window function coverage (requires multisite test env)

---

## Phase 5: June 2026 Comprehensive Audit — Action Items

Generated from full-platform audit (2026-06-08). See `ROADMAP.md` §7 for finding details.

### 5.1 Critical — Fix Before Next Release

| Ref | Area | Task | File | Effort |
|-----|------|------|------|--------|
| CR-1 | Plugin | **Move `RSA_Rest_API` to core autoloader.** All REST endpoints must be free; premium gating is internal to each callback. The "highways are open, only the features are gated." | `rich-statistics.php:153-167` | Low |
| CR-2 | Plugin | **Guard `is_plugin_active_for_network()` availability.** Fatal on multisite frontend where `wp-admin/includes/plugin.php` is not loaded. | `includes/class-db.php:493` | Low |
| CR-3 | PWA | **Encrypt credentials in `localStorage`.** Application Passwords for all connected sites are stored as reversible base64. Use Web Crypto API to encrypt with a user-derived key, or Credential Management API. | `docs/app/app.js:100,184` | Medium |
| CR-4 | PWA | **Encrypt AI provider API keys in `localStorage`.** Same mechanism as CR-3. | `docs/app/app.js:1224` | Medium |
| CR-5 | Desktop | **Validate `versions.json` response type in `tauriNavigateToVersion()`.** Non-array response causes silent `TypeError` with no user feedback. | `docs/app/app.js:518-564` | Low |
| CR-6 | CI/CD | **Fix `build-release.yml` job gates for `workflow_dispatch`.** Jobs skip silently when triggered via `gh workflow run` because `github.ref` may be a branch ref, not a tag ref. | `.github/workflows/build-release.yml:54,104,226,245` | Low |
| CR-7 | PWA | **Bump Service Worker cache name and recreate v2.4.27 snapshots.** Current name is `rsa-2-4-26` in root + snapshots, causing stale asset serving. | `docs/app/sw.js:19` | Low |

### 5.2 High Priority

| Ref | Area | Task | File | Effort |
|-----|------|------|------|--------|
| HI-1 | Plugin | **Fix consent banner CSS handle.** `wp_add_inline_style('rsa-tracker', ...)` attaches to a script handle — styles are silently discarded. Register a dummy style handle. | `includes/class-consent-banner.php:37` | Low |
| HI-2 | Plugin | **Fix UTC/timezone misalignment.** Replace `current_time('timestamp')` (deprecated) and `wp_date()` with `gmdate()` / `time()` for all DB cutoff strings. | `class-analytics.php:33`, `class-db.php:354,384,409-410`, `class-admin.php:323,325,748` | Medium |
| HI-3 | Plugin | **Add `try/finally` around all `switch_to_blog()` loops.** Exception in `prune_old_data()` or `aggregate_heatmap()` leaks blog context. | `class-db.php:456-479`, `rich-statistics.php:182-198` | Medium |
| HI-4 | CI/CD | **Add test gates before develop/test deploy.** `build-develop.yml` and `build-test.yml` deploy without running tests. | `build-develop.yml`, `build-test.yml` | Low |
| HI-5 | CI/CD | **Add tests before release workflow.** `build-release.yml` has no test step — forced tags bypass quality gates. | `build-release.yml` | Low |
| HI-6 | CI/CD | **Fix `update.json` race condition.** Windows matrix job may finish before linux-arm64 pushes its `.deb`, missing arm64 from update manifest. | `job-build-desktop.yml:321-333` | Medium |
| HI-7 | PWA | **Restrict browser cache purge to `rsa-*` keys.** Currently wipes ALL origin caches, including WordPress site caches if PWA is served from the same domain. | `docs/app/app.js:720-723` | Low |
| HI-8 | Docs | **Rewrite `AGENTS.md`.** Severely outdated: claims v2.3.0, 12 integration files (actual 28), 55 E2E tests (actual 61), `build-test.yml` push trigger (actual workflow_dispatch). | `AGENTS.md` | Medium |
| HI-9 | Docs | **Rewrite `DEVELOPMENT.md` release process section.** Describes manual `git merge --no-ff` and `git push origin main --tags` which violates branch protection. | `DEVELOPMENT.md:184-237` | Medium |
| HI-10 | Desktop | **Bump Tauri/Cargo versions to 2.4.27.** Currently `2.4.26` / `2.4.24` — local builds produce incorrectly versioned apps. | `src-tauri/tauri.conf.json:4`, `Cargo.toml:3` | Low |

### 5.3 Medium Priority

| Ref | Area | Task | File | Effort |
|-----|------|------|------|--------|
| ME-1 | Plugin | **Return `WP_Error` for missing window functions.** Currently returns HTTP 200 with embedded error array. | `class-rest-api.php:1119-1163` | Low |
| ME-2 | Plugin | **Fix CLI data key references.** `behavior` accesses non-existent keys; `user-flow` lacks MySQL capability error handling. | `cli/class-cli.php:245-258,336-348` | Low |
| ME-3 | Plugin | **Make Freemius settings sync non-blocking.** Synchronous external HTTP call in `save_settings()` can white-screen on slow API. | `class-admin.php:842-853` | Low |
| ME-4 | Plugin | **Fail fast on missing core class files.** Current `file_exists()` guard causes confusing late fatal errors. | `rich-statistics.php:139-144,161-166` | Low |
| ME-5 | Plugin | **Paginate `get_trackable_pages()`.** `numberposts => -1` loads all public posts into memory. | `class-admin.php:627-635` | Low |
| ME-6 | Plugin | **Use JSON-safe sanitizer for `rsa_consent_styles`.** `sanitize_text_field` can corrupt JSON. | `class-admin.php:793` | Low |
| ME-7 | Plugin | **Consolidate export logic.** Deprecate `export_events()`, delegate to `export_data()`. | `class-analytics.php:1153-1276` | Low |
| ME-8 | Plugin | **Minimize uninstall bootstrap.** Only require `class-db.php`, not full plugin with Freemius init. | `uninstall.php:16` | Low | ✅ Fixed |
| ME-9 | Plugin | **Use `wp_localize_script` for tracker session ID.** Raw `<script>` echo in `wp_enqueue_scripts` is non-standard. | `class-tracker.php:77` | Low |
| ME-10 | PWA | **Restrict CSP `connect-src`.** Currently allows any HTTPS origin. Limit to known app hosts + user's WP site. | `docs/app/index.html:5`, `tauri.conf.json:20` | Low |
| ME-11 | PWA | **Add `try/catch` around `JSON.parse` in app init.** Corrupted localStorage crashes the entire app. | `docs/app/app.js:100,104` | Low |
| ME-12 | PWA | **Destroy AI chart instances on cleanup.** Chart.js instances leak in `state.charts` on chat clear / view switch. | `docs/app/app.js:1907-1963` | Low | ✅ Fixed |
| ME-13 | Desktop | **Fix Tauri identifier for dev/test.** `com.richardkentgates.rich-statistics(Dev)` is invalid reverse-DNS. Use `.dev` suffix. | `job-build-desktop.yml:142` | Low |
| ME-14 | CI/CD | **Read server IP/user from vars in desktop job.** Currently hardcoded; `setup-webhook.yml` uses vars. | `job-build-desktop.yml:43-47` | Low |
| ME-15 | Test | **Run uninstall tests in CI.** `@group ddl` exclusion skips `UninstallTest.php` — data deletion unverified. | `phpunit.xml.dist:22-25` | Low |
| ME-16 | Test | **Add PHP 8.0 to CI matrix.** Declared minimum is untested. | `.github/workflows/tests.yml:24` | Low |

### 5.4 Test Coverage — Priority 1 (Critical)

| Component | File | What to Test |
|-----------|------|-------------|
| PWA OTP handler | `class-pwa-download.php` | ✅ `PwaDownloadTest.php` — OTP generation, transient storage, verify-otp success/consumption/rate-limiting |
| Heatmap admin assets | `class-heatmap.php` | ✅ `HeatmapAdminTest.php` — hook registration, method existence |
| Tracker init/enqueue | `class-tracker.php` | ✅ `TrackerInitTest.php` — hook registration, localize script payload, multisite disable flag |
| DB multisite activate | `class-db.php` | `activate(true)` loops over `get_sites()`, calls `switch_to_blog()` + `install()` for each, then `restore_current_blog()` |
| DB multisite uninstall | `class-db.php` | `maybe_remove_data()` multisite branch: `is_multisite()`, `get_sites()`, `switch_to_blog()`, `drop_site_tables()`, `delete_site_option()` |
| DB daily maintenance | `class-db.php` | `daily_maintenance()` multisite loop: `switch_to_blog()`, `prune_old_data()`, `aggregate_heatmap()` per site |
| CLI commands | `cli/class-cli.php` | ✅ `CLICommandTest.php` — `validate_period`, `format_seconds` via reflection |
| REST /track happy path | `class-rest-api.php` | ✅ `RestTrackTest.php` — 200 response with valid nonce |
| REST CORS origin | `class-rest-api.php` | Dispatch request with known `Origin` header, assert response `Access-Control-Allow-Origin` matches allowed origin; assert disallowed origin is rejected |

### 5.5 Test Coverage — Priority 2 (High)

| Component | File | What to Test |
|-----------|------|-------------|
| Admin menus | `class-admin.php` | ✅ `AdminMenusTest.php` — menu/submenu registration, capability requirements, network menus |
| Admin assets | `class-admin.php` | ✅ `AdminAssetsTest.php` — Chart.js, admin CSS/JS, localization, profile OTP assets, capability gating |
| Admin page data | `class-admin.php` | `get_page_data_for_current_screen()` for each `$_GET['page']` slug: campaigns (free), user-flow (premium), click-map (premium), heatmap (premium), WooCommerce (conditional) |
| Analytics export | `class-analytics.php` | ✅ `AnalyticsExportTest.php` — `export_events()` and `export_data()` in JSON/CSV, empty data and seeded rows |
| Analytics UTM mediums | `class-analytics.php` | ✅ Added to `AnalyticsTest.php` — distinct values, null/empty exclusion, medium filter on `get_campaigns()` |
| Analytics window functions | `class-analytics.php` | `mysql_supports_window_functions()` with mocked `$wpdb->get_var()` returning `8.0.0`, `10.1.0`, `5.7.0` |
| Consent banner enqueue | `class-consent-banner.php` | Assert `wp_add_inline_style` is called with expected CSS string after `init()` |
| Tracker payload | `class-tracker.php` | `parse_payload()` edge cases: invalid JSON, missing `session_id`, non-UUID `session_id`, oversized `page` |
| E2E error states | `tests/e2e/` | ✅ `pwa-error-states.spec.js` — 403 (login screen), 404 (error message), network abort (site-down banner) |
| E2E version mismatch | `tests/e2e/` | ✅ `pwa-version-mismatch.spec.js` — `envMismatch`, `pluginTooNew`, `appTooNew` banners |

### 5.6 Test Coverage — Priority 3 (Medium / Backlog)

| Component | File | What to Test |
|-----------|------|-------------|
| E2E full journey | `tests/e2e/` | ✅ `pwa-user-journey.spec.js` — welcome → add site → OTP → connect → navigate → disconnect |
| E2E offline refresh | `tests/e2e/` | Toggle `setOffline(true)` then back to online; assert queued requests replayed |
| E2E CSV export | `tests/e2e/` | Click Export view, intercept download, validate content |
| CI multisite job | `.github/workflows/` | Add `WP_MULTISITE=1` matrix job |
| CI DDL job | `.github/workflows/` | Dedicated job running `--group ddl` (UninstallTest) |
| CI PHP 8.0 | `.github/workflows/` | Add `8.0` to test matrix |
| Coverage reporting | `.github/workflows/` | Upload HTML coverage to Codecov with threshold enforcement |
| Bot detection edge cases | `class-bot-detection.php` | Empty UA, unknown UA, bots without version strings |
| Email WooCommerce HTML | `class-email.php` | `build_html()` with WC data injected — assert funnel/top-product placeholders present |

---

**June 2026 audit action items — PARTIALLY COMPLETED.**  
**Verified complete:** CR-1, CR-2, CR-3 (credential encryption), CR-4 (AI key encryption), CR-5 (Array.isArray guard), CR-6 (workflow_dispatch gate), CR-7 (SW cache), HI-1–HI-10, ME-1, ME-2, ME-5 (paginate get_trackable_pages), ME-6 (sanitize_json_field), ME-7 (consolidate export), ME-8 (uninstall bootstrap), ME-9 (wp_localize_script), ME-11 (try/catch JSON.parse), ME-12 (destroyCharts), ME-13 (Tauri identifier), ME-14 (server vars from workflow vars), ME-16 (PHP 8.0 matrix).  
**Remaining open:** ME-3 (Freemius sync non-blocking — partially addressed with try/catch, full async requires cron refactor), ME-4 (fail fast core classes — core already fail-fast, premium file_exists is intentional for free builds), ME-10 (CSP connect-src — by design, arbitrary user-configured WP sites).  
**Final test counts:** 471 PHPUnit tests (1,013 assertions) + 68 E2E tests (all passing)  
**Production bugs found during this audit:** 7 (CR-1, CR-2, HI-1, HI-2, HI-3, ME-1, ME-2)  
**Additional fixes in this session:** ME-8 (uninstall bootstrap), ME-12 (AI chart memory leak), RestApiTest capability unlock (-10 skips, +31 assertions)
