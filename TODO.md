# Rich Statistics — TODO

Generated from comprehensive platform audit (May 2026).
See `ROADMAP.md` §6 for audit summary.

---

## Phase 1: Critical — Ship with next release

### BC-1: Snapshot format mismatch — flat vs channel subdirectories
- **Area:** Snapshots / PWA / Server
- **Files:** `docs/app/v/*/`, all 3 server environments, `docs/app/app.js`
- **Impact:** New Tauri desktop builds navigate to `/v/{version}/{channel}/index.html` but 38 of 39 existing snapshots on production have flat files at `/v/{version}/index.html`. New desktop builds will 404 on all versions before 2.4.16.
- **Fix:**
  1. Run migration script on all 3 server environments to convert flat → `stable/` + `beta/` subdirs
  2. Also run migration in the repo's `docs/app/v/` directories
  3. Update `app.js` to try flat path as fallback if channel-subdir 404s (backward compat)
  4. Update `build.sh` to match CI channel-subdir format
- **Server migration command:**
  ```bash
  for dir in /var/www/rs-app/public_html/v/*/; do
      version=$(basename "$dir")
      [ -d "$dir/stable" ] && continue
      mkdir -p "$dir/stable"
      for f in "$dir"/*; do [ -f "$f" ] && mv "$f" "$dir/stable/"; done
      [ -d "$dir/icons" ] && mv "$dir/icons" "$dir/stable/"
      cp -r "$dir/stable" "$dir/beta"
  done
  ```

### BC-2: `versions-beta.json` missing from dev and test environments
- **Area:** Server
- **Files:** `/var/www/rs-app-dev/`, `/var/www/rs-app-test/`
- **Impact:** Beta channel routing unavailable on dev/test.richstatistics.com — `app.js` fetch to `/versions-beta.json` returns 404
- **Fix:** Copy `versions.json` to `versions-beta.json` on dev and test PWA environments

### C1. `sw-init.js` missing from all versioned snapshots
- **Area:** PWA
- **Files:** `docs/app/v/2.3.0/`, `docs/app/v/2.4.0/`, `docs/app/v/2.4.1/`
- **Impact:** Service worker registration fails silently for anyone using a versioned PWA
- **Fix:** Copy `docs/app/sw-init.js` into all three `v/*/` directories

### C7. All `sw.js` files have stale cache name `rsa-1-5-2`
- **Area:** PWA
- **Files:** `docs/app/sw.js`, `docs/app/v/2.3.0/sw.js`, `docs/app/v/2.4.0/sw.js`, `docs/app/v/2.4.1/sw.js`
- **Impact:** Users on old cache won't get new assets; cache never invalidated since v1.5.2
- **Fix:** Bump to `'rsa-2-4-1'` in all sw.js files (root + all snapshots)

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

---

## Phase 2: High Priority

### BC-3: Beta tag hardcoded to `.beta.1` — no increment
- **Area:** CI/CD
- **File:** `.github/workflows/promote.yml:70-74`
- **Impact:** Second beta release for the same version will fail (tag collision)
- **Fix:** Add tag increment logic:
  ```bash
  EXISTING=$(git tag -l "v${VERSION}-beta.*" | sort -V | tail -1)
  if [ -n "$EXISTING" ]; then
      SUFFIX=${EXISTING##*-beta.}
      NEXT=$((SUFFIX + 1))
      TAG="v${VERSION}-beta.${NEXT}"
  else
      TAG="v${VERSION}-beta.1"
  fi
  ```

### BC-4: Fallback URL hardcoded to `/stable/` for beta users
- **Area:** PWA
- **File:** `docs/app/app.js:601`
- **Impact:** Beta channel users redirected to stable snapshot when their version isn't bundled
- **Fix:** Pass `channel` to fallback URL instead of hardcoding `'stable'`

### BC-6: Apache vhost missing immutable cache headers for `/v/` paths
- **Area:** Server
- **Files:** `/etc/apache2/sites-available/app.richstatistics.com-le-ssl.conf`, `dev.*`, `test.*`
- **Impact:** Versioned PWA assets served without `Cache-Control: immutable` — browsers revalidate on every load
- **Fix:** Add to all 3 SSL vhosts:
  ```apache
  <LocationMatch "^/v/[0-9]+\.[0-9]+\.[0-9]+/">
      Header set Cache-Control "public, max-age=31536000, immutable"
      Header set Access-Control-Allow-Origin "*"
  </LocationMatch>
  ```

### BC-7: `update.json` signatures are empty
- **Area:** Server / CI/CD
- **File:** `/var/www/rs-app/public_html/dist/update.json`
- **Impact:** Tauri updater will reject unsigned updates
- **Fix:** Investigate signing pipeline — verify `TAURI_SIGNING_PRIVATE_KEY`, `TAURI_KEY_PASSWORD` secrets; check `.sig` file generation and `gen-update-json.py` matching logic

### BC-9/10: Version snapshot gap and constant drift
- **Area:** Repo
- **Files:** `rich-statistics.php`, `docs/app/v/`
- **Impact:** `RSA_VERSION=2.4.3` but no snapshot for 2.4.2 or 2.4.3; plugin header says `2.4.1`
- **Fix:** Create missing snapshots, sync version constants

### H1-H6: CI/CD Hardcoded values & security
- **Area:** CI/CD
- **Files:** `.github/workflows/job-build-desktop.yml`, `setup-webhook.yml`, `bin/*`
- **Items:** Server IP, SSH username, host key verification, StrictHostKeyChecking, dead input
- **Status:** These were marked "✅ Fixed" in prior audit — verify they're truly resolved

### CI/CD: Add Freemius ZIP upload to `build-release.yml`
- **Area:** CI/CD (New Feature)
- **File:** `.github/workflows/build-release.yml`
- **Impact:** Plugin ZIP currently requires manual upload to Freemius dashboard after each release
- **Fix:** Add a new `upload-freemius` job to `build-release.yml` that downloads the ZIP artifact and POSTs it to `https://api.freemius.com/v1/products/25954/releases.json` with the Freemius Developer API. Channel-aware: beta tags send `is_beta=true`, stable tags send `is_beta=false`.
- **Prerequisite:** Add `FREEMIUS_SECRET_KEY` GitHub secret (get from Freemius Dashboard → Developer → API Keys)
- **Only involves GitHub Actions** — no server-side scripts, no Apache config, no cron jobs

---

## Phase 3: Medium Priority

### BC-5: `build.sh` creates flat snapshots
- **Area:** Dev tooling
- **File:** `build.sh:166-189`
- **Impact:** Local builds produce different directory structure than CI releases
- **Fix:** Update to create `v/{version}/{stable,beta}/` subdirectories matching CI

### BC-8: Server accumulates snapshots with no pruning
- **Area:** Server
- **Files:** `bin/server-update-webapp.sh`, dev/test variants
- **Impact:** 39 versions on production, growing unbounded
- **Fix:** Add prune step matching CI logic (keep last 12) to server update scripts

### BC-11: No defensive regex guard on `channel` in app.js
- **Area:** PWA
- **File:** `docs/app/app.js`
- **Impact:** Currently safe but fragile — future code changes could introduce vulnerability
- **Fix:** Add `channel = /^(stable|beta)$/.test(channel) ? channel : 'stable'`

### BC-12: `setup-webhook.yml` always deploys production webhook
- **Area:** CI/CD
- **File:** `.github/workflows/setup-webhook.yml:66`
- **Impact:** Dev/test environments deployed via this workflow validate against production token
- **Fix:** Use environment-appropriate webhook file (`server-webhook-dev.php` for dev, etc.)

### C2-C3: APT setup & CI deployment (previously critical, now verified)
- **Area:** Server / CI/CD
- **Status:** ✅ `bin/setup-apt-repo.sh` exists; CI deploys apt scripts. Verified on server.

### C4: CHANGELOG.md
- **Status:** ✅ Regenerated — all 42 versions documented

### M1-M29: All prior Phase 2-3 items
- **Status:** All marked ✅ fixed in prior audit — verify during next release cycle

---

## Phase 4: Low Priority

(All items L1-L36 from prior audit — unchanged) See `ROADMAP.md` §9.8+ for Apache config notes.

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

## Phase 3: Medium Priority (29 items) — 27/29 fixed

### CI/CD (M1–M9)

| Ref | Finding | Status |
|-----|---------|--------|
| M1 | No retry logic on SSH/SCP — transient failures require full re-run | ✅ Fixed — 3 retries with 10s backoff |
| M2 | Chart.js SRI hash is placeholder/disabled — no security value | ✅ Fixed — now computes and logs actual hash |
| M3 | No Node.js version pinning for Tauri build | ✅ Fixed — Node.js 20 pinned |
| M4 | `build-release.yml` doesn't use reusable `job-build-zip.yml` — ZIP logic duplicated | ✅ Fixed — now calls reusable workflow |
| M5 | `tests.yml` missing Composer cache on integration job | ✅ Fixed |
| M6 | `tests.yml` PHP 8.3 missing from integration matrix | ✅ Fixed |
| M7 | `tests.yml` PHPCS failure handler references non-existent XML report | ✅ Fixed |
| M8 | `sed` regex uses `[0-9]*` instead of `[0-9]+` (imprecise) | ✅ Fixed |
| M9 | `workflow_dispatch` on release without tag creates orphan artifacts | ✅ Already fixed (H6 guard) |

### Database (M10–M14)

| Ref | Finding | Status |
|-----|---------|--------|
| M10 | Options deletion uses `LIKE 'rsa_%'` — broad pattern | ✅ Fixed — explicit option list |
| M11 | `get_sites('number' => 0)` fetches ALL sites at once — memory risk | ✅ Fixed — batched at 100 |
| M12 | Maintenance lock uses 1-hour TTL — concurrent runs possible | ✅ Fixed — 30 min TTL |
| M13 | `heatmap` table has no standalone index on `date_bucket` | ✅ Fixed |
| M14 | Events table has no indexes on `utm_source` or `utm_medium` | ✅ Fixed |

### Tests (M15–M24)

| Ref | Finding | Status |
|-----|---------|--------|
| M15 | No tests for CORS handling | ✅ Fixed — CoverageGapTest |
| M16 | No tests for `remove_cookie_auth()` | ✅ Fixed — CoverageGapTest |
| M17 | No tests for `post_track()` REST endpoint | ✅ Fixed — CoverageGapTest |
| M18 | No tests for `post_verify_otp()` rate limiting | ✅ Fixed — CoverageGapTest |
| M19 | No tests for `strip_pii()` IPv6 handling | ✅ Removed — IP never used in visitor tracking |
| M20 | `/export` endpoint has no integration tests | ✅ Fixed — CoverageGapTest |
| M21 | `/user-flow/journey` and `/user-flow/sources` no response shape tests | ✅ Fixed — CoverageGapTest |
| M22 | `RSA_DB::activate()` network-wide path untested | ✅ Fixed — CoverageGapTest |
| M23 | `RSA_DB::register_hooks()` untested | ✅ Fixed — CoverageGapTest |
| M24 | `RSA_DB::on_new_blog_event()` execution untested | ✅ Fixed — CoverageGapTest |

### Server & Docs (M25–M29)

| Ref | Finding | Status |
|-----|---------|--------|
| M25 | Webhook doesn't validate Content-Type or body | ✅ Fixed — Content-Type restricted |
| M26 | ROADMAP.md §3 says `update.json` signatures empty — still P0 | ✅ Fixed — CI populates signatures |
| M27 | ROADMAP.md §4 CI gaps (webhook token, SSH key) may still be open | ✅ Resolved by H1-H4 |
| M28 | `SECURITY.md` missing webhook security, APT signing key, CI secret management | ✅ Fixed |
| M29 | `README.md` badge links point to old domain `statistics.richardkentgates.com` | ✅ Fixed |

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
