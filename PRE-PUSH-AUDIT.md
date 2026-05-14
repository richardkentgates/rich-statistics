# Pre-Push Comprehensive Audit — v2.4.1

**Date:** 2026-05-13
**Scope:** Full platform deep audit — REST API, PWA, Admin, Tracker, DB, WooCommerce, CI/CD, Freemius
**Goal:** Identify ALL issues before v2.4.1 tag push

---

## 🔴 Critical — Must Fix Before Push

### C1. Network Dashboard AI API Key Exposed in Client-Side JavaScript
- **Location:** `templates/admin/network-dashboard.php:199-201, 338-339`
- **Impact:** Any network admin user (or anyone viewing page source) can steal the OpenAI/LLM API key. XSS on the network admin page grants unlimited billed API access.
- **Fix:** Route all AI requests through a server-side REST endpoint that holds the key. Never send API keys to the browser.
- **Status:** ⏳ Requires architectural change — defer to v2.5.0 if timeline is tight, but document as known issue

### C2. Session INSERT/UPDATE Race Condition
- **Location:** `includes/class-tracker.php:163-204`
- **Impact:** Concurrent pageviews from same session can both fail SELECT, both INSERT → duplicate key error silently swallowed → `pages_viewed` counter off by one. Data corruption on high-traffic sites.
- **Fix:** Replace SELECT-then-branch with `INSERT ... ON DUPLICATE KEY UPDATE pages_viewed = pages_viewed + 1, exit_page = VALUES(exit_page), ...`
- **Status:** ✅ Ready to fix

---

## 🟠 High — Should Fix Before Push

### H1. REST `/track` Endpoint Lacks Per-IP Rate Limiting
- **Location:** `includes/class-rest-api.php:1132-1159`
- **Impact:** Rate limiter is per-session (keyed by session ID). Attacker generates unlimited session IDs → unlimited tracking injection → database bloat, skewed analytics.
- **Fix:** Add per-IP rate limiter (transient keyed by `hash('sha256', $_SERVER['REMOTE_ADDR'])`) before delegating to `handle_ingest()`.
- **Status:** ✅ Ready to fix

### H2. Heatmap Table Missing Unique Key — `ON DUPLICATE KEY UPDATE` Never Fires
- **Location:** `includes/class-db.php:145-154` (schema), `includes/class-db.php:370-381` (aggregation)
- **Impact:** Without a unique constraint on `(page, x_pct, y_pct, date_bucket)`, the `ON DUPLICATE KEY UPDATE` inserts duplicate rows every run. Heatmap table grows unboundedly with duplicates.
- **Fix:** Add `UNIQUE KEY page_coords_date (page(191), x_pct, y_pct, date_bucket)` to schema. Add migration for existing tables.
- **Status:** ✅ Ready to fix

### H3. Tracker Uses Local Time Instead of UTC
- **Location:** `includes/class-tracker.php:196, 212`, `includes/class-woocommerce.php:141`
- **Impact:** `current_time('mysql')` uses WordPress site timezone. Retention pruning uses `gmdate()` (UTC). Events inserted at UTC+5 with a 90-day retention will be pruned 5 hours early. Cross-site comparisons are unreliable.
- **Fix:** Change to `current_time('mysql', true)` (forces GMT) or `gmdate('Y-m-d H:i:s')`.
- **Status:** ✅ Ready to fix

### H4. Template Renderers Lack Direct-Access Capability Checks
- **Location:** `includes/class-admin.php:594-649` (all 14 `page_*` methods)
- **Impact:** `add_menu_page` capability only controls menu visibility. If another plugin or custom code calls `RSA_Admin::page_overview()` directly, the capability check is bypassed. Defense-in-depth gap.
- **Fix:** Add `if ( ! current_user_can( 'rsa_manage_statistics' ) ) { wp_die(..., '', ['response' => 403]); }` to each page renderer method.
- **Status:** ✅ Ready to fix

### H5. SSH `StrictHostKeyChecking=no` — MITM Vulnerability in CI
- **Location:** `.github/workflows/job-build-desktop.yml:141-142, 191, 199`, `setup-webhook.yml:27-28`
- **Impact:** CI runner blindly accepts any host key. Compromised DNS, BGP hijack, or network attacker could intercept SSH connection and receive the private key or inject malicious commands during binary pushes.
- **Fix:** Pre-populate `known_hosts` with server's public key fingerprint via a GitHub secret.
- **Status:** ⏳ Requires adding server host key as GitHub secret

### H6. CI Actions Not Pinned to SHA — Supply Chain Attack Vector
- **Location:** All workflow files (14 action references)
- **Impact:** Actions referenced by mutable tags (`@v4`, `@v2`, `@stable`). Compromised action repo could inject malicious code with full secret access.
- **Fix:** Pin every action to a full commit SHA hash.
- **Status:** ✅ Ready to fix (lookup current SHAs)

---

## 🟡 Medium — Fix Before Push If Possible

### M1. `post_user_settings` Mass Assignment — Unbounded Array
- **Location:** `includes/class-rest-api.php:764-787`
- **Impact:** Authenticated user sends array with millions of entries → user meta bloat, slow retrieval, potential DoS.
- **Fix:** `if ( count( $raw ) > 100 ) return new WP_Error('too_many_sites', ...)`
- **Status:** ✅ Ready to fix

### M2. `strip_pii()` Misses IPv6 Addresses
- **Location:** `includes/class-rest-api.php:1181-1183`
- **Impact:** IPv6 addresses in data are not redacted in AI tool responses.
- **Fix:** Add IPv6 regex pattern to `strip_pii()`.
- **Status:** ✅ Ready to fix

### M3. `esc()` Function Missing Single Quote & Backtick Escaping
- **Location:** `docs/app/app.js:3141-3148`
- **Impact:** If any future code places `esc()` output in single-quoted attributes, XSS becomes possible.
- **Fix:** Add `.replace( /'/g, '&#39;' )` and `.replace( /`/g, '&#96;' )`.
- **Status:** ✅ Ready to fix

### M4. Site IDs Generated with `Math.random()` — Predictable
- **Location:** `docs/app/app.js:247-249`
- **Impact:** `Math.random()` is not cryptographically secure. Predictable IDs could be used in CSRF-like attacks against multi-site sync.
- **Fix:** Use `crypto.randomUUID()` (available in all modern browsers).
- **Status:** ✅ Ready to fix

### M5. Unescaped Echo in `user-flow.php` Template
- **Location:** `templates/admin/user-flow.php:178-179, 251-252`
- **Impact:** Unescaped output violates WordPress coding standards. Would become XSS if validation logic ever changed.
- **Fix:** Wrap in `esc_attr()`.
- **Status:** ✅ Ready to fix

### M6. Missing `page_footer()` on Premium Gate Early Returns
- **Location:** `templates/admin/click-map.php:26`, `templates/admin/heatmap.php:27`
- **Impact:** Early return without `page_footer()` leaves `.rsa-wrap` div unclosed, breaking admin page HTML.
- **Fix:** Add `RSA_Admin::page_footer();` before `return;`.
- **Status:** ✅ Ready to fix

### M7. `render()` Method Has No Path Traversal Protection
- **Location:** `includes/class-admin.php:663-668`
- **Impact:** Template slug concatenated directly into file path. If any caller passes user-controlled input, LFI vulnerability.
- **Fix:** Validate against whitelist of known template slugs.
- **Status:** ✅ Ready to fix

### M8. Test Email Link Uses GET for State-Changing Action
- **Location:** `templates/admin/preferences.php:300-304`
- **Impact:** GET-based state change violates HTTP semantics. Vulnerable to CSRF via image tags or prefetching (nonce mitigates but pattern is problematic).
- **Fix:** Convert to POST form with submit button.
- **Status:** ✅ Ready to fix

### M9. Tracker Rate Limiting is Per-Session Only (AJAX path)
- **Location:** `includes/class-tracker.php:306-314`
- **Impact:** Attacker generates unlimited session IDs → bypasses 60/min limit entirely.
- **Fix:** Add secondary per-IP rate limiter keyed by `$_SERVER['REMOTE_ADDR']`.
- **Status:** ✅ Ready to fix

### M10. `uninstall.php` Uses Direct Query Without `prepare()`
- **Location:** `uninstall.php:62`
- **Impact:** Inconsistent with rest of codebase. Sets bad precedent.
- **Fix:** Use `$wpdb->prepare( "... LIKE %s", 'rsa_%' )`.
- **Status:** ✅ Ready to fix

### M11. `uninstall.php` Doesn't Clear Scheduled Cron Hooks
- **Location:** `uninstall.php` (missing)
- **Impact:** Orphaned cron entries continue firing after tables are dropped → PHP errors in logs.
- **Fix:** Add `wp_clear_scheduled_hook('rsa_daily_maintenance')` and `wp_clear_scheduled_hook('rsa_send_digest')`.
- **Status:** ✅ Ready to fix

### M12. `daily_maintenance` Has No Lock to Prevent Concurrent Runs
- **Location:** `includes/class-db.php:388-406`
- **Impact:** If cron takes >24h on large sites, second instance starts → duplicate pruning or aggregation.
- **Fix:** `if ( get_transient( 'rsa_maintenance_lock' ) ) return; set_transient( 'rsa_maintenance_lock', 1, HOUR_IN_SECONDS );`
- **Status:** ✅ Ready to fix

### M13. `prune_old_data` Can Lock Tables / Timeout on Large Datasets
- **Location:** `includes/class-db.php:286-335`
- **Impact:** `DELETE ... LIMIT 5000` loop runs synchronously. On millions of rows, can timeout and leave partial state.
- **Fix:** Add time_limit check: `if ( microtime(true) - $start > 55 ) break;`
- **Status:** ✅ Ready to fix

### M14. WooCommerce Double-Tracking Race Condition
- **Location:** `includes/class-woocommerce.php:102-106`
- **Impact:** Two hooks (`woocommerce_payment_complete` + `woocommerce_order_status_processing`) can both read `_rsa_tracked` as empty before either writes → duplicate order events.
- **Fix:** Use `add_post_meta( $order_id, '_rsa_tracked', '1', true )` (4th param `true` = only insert if key doesn't exist).
- **Status:** ✅ Ready to fix

### M15. `secrets: inherit` Over-Privileges Desktop Build
- **Location:** `.github/workflows/build-develop.yml:37`, `build-test.yml:37`, `build-release.yml:173`
- **Impact:** ALL repo secrets passed to reusable workflow. Future secrets (API keys, AWS creds) would be auto-exposed.
- **Fix:** Explicitly pass only needed secrets.
- **Status:** ✅ Ready to fix

### M16. Chart.js Downloaded Without SRI Hash Verification
- **Location:** `build.sh:59-61`
- **Impact:** Compromised CDN or MITM could substitute malicious `chart.min.js` bundled into every plugin ZIP.
- **Fix:** Download with SHA384 hash verification.
- **Status:** ✅ Ready to fix

### M17. Plugin ZIP Built Without Checksum/Signature
- **Location:** `.github/workflows/job-build-zip.yml`, `build-release.yml`
- **Impact:** Compromised runner or malicious build step could deliver backdoored plugin with no way to verify integrity.
- **Fix:** Generate and publish SHA256 checksum alongside ZIP.
- **Status:** ✅ Ready to fix

### M18. Tauri CSP `connect-src *` Allows Arbitrary Connections
- **Location:** `src-tauri/tauri.conf.json:20`
- **Impact:** Desktop app can connect to any URL. If XSS exists in the PWA, attacker can exfiltrate data to any origin.
- **Fix:** Restrict to known origins: `'self'` + the three app domains.
- **Status:** ✅ Ready to fix

### M19. `maybe_remove_data()` Not Multisite-Aware
- **Location:** `includes/class-db.php:255-271`
- **Impact:** Called on multisite without iterating all sites → only drops one site's data. Network-level options not cleaned.
- **Fix:** Mirror `uninstall.php` pattern — iterate all sites, use `delete_site_option()` for network options.
- **Status:** ✅ Ready to fix

### M20. `build-release.yml` ZIP Build Diverges from `build.sh`
- **Location:** `build-release.yml:57-79` vs `build.sh`
- **Impact:** Two separate ZIP build methods could produce different ZIPs. Inline method excludes `*.sh` but build path could drift.
- **Fix:** Use `build.sh` consistently in both workflows.
- **Status:** ✅ Ready to fix

### M21. `workflow_dispatch` on Release Can Bypass Tag Protection
- **Location:** `build-release.yml:7-15`
- **Impact:** Manual dispatch can build a "release" without a git tag. ZIP upload and PWA snapshot steps are NOT gated on tag presence.
- **Fix:** Gate all release steps on tag presence, or remove `workflow_dispatch`.
- **Status:** ✅ Ready to fix

### M22. Heatmap Aggregation Loads All Clicks Into PHP Memory
- **Location:** `includes/class-db.php:348-354`
- **Impact:** `SELECT page, x_pct, y_pct FROM ...` loads every click from yesterday into PHP. High-traffic site → OOM crash.
- **Fix:** Use pure-SQL aggregation: `INSERT INTO ... SELECT ... GROUP BY ... ON DUPLICATE KEY UPDATE`.
- **Status:** ✅ Ready to fix

### M23. `handle_test_send()` Capability Already Fixed (see previous round)
- **Location:** `includes/class-email.php:138`
- **Status:** ✅ Already fixed in previous round

---

## 🟢 Low — Can Defer

### L1. `$_SERVER['REQUEST_METHOD']` Not Restored in REST `/track`
- **Location:** `includes/class-rest-api.php:1136`
- **Fix:** Save and restore in try/finally block.

### L2. `window.location.reload(true)` Deprecated Parameter
- **Location:** `docs/app/app.js:636, 638`
- **Fix:** Remove `true` parameter.

### L3. `http:` Allowed in Site URL Validation
- **Location:** `docs/app/app.js:802`
- **Fix:** Require `https:` except for `localhost`.

### L4. `sort` Param Not Enum-Validated in REST Schema
- **Location:** `includes/class-rest-api.php:496-500`
- **Fix:** Add `'enum' => ['count', 'from_page', 'to_page']`.

### L5. `get_blog_details()` Deprecated Since WP 5.1
- **Location:** `templates/admin/network-dashboard.php:93`, `network-settings.php:141`
- **Fix:** Replace with `get_site()`.

### L6. `parse_ua()` Called Even for Known Bots
- **Location:** `includes/class-tracker.php:146`
- **Fix:** Move inside `!is_bot()` branch.

### L7. PHPCS `|| true` Fails Open
- **Location:** `job-build-zip.yml:46`, `build-release.yml:55`, `tests.yml:165`
- **Fix:** Remove `|| true`.

### L8. `xargs` Empty Input Fails Open
- **Location:** `job-build-zip.yml:37-40`, `build-release.yml:45-49`
- **Fix:** Use `-exec` instead of `xargs`.

### L9. No Replay Protection on Webhook
- **Location:** `server-webhook.php:36-43`
- **Fix:** Add timestamp-based nonce with 5-minute window.

### L10. Webhook Handler Runs as Root via Sudo
- **Location:** `setup-webhook.yml:69-70`
- **Fix:** Run as dedicated unprivileged user.

---

## Summary

| Severity | Total | Ready to Fix | Requires Manual Setup |
|----------|-------|-------------|----------------------|
| 🔴 Critical | 2 | 1 (C2) | 1 (C1: AI proxy architecture) |
| 🟠 High | 6 | 4 (H2-H4, H6) | 2 (H1, H5) |
| 🟡 Medium | 23 | 23 | 0 |
| 🟢 Low | 10 | 10 | 0 |

**Recommended v2.4.1 scope:**
- Fix all 🔴 Critical (except C1 — document as known issue for v2.5.0)
- Fix all 🟠 High (H5 requires adding host key secret — do it now)
- Fix all 🟡 Medium (all are code-only fixes)
- Fix 🟢 Low items that are 1-line changes (L1-L4, L6-L8)
- Defer: L9-L10 (server-side, not plugin code)

**Total changes:** ~35 files touched, ~500 lines of changes.
