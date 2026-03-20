# Changelog

All notable changes to Rich Statistics are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [2.0.2] — 2026-03-20

### Changed
- Desktop update banner: removed manual download link now that updates are handled via the APT repository

---

## [2.0.1] — 2026-03-20

### Fixed
- WooCommerce sub-menu item now hides entirely when tracking is disabled (was incorrectly showing an upgrade notice)
- Email digest recipients now correctly filtered by allowed roles when role-based recipients is enabled

### Added
- Per-role app access control (`rsa_allowed_roles`) — configure which user roles can access the PWA, REST API, and OTP generation
- Preferences: App Access card with role checkboxes (premium)
- Preferences: role-based email recipients option — send digests to all users with allowed roles instead of a manual list
- Email digest now includes a Top Referrers section
- Email digest now includes a WooCommerce section (orders, revenue, add-to-cart KPIs + top products by views) when WooCommerce tracking is active

---

## [2.0.0] — 2026-03-20

### Added

**Core / Free**
- Privacy-first pageview and session tracking with `sessionStorage`-based UUID (no cookies, no PII)
- OS, browser, browser version, timezone, language, viewport detection
- Aggressive bot detection: 10 client-side signals plus server-side User-Agent and HTTP header scoring
- Time-on-page tracking via the Visibility API with `sendBeacon` delivery
- Referrer tracking (domain only), UTM campaign capture and persistence per session
- Admin dashboard: Overview, Pages, Audience, Referrers, Behavior, Campaigns, User Flow (Path Explorer + Journey Table), Data Settings
- Email digest (daily / weekly / monthly) via `wp_mail`
- Configurable data retention (1–730 days, default 90) with nightly cron pruning
- WP-CLI: `overview`, `top-pages`, `audience`, `export`, `purge`, `email-test`, `status`
- Multisite support with per-site tables and network admin panel
- WooCommerce analytics: product views, add-to-cart, orders, revenue, funnel breakdown

**Premium (Freemius)**
- Click tracking: protocol-based and CSS selector targeting with admin click map
- Heatmap: viewport-relative coordinate capture, nightly aggregation, thermal canvas overlay with tooltips
- REST API (`rsa/v1`): authenticated endpoints for all analytics views
- Progressive Web App: installable mobile dashboard with offline support, persistent data cache, and offline/site-down banners
- Linux desktop app: native Tauri `.deb` for amd64 and arm64, distributed via APT repository with automatic version-matching against the installed plugin

### Security
- All SQL uses `$wpdb->prepare()`; all output uses `esc_html()` / `esc_attr()` / `wp_kses_post()`
- AJAX ingest rate-limited to 60 events/session/minute via WP transients
- Export CSV escapes all cell values to prevent formula injection
- No third-party scripts, fonts, or analytics loaded at runtime

---

[2.0.0]: https://github.com/richardkentgates/rich-statistics/releases/tag/v2.0.0
