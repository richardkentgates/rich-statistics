=== Rich Statistics ===
Contributors: richardkentgates
Tags: analytics, privacy, statistics, heatmap, click-tracking, gdpr
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.0.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Privacy-first analytics for WordPress publishers. No PII, no consent banners required. OS, browser, timezone, language, time on page, referrers and more.

== Description ==

**Rich Statistics** gives WordPress publishers useful analytics data without collecting any personally identifiable information (PII). Because no IP addresses, cookies, or personal identifiers are stored, most sites using Rich Statistics do not require a cookie consent banner for their analytics data.

**Free features:**

* Pageviews, sessions, bounce rate with daily sparklines
* Audience breakdown: OS, browser version, viewport, language, timezone
* Top pages ranked by views with average time on page
* Referrer tracking at the domain level only
* Behavior analysis: time-on-page histogram, session depth, entry pages
* Aggressive bot detection: 10 client-side signals plus server-side UA/header scoring
* Configurable data retention (1–730 days, default 90)
* WP-CLI support: overview, top-pages, audience, export, prune, version
* Full Multisite support with per-site tables and network admin panel
* All third-party dependencies bundled locally — no CDN calls at runtime

**Premium features (via Freemius):**

* Click tracking by protocol (http, tel, mailto, geo, sms) and by element ID/class
* Heatmaps with viewport-relative thermal canvas overlay
* Weekly and monthly email digest reports via wp_mail
* Full REST API (9 endpoints) authenticated via WP Application Passwords
* Progressive Web App: installable mobile analytics dashboard

**Privacy by design:**

Sessions are identified using a `sessionStorage` UUID — this identifier lives only in the browser tab and is never sent to any third party. No cookies are set. No IP addresses are stored. Referrer URLs are truncated to domain-only. Sensitive query parameters are stripped from page paths before storage.

== Installation ==

1. In your WordPress admin go to **Plugins → Add New** and search for **Rich Statistics**
2. Click **Install Now**, then **Activate**
3. Navigate to **Analytics** in the admin sidebar to view your data

Alternatively, install via WP-CLI:

    wp plugin install rich-statistics --activate

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

Yes. Go to **Analytics → Data Settings** and click **Export to CSV**, or use WP-CLI: `wp rsa export --period=90d`

= How do I delete all data? =

Go to **Analytics → Data Settings**, enable **Remove all data on uninstall**, then delete the plugin. Alternatively run `wp rsa prune --days=0` to remove all rows immediately.

= What is the Premium plan? =

The Premium plan unlocks click tracking, heatmaps, the REST API, and the PWA web app. It is available for purchase at [statistics.richardkentgates.com](https://statistics.richardkentgates.com).

== Screenshots ==

1. Overview dashboard — KPI cards, daily line chart, top pages preview
2. Audience page — OS, browser, viewport, language, and timezone breakdowns
3. Heatmap (Premium) — thermal canvas overlay on a live page preview
4. Click Map (Premium) — ranked click element table with protocol breakdown
5. PWA Web App (Premium) — mobile analytics dashboard

== Changelog ==

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.0.0 =
Initial release. No upgrade actions required.
