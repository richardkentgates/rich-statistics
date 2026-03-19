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
