# Changelog

All notable changes to Rich Statistics are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
