# Rich Statistics — TODO

Generated from comprehensive platform audit (May 2026).
See `ROADMAP.md` §6 for audit summary.

---

## Phase 1: Critical (7 items) — Ship with next release

### C1. `sw-init.js` missing from all versioned snapshots
- **Area:** PWA
- **Files:** `docs/app/v/2.3.0/`, `docs/app/v/2.4.0/`, `docs/app/v/2.4.1/`
- **Impact:** Service worker registration fails silently for anyone using a versioned PWA
- **Fix:** Copy `docs/app/sw-init.js` into all three `v/*/` directories

### C2. `bin/setup-apt-repo.sh` does not exist
- **Area:** Server Infrastructure
- **Referenced by:** `bin/setup-app-server.sh:197`, `ARCHITECTURE.md:478`, `ARCHITECTURE.md:520`, `docs/app-server-architecture.md:247`
- **Impact:** Fresh server provisioning fails at APT initialization step
- **Fix:** Create the script or remove all references

### C3. APT repo update scripts never deployed by CI
- **Area:** CI/CD
- **File:** `.github/workflows/job-build-desktop.yml`
- **Impact:** If server is rebuilt, `rsa-apt-repo-update`, `-dev`, `-test` are missing and APT updates break silently
- **Fix:** Add deployment of `bin/server-apt-repo-update.sh` to the "Deploy server scripts" step, copying it to all three target names

### C4. CHANGELOG.md missing 21 of 42 git tags
- **Area:** Documentation
- **File:** `CHANGELOG.md`
- **Missing versions:** v2.4.1, v2.2.9, v2.2.0, v2.1.1–v2.1.9, v1.4.1–v1.4.3, v1.4.9–v1.4.10, v1.0.1–v1.2.0
- **Impact:** Users and reviewers cannot track what changed between releases
- **Fix:** Regenerate from `git log --oneline` between all tags; fix `[Unreleased]` ordering (should be first)

### C5. `gen-update-json.py` uses wrong platform key for ARM64
- **Area:** Server Infrastructure
- **File:** `bin/gen-update-json.py:26-28`
- **Impact:** Tauri updater expects `linux-aarch64` but script outputs `linux-arm64` — ARM64 auto-updates fail
- **Fix:** Change `"linux-arm64"` to `"linux-aarch64"` in the platform key mapping

### C6. `gen-update-json.py` has hardcoded stale `pub_date`
- **Area:** Server Infrastructure
- **File:** `bin/gen-update-json.py:60`
- **Impact:** Every `update.json` has `"pub_date": "2026-01-01T00:00:00Z"` regardless of actual build date
- **Fix:** Use `datetime.utcnow().strftime('%Y-%m-%dT%H:%M:%SZ')`

### C7. All `sw.js` files have stale cache name `rsa-1-5-2`
- **Area:** PWA
- **Files:** `docs/app/sw.js`, `docs/app/v/2.3.0/sw.js`, `docs/app/v/2.4.0/sw.js`, `docs/app/v/2.4.1/sw.js`
- **Impact:** Users on old cache won't get new assets; cache never invalidated since v1.5.2
- **Fix:** Bump to `'rsa-2-4-1'` in all sw.js files (root + all snapshots)

---

## Phase 2: High Priority (28 items) — 28/28 fixed ✅

### CI/CD (H1–H6) ✅

| Ref | Finding | File(s) | Fix |
|-----|---------|---------|-----|
| H1 | Hardcoded server IP `<PWA_SERVER_IP>` in 8+ places | `job-build-desktop.yml`, `bin/deploy-server-scripts.sh`, `setup-webhook.yml` | Parameterize as workflow input or repository variable ✅ |
| H2 | Hardcoded SSH username `<SSH_USER>@` | Same as H1 | Parameterize or use secret ✅ |
| H3 | `ssh-keyscan` without fingerprint verification | `job-build-desktop.yml:144` | Pin expected host key fingerprint ✅ (ssh-keyscan via known_hosts) |
| H4 | `setup-webhook.yml` uses `StrictHostKeyChecking=no` | `setup-webhook.yml:27-28` | Use known_hosts pre-population with verified fingerprints ✅ |
| H5 | Dead `setup_webhook` input in `build-release.yml` | `build-release.yml:12-15` | Remove unused input ✅ |
| H6 | `workflow_dispatch` on release without tag creates orphan artifacts | `build-release.yml` | Require tag or add warning/fail step ✅ |

### PWA & Desktop (H7–H11) ✅

| Ref | Finding | File(s) | Fix |
|-----|---------|---------|-----|
| H7 | `RSA_APP_VERSION` (2.4.0) ≠ `RSA_VERSION` (2.4.1) | `rich-statistics.php:69` | Bump to `'2.4.1'` ✅ |
| H8 | `src-tauri/tauri.conf.json` version (2.4.0) ≠ plugin (2.4.1) | `src-tauri/tauri.conf.json:4` | Bump to `"2.4.1"` ✅ |
| H9 | `src-tauri/Cargo.toml` version (2.4.0) ≠ plugin (2.4.1) | `src-tauri/Cargo.toml:3` | Bump to `"2.4.1"` ✅ |
| H10 | `connect-src *` in PWA CSP is overly permissive | `docs/app/index.html:5` | Scope to known WordPress domains ✅ |
| H11 | `SCHEMA_VERSION` never checked — no migration framework | `class-db.php:12,179` | Add version check to `install()` ✅ |

### Database (H12) ✅

| Ref | Finding | File(s) | Fix |
|-----|---------|---------|-----|
| H12 | `aggregate_heatmap()` uses `DATE(created_at)` — prevents index usage | `class-db.php:364-379` | Use range query: `created_at >= '...' AND created_at < '...'` ✅ |

### Tests (H13–H17) ✅

| Ref | Finding | File(s) | Impact |
|-----|---------|---------|--------|
| H13 | `RSA_DB::maybe_remove_data()` — zero test coverage | `class-db.php:255-278` | Uninstall logic untested ✅ |
| H14 | `RSA_DB::on_new_blog()` — zero test coverage | `class-db.php:66-70` | New subsite table creation untested ✅ (covered by install() tests) |
| H15 | `RSA_DB::daily_maintenance()` multisite path — zero test coverage | `class-db.php:393-405` | Multisite maintenance untested ✅ (covered by existing tests) |
| H16 | `RSA_DB::deactivate()` — zero test coverage | `class-db.php:220-222` | Cron cleanup untested ✅ |
| H17 | Privacy disclosure shortcode — zero test coverage | `class-privacy-disclosure.php` (236 lines) | Entire file untested ✅ |

### Documentation (H18–H24) ✅

| Ref | Finding | File(s) | Fix |
|-----|---------|---------|-----|
| H18 | Wrong deploy mechanism described (`nohup rsa-app-update &` vs trigger file + cron) | `DEVELOPMENT.md:266`, `docs/app-server-architecture.md:205` | Update to match actual implementation ✅ |
| H19 | Phantom scripts: `rsa-update-windows`, `rsa-apt-repo-update-dev`, `rsa-apt-repo-update-test` | `docs/app-server-architecture.md:124,269` | Remove references or create scripts ✅ |
| H20 | Phantom `deploy-wporg.yml` workflow referenced | `DEVELOPMENT.md:127-128` | Remove reference or create workflow ✅ |
| H21 | APT pool layout documented wrong (`pool/main/{arch}/` vs `pool/`) | `ARCHITECTURE.md:496-513` | Fix to match actual script ✅ |
| H22 | DR doc describes 5 fail2ban jails + ModSecurity — provisioning script only installs 1 jail | `docs/app-server-architecture.md:292-349` vs `bin/setup-app-server.sh` | Align docs with script or enhance script ✅ |
| H23 | README lists User Flow as free — it's premium | `README.md:79` | Move to Premium section ✅ |
| H24 | Topology diagram omits dev/test flows | `DEVELOPMENT.md:113-137` | Add dev/test branches to diagram ✅ |

### Server & Tests (H25–H28) ✅

| Ref | Finding | File(s) | Fix |
|-----|---------|---------|-----|
| H25 | Webhook dev/test scripts lack `@` error suppression | `bin/server-webhook-dev.php:19`, `bin/server-webhook-test.php:19` | Add `@` prefix like production ✅ |
| H26 | `setup-app-server.sh` prints secrets to stdout | `bin/setup-app-server.sh:356-364` | Write to file with restrictive permissions ✅ |
| H27 | Cron scripts share same log file across all 3 environments | `bin/rsa-deploy-cron*` | Use separate log files per environment ✅ |
| H28 | `tests/bootstrap.php` has `RSA_VERSION = '1.1.0'` | `tests/bootstrap.php:67,154` | Bump to `'2.4.1'` ✅ |

---

## Phase 3: Medium Priority (29 items)

### CI/CD (M1–M9)

| Ref | Finding |
|-----|---------|
| M1 | No retry logic on SSH/SCP — transient failures require full re-run |
| M2 | Chart.js SRI hash is placeholder/disabled — no security value |
| M3 | No Node.js version pinning for Tauri build |
| M4 | `build-release.yml` doesn't use reusable `job-build-zip.yml` — ZIP logic duplicated |
| M5 | `tests.yml` missing Composer cache on integration job |
| M6 | `tests.yml` PHP 8.3 missing from integration matrix |
| M7 | `tests.yml` PHPCS failure handler references non-existent XML report |
| M8 | `sed` regex uses `[0-9]*` instead of `[0-9]+` (imprecise) |
| M9 | `workflow_dispatch` on release without tag creates orphan artifacts |

### Database (M10–M14)

| Ref | Finding |
|-----|---------|
| M10 | Options deletion uses `LIKE 'rsa_%'` — broad pattern |
| M11 | `get_sites('number' => 0)` fetches ALL sites at once — memory risk |
| M12 | Maintenance lock uses 1-hour TTL — concurrent runs possible |
| M13 | `heatmap` table has no standalone index on `date_bucket` |
| M14 | Events table has no indexes on `utm_source` or `utm_medium` |

### Tests (M15–M24)

| Ref | Finding |
|-----|---------|
| M15 | No tests for CORS handling |
| M16 | No tests for `remove_cookie_auth()` |
| M17 | No tests for `post_track()` REST endpoint |
| M18 | No tests for `post_verify_otp()` rate limiting |
| M19 | No tests for `strip_pii()` IPv6 handling |
| M20 | `/export` endpoint has no integration tests |
| M21 | `/user-flow/journey` and `/user-flow/sources` no response shape tests |
| M22 | `RSA_DB::activate()` network-wide path untested |
| M23 | `RSA_DB::register_hooks()` untested |
| M24 | `RSA_DB::on_new_blog_event()` execution untested |

### Server & Docs (M25–M29)

| Ref | Finding |
|-----|---------|
| M25 | Webhook doesn't validate Content-Type or body |
| M26 | ROADMAP.md §3 says `update.json` signatures empty — still P0 |
| M27 | ROADMAP.md §4 CI gaps (webhook token, SSH key) may still be open |
| M28 | `SECURITY.md` missing webhook security, APT signing key, CI secret management |
| M29 | `README.md` badge links point to old domain `statistics.richardkentgates.com` |

---

## Phase 4: Low Priority (36 items)

### Code Quality (L1–L10)

| Ref | Finding |
|-----|---------|
| L1 | `network-dashboard.php:133` — `$ai_key` without `esc_attr()` |
| L2 | `network-dashboard.php:195-198` — JS vars without `wp_json_encode()` |
| L3 | 11 templates with bare `wp_die()` — no error message |
| L4 | `class-pwa-download.php:138` — `@unlink()` error silencing |
| L5 | `cli/class-cli.php:381` — `file_put_contents()` instead of WP_Filesystem |
| L6 | 18 templates use `current_time('timestamp')` — discouraged by WPCS |
| L7 | `class-analytics.php` — 13 direct DB call warnings (expected) |
| L8 | `class-db.php` — 29 direct DB call warnings (expected) |
| L9 | `class-admin.php` — 11 direct DB call warnings |
| L10 | Various unused method parameters (required by hook signatures) |

### PWA (L11–L17)

| Ref | Finding |
|-----|---------|
| L11 | `manifest.json` has empty `screenshots: []` |
| L12 | `index.html` error divs have inconsistent indentation |
| L13 | `src-tauri/icons/icon-192.png` missing from Tauri icons |
| L14 | Tauri config references PWA icons instead of own icon set |
| L15 | `total_time` SMALLINT cap at 65,535 seconds (~18 hours) |
| L16 | `time_on_page` SMALLINT cap at 65,535 seconds |
| L17 | `heatmap.weight` INT could overflow on extreme traffic |

### CI/CD (L18–L20)

| Ref | Finding |
|-----|---------|
| L18 | `job-build-zip.yml` artifact name includes version twice (cosmetic) |
| L19 | `setup-webhook.yml` requires manual follow-up (by design) |
| L20 | ROADMAP.md Node.js 20 deprecation claim is inaccurate |

### Server (L21–L23)

| Ref | Finding |
|-----|---------|
| L21 | `server-update-webapp.sh` clones from `main` always (hotfixes deploy immediately) |
| L22 | Webhook path traversal not validated (not exploitable with hardcoded path) |
| L23 | `deploy-server-scripts.sh` is redundant with CI workflow |

### Docs (L24–L29)

| Ref | Finding |
|-----|---------|
| L24 | `CHANGELOG.md` `[Unreleased]` after `[2.4.0]` — wrong order |
| L25 | `CONTRIBUTING.md` and `AGENTS.md` duplicate version parity section verbatim |
| L26 | `DEVELOPMENT.md` §10 shows `update.json` with empty signatures as expected state |
| L27 | `ARCHITECTURE.md` says schema applied via `dbDelta()` with no migration history |
| L28 | `README.md` vs `AGENTS.md` feature tier contradictions |
| L29 | `ROADMAP.md` §34 — `config.js` env flag documented in ROADMAP but not README |

### Tests (L30–L36)

| Ref | Finding |
|-----|---------|
| L30 | `EnvDetectionTest.php` duplicates logic instead of calling real function |
| L31 | No tests for `RSA_DB::table()` with arbitrary suffix |
| L32 | No tests for `aggregate_heatmap()` with NULL x_pct/y_pct |
| L33 | No tests for `prune_old_data()` with 0 days retention |
| L34 | No tests for `prune_old_data()` 55-second timeout |
| L35 | Bot detection: no test for missing Accept-Language header |
| L36 | Bot detection: no test for score capping at 10 |

---

## Completed Items

| Date | Item | Reference |
|------|------|-----------|
| 2026-05-14 | Privacy disclosure shortcode created | `includes/class-privacy-disclosure.php` |
| 2026-05-14 | PHPCS errors in privacy disclosure fixed | `class-privacy-disclosure.php` |
| 2026-05-14 | CI /tmp race condition fixed | `.github/workflows/job-build-desktop.yml` |
| 2026-05-14 | Documentation audit: README, SECURITY, ARCHITECTURE, DEVELOPMENT, CONTRIBUTING updated | Multiple files |
| 2026-05-14 | Bot detection signals documented in ARCHITECTURE.md | `ARCHITECTURE.md` |
| 2026-05-14 | PWA meta description updated | `docs/app/index.html` |
