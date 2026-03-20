# Rich Statistics — Architecture

This document describes how the plugin is structured, how its components fit together, and the key design decisions behind them.

---

## High-Level Design

Rich Statistics is a **self-contained WordPress plugin** with no runtime external dependencies.

```
Visitor browser
    │
    │  POST admin-ajax.php?action=rsa_track
    ▼
RSA_Tracker::handle_ingest()   ← normalise, sanitise, bot-score
    │
    ├── RSA_Bot_Detection::score()
    │
    ├── rsa_sessions (upsert)
    └── rsa_events   (insert)

WordPress admin
    │
    ▼
RSA_Admin (menus + enqueue)
    │
    └── RSA_Analytics → SQL → JSON → wp_localize_script → admin-charts.js
                                                           Chart.js (bundled)

PWA / mobile app
    │  HTTP Basic (Application Passwords)
    ▼
RSA_Rest_API  →  RSA_Analytics
```

---

## Directory Layout

```
rich-statistics/
├── rich-statistics.php       Main plugin file — constants, autoload, bootstrap
├── includes/
│   ├── class-db.php          Schema, activation, migrations (SCHEMA_VERSION)
│   ├── class-tracker.php     Frontend enqueueing + ingest AJAX handler
│   ├── class-bot-detection.php  Two-layer bot scorer (JS bitmask + server UA)
│   ├── class-analytics.php   All read queries (overview, pages, audience…)
│   ├── class-admin.php       Admin menus, asset enqueueing, page rendering
│   ├── class-rest-api.php    Premium REST API (rsa/v1/*)
│   ├── class-click-tracking.php  Premium click event handler + admin page
│   ├── class-heatmap.php     Premium heatmap aggregation + display
│   ├── class-email.php       Scheduled HTML digest emails
│   ├── class-pwa-download.php   Serves the PWA ZIP download; OTP generation
│   └── class-woocommerce.php    WooCommerce analytics: event recording (product views, add-to-cart, orders), path normalisation for WC URLs, dashboard data queries
├── cli/
│   └── class-cli.php         WP-CLI command group: wp rich-stats *
├── assets/
│   ├── js/
│   │   ├── tracker.js         Frontend tracker (bot signals, UTM, events)
│   │   ├── admin-charts.js    Admin dashboard charts (Chart.js wrappers)
│   │   ├── heatmap-overlay.js Premium iframe+canvas heatmap overlay
│   │   └── rsa-profile-otp.js App Code / OTP generation UI on profile page
│   └── css/
│       └── admin.css          Admin dashboard styles
├── templates/admin/           PHP partials rendered by RSA_Admin
│   ├── overview.php
│   ├── pages.php
│   ├── audience.php
│   ├── referrers.php
│   ├── campaigns.php          UTM campaign breakdown
│   ├── behavior.php
│   ├── user-flow.php
│   ├── click-map.php          Premium
│   ├── heatmap.php            Premium
│   ├── preferences.php
│   ├── export.php
│   ├── email-settings.php
│   ├── data-settings.php
│   └── network-settings.php
├── templates/email/           HTML email digest template
├── webapp/                    Installable PWA (vanilla JS, no build step)
├── docs/                      GitHub Pages site + wiki (not shipped in dist ZIP)
├── tests/                     PHPUnit unit + integration tests
├── cli/                       WP-CLI command class
├── languages/                 POT file for i18n
├── vendor/                    Composer dev dependencies (not shipped in dist ZIP)
└── .github/workflows/         CI: tests.yml, build-release.yml
```

---

## Database Schema (v9)

All tables use `{$wpdb->prefix}rsa_` prefix. Each WordPress subsite gets its own tables (standard multisite behaviour).

### `{prefix}rsa_events`

One row per pageview.

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED PK | |
| `session_id` | VARCHAR(36) | UUIDv4 from `sessionStorage` |
| `page` | VARCHAR(512) | Path + sanitised query string |
| `referrer_domain` | VARCHAR(255) | Referring hostname only, `www.` stripped |
| `os` | VARCHAR(64) | Parsed from UA server-side |
| `browser` | VARCHAR(64) | |
| `browser_version` | VARCHAR(16) | |
| `language` | VARCHAR(10) | `navigator.language` |
| `timezone` | VARCHAR(64) | `Intl.DateTimeFormat().resolvedOptions().timeZone` |
| `viewport_w` | SMALLINT UNSIGNED | `window.innerWidth` |
| `viewport_h` | SMALLINT UNSIGNED | `window.innerHeight` |
| `time_on_page` | SMALLINT UNSIGNED | Seconds (Visibility API timer) |
| `bot_score` | TINYINT UNSIGNED | 0–10; rows ≥ threshold are excluded from queries |
| `utm_source` | VARCHAR(100) | `utm_source` URL param on landing page |
| `utm_medium` | VARCHAR(100) | `utm_medium` URL param on landing page |
| `utm_campaign` | VARCHAR(255) | `utm_campaign` URL param on landing page |
| `created_at` | DATETIME | WP local time |

Indexes: `session_id`, `page(191)`, `created_at`, `utm_campaign(191)`

### `{prefix}rsa_sessions`

One row per browser session (a session ends when the tab is closed).

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED PK | |
| `session_id` | VARCHAR(36) UNIQUE | |
| `pages_viewed` | SMALLINT UNSIGNED | Incremented on each event |
| `total_time` | SMALLINT UNSIGNED | Cumulative seconds |
| `entry_page` | VARCHAR(512) | First page of the session |
| `exit_page` | VARCHAR(512) | Last page (updated on each event) |
| `os` | VARCHAR(64) | |
| `browser` | VARCHAR(64) | |
| `language` | VARCHAR(10) | |
| `timezone` | VARCHAR(64) | |
| `created_at` | DATETIME | |
| `updated_at` | DATETIME | AUTO `ON UPDATE CURRENT_TIMESTAMP` |

### `{prefix}rsa_clicks` (Premium)

One row per tracked click event.

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED PK | |
| `session_id` | VARCHAR(36) | |
| `page` | VARCHAR(512) | |
| `element_tag` | VARCHAR(32) | `A`, `BUTTON`, etc. |
| `element_id` | VARCHAR(255) | `id` attribute |
| `element_class` | VARCHAR(512) | `class` attribute (first 512 chars) |
| `element_text` | VARCHAR(255) | Trimmed `innerText` |
| `href_protocol` | VARCHAR(32) | `tel`, `mailto`, `geo`, `sms`, `download` |
| `href_value` | VARCHAR(512) | Phone number, email address, URL, etc. |
| `matched_rule` | VARCHAR(255) | CSS selector rule that matched this element |
| `x_pct` | DECIMAL(5,2) | Click x as % of viewport width |
| `y_pct` | DECIMAL(5,2) | Click y as % of viewport height |
| `created_at` | DATETIME | |

### `{prefix}rsa_heatmap` (Premium)

Pre-aggregated nightly from `rsa_clicks` by a cron task.

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED PK | |
| `page` | VARCHAR(512) | |
| `x_pct` | DECIMAL(5,2) | 2% grid bucket |
| `y_pct` | DECIMAL(5,2) | |
| `weight` | INT UNSIGNED | Aggregated click count in this bucket |
| `date_bucket` | DATE | Aggregated per day |

### `{prefix}rsa_wc_events` (Premium)

One row per WooCommerce interaction event.

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED PK | |
| `session_id` | VARCHAR(36) | Anonymous browser session UUID |
| `event_type` | VARCHAR(32) | `product_view`, `add_to_cart`, `order_complete` |
| `product_id` | BIGINT UNSIGNED | WooCommerce product ID (NULL for order events) |
| `product_name` | VARCHAR(255) | Product title at time of event |
| `product_sku` | VARCHAR(100) | SKU at time of event |
| `quantity` | SMALLINT UNSIGNED | Quantity added/purchased |
| `order_total` | DECIMAL(12,2) | Order total for `order_complete` events; NULL otherwise |
| `order_currency` | VARCHAR(8) | ISO 4217 currency code |
| `created_at` | DATETIME | UTC timestamp |

Indexes: `session_id`, `event_type`, `created_at`. No customer PII is stored.

---

## Request Lifecycle — Tracking Ingest

```
tracker.js  (runs on every frontend page)
  1. Verify config is present (ajaxUrl); bail if not
  2. Check DNT/GPC: if navigator.doNotTrack === '1', window.doNotTrack === '1',
     or navigator.globalPrivacyControl === true → return immediately, no data sent
  3. Gather bot-detection signals → integer bitmask
  4. Read UTM params from URL or sessionStorage
  5. Generate/recall UUIDv4 session ID from sessionStorage
  6. On tab close / visibility change → POST admin-ajax.php (Beacon API)

RSA_Tracker::handle_ingest()
  1. verify_nonce('rsa_track')
  2. check multisite network-disable switch
  3. parse_payload() → sanitise all fields
  4. RSA_Bot_Detection::score(bitmask, UA, headers) → int 0–10
  5. if score ≥ threshold → silent discard
  6. rate-limit check (transient per session, 60 req/min)
  7. strip referrer to domain only
  8. upsert rsa_sessions
  9. insert rsa_events (including utm_source / utm_medium / utm_campaign)
```

---

## Bot Detection

Two independent scoring layers, summed and capped at 10. Requests scoring ≥ the configured threshold (default 3) are silently discarded.

**Layer 1 — JavaScript (client-side bitmask sent with payload):** Checks browser environment signals and sends a pass/fail bitmask. The specific signals checked are intentionally undisclosed to prevent circumvention.

**Layer 2 — PHP server-side (reads only UA + 2 headers, never `REMOTE_ADDR`):** Checks User-Agent patterns and HTTP request headers. Specific patterns are intentionally undisclosed.

Requests are never blocked; scoring ≥ threshold results in silent discard. This avoids false positives breaking legitimate tracking and prevents probing of the threshold.

---

## Admin Rendering Pattern

`RSA_Admin::enqueue_assets($hook)` fires on `admin_enqueue_scripts`:

1. Identifies the current sub-page from the `$hook` string
2. Calls `RSA_Analytics::get_*()` to run the SQL query for that page
3. `wp_localize_script('rsa-admin-charts', 'RSA_DATA', $data)` injects the results as a JS global
4. `admin-charts.js` reads `RSA_DATA` and renders Chart.js charts

Templates are plain PHP partials under `templates/admin/` — no templating engine. Each template accesses `RSA_Analytics` directly for its own filter-dependent data (e.g. the referrers table with a page filter).

---

## UTM Campaign Tracking

1. **Capture:** `tracker.js` reads `utm_source`, `utm_medium`, `utm_campaign` from `window.location.search` on every page load
2. **Persist:** values are stored in `sessionStorage` key `rsa_utm` so they survive within-session navigation to pages that don't have UTM params in the URL
3. **Send:** all three values are included in every ingest POST payload
4. **Store:** `RSA_Tracker::handle_ingest()` writes them to the three UTM columns on `rsa_events`
5. **Report:** `RSA_Analytics::get_campaigns()` groups by source/medium/campaign; the **Campaigns** admin page renders the results

---

## Premium Gating

All premium feature classes check `rs_fs()->can_use_premium_code__premium_only()` before doing anything. When the Freemius SDK (`freemius/` directory) is absent (i.e. in development), a stub in `rich-statistics.php` disables premium features gracefully so the free-tier codebase and tests work without any Freemius dependency.

---

## Multisite

- `RSA_DB::activate(true)` iterates all subsites and runs `install()` for each
- `RSA_DB::on_new_blog()` hooks `wp_initialize_site` to install tables for new subsites
- All table name helpers (`RSA_DB::events_table()` etc.) use `$wpdb->prefix` which is already set to the current subsite's prefix when called inside `switch_to_blog()`
- A network admin panel at `rich-statistics-network` provides the network-wide disable switch

---

## Branching Strategy

```
main         ← tagged releases only (v1.x.x)
  └── develop ← integration branch; all features merge here first
        └── feature/* ← short-lived feature branches (PR → develop)
        └── fix/*     ← bug-fix branches (PR → develop)
        └── release/* ← stabilisation branch cut from develop before tagging
```

- **`main`** is always in a releasable state and matches the latest tag
- **`develop`** is the target for all PRs
- Tags (`v1.x.x`) are applied to `main` after `release/*` merges in
- CI (`tests.yml`) runs on push/PR to both `main` and `develop`
- The release build (`build-release.yml`) triggers on `v*.*.*` tags

---

## CI / CD

| Workflow | Trigger | What it does |
|---|---|---|
| `tests.yml` | push/PR to `main` or `develop` | Unit tests (PHP 8.1–8.3), integration tests (PHP 8.1–8.2 × WP latest/6.4), PHP lint |
| `build-release.yml` | push of `v*.*.*` tag | Creates plugin ZIP, uploads as GitHub Release artifact |

---

## Schema Migrations

`RSA_DB::maybe_upgrade()` is called from `install()` on every activation/upgrade. It runs a series of `INFORMATION_SCHEMA` checks followed by `ALTER TABLE … ADD COLUMN IF NOT EXISTS`-style operations (explicit per-column, not dynamic). The `SCHEMA_VERSION` constant acts as documentation of the highest migration and is asserted in the unit tests.

| Version | Change |
|---|---|
| 6 | Added `matched_rule` to `rsa_clicks` |
| 7 | Added `href_value` to `rsa_clicks` |
| 8 | Added `utm_source`, `utm_medium`, `utm_campaign` to `rsa_events` |
| 9 | Added `rsa_wc_events` table (WooCommerce event tracking, Premium) |

---

## PWA / Desktop App Architecture

The companion app is served from GitHub Pages at `rs-app.richardkentgates.com/app/` and is also bundled inside the Tauri-wrapped Linux desktop app (`.deb`).

### Versioned Snapshot Folder Layout

Every release bakes a frozen snapshot of the app files into `docs/app/{version}/`:

```
docs/app/
├── index.html        ← "live" canonical copy (always latest)
├── app.js
├── app.css
├── sw.js
├── config.js
├── chart.min.js
├── manifest.json
├── icons/
├── versions.json     ← JSON array of all published semver strings
├── 1.4.6/            ← frozen snapshot at that release
│   ├── index.html
│   ├── app.js
│   └── ...
├── 1.4.7/
└── 1.4.8/
```

The `build-release.yml` CI workflow creates the snapshot in its **`build`** job (web app, pushed to `main`) and again in the **`build-desktop`** job (bundled into the `.deb` before `tauri build` runs).

**Critical:** the desktop job must create the snapshot *before* `tauri build` so it is included in the bundle. If the snapshot only exists in the git-pushed commit from the `build` job, the `build-desktop` job is checking out the tag (which predates that commit) and the folder will be absent from the `.deb`.

### Version Switching — How It Works

The REST endpoint `/wp-json/rsa/v1/info` returns `{"ok":true,"data":{"version":"X.Y.Z",...}}` where `version` is the `RSA_VERSION` PHP constant.

**`checkPluginVersion()` in `app.js`:**
1. Fetches `/wp-json/rsa/v1/info` for the connected site.
2. Stores the version in `localStorage` under `rsa_pv_{siteId}`.
3. In Tauri → calls `tauriNavigateToVersion(pluginVersion)`.
4. In browser → clears all SW caches and reloads if the stored version differs.

**`tauriNavigateToVersion(pluginVersion)` in `app.js`:**
1. Fetches `/versions.json` from the Tauri local server to get the list of bundled versions.
2. If the requested version is in the list, does a `HEAD` fetch for `/{version}/index.html` to **verify the folder is physically present** in the bundle before navigating.
3. If present → `window.location.href = '/{version}/'`.
4. If absent (snapshot missing from this `.deb` build) → stays on current location silently.
5. If the plugin version is newer than all bundled versions → shows the in-app update banner with a `.deb` download link, and navigates to the highest bundled version available.

**`localStorage` key:** `rsa_pv_{siteId}` — one key per registered site so version state is independent per site.

### PWA Service Worker — Force Reload on Update

The service worker (`sw.js`) uses a **dual-notification** strategy to ensure all open tabs reload when a new SW activates. A single notification mechanism is unreliable because there is no guarantee of message delivery order.

**SW side (`activate` event):**
```javascript
event.waitUntil(
    caches.keys()
        .then(keys => Promise.all(keys.map(k => caches.delete(k))))
        .then(() => self.clients.claim())
        .then(() => self.clients.matchAll({ includeUncontrolled: true }))
        .then(clients => clients.forEach(c => c.postMessage({ type: 'SW_ACTIVATED' })))
);
```
`clients.claim()` **must be inside the `event.waitUntil()` promise chain**, not called fire-and-forget. Outside the chain it races with activation completion.

**Page side (`index.html` SW registration):**
```javascript
var _rsaHadSWController = !! navigator.serviceWorker.controller;
navigator.serviceWorker.register('./sw.js').then(reg => { ... });
navigator.serviceWorker.addEventListener('controllerchange', function () {
    if (_rsaHadSWController) window.location.reload();
});
navigator.serviceWorker.addEventListener('message', function (event) {
    if (event.data && event.data.type === 'SW_ACTIVATED' && _rsaHadSWController) {
        window.location.reload();
    }
});
```
`_rsaHadSWController` distinguishes a **first install** (no existing SW → no reload needed) from an **update** (had a SW → reload to load new assets). Without this guard every first-install triggers an unnecessary reload.

### Known Issues Resolved (March 2026)

#### `RSA_VERSION` constant not bumped (v1.4.3 → v1.4.5)
**Symptom:** REST API returned `"version": "1.4.2"` even on plugin versions 1.4.3, 1.4.4, and 1.4.5. All version display and the auto-switching mechanism were broken.
**Root cause:** Only the plugin header `Version:` comment was being bumped on each release; the `define( 'RSA_VERSION', '...' )` constant in `rich-statistics.php` was left at `'1.4.2'`.
**Rule:** Both `Version:` header **and** `RSA_VERSION` constant **must** be bumped together on every release. The constant is what the REST API returns; the header is what WordPress reads for updates.

#### Tauri desktop app: blank "Add your site" screen after version switch (v1.4.7)
**Symptom:** After `checkPluginVersion()` detected a new plugin version and called `tauriNavigateToVersion()`, the desktop app navigated to `/{version}/` but that folder was not present in the `.deb` bundle. WebKit served partial/broken HTML with no JavaScript, showing the default no-JS state: welcome screen with a broken image icon.
**Root cause — CI:** The `build-desktop` job checked out the release tag. The versioned snapshot folder is created by the `build` job which then commits it to `main` *after* the tag. The `build-desktop` job therefore never had the folder and did not include it in `frontendDist`.
**Root cause — `versions.json`:** The desktop job *did* add the version to `versions.json` in its local checkout, so the app believed the folder existed when in fact it did not.
**Fix 1 — CI:** Added a "Create versioned app snapshot" step to the `build-desktop` job, *before* `tauri build`, that copies the current `docs/app/*.{html,js,css,...}` files into `docs/app/{VERSION}/`. This mirrors what the `build` job does.
**Fix 2 — Runtime guard:** `tauriNavigateToVersion()` now does a `HEAD` request for `/{version}/index.html` before navigating. If the response is not `2xx`, the function returns silently, keeping the app on its current (working) location. This means users with an older affected build gracefully stay at root rather than hitting a blank screen.

#### PWA service worker: tabs not reloading after update
**Symptom:** After deploying a new release, open tabs continued to serve stale cached assets. Closing and reopening the tab fixed it, but the auto-reload on update was silent.
**Root cause:** `self.clients.claim()` was called fire-and-forget outside `event.waitUntil()`, racing with the `activate` event completing. No `controllerchange` or `message` listener existed on the page.
**Fix:** See "PWA Service Worker — Force Reload on Update" section above.

---

## App Server

The companion app server (`rs-app.richardkentgates.com`, GCP) serves:
- `/app/` — live canonical PWA (GitHub Pages mirror)
- `/app/{version}/` — versioned snapshots referenced by `versions.json`
- `/desktop/rich-statistics-linux-amd64.deb` — latest amd64 `.deb`
- `/desktop/rich-statistics-linux-arm64.deb` — latest arm64 `.deb`

**Webhook deploy flow:**
1. CI `ping-deploy` job POSTs to `/_deploy/` with `DEPLOY_WEBHOOK_TOKEN`
2. Server runs `/usr/local/bin/rsa-app-update` which pulls `docs/app/` from GitHub and syncs to `/var/www/rs-app/`
3. `.deployed-version` file on the server records the last deployed version

**SSH access:** `richardkentgates@104.197.231.120` using `~/.ssh/id_rsa`

**Test server (WP site for development):** `richardkentgates@34.56.56.233`, WP root at `/srv/www/wordpress`, WP-CLI at `/usr/local/bin/wp`.
> Note: `wp plugin install` from a local ZIP fails on this server due to `upgrade/` directory permissions. Use `sudo unzip -o plugin.zip -d /srv/www/wordpress/wp-content/plugins/` instead.
