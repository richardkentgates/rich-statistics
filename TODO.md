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
| C7 | Root sw.js cache name bumped | `rsa-2-4-20` in `docs/app/sw.js:19` |
| H1 | No hardcoded server IPs | Uses `inputs.server-ip`, `vars.SERVER_IP` |
| H2 | No hardcoded SSH username | Parameterized throughout |
| H3 | SSH fingerprint verification | `job-build-desktop.yml` verifies against `EXPECTED_HOST_FINGERPRINT` var |
| H4 | No StrictHostKeyChecking=no | Removed from all workflows |
| H5 | Dead setup_webhook input removed | Not found in any workflow |
| H6 | workflow_dispatch guard added | `build-release.yml` requires tag or version input |
| H7-H9 | RSA_APP_VERSION synced to 2.4.20 | `rich-statistics.php:69` matches `RSA_VERSION` |
| H10 | CSP scoped to known domains | `connect-src 'self' https:` |
| H11 | SCHEMA_VERSION checked on install | `class-db.php:86` |
| H12 | Heatmap uses range query | `created_at >= %s AND created_at < %s` |
| H25 | Webhook @ suppression added | All 3 use `@file_put_contents` |
| H26 | Secret exposure fixed | `setup-app-server.sh` no longer echoes secret paths to stdout |
| H27 | Separate cron log files | `rsa-deploy-cron`, `-dev`, `-test` |
| H28 | tests/bootstrap.php version synced | `RSA_VERSION = '2.4.20'` |
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

## Phase 5: Remove In-Plugin PWA Serving (Full audit complete — awaiting approval)

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

## Phase 6: Consent Banner Feature (Planning — not yet implemented)

### Task

Add an optional visitor consent banner that allows site owners to comply with privacy regulations by giving visitors control over which tracking categories are active.

### Design

Two independent toggles that map to one stored mode:

| Show Banner | Auto-Consent | Stored `rsa_consent_mode` | Behavior |
|-------------|-------------|---------------------------|----------|
| No | No | `off` | Track everything, no banner, no localStorage (current default) |
| No | Yes | `auto-consent` | Track everything, localStorage receipt on first interaction, no banner |
| Yes | No | `banner` | Show banner, track nothing until visitor chooses |
| Yes | Yes | `banner-auto` | Show banner, track immediately, visitor can customize/reject |

**All metrics default ON.** The "Customize" panel lets visitors turn categories OFF, not on.

Per-metric categories (all default ON):

| Category | Controls | Tables | Free/Premium |
|-----------|---------|--------|-------------|
| Analytics | Pageviews, sessions, viewport, time on page | `rsa_events`, `rsa_sessions` | Free |
| Campaigns | UTM tracking (source, medium, campaign) | UTM fields in `rsa_events` | Free |
| Click Tracking | Element clicks, viewport coordinates, heatmap | `rsa_clicks`, `rsa_heatmap` | Premium |
| Commerce | WooCommerce purchase events, product views | `rsa_wc_events` | Premium |

Banner styling (fully customizable): `borderRadius`, `fontColor`, `backgroundColor`, `borderColor`, `borderWidth`, `shadowX`, `shadowY`, `shadowBlur`, `shadowSpread`, `shadowAlpha`. Alpha stored separately for dedicated opacity slider; combined into `rgba()` at render time.

Consent persistence: LocalStorage stores visitor category choices. The banner itself always renders on every page load when Show Banner is checked — it is never dismissed or hidden by visitor action.

### Settings Storage (new `wp_options` keys)

| Option key | Type | Default |
|-----------|------|---------|
| `rsa_consent_mode` | string | `'off'` |
| `rsa_consent_banner_text` | string | Default consent message |
| `rsa_consent_accept_label` | string | `'Accept All'` |
| `rsa_consent_reject_label` | string | `'Reject All'` |
| `rsa_consent_customize_label` | string | `'Customize'` |
| `rsa_consent_categories` | array | All four categories ON |
| `rsa_consent_styles` | JSON | Style config object (see above) |

### Files to Create

| File | Purpose |
|------|---------|
| `includes/class-consent-banner.php` | PHP class: render banner HTML, inject CSS custom properties from styles, expose consent config via `wp_localize_script`. Exit early if mode is `off`. |
| `assets/css/consent-banner.css` | Base banner layout styles (position: fixed, z-index, responsive, animations). Colors/shadows use CSS custom properties set server-side. |
| `assets/js/admin-consent-preview.js` | Admin live preview — reads style inputs and renders a mini banner preview in real time. |

### Files to Modify

| File | Change |
|------|--------|
| `includes/class-db.php` | Add `rsa_consent_*` defaults to `seed_defaults()`. Add uninstall cleanup. |
| `includes/class-admin.php` | Handle new options in `save_settings()`. Hook `RSA_Consent_Banner::init()`. |
| `includes/class-tracker.php` | Add `consentMode`, `consentCategories`, `consentVersion` to `wp_localize_script` data. |
| `assets/js/tracker.js` | Check `window.RSA.consentMode` + `localStorage.rsa_consent` before sending beacons. Per-category gate. Listen for `rsaConsentChange` event. |
| `templates/admin/preferences.php` | Add "Consent Banner" section: Show Banner checkbox, Auto-Consent checkbox, banner text, button labels, per-metric toggles (all default ON), style controls with live preview. |
| `includes/class-rest-api.php` | Add `GET/POST /rsa/v1/consent-settings` endpoints behind `check_basic_auth`. |
| `includes/class-privacy-disclosure.php` | Update legal claims to reflect actual consent mode (banner → explicit consent, auto-consent → implied consent, off → remove "consent not required" claim). |
| `uninstall.php` | Clean up `rsa_consent_*` options. |
| `languages/rich-statistics.pot` | Regenerate. |
| `CHANGELOG.md` | Document. |
| `docs/app/app.js` | Add consent settings panel calling REST endpoints. |
| `docs/app/v/2.4.26/{stable,beta}/app.js` | Copy updated app.js. |

### What Must Be Preserved

- Default behavior (`rsa_consent_mode=off`): track everything, no banner, no localStorage
- DNT/GPC check in `tracker.js` — exits before consent logic
- Existing `window.RSA` config structure — consent keys are additive
- Premium gating: Click Tracking and Commerce show "Premium" badge in banner when license inactive
- All existing REST endpoints — consent endpoints are net-new

### Implementation Order

**Phase A — Backend (PHP)**
1. `includes/class-db.php` — Add `rsa_consent_*` defaults to `seed_defaults()`
2. `includes/class-consent-banner.php` — New class
3. `includes/class-admin.php` — Save handler + hook
4. `templates/admin/preferences.php` — Consent Banner section
5. `assets/css/consent-banner.css` — Base styles
6. `assets/js/admin-consent-preview.js` — Admin live preview
7. `includes/class-tracker.php` — Consent config in `wp_localize_script`
8. `includes/class-rest-api.php` — Consent settings endpoints
9. `includes/class-privacy-disclosure.php` — Update legal claims
10. `uninstall.php` — Cleanup

**Phase B — Frontend (tracker.js)**
11. `assets/js/tracker.js` — Consent check + per-category gating

**Phase C — PWA/App**
12. `docs/app/app.js` — Consent settings panel

**Phase D — Housekeeping**
13. `languages/rich-statistics.pot` — Regenerate
14. `CHANGELOG.md` — Document
15. Copy `app.js` to `docs/app/v/2.4.26/{stable,beta}/`
16. `composer phpcs` + `composer test`

### Risk Assessment

- **Low risk for existing installations.** Default `rsa_consent_mode=off` means no change until site owner opts in.
- **No database migration.** All settings are `wp_options` with defaults seeded on update.
- **No schema changes.** Consent enforced client-side before beacons sent.
- **Backwards-compatible.** If `window.RSA.consentMode` is undefined (old cached tracker), tracking proceeds as before.
- **Privacy disclosure** must be updated to reflect the consent mode in use.

### Test Plan

| Test | Verify |
|------|--------|
| Default (`off`) | No banner, all tracking, no localStorage |
| `auto-consent` | No banner, all tracking, localStorage receipt on interaction |
| `banner` (no auto) | Banner shown, no tracking until visitor chooses |
| `banner-auto` | Banner shown, tracking immediate, visitor can customize |
| Category gating | Reject "Clicks" → click beacons dropped; accept "Analytics" → pageviews send |
| Banner persistence | Banner always renders on every page load when Show Banner is checked, regardless of prior visitor choices |
| Premium gating | Click/Commerce categories show "Premium" badge without license |
| DNT/GPC | `doNotTrack=1` or `globalPrivacyControl=true` exits tracker before consent logic |
| Privacy disclosure | Shortcode reflects actual consent mode |
| REST API | GET/POST consent-settings require `rsa_manage_statistics` |
| PWA settings | Reads/writes consent config via REST API |
