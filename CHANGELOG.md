# Changelog

All notable changes to Rich Statistics are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

### Added
- **UTM campaign tracking** — `utm_source`, `utm_medium`, and `utm_campaign` parameters are now captured
  automatically from landing-page URLs and persisted for the browser session via `sessionStorage`. Values
  are stored in three new columns on `rsa_events` and displayed in a new **Campaigns** admin page.
  Existing installs are migrated automatically on activation (schema v8).
- **Campaigns admin page** — new sub-menu page under Rich Statistics shows each source / medium /
  campaign combination with session counts, pageview counts, and share bars. Filterable by medium.

### Fixed
- **User Flow SQL error** — `HAVING \`count\`` referenced a SELECT-level alias which MySQL rejects in a
  HAVING clause. Replaced all three occurrences with the aggregate expression `COUNT(*)` directly.
- **Flow chart node labels** — Sankey chart nodes now display the visit count and percentage share
  (e.g. `1,234 (42%)`) alongside the page path so traffic volume is visible without hovering.

---

## [1.2.0] — 2026-03-17

### Added
- **PWA OTP pairing flow** — a "Generate App Code" button on the user profile page issues a server-side
  HMAC-signed 6-digit code (valid 15 minutes). A new REST endpoint (`rsa/v1/verify-otp`) validates the
  code and returns the authenticated username, removing the need to enter credentials manually in the app.
- **Two-step app connection** — the webapp now guides users through: (1) enter site URL + App Code to
  verify the site, then (2) enter the Application Password to complete the connection. Field pre-fill is
  still supported for the personalized download path.
- **GitHub Pages app hosting** — the PWA dashboard is now hosted at
  `statistics.richardkentgates.com/app/` for direct browser installation without visiting the
  WordPress admin. Chart.js is loaded from CDN in this hosted version.
- **"Get the App" landing page section** — the plugin website now includes a dedicated section with
  install instructions, "Open Web App" and "Download App ZIP" CTAs, and nav/footer links.

### Fixed
- Profile page section ordering: the **Rich Statistics App** section (with "Generate App Code") now
  appears **before** the Application Passwords section in WordPress user profiles, so the instructions
  read in the correct order.

---

## [1.1.0] — 2026-03-16

### Added
- Click destination capture: the `href_value` field now records the actual protocol payload — phone number for `tel:` links, email address for `mailto:` links, SMS number for `sms:` links, coordinates for `geo:` links, and file path/URL for downloads
- New **Destination** column in the Click Map admin table
- WP-CLI: `wp rich-stats clicks` command (Premium) — lists click events with Protocol, Destination, Tag, Text, and Clicks columns
- PWA: Click Map view updated with Destination column; all REST API response shapes corrected and envelope unwrapping added to `apiGet`

### Fixed
- REST API endpoints (`/pages`, `/audience`, `/referrers`, `/clicks`) now return correctly-shaped JSON matching the PWA consumer field names
- PWA `renderBehavior` session depth chart now reads `b.bucket` (was incorrectly reading `b.depth`)

---

## [1.0.1] — 2026-03-16

### Fixed
- Timezone detection now uses the IANA timezone from `Intl.DateTimeFormat` rather than UTC offset, giving accurate city-level timezone labels in the Audience view
- Admin menu renamed from "Statistics" to "Analytics" to avoid conflict with other plugins
- User Flow chart redesigned as a step-based Sankey diagram using `ROW_NUMBER()` window functions; minimum session threshold configurable per period
- WooCommerce: cart, checkout, and order-confirmation pages now tracked as meaningful pageviews (previously excluded as non-content pages)
- Multisite: per-site database tables created correctly on subsite activation; network admin panel shows per-site row counts
- Export CSV now escapes all cell values to prevent formula injection

---

## [1.0.0] — 2026-03-15

### Added

**Core / Free**
- Initial release of Rich Statistics
- Pageview and session tracking with `sessionStorage`-based UUID (not cookies)
- Operating system, browser, browser version, timezone, language, viewport detection
- Aggressive bot detection: 10 client-side signals (webdriver, headless flags, missing APIs, instant-load heuristics, etc.) plus server-side User-Agent and HTTP header scoring
- Time-on-page tracking using the Visibility API with `sendBeacon` delivery
- Bot score threshold configurable per-site (default: 3/10)
- Referrer tracking — domain only, no full URLs
- Sensitive query-parameter stripping on stored page paths (no email-shaped or oversized params)
- Admin dashboard with six views: Overview, Pages, Audience, Referrers, Behavior, Data Settings
- Email digest (daily / weekly / monthly) via `wp_mail` — no third-party email services
- Data retention configurable from 1 to 730 days (default: 90), with nightly cron pruning
- WP-CLI: `overview`, `top-pages`, `audience`, `export`, `purge (--dry-run)`, `email-test`, `status`
- Multisite support: per-site tables using `$wpdb->prefix`, network admin panel, network-wide tracker disable switch
- Option to remove all data on plugin uninstall (configurable)
- Chart.js 4.4.2 bundled locally — no CDN requests

**Premium (Freemius)**
- Click tracking: protocol-based (http, tel, mailto, geo, sms) with toggles + CSS ID / class targeting
- Heatmap: viewport-relative coordinate capture, nightly 2% grid aggregation, thermal canvas overlay
- REST API (`rsa/v1`): 9 endpoints authenticated via WP Application Passwords
- Progressive Web App: installable mobile analytics dashboard with offline support and service worker caching

### Security
- AJAX ingest rate-limited to 60 events/session/minute via WP transients
- All user-supplied data sanitised and validated before DB insertion
- Export endpoint uses `Content-Disposition` header for safe CSV delivery
- No third-party scripts, fonts, or analytics loaded at runtime

---

[Unreleased]: https://github.com/richardkentgates/rich-statistics/compare/v1.2.0...HEAD
[1.2.0]: https://github.com/richardkentgates/rich-statistics/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/richardkentgates/rich-statistics/compare/v1.0.1...v1.1.0
[1.0.1]: https://github.com/richardkentgates/rich-statistics/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/richardkentgates/rich-statistics/releases/tag/v1.0.0
