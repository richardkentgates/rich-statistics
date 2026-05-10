# Changelog

All notable changes to Rich Statistics are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.2.7] - 2026-05-10

### Changed
- Server restructured to standard LAMP layout (`public_html/`, `desktop/` renamed to `dist/`)
- All download URLs updated from `/desktop/` to `/dist/`
- CORS origin allowlist replaces reflected-origin (security hardening)
- `$_POST` global now saved/restored in `/track` REST endpoint (was polluting global state)
- AI API key masked in preferences form (first 8 chars only)
- AI error responses no longer leak internal details

### Fixed
- `TrackerRateLimitTest` now handles null `$wp_filter` (BrainMonkey compatibility)
- CLI export restricts output path to WordPress directory
- Nonce verification moved before capability check in OTP generator
- Apache vhost: missing `_deploy/` Alias added (was returning 404)
- APT repository: ModSecurity exclusion added for directory listing
- Security headers: X-Frame-Options, X-Content-Type-Options, HSTS, Referrer-Policy

### Security
- CORS: allowlist-based origin validation (was reflecting arbitrary origins with credentials)
- `$_POST`: save/restore pattern prevents global state corruption from public endpoint
- CLI export: path restricted to ABSPATH to prevent arbitrary file writes
- AI errors: logged server-side; generic message returned to client
- Branch protection enabled on `main` (PR required, status checks enforced)
- Server: dist/ directory locked to 755, web root parent permissions restricted

## [2.2.6] - 2026-05-09

### Added
- Premium feature gating UI in PWA (locked features show upgrade prompt)
- `Build Dev` workflow for develop branch CI

### Fixed
- Versioned app snapshot creation in CI desktop build job

## [2.2.5] - 2026-05-09

### Fixed
- Test isolation in AJAX handler tests (cleanup between test methods)

## [2.2.4] - 2026-05-09

### Fixed
- OTP rate limiting edge case (IP hash collision handling)

## [2.2.3] - 2026-05-09

### Added
- PWA version switching in Tauri desktop app

## [2.2.2] - 2026-05-09

### Fixed
- Chart.js loading in versioned PWA snapshots

## [2.2.1] - 2026-05-09

### Changed
- PWA UX improvements for mobile viewport

## [2.1.0] - 2026-05-08

### Added
- Windows desktop app support (`.exe` installer via NSIS)
- Windows platform added to `update.json` for automatic updates
- `build-desktop-windows` CI job for automated Windows builds

### Fixed
- CSV export formatting now includes UTF-8 BOM for Excel compatibility
- Consistency fix: `export_events()` now uses proper CSV quoting (matching `export_data()`)
- Integration tests now blocking in CI (removed `continue-on-error`)

### Changed
- Tauri config updated with Windows NSIS target
- Documentation updated: `webapp/` references corrected to `src-tauri/`
- Development docs now include Windows in update.json examples

### Security
- (none)

## [2.0.2] - 2026-03-20

### Fixed
- PWA service worker cache invalidation on version change
- OTP site-pairing rate-limiting now properly resets on success

## [2.0.1] - 2026-03-18

### Added
- WooCommerce analytics (funnel, revenue, top products)
- Campaign tracking (UTM parameter breakdown)

### Fixed
- Heatmap overlay rendering on high-DPI displays

## [2.0.0] - 2026-03-15

### Added
- Complete rewrite with privacy-first architecture
- Progressive Web App (PWA) for mobile/desktop
- Linux desktop app (`.deb` for amd64/arm64 via Tauri)
- OTP-based site pairing (replaces file import)
- Click tracking with heatmap visualization
- User flow analysis (entry sources, journey mapping)
- Email digest reports (scheduled)

### Changed
- Switched from cookie-based to cookieless tracking
- Bot detection now two-layer (client + server-side)

### Removed
- Legacy `.rsasite` file import (replaced by OTP flow)

## [1.4.8] - 2026-03-10

### Fixed
- Session tracking accuracy improvements
- Browser/OS detection updates

## [1.4.7] - 2026-03-05

### Added
- Custom date range support for all analytics views
- Export data via REST API (CSV/JSON)

### Fixed
- Timezone handling in analytics aggregation

## [1.4.6] - 2026-02-28

### Added
- Audience breakdown: viewport sizes, timezones
- Referrer domain aggregation

### Fixed
- PWA "Add to Home Screen" prompt timing

## [1.4.5] - 2026-02-20

### Fixed
- Admin CSS compatibility with WordPress 6.4+
- Chart.js tooltip positioning

## [1.4.4] - 2026-02-15

### Added
- Initial PWA support
- Tauri desktop app foundation

### Fixed
- Bot detection false positives
- PHP 8.3 compatibility

## [1.4.0] - 2026-01-30

### Added
- Freemius integration for premium licensing
- REST API with Application Password auth
- WP-CLI commands for automation

### Changed
- Plugin architecture refactored to class-based structure

## [1.3.0] - 2025-12-15

### Added
- Email digest reports
- Data export (CSV format)
- Settings API for user preferences

### Fixed
- Database migration reliability

## [1.0.0] - 2025-10-01

### Added
- Initial release
- Pageview tracking with bot filtering
- Basic analytics dashboard
- Session tracking
- WordPress admin integration
