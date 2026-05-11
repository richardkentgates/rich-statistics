=== Rich Statistics ===
Contributors: richardkentgates
Tags: analytics, privacy, statistics, heatmap, click-tracking
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 2.2.9
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Privacy-first analytics for WordPress publishers. No PII, no cookies, no consent banners required.

== Description ==

**Rich Statistics** gives WordPress publishers useful analytics data without collecting any personally identifiable information (PII). Because no IP addresses, cookies, or personal identifiers are stored, most sites using Rich Statistics do not require a cookie consent banner for their analytics data.

**Free features:**

* Pageviews, sessions, bounce rate with daily sparklines
* Audience breakdown: OS, browser version, viewport, language, timezone
* Top pages ranked by views with average time on page
* Referrer tracking at the domain level only
* UTM campaign tracking (utm_source, utm_medium, utm_campaign) — auto-captured from landing page URL, persisted for the session
* Campaigns view: source / medium / campaign breakdown with session and pageview counts
* User Flow: Path Explorer (Miller columns) with drop-off funnel
* Behavior analysis: time-on-page histogram, session depth, entry pages
* Aggressive bot detection: 10 client-side signals plus server-side UA/header scoring
* Configurable data retention (1–730 days, default 90)
* Email digest reports (daily/weekly/monthly) via wp_mail — no third-party email service
* WP-CLI support: overview, top-pages, audience, export, purge, status
* Full Multisite support with per-site tables and network admin panel
* All third-party dependencies bundled locally — no CDN calls at runtime

**Premium features (via Freemius):**

* Click tracking by protocol (tel, mailto, geo, sms) and by element ID/class — with destination capture (phone number, email, coordinates, file URL)
* Heatmap with viewport-relative thermal canvas overlay and hotspot tooltips
* Full REST API (14 endpoints) authenticated via WP Application Passwords
* Progressive Web App: installable mobile analytics dashboard
* Linux native desktop app (x86_64 and ARM64) built with Tauri

**Privacy by design:**

Sessions are identified using a `sessionStorage` UUID — this identifier lives only in the browser tab and is never sent to any third party. No cookies are set. No IP addresses are stored. Referrer URLs are truncated to domain-only. Sensitive query parameters are stripped from page paths before storage.

== Installation ==

1. Search for **Rich Statistics** in your WordPress admin under **Plugins → Add New**, or install via WP-CLI:

    wp plugin install rich-statistics --activate

2. Activate the plugin
3. Navigate to **Analytics** in the admin sidebar to view your data

To upgrade to Premium, go to **Analytics → Upgrade** inside WordPress. The upgrade is delivered as a standard WordPress plugin update — no ZIP file required.

== Frequently Asked Questions ==

= Does this plugin set cookies? =

No. Sessions are tracked using `sessionStorage` only, which is cleared when the browser tab closes. It is never transmitted to any third party.

= Do I need a cookie consent banner? =

Rich Statistics does not collect personally identifiable information. For most jurisdictions this means analytics tracking consent is not required. You should always consult a lawyer for advice specific to your site, jurisdiction, and audience.

= How is bot traffic filtered? =

Rich Statistics uses an aggressive multi-signal approach: 10 client-side behaviour flags (webdriver detection, missing browser APIs, instant page-load time, etc.) are scored server-side. Known bots (Googlebot, Bingbot, etc.) and suspicious headless browser signatures are also detected via User-Agent pattern matching. The score threshold is configurable.

= Is this compatible with WordPress Multisite? =

Yes. Each subsite gets its own database tables. The Network Admin includes a panel to view per-site status and configure network-wide settings such as default data retention and a global tracker disable switch.

= What PHP version is required? =

PHP 8.0 or higher. WordPress 6.0 or higher.

= Where is my data stored? =

All data is stored in your WordPress database in four tables: `wp_rsa_events`, `wp_rsa_sessions`, `wp_rsa_clicks` (Premium), and `wp_rsa_heatmap` (Premium). No data is ever sent to external servers.

= Can I export my data? =

Yes. Go to **Analytics → Data Settings** and click **Export to CSV**, or use WP-CLI: `wp rich-stats export --period=90d`

= How do I delete all data? =

Go to **Analytics → Data Settings**, enable **Remove all data on uninstall**, then delete the plugin. Alternatively run `wp rich-stats purge --older-than=0` to remove all rows immediately.

= What is the Premium plan? =

The Premium plan unlocks click tracking, heatmaps, the REST API, and the PWA web app. It is available for purchase at [statistics.richardkentgates.com](https://statistics.richardkentgates.com).

== Screenshots ==

1. Overview dashboard — KPI cards, daily line chart, top pages preview
2. Audience page — OS, browser, viewport, language, and timezone breakdowns
3. Heatmap (Premium) — thermal canvas overlay on a live page preview
4. Click Tracking (Premium) — ranked click element table with protocol breakdown
5. PWA Web App (Premium) — mobile analytics dashboard

== Changelog ==

= 2.2.9 =
* Coding standards cleanup: zero PHPCS errors across entire codebase
* CI workflow refactoring: reusable sub-workflows reduce duplication by 70%
* Webhook token sync: dev/test deploy now functional
* Windows desktop push fixed (shell: bash on PowerShell runners)

= 2.2.8 =
* Documentation overhaul across all 6 doc files
* Env-aware PWA config.js + config-dev.js/index-dev.html
* Removed dead /verify-install endpoint
* Premium admin gating (require_premium_or_exit)
* Tracker session total_time NULL-coercion fix
* Migration test coverage (9 new tests) + env detection tests (10 new)

= 2.2.7 =
* Security audit: CORS allowlist, $_POST save/restore, AI error masking, branch protection
* Infrastructure: production/dev/test environments operational with CI/CD
* Documentation: ROADMAP.md, AGENTS.md, README.md, CONTRIBUTING.md updated with all environments

= 2.2.6 =
* Premium feature gating UI in PWA (locked views show upgrade overlay)
* Build Dev workflow for develop branch CI

= 2.2.5 =
* Test isolation fix in AJAX handler tests

= 2.2.4 =
* OTP rate limiting edge case fix (IP hash collision handling)

= 2.2.3 =
* PWA version switching in Tauri desktop app (versioned snapshot navigation)

= 2.2.2 =
* Chart.js loading fix in versioned PWA snapshots

= 2.2.1 =
* PWA UX improvements for mobile viewport

= 2.1.0 =
* Windows desktop app support (`.exe` installer via NSIS)
* Windows platform added to update.json for automatic updates
* CSV export: UTF-8 BOM for Excel compatibility, consistent quoting

= 2.0.2 =
* PWA service worker cache invalidation on version change
* OTP site-pairing rate-limiting properly resets on success

= 2.0.1 =
* WooCommerce analytics (funnel, revenue, top products)
* UTM campaign tracking

= 2.0.0 =
* Complete rewrite with privacy-first architecture
* Progressive Web App (PWA) for mobile/desktop
* Linux desktop app (`.deb` for amd64/arm64 via Tauri 2)
* OTP-based site pairing (replaces file import)
* Click tracking with heatmap visualization
* User flow analysis (entry sources, journey mapping)
* Email digest reports (scheduled)
* Switched from cookie-based to cookieless tracking
* Bot detection now two-layer (client + server-side)

= 1.4.2 =
* Added Linux desktop app with auto-update support via Tauri
* Added "Desktop App" download link in the web app nav (Linux only)

= 1.4.1 =
* Fixed mobile hamburger menu not opening
* Fixed heatmap height on desktop

= 1.4.0 =
* Admin heatmap redesigned as self-contained dark canvas
* Custom date range selector added to all views
* Heatmap REST API date_from/date_to parameters

= 1.3.0 =
* UTM campaign tracking (capture and session attribution)
* Campaigns admin page with source/medium/campaign breakdown
* Path Explorer User Flow (Miller columns, drop-off funnel)
* REST API: /campaigns, /user-flow endpoints

= 1.2.0 =
* PWA OTP pairing: 6-digit HMAC-signed code for secure app connection
* /verify-otp REST endpoint
* Hosted PWA at rs-app.richardkentgates.com

= 1.1.0 =
* Click destination capture (phone, email, geo, SMS, download URLs)
* WP-CLI clicks command (Premium)
* REST API response shape fixes

= 1.0.1 =
* Timezone detection fix (IANA names instead of UTC offset)
* Renamed admin menu to Analytics
* WooCommerce page tracking

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 2.2.7 =
Security hardening release. Schema unchanged. Update is safe without manual action.

= 2.0.0 =
Major rewrite — schema upgraded to v9 (adds wc_events table). Update is automatic on activation.

= 1.2.0 =
No database schema changes. Update is safe to apply without any manual action.

= 1.1.0 =
Database schema updated automatically on activation (adds href_value column to clicks table). No manual action required.

= 1.0.0 =
Initial release. No upgrade actions required.
