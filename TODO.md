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

---

## Phase 3: Medium Priority

(no remaining items — P4.2 assets ready)

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
